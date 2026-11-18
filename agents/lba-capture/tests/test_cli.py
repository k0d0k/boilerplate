"""CLI: parser, version, setup provisioning and status output."""

from __future__ import annotations

import io
import sys
from pathlib import Path

import pytest

from lba_capture import __version__, cli


@pytest.fixture(autouse=True)
def isolated_dirs(monkeypatch: pytest.MonkeyPatch, tmp_path: Path):
    """Redirect the per-user config/state dirs into the test's tmp dir."""
    monkeypatch.setenv("XDG_CONFIG_HOME", str(tmp_path / "config"))
    monkeypatch.setenv("XDG_STATE_HOME", str(tmp_path / "state"))
    # Force the restricted-file credential store so the test never touches a
    # real OS keychain.
    monkeypatch.setitem(sys.modules, "keyring", None)


def test_parser_requires_a_command() -> None:
    parser = cli.build_parser()

    with pytest.raises(SystemExit):
        parser.parse_args([])


def test_version_command(capsys: pytest.CaptureFixture) -> None:
    assert cli.main(["version"]) == 0
    assert __version__ in capsys.readouterr().out


def test_setup_refuses_a_missing_watch_directory(tmp_path: Path, capsys: pytest.CaptureFixture) -> None:
    code = cli.main(
        [
            "setup",
            "--base-url",
            "https://dev.lbacloud.com",
            "--watch-dir",
            str(tmp_path / "missing"),
            "--token-stdin",
            "--no-keyring",
        ]
    )

    assert code == 2
    assert "does not exist" in capsys.readouterr().err


def test_setup_stores_config_and_credential(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch, capsys: pytest.CaptureFixture
) -> None:
    watch = tmp_path / "exports"
    watch.mkdir()
    monkeypatch.setattr("sys.stdin", io.StringIO("provisioned-token\n"))

    code = cli.main(
        [
            "setup",
            "--base-url",
            "https://dev.lbacloud.com",
            "--watch-dir",
            str(watch),
            "--token-stdin",
            "--no-keyring",
        ]
    )

    assert code == 0

    config_path = cli.default_config_path()
    credential_path = config_path.parent / "credential.json"

    assert config_path.exists()
    assert credential_path.exists()
    # The secret lives only in the credential file, never in the config.
    assert "provisioned-token" not in config_path.read_text(encoding="utf-8")
    assert "provisioned-token" in credential_path.read_text(encoding="utf-8")

    out = capsys.readouterr().out
    assert "restricted-file" in out


def test_setup_rejects_a_plain_http_base_url(tmp_path: Path, capsys: pytest.CaptureFixture) -> None:
    watch = tmp_path / "exports"
    watch.mkdir()

    code = cli.main(
        [
            "setup",
            "--base-url",
            "http://example.com",
            "--watch-dir",
            str(watch),
            "--token-stdin",
            "--no-keyring",
        ]
    )

    assert code == 2
    assert "https" in capsys.readouterr().err


def test_status_without_configuration_reports_an_error(capsys: pytest.CaptureFixture) -> None:
    # Move to a config path that does not exist yet.
    assert cli.main(["--config", "/nonexistent/lba/config.json", "status"]) == 2
    assert "no configuration" in capsys.readouterr().err


def test_status_never_echoes_the_secret(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch, capsys: pytest.CaptureFixture
) -> None:
    watch = tmp_path / "exports"
    watch.mkdir()
    monkeypatch.setattr("sys.stdin", io.StringIO("super-secret-token\n"))

    cli.main(
        [
            "setup",
            "--base-url",
            "https://dev.lbacloud.com",
            "--watch-dir",
            str(watch),
            "--token-stdin",
            "--no-keyring",
        ]
    )
    capsys.readouterr()  # discard setup output

    assert cli.main(["status"]) == 0

    out = capsys.readouterr().out
    assert "super-secret-token" not in out
    assert "present" in out
