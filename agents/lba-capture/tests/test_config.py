"""Config: (de)serialisation, validation and web-served-directory refusal."""

from __future__ import annotations

from pathlib import Path

from lba_capture.config import DEFAULT_EXTENSIONS, AgentConfig


def test_round_trip_through_disk(tmp_path: Path) -> None:
    config = AgentConfig(
        base_url="https://dev.lbacloud.com",
        watch_dir=str(tmp_path / "exports"),
        spool_dir=str(tmp_path / "spool"),
        state_dir=str(tmp_path / "state"),
        log_dir=str(tmp_path / "logs"),
    )
    path = tmp_path / "config.json"
    config.save(path)

    loaded = AgentConfig.load(path)

    assert loaded.base_url == "https://dev.lbacloud.com"
    assert loaded.watch_dir == config.watch_dir
    assert loaded.extensions == list(DEFAULT_EXTENSIONS)


def test_config_file_never_contains_a_secret(tmp_path: Path) -> None:
    config = AgentConfig()
    path = tmp_path / "config.json"
    config.save(path)

    text = path.read_text(encoding="utf-8").lower()

    assert "bearer" not in text
    assert "token" not in text
    assert "secret" not in text
    assert "password" not in text


def test_from_dict_normalises_extensions() -> None:
    config = AgentConfig.from_dict({"extensions": ["JPG", ".PNG", "webp"]})

    assert config.extensions == ["jpg", "png", "webp"]


def test_from_dict_empty_extensions_falls_back_to_defaults() -> None:
    config = AgentConfig.from_dict({"extensions": []})

    assert config.extensions == list(DEFAULT_EXTENSIONS)


def test_validate_flags_missing_base_url() -> None:
    problems = AgentConfig(watch_dir="/tmp/x").validate()

    assert any("base_url" in problem for problem in problems)


def test_validate_rejects_plain_http_base_url() -> None:
    config = AgentConfig(
        base_url="http://example.com",
        watch_dir="/tmp/x",
        spool_dir="/tmp/s",
        state_dir="/tmp/st",
    )

    assert any("https" in problem for problem in config.validate())


def test_validate_allows_http_localhost_for_testing() -> None:
    config = AgentConfig(
        base_url="http://localhost:8000",
        watch_dir="/tmp/x",
        spool_dir="/tmp/s",
        state_dir="/tmp/st",
    )

    assert config.validate() == []


def test_validate_refuses_spool_inside_a_web_served_directory() -> None:
    config = AgentConfig(
        base_url="https://dev.lbacloud.com",
        watch_dir="/srv/exports",
        spool_dir="/var/www/html/spool",
        state_dir="/var/lib/lba/state",
    )

    assert any("web-served" in problem for problem in config.validate())


def test_validate_refuses_log_dir_inside_public_html() -> None:
    config = AgentConfig(
        base_url="https://dev.lbacloud.com",
        watch_dir="/srv/exports",
        spool_dir="/srv/spool",
        state_dir="/srv/state",
        log_dir="/home/user/public_html/logs",
    )

    assert any("web-served" in problem for problem in config.validate())
