"""Non-secret agent configuration.

This module holds ONLY non-secret settings. The bearer credential is never
written here — it lives in the OS secure store (see :mod:`lba_capture.credentials`).
The config file is therefore safe to inspect and contains no token, no patient
identifier and no tenant identifier.
"""

from __future__ import annotations

import json
import os
import sys
from dataclasses import asdict, dataclass, field
from pathlib import Path
from typing import Any

DEFAULT_EXTENSIONS: tuple[str, ...] = ("jpg", "jpeg", "png", "webp")


def platform_config_dir() -> Path:
    """The per-user configuration directory for the agent."""
    if sys.platform.startswith("win"):
        base = os.environ.get("APPDATA") or str(Path.home() / "AppData" / "Roaming")
        return Path(base) / "LBACapture"
    if sys.platform == "darwin":
        return Path.home() / "Library" / "Application Support" / "LBACapture"
    base = os.environ.get("XDG_CONFIG_HOME") or str(Path.home() / ".config")
    return Path(base) / "lba-capture"


def platform_state_dir() -> Path:
    """The per-user state directory (spool, ledger, logs, status)."""
    if sys.platform.startswith("win"):
        base = os.environ.get("LOCALAPPDATA") or str(Path.home() / "AppData" / "Local")
        return Path(base) / "LBACapture"
    if sys.platform == "darwin":
        return Path.home() / "Library" / "Application Support" / "LBACapture"
    base = os.environ.get("XDG_STATE_HOME") or str(Path.home() / ".local" / "state")
    return Path(base) / "lba-capture"


@dataclass
class AgentConfig:
    """The non-secret, operator-editable agent configuration."""

    base_url: str = ""
    watch_dir: str = ""
    spool_dir: str = ""
    state_dir: str = ""
    log_dir: str = ""
    extensions: list[str] = field(default_factory=lambda: list(DEFAULT_EXTENSIONS))
    # A purely human-readable label recorded on the server-side intake so an
    # operator can recognise the device. It is NEVER used for routing and must
    # not contain patient data.
    instrument_label: str = "Microscope export agent"
    # File stabilisation: a candidate file must not change in size across this
    # many consecutive polls and must not be modified within this many seconds.
    stabilize_seconds: float = 5.0
    stabilize_checks: int = 2
    poll_interval_seconds: float = 5.0
    # Bounded spool: the agent stops ingesting new files once either limit is
    # reached, so an offline clinic can never fill the disk.
    max_spool_items: int = 1000
    max_spool_bytes: int = 5 * 1024 * 1024 * 1024
    # Upload retry/backoff for transient (network / 429 / 5xx) failures.
    max_retries: int = 5
    backoff_base_seconds: float = 2.0
    backoff_max_seconds: float = 300.0
    http_timeout_seconds: float = 60.0
    verify_tls: bool = True

    # ------------------------------------------------------------------ paths

    def path_spool(self) -> Path:
        return Path(self.spool_dir)

    def path_state(self) -> Path:
        return Path(self.state_dir)

    def path_logs(self) -> Path:
        return Path(self.log_dir)

    def path_ledger(self) -> Path:
        return self.path_state() / "seen.json"

    def path_status(self) -> Path:
        return self.path_state() / "status.json"

    def path_pid(self) -> Path:
        return self.path_state() / "agent.pid"

    # ------------------------------------------------------------- (de)serial

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)

    @classmethod
    def from_dict(cls, data: dict[str, Any]) -> AgentConfig:
        known = {f: data[f] for f in cls.__dataclass_fields__ if f in data}
        config = cls(**known)

        if not config.extensions:
            config.extensions = list(DEFAULT_EXTENSIONS)

        config.extensions = [str(ext).lower().lstrip(".") for ext in config.extensions]

        return config

    def save(self, path: Path) -> None:
        path.parent.mkdir(parents=True, exist_ok=True)
        written = path.with_suffix(path.suffix + ".tmp")
        written.write_text(json.dumps(self.to_dict(), indent=2) + "\n", encoding="utf-8")
        _restrict(written)
        os.replace(written, path)
        _restrict(path)

    @classmethod
    def load(cls, path: Path) -> AgentConfig:
        return cls.from_dict(json.loads(path.read_text(encoding="utf-8")))

    # ------------------------------------------------------------ validation

    def validate(self) -> list[str]:
        """Return a list of human-readable problems (empty means usable)."""
        problems: list[str] = []

        if not self.base_url:
            problems.append("base_url is not set (run `lba-capture setup`)")
        elif not self.base_url.startswith("https://") and not self.base_url.startswith("http://localhost"):
            problems.append("base_url must be https (or http://localhost for local testing)")

        for name, value in (("watch_dir", self.watch_dir), ("spool_dir", self.spool_dir), ("state_dir", self.state_dir)):
            if not value:
                problems.append(f"{name} is not set")

        # Defensive: the agent's own writable directories must never sit inside a
        # web-served directory on the agent host.
        for name, value in (("spool_dir", self.spool_dir), ("log_dir", self.log_dir or self.state_dir)):
            if value and _looks_web_served(Path(value)):
                problems.append(f"{name} must not be inside a web-served public directory")

        if self.max_spool_items <= 0:
            problems.append("max_spool_items must be positive")

        if self.stabilize_checks < 1:
            problems.append("stabilize_checks must be at least 1")

        return problems

    def ensure_directories(self) -> None:
        for path in (self.path_spool(), self.path_state(), self.path_logs()):
            path.mkdir(parents=True, exist_ok=True)
        _restrict(self.path_spool())
        _restrict(self.path_state())
        if self.log_dir:
            _restrict(self.path_logs())


def default_config_path() -> Path:
    return platform_config_dir() / "config.json"


def default_spool_dir() -> Path:
    return platform_state_dir() / "spool"


def default_log_dir() -> Path:
    return platform_state_dir() / "logs"


def default_state_dir() -> Path:
    return platform_state_dir()


# Directory names that conventionally hold web-served content. The agent's own
# writable areas must never live inside one of these.
_WEB_SERVED_NAMES = {"public", "public_html", "www", "wwwroot", "htdocs", "webroot"}


def _looks_web_served(path: Path) -> bool:
    return any(part.lower() in _WEB_SERVED_NAMES for part in path.parts)


def _restrict(path: Path) -> None:
    """Best-effort restriction of a path to the current user only."""
    if sys.platform.startswith("win"):
        return
    try:
        if path.is_dir():
            path.chmod(0o700)
        elif path.exists():
            path.chmod(0o600)
    except OSError:
        # Some filesystems (e.g. mounted volumes) do not support chmod; the
        # keyring is the preferred credential store precisely to avoid relying
        # on file permissions alone.
        pass
