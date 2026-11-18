"""The capture agent loop: stabilise -> spool -> upload, with offline recovery.

This ties the pieces together and owns all the retry/backoff policy:

* **ingest** — stable files in the watch directory are copied into a bounded,
  content-addressed spool (identical content collapses to one entry);
* **drain** — spooled entries are uploaded oldest-first. A ``201``/``200`` clears
  the entry; a ``422``/``404`` quarantines it permanently (never retried); a
  ``401`` marks the agent as needing re-provisioning and stops the drain; a
  ``429``/``5xx``/network error leaves the entry queued and applies exponential
  backoff.
* **offline** — because queued entries persist on disk, an offline clinic simply
  accumulates a backlog that drains automatically once connectivity returns.
"""

from __future__ import annotations

import logging
import time
from collections.abc import Callable
from pathlib import Path

from .config import AgentConfig
from .credentials import CredentialStore
from .spool import Spool, SpoolFull
from .status import Status
from .uploader import Outcome, Uploader
from .watcher import Ledger, WatchScanner

logger = logging.getLogger("lba_capture.agent")


class CaptureAgent:
    def __init__(
        self,
        config: AgentConfig,
        store: CredentialStore,
        *,
        clock: Callable[[], float] = time.time,
        sleep: Callable[[float], None] = time.sleep,
    ) -> None:
        self.config = config
        self.store = store
        self.clock = clock
        self.sleep = sleep

        self.spool = Spool(config.path_spool(), max_items=config.max_spool_items, max_bytes=config.max_spool_bytes)
        self.spool.ensure()
        self.scanner = WatchScanner(
            watch_dir=Path(config.watch_dir),
            extensions=tuple(config.extensions),
            stabilize_seconds=config.stabilize_seconds,
            stabilize_checks=config.stabilize_checks,
        )
        self.ledger = Ledger(config.path_ledger())
        self.status = Status.load(config.path_status())
        self.uploader = Uploader(
            config.base_url,
            verify_tls=config.verify_tls,
            timeout=config.http_timeout_seconds,
        )

        self._stop = False
        self._backoff = 0.0

    # ------------------------------------------------------------------ signals

    def stop(self) -> None:
        self._stop = True

    # ------------------------------------------------------------------ ingest

    def ingest_once(self) -> int:
        """Spool every newly completed file. Returns the number spooled."""
        if self.spool.is_full():
            self.status.note = "spool full — not ingesting new files until the backlog drains"
            logger.warning(self.status.note)
            return 0

        spooled = 0
        for source in self.scanner.stable_files():
            if self.ledger.is_current(source):
                continue

            try:
                self.spool.add(source)
            except SpoolFull:
                self.status.note = "spool full — not ingesting new files until the backlog drains"
                logger.warning(self.status.note)
                break

            self.ledger.record(source)
            spooled += 1
            logger.info("spooled %s", source.name)

        return spooled

    # ------------------------------------------------------------------- drain

    def drain_once(self) -> None:
        """Attempt to upload every queued entry, oldest first."""
        secret = self.store.get()
        self.status.credential_present = bool(secret)

        if not secret:
            self.status.state = "needs_provisioning"
            self.status.note = "no credential stored — run `lba-capture setup` with a fresh bearer secret"
            return

        self.status.state = "running"
        self.status.last_attempt_at = self.clock()

        for entry in self.spool.pending():
            result = self.uploader.upload(entry.path, secret, filename=entry.original_name)

            if result.outcome is Outcome.STORED:
                self.spool.complete(entry)
                self.status.uploaded += 1
                self.status.last_success_at = self.clock()
                self._backoff = 0.0
                logger.info("uploaded %s", entry.original_name)
                continue

            if result.outcome is Outcome.DUPLICATE:
                self.spool.complete(entry)
                self.status.duplicates += 1
                self.status.last_success_at = self.clock()
                self._backoff = 0.0
                logger.info("duplicate already stored %s", entry.original_name)
                continue

            if result.outcome is Outcome.REJECTED:
                self.spool.quarantine(entry, result.message or "rejected")
                self.status.rejected += 1
                self.status.last_error = f"{entry.original_name}: {result.message}"
                logger.warning("rejected %s: %s", entry.original_name, result.message)
                continue

            if result.outcome is Outcome.UNAUTHORIZED:
                self.status.state = "needs_provisioning"
                self.status.last_error = "credential rejected — re-provision with a fresh bearer secret"
                logger.error("ingest credential rejected (401); stopping drain until re-provisioned")
                return

            # TRANSIENT — leave queued, back off and stop this cycle.
            self.status.last_error = result.message
            self._raise_backoff()
            logger.warning("transient failure uploading %s: %s", entry.original_name, result.message)
            return

    # --------------------------------------------------------------------- loop

    def refresh_counts(self) -> None:
        self.status.spool_pending = self.spool.depth()
        self.status.spool_failed = len(list(self.spool.failed_dir.glob("*"))) if self.spool.failed_dir.exists() else 0
        self.status.credential_present = bool(self.store.get())

    def run(self, *, once: bool = False, max_cycles: int | None = None) -> None:
        self.status.started_at = self.clock()
        if once:
            self.status.state = "running"

        cycle = 0
        try:
            while not self._stop:
                self.ingest_once()
                self.drain_once()
                self.refresh_counts()
                self.status.save(self.config.path_status())

                cycle += 1
                if once or (max_cycles is not None and cycle >= max_cycles):
                    break

                self.sleep(self._sleep_interval())
        except KeyboardInterrupt:  # pragma: no cover - interactive only
            logger.info("interrupted; stopping")
        finally:
            if not once:
                self.status.state = "stopped"
                self.status.save(self.config.path_status())

    def _sleep_interval(self) -> float:
        if self._backoff > 0:
            return min(self._backoff, self.config.backoff_max_seconds)
        return self.config.poll_interval_seconds

    def _raise_backoff(self) -> None:
        if self._backoff <= 0:
            self._backoff = self.config.backoff_base_seconds
        else:
            self._backoff = min(self._backoff * 2, self.config.backoff_max_seconds)
