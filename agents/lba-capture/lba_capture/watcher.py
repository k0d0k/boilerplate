"""Watch-directory scanning with file stabilisation.

The manufacturer software writes an export file over some period; reading a
half-written file would corrupt the intake. A candidate is therefore only
considered complete once its size is unchanged across several consecutive polls
AND its modification time is older than the configured stabilisation window.
"""

from __future__ import annotations

import json
import time
from dataclasses import dataclass, field
from pathlib import Path


@dataclass
class _Observation:
    size: int
    stable_count: int


@dataclass
class WatchScanner:
    watch_dir: Path
    extensions: tuple[str, ...]
    stabilize_seconds: float
    stabilize_checks: int
    _observations: dict[str, _Observation] = field(default_factory=dict)

    def candidates(self) -> list[Path]:
        """All files in the watch directory with an accepted extension."""
        if not self.watch_dir.is_dir():
            return []

        accepted = {ext.lower().lstrip(".") for ext in self.extensions}
        found = [
            path
            for path in sorted(self.watch_dir.iterdir())
            if path.is_file() and path.suffix.lower().lstrip(".") in accepted
        ]

        return found

    def stable_files(self, *, now: float | None = None) -> list[Path]:
        """Return the subset of candidates that have finished being written."""
        now = time.time() if now is None else now
        stable: list[Path] = []
        seen_keys: set[str] = set()

        for path in self.candidates():
            key = str(path)
            seen_keys.add(key)

            try:
                stat = path.stat()
            except OSError:
                self._observations.pop(key, None)
                continue

            previous = self._observations.get(key)

            if previous is not None and previous.size == stat.st_size:
                stable_count = previous.stable_count + 1
            else:
                stable_count = 0

            self._observations[key] = _Observation(stat.st_size, stable_count)

            age = now - stat.st_mtime
            if stable_count >= self.stabilize_checks and age >= self.stabilize_seconds:
                stable.append(path)

        # Forget observations for files that disappeared.
        for key in [key for key in self._observations if key not in seen_keys]:
            self._observations.pop(key, None)

        return stable


class Ledger:
    """A lightweight record of source files already spooled.

    Keyed by absolute path with the size and mtime that were spooled, so an
    unchanged source file is not re-hashed on every poll. This is an
    optimisation only — correctness comes from the content-addressed spool and
    the server-side checksum dedup.
    """

    def __init__(self, path: Path) -> None:
        self.path = path
        self._entries: dict[str, dict[str, float]] = {}
        self._load()

    def is_current(self, source: Path) -> bool:
        try:
            stat = source.stat()
        except OSError:
            return False

        record = self._entries.get(str(source))
        if record is None:
            return False

        return record.get("size") == stat.st_size and record.get("mtime") == stat.st_mtime

    def record(self, source: Path) -> None:
        try:
            stat = source.stat()
        except OSError:
            return

        self._entries[str(source)] = {"size": stat.st_size, "mtime": stat.st_mtime}
        self._flush()

    def _load(self) -> None:
        if not self.path.exists():
            return
        try:
            data = json.loads(self.path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            return
        if isinstance(data, dict):
            self._entries = {str(k): v for k, v in data.items() if isinstance(v, dict)}

    def _flush(self) -> None:
        self.path.parent.mkdir(parents=True, exist_ok=True)
        tmp = self.path.with_suffix(".tmp")
        tmp.write_text(json.dumps(self._entries) + "\n", encoding="utf-8")
        tmp.replace(self.path)
