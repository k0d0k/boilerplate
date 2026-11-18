"""Agent loop: ingest, drain, offline backlog, backoff and quiescence."""

from __future__ import annotations

from pathlib import Path

import pytest

from lba_capture.agent import CaptureAgent
from lba_capture.config import AgentConfig


class DictStore:
    """In-memory credential store for tests."""

    backend = "test"

    def __init__(self, secret: str | None = None) -> None:
        self._secret = secret

    def get(self) -> str | None:
        return self._secret

    def set(self, secret: str) -> None:
        self._secret = secret

    def clear(self) -> None:
        self._secret = None


def make_config(tmp_path: Path, base_url: str, **overrides) -> AgentConfig:
    watch = tmp_path / "watch"
    watch.mkdir(exist_ok=True)

    config = AgentConfig(
        base_url=base_url,
        watch_dir=str(watch),
        spool_dir=str(tmp_path / "spool"),
        state_dir=str(tmp_path / "state"),
        log_dir=str(tmp_path / "logs"),
        stabilize_seconds=0.0,
        stabilize_checks=1,
        poll_interval_seconds=0.0,
    )

    for key, value in overrides.items():
        setattr(config, key, value)

    return config


def make_agent(tmp_path: Path, base_url: str, *, secret: str | None = "token", **overrides) -> CaptureAgent:
    return CaptureAgent(make_config(tmp_path, base_url, **overrides), DictStore(secret))


def _drop_export(tmp_path: Path, name: str = "frame.png", data: bytes = b"\x89PNGdata") -> Path:
    path = tmp_path / "watch" / name
    path.write_bytes(data)
    return path


def test_ingest_spools_stable_files_once(tmp_path: Path) -> None:
    agent = make_agent(tmp_path, "http://127.0.0.1:1")
    _drop_export(tmp_path)

    assert agent.ingest_once() == 0  # first poll only records the size
    assert agent.ingest_once() == 1  # stable -> spooled
    assert agent.ingest_once() == 0  # ledger suppresses a re-spool
    assert agent.spool.depth() == 1


def test_ingest_respects_a_full_spool(tmp_path: Path) -> None:
    agent = make_agent(tmp_path, "http://127.0.0.1:1", max_spool_items=0)
    _drop_export(tmp_path)

    assert agent.ingest_once() == 0
    assert agent.spool.depth() == 0
    assert "spool full" in agent.status.note


def test_drain_stored_clears_the_spool(tmp_path: Path, png_file: Path, ingest_server, fake_response) -> None:
    ingest_server.default = fake_response(201, {"data": {"duplicate": False}})
    agent = make_agent(tmp_path, ingest_server.base_url)
    agent.spool.add(png_file)

    agent.drain_once()

    assert agent.spool.depth() == 0
    assert agent.status.uploaded == 1
    assert agent.status.duplicates == 0


def test_drain_duplicate_clears_the_spool(tmp_path: Path, png_file: Path, ingest_server, fake_response) -> None:
    ingest_server.default = fake_response(200, {"data": {"duplicate": True}})
    agent = make_agent(tmp_path, ingest_server.base_url)
    agent.spool.add(png_file)

    agent.drain_once()

    assert agent.spool.depth() == 0
    assert agent.status.duplicates == 1
    assert agent.status.uploaded == 0


def test_drain_rejected_quarantines(tmp_path: Path, png_file: Path, ingest_server, fake_response) -> None:
    ingest_server.default = fake_response(422, {"error": {"code": "rejected", "message": "bad"}})
    agent = make_agent(tmp_path, ingest_server.base_url)
    agent.spool.add(png_file)

    agent.drain_once()

    assert agent.spool.depth() == 0
    assert agent.status.rejected == 1
    assert list(agent.spool.failed_dir.glob("*.png"))


def test_drain_transient_keeps_the_entry_and_backs_off(tmp_path: Path, png_file: Path, ingest_server, fake_response) -> None:
    ingest_server.default = fake_response(503, {"error": {"code": "unavailable"}})
    agent = make_agent(tmp_path, ingest_server.base_url)
    agent.spool.add(png_file)

    agent.drain_once()

    assert agent.spool.depth() == 1  # preserved for a later retry
    assert agent.status.last_error != ""
    assert agent._backoff > 0


def test_offline_network_failure_is_transient(tmp_path: Path, png_file: Path) -> None:
    agent = make_agent(tmp_path, "http://127.0.0.1:1", http_timeout_seconds=1.0)
    agent.spool.add(png_file)

    agent.drain_once()

    assert agent.spool.depth() == 1
    assert agent._backoff > 0


def test_missing_credential_needs_provisioning(tmp_path: Path, png_file: Path) -> None:
    agent = make_agent(tmp_path, "http://127.0.0.1:1", secret=None)
    agent.spool.add(png_file)

    agent.drain_once()

    assert agent.status.state == "needs_provisioning"
    assert agent.spool.depth() == 1


def test_rejected_credential_needs_provisioning(tmp_path: Path, png_file: Path, ingest_server, fake_response) -> None:
    ingest_server.default = fake_response(401, {"error": {"code": "unauthenticated"}})
    agent = make_agent(tmp_path, ingest_server.base_url)
    agent.spool.add(png_file)

    agent.drain_once()

    assert agent.status.state == "needs_provisioning"
    assert agent.spool.depth() == 1


def test_backoff_grows_and_caps(tmp_path: Path) -> None:
    agent = make_agent(tmp_path, "http://127.0.0.1:1", backoff_base_seconds=2.0, backoff_max_seconds=8.0)

    agent._raise_backoff()
    assert agent._backoff == 2.0

    agent._raise_backoff()
    assert agent._backoff == 4.0

    agent._raise_backoff()
    assert agent._backoff == 8.0

    agent._raise_backoff()
    assert agent._backoff == 8.0  # capped


def test_run_once_writes_a_status_file(tmp_path: Path, png_file: Path, ingest_server, fake_response) -> None:
    ingest_server.default = fake_response(201, {"data": {"duplicate": False}})
    agent = make_agent(tmp_path, ingest_server.base_url)
    agent.spool.add(png_file)

    agent.run(once=True)

    assert agent.config.path_status().exists()
    assert agent.status.uploaded == 1
    assert agent.status.spool_pending == 0


def test_offline_backlog_drains_when_connectivity_returns(tmp_path: Path, png_file: Path, ingest_server, fake_response) -> None:
    # Offline first: nothing is listening on the dead port.
    agent = make_agent(tmp_path, "http://127.0.0.1:1", http_timeout_seconds=1.0)
    agent.spool.add(png_file)
    agent.drain_once()
    assert agent.spool.depth() == 1

    # Connectivity returns: the same agent drains the preserved backlog.
    agent.uploader = agent.uploader.__class__(ingest_server.base_url)
    ingest_server.default = fake_response(201, {"data": {"duplicate": False}})
    agent.drain_once()

    assert agent.spool.depth() == 0
    assert agent.status.uploaded == 1


@pytest.mark.parametrize("ext", ["png", "jpg"])
def test_accepted_extensions(tmp_path: Path, ext: str) -> None:
    agent = make_agent(tmp_path, "http://127.0.0.1:1")
    _drop_export(tmp_path, name=f"frame.{ext}")

    agent.ingest_once()
    agent.ingest_once()

    assert agent.spool.depth() == 1
