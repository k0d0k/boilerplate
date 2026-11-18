"""Secure storage for the scoped ingest bearer credential.

Priority order:

1. the OS secure store via the OPTIONAL ``keyring`` package (macOS Keychain,
   Windows Credential Manager, Secret Service on Linux);
2. a permission-restricted file (``0600``) inside the agent's config directory,
   used only when no keyring backend is available.

The credential is only ever read at upload time and is never logged, never
written into the non-secret config file, and never echoed back by ``status``.
"""

from __future__ import annotations

import json
import logging
import os
import sys
from pathlib import Path
from typing import Protocol

from .config import platform_config_dir

logger = logging.getLogger("lba_capture.credentials")

KEYRING_SERVICE = "lba-cloud-capture-agent"
KEYRING_USERNAME = "ingest-bearer"
CREDENTIAL_FILE_NAME = "credential.json"


def platform_credential_file() -> Path:
    return platform_config_dir() / CREDENTIAL_FILE_NAME


class CredentialStore(Protocol):
    backend: str

    def get(self) -> str | None: ...

    def set(self, secret: str) -> None: ...

    def clear(self) -> None: ...


class KeyringCredentialStore:
    """Stores the credential in the OS secure store."""

    backend = "os-keyring"

    def __init__(self) -> None:
        import keyring  # imported lazily so the dependency stays optional

        self._keyring = keyring

    def get(self) -> str | None:
        return self._keyring.get_password(KEYRING_SERVICE, KEYRING_USERNAME)

    def set(self, secret: str) -> None:
        self._keyring.set_password(KEYRING_SERVICE, KEYRING_USERNAME, secret)

    def clear(self) -> None:
        try:
            self._keyring.delete_password(KEYRING_SERVICE, KEYRING_USERNAME)
        except self._keyring.errors.PasswordDeleteError:
            pass


class FileCredentialStore:
    """Permission-restricted fallback when no keyring backend is available."""

    backend = "restricted-file"

    def __init__(self, path: Path | None = None) -> None:
        self._path = path or platform_credential_file()

    def get(self) -> str | None:
        if not self._path.exists():
            return None
        try:
            data = json.loads(self._path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            return None
        secret = data.get("bearer")
        return secret if isinstance(secret, str) and secret else None

    def set(self, secret: str) -> None:
        self._path.parent.mkdir(parents=True, exist_ok=True)
        tmp = self._path.with_suffix(".tmp")
        tmp.write_text(json.dumps({"bearer": secret}), encoding="utf-8")
        _chmod_600(tmp)
        os.replace(tmp, self._path)
        _chmod_600(self._path)

    def clear(self) -> None:
        try:
            self._path.unlink()
        except FileNotFoundError:
            pass


def resolve_store(prefer_keyring: bool = True) -> CredentialStore:
    """Return the strongest available credential store."""
    if prefer_keyring:
        try:
            return KeyringCredentialStore()
        except Exception:
            # The keyring package (or its backend) may be missing or unusable on
            # a bare machine; the agent must keep working, so fall through to the
            # restricted file store.
            logger.debug("keyring unavailable; falling back to the restricted file store", exc_info=True)

    return FileCredentialStore()


def _chmod_600(path: Path) -> None:
    if sys.platform.startswith("win"):
        return
    try:
        path.chmod(0o600)
    except OSError:
        pass
