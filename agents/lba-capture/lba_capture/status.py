"""Local, secret-free status reporting.

The status file is what ``lba-capture status`` prints and what an operator
inspects to understand a clinic's backlog. It never contains the bearer
credential, a patient identifier or a tenant identifier.
"""

from __future__ import annotations

import json
import os
import time
from dataclasses import asdict, dataclass
from pathlib import Path


@dataclass
class Status:
    state: str = "idle"  # idle | running | needs_provisioning | stopped
    spool_pending: int = 0
    spool_failed: int = 0
    uploaded: int = 0
    duplicates: int = 0
    rejected: int = 0
    last_error: str = ""
    last_success_at: float = 0.0
    last_attempt_at: float = 0.0
    started_at: float = 0.0
    credential_present: bool = False
    note: str = ""

    def to_dict(self) -> dict:
        return asdict(self)

    def save(self, path: Path) -> None:
        path.parent.mkdir(parents=True, exist_ok=True)
        tmp = path.with_suffix(".tmp")
        tmp.write_text(json.dumps(self.to_dict(), indent=2) + "\n", encoding="utf-8")
        os.replace(tmp, path)

    @classmethod
    def load(cls, path: Path) -> Status:
        if not path.exists():
            return cls()
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            return cls()
        known = {f: data[f] for f in cls.__dataclass_fields__ if f in data}
        return cls(**known)


@dataclass
class PidFile:
    path: Path

    def write(self, pid: int) -> None:
        self.path.parent.mkdir(parents=True, exist_ok=True)
        self.path.write_text(str(pid) + "\n", encoding="utf-8")

    def read(self) -> int | None:
        if not self.path.exists():
            return None
        try:
            return int(self.path.read_text(encoding="utf-8").strip())
        except (OSError, ValueError):
            return None

    def clear(self) -> None:
        try:
            self.path.unlink()
        except FileNotFoundError:
            pass


def process_alive(pid: int | None) -> bool:
    if pid is None or pid <= 0:
        return False
    try:
        os.kill(pid, 0)
    except ProcessLookupError:
        return False
    except PermissionError:
        return True
    except OSError:
        return False
    return True


def age_seconds(timestamp: float) -> float:
    return 0.0 if not timestamp else max(0.0, time.time() - timestamp)
