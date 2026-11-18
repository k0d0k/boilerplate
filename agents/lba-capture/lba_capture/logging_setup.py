"""Logging with defence-in-depth credential redaction.

The agent must never write the bearer credential into a log file. On top of
never formatting it into messages, a redaction filter scrubs any accidental
occurrence of the known secret before a record is emitted.
"""

from __future__ import annotations

import logging
import sys
from pathlib import Path

REDACTED = "***redacted***"


class SecretRedactingFilter(logging.Filter):
    def __init__(self, secrets: list[str]) -> None:
        super().__init__()
        self._secrets = [secret for secret in secrets if secret]

    def add_secret(self, secret: str) -> None:
        if secret and secret not in self._secrets:
            self._secrets.append(secret)

    def filter(self, record: logging.LogRecord) -> bool:
        message = record.getMessage()
        for secret in self._secrets:
            if secret in message:
                message = message.replace(secret, REDACTED)
        record.msg = message
        record.args = ()
        return True


def configure_logging(log_dir: Path, *, secrets: list[str] | None = None, verbose: bool = False) -> SecretRedactingFilter:
    log_dir.mkdir(parents=True, exist_ok=True)
    redactor = SecretRedactingFilter(secrets or [])

    formatter = logging.Formatter("%(asctime)s %(levelname)s %(name)s: %(message)s")

    file_handler = logging.FileHandler(log_dir / "agent.log", encoding="utf-8")
    file_handler.setFormatter(formatter)
    file_handler.addFilter(redactor)

    stream_handler = logging.StreamHandler(sys.stderr)
    stream_handler.setFormatter(formatter)
    stream_handler.addFilter(redactor)

    root = logging.getLogger()
    root.handlers.clear()
    root.addHandler(file_handler)
    root.addHandler(stream_handler)
    root.setLevel(logging.DEBUG if verbose else logging.INFO)

    return redactor
