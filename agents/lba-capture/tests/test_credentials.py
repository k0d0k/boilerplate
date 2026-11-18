"""Credential stores: the restricted file fallback and the keyring path."""

from __future__ import annotations

import stat
import sys
import types
from pathlib import Path

import pytest

from lba_capture import credentials
from lba_capture.credentials import (
    FileCredentialStore,
    KeyringCredentialStore,
    resolve_store,
)


def test_file_store_round_trip(tmp_path: Path) -> None:
    store = FileCredentialStore(tmp_path / "credential.json")

    assert store.get() is None

    store.set("s3cret-value")

    assert store.get() == "s3cret-value"

    store.clear()

    assert store.get() is None


def test_file_store_restricts_permissions(tmp_path: Path) -> None:
    path = tmp_path / "credential.json"
    store = FileCredentialStore(path)
    store.set("abc")

    if sys.platform.startswith("win"):
        pytest.skip("POSIX permissions not applicable")

    mode = stat.S_IMODE(path.stat().st_mode)
    assert mode == 0o600


def test_file_store_survives_corrupt_content(tmp_path: Path) -> None:
    path = tmp_path / "credential.json"
    path.write_text("not json", encoding="utf-8")

    assert FileCredentialStore(path).get() is None


def _fake_keyring_module(backing: dict) -> types.ModuleType:
    module = types.ModuleType("keyring")
    errors = types.ModuleType("keyring.errors")

    class PasswordDeleteError(Exception):
        pass

    errors.PasswordDeleteError = PasswordDeleteError
    module.errors = errors

    def get_password(service: str, username: str):
        return backing.get((service, username))

    def set_password(service: str, username: str, secret: str) -> None:
        backing[(service, username)] = secret

    def delete_password(service: str, username: str) -> None:
        if (service, username) not in backing:
            raise PasswordDeleteError("absent")
        del backing[(service, username)]

    module.get_password = get_password
    module.set_password = set_password
    module.delete_password = delete_password
    return module


def test_keyring_store_round_trip(monkeypatch: pytest.MonkeyPatch) -> None:
    backing: dict = {}
    monkeypatch.setitem(sys.modules, "keyring", _fake_keyring_module(backing))

    store = KeyringCredentialStore()
    assert store.backend == "os-keyring"

    store.set("keyring-secret")
    assert store.get() == "keyring-secret"

    store.clear()
    assert store.get() is None


def test_resolve_store_prefers_keyring(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setitem(sys.modules, "keyring", _fake_keyring_module({}))

    assert resolve_store(prefer_keyring=True).backend == "os-keyring"


def test_resolve_store_falls_back_without_keyring(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setitem(sys.modules, "keyring", None)

    assert resolve_store(prefer_keyring=True).backend == "restricted-file"


def test_resolve_store_honours_explicit_fallback(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setitem(sys.modules, "keyring", _fake_keyring_module({}))

    assert resolve_store(prefer_keyring=False).backend == "restricted-file"


def test_platform_credential_file_is_under_the_config_dir(monkeypatch: pytest.MonkeyPatch, tmp_path: Path) -> None:
    monkeypatch.setattr(credentials, "platform_config_dir", lambda: tmp_path)

    assert credentials.platform_credential_file() == tmp_path / "credential.json"
