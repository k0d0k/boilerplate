"""A bounded, content-addressed local spool.

Spooled files are named by their SHA-256 so identical content collapses to a
single entry — the local half of the idempotency guarantee (the server half is
the checksum unique index on ``(examination_id, checksum)``). The spool is
bounded by both item count and total bytes so an offline clinic can never fill
its disk; once a bound is reached the agent stops ingesting new files.

Layout::

    <spool_dir>/<sha256>.<ext>          pending upload
    <spool_dir>/<sha256>.json           sidecar metadata (original name, time)
    <spool_dir>/failed/<sha256>.<ext>   permanently rejected by the server
    <spool_dir>/failed/<sha256>.reason  the rejection reason
"""

from __future__ import annotations

import hashlib
import json
import shutil
import time
from dataclasses import dataclass
from pathlib import Path


class SpoolFull(RuntimeError):
    """Raised when a new file cannot be spooled without exceeding a bound."""


@dataclass
class SpoolEntry:
    path: Path
    checksum: str
    original_name: str

    @property
    def sidecar(self) -> Path:
        return self.path.with_suffix(".json")


class Spool:
    def __init__(self, root: Path, *, max_items: int, max_bytes: int) -> None:
        self.root = root
        self.failed_dir = root / "failed"
        self.max_items = max_items
        self.max_bytes = max_bytes

    def ensure(self) -> None:
        self.root.mkdir(parents=True, exist_ok=True)
        self.failed_dir.mkdir(parents=True, exist_ok=True)

    # --------------------------------------------------------------- ingestion

    def add(self, source: Path, *, checksum: str | None = None) -> SpoolEntry:
        """Copy ``source`` into the spool (idempotent by content hash)."""
        self.ensure()

        checksum = checksum or hash_file(source)
        extension = source.suffix.lower().lstrip(".") or "bin"
        target = self.root / f"{checksum}.{extension}"

        if target.exists():
            # Identical content is already spooled (or was already uploaded and
            # removed) — nothing new to do.
            return SpoolEntry(target, checksum, _read_original_name(target) or source.name)

        if self.is_full(additional_bytes=source.stat().st_size):
            raise SpoolFull(
                f"spool limit reached ({self.depth()} items / {self.size_bytes()} bytes); "
                "backlog will drain as uploads succeed"
            )

        shutil.copy2(source, target)
        target.with_suffix(".json").write_text(
            json.dumps({"original_name": source.name, "spooled_at": time.time()}) + "\n",
            encoding="utf-8",
        )

        return SpoolEntry(target, checksum, source.name)

    # ----------------------------------------------------------------- queries

    def pending(self) -> list[SpoolEntry]:
        self.ensure()
        entries: list[SpoolEntry] = []

        for path in sorted(self.root.glob("*")):
            if path.is_dir() or path.suffix.lower() == ".json":
                continue
            entries.append(SpoolEntry(path, path.stem, _read_original_name(path) or path.name))

        return entries

    def depth(self) -> int:
        return len(self.pending())

    def size_bytes(self) -> int:
        total = 0
        for entry in self.pending():
            try:
                total += entry.path.stat().st_size
            except OSError:
                continue
        return total

    def is_full(self, *, additional_bytes: int = 0) -> bool:
        return self.depth() >= self.max_items or (self.size_bytes() + additional_bytes) > self.max_bytes

    # -------------------------------------------------------------- lifecycle

    def complete(self, entry: SpoolEntry) -> None:
        """Remove a successfully uploaded entry and its sidecar."""
        _unlink(entry.path)
        _unlink(entry.sidecar)

    def quarantine(self, entry: SpoolEntry, reason: str) -> None:
        """Move a permanently rejected entry aside; it is never retried."""
        self.failed_dir.mkdir(parents=True, exist_ok=True)
        destination = self.failed_dir / entry.path.name

        try:
            shutil.move(str(entry.path), str(destination))
        except OSError:
            _unlink(entry.path)

        (self.failed_dir / f"{entry.checksum}.reason").write_text(reason + "\n", encoding="utf-8")
        _unlink(entry.sidecar)


def hash_file(path: Path, chunk_size: int = 1024 * 1024) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(chunk_size), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _read_original_name(path: Path) -> str | None:
    sidecar = path.with_suffix(".json")
    if not sidecar.exists():
        return None
    try:
        data = json.loads(sidecar.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return None
    name = data.get("original_name")
    return name if isinstance(name, str) and name else None


def _unlink(path: Path) -> None:
    try:
        path.unlink()
    except FileNotFoundError:
        pass
