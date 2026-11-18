"""Watch-directory scanning (stabilisation) and the seen-file ledger."""

from __future__ import annotations

import os
from pathlib import Path

from lba_capture.watcher import Ledger, WatchScanner


def _scanner(watch_dir: Path, *, seconds: float = 5.0, checks: int = 1) -> WatchScanner:
    return WatchScanner(
        watch_dir=watch_dir,
        extensions=("jpg", "png"),
        stabilize_seconds=seconds,
        stabilize_checks=checks,
    )


def test_candidates_only_accepted_extensions(tmp_path: Path) -> None:
    (tmp_path / "a.png").write_bytes(b"a")
    (tmp_path / "b.jpg").write_bytes(b"b")
    (tmp_path / "c.txt").write_bytes(b"c")
    (tmp_path / "d.mp4").write_bytes(b"d")

    names = {path.name for path in _scanner(tmp_path).candidates()}

    assert names == {"a.png", "b.jpg"}


def test_candidates_ignores_missing_directory(tmp_path: Path) -> None:
    assert _scanner(tmp_path / "nope").candidates() == []


def test_stable_requires_a_quiet_period(tmp_path: Path) -> None:
    path = tmp_path / "a.png"
    path.write_bytes(b"content")
    fixed = 1_000_000.0
    os.utime(path, (fixed, fixed))

    scanner = _scanner(tmp_path, seconds=5.0, checks=1)

    # First observation only records the size.
    assert scanner.stable_files(now=fixed) == []
    # Size is stable now, but the file was modified too recently.
    assert scanner.stable_files(now=fixed + 1.0) == []
    # Quiet for longer than the stabilisation window -> stable.
    assert scanner.stable_files(now=fixed + 10.0) == [path]


def test_growing_file_is_not_stable(tmp_path: Path) -> None:
    path = tmp_path / "a.png"
    path.write_bytes(b"first")
    fixed = 1_000_000.0
    os.utime(path, (fixed, fixed))

    scanner = _scanner(tmp_path, seconds=0.0, checks=2)

    assert scanner.stable_files(now=fixed + 1.0) == []

    path.write_bytes(b"first-and-more")  # size changed between polls
    os.utime(path, (fixed, fixed))

    assert scanner.stable_files(now=fixed + 2.0) == []
    assert scanner.stable_files(now=fixed + 3.0) == []
    assert scanner.stable_files(now=fixed + 4.0) == [path]


def test_ledger_remembers_a_spooled_source(tmp_path: Path) -> None:
    source = tmp_path / "a.png"
    source.write_bytes(b"data")
    ledger = Ledger(tmp_path / "seen.json")

    assert ledger.is_current(source) is False

    ledger.record(source)

    assert ledger.is_current(source) is True


def test_ledger_detects_a_changed_source(tmp_path: Path) -> None:
    source = tmp_path / "a.png"
    source.write_bytes(b"data")
    ledger = Ledger(tmp_path / "seen.json")
    ledger.record(source)

    source.write_bytes(b"data-changed")

    assert ledger.is_current(source) is False


def test_ledger_persists_across_instances(tmp_path: Path) -> None:
    source = tmp_path / "a.png"
    source.write_bytes(b"data")
    path = tmp_path / "seen.json"

    Ledger(path).record(source)

    assert Ledger(path).is_current(source) is True
