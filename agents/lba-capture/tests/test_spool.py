"""The bounded, content-addressed local spool."""

from __future__ import annotations

from pathlib import Path

import pytest

from lba_capture.spool import Spool, SpoolFull, hash_file


def _spool(tmp_path: Path, *, max_items: int = 100, max_bytes: int = 10**9) -> Spool:
    return Spool(tmp_path / "spool", max_items=max_items, max_bytes=max_bytes)


def test_add_copies_source_into_the_spool(tmp_path: Path, png_file: Path) -> None:
    spool = _spool(tmp_path)

    entry = spool.add(png_file)

    assert entry.path.exists()
    assert entry.path.read_bytes() == png_file.read_bytes()
    assert entry.checksum == hash_file(png_file)
    assert entry.original_name == "sample.png"


def test_add_is_idempotent_by_content(tmp_path: Path, png_file: Path) -> None:
    spool = _spool(tmp_path)

    first = spool.add(png_file)
    second = spool.add(png_file)

    assert first.path == second.path
    assert spool.depth() == 1


def test_identical_content_from_different_names_collapses(tmp_path: Path, png_file: Path) -> None:
    spool = _spool(tmp_path)
    other = tmp_path / "copy.png"
    other.write_bytes(png_file.read_bytes())

    spool.add(png_file)
    spool.add(other)

    assert spool.depth() == 1


def test_is_full_by_item_count(tmp_path: Path) -> None:
    spool = _spool(tmp_path, max_items=2)

    for index in range(2):
        source = tmp_path / f"img{index}.png"
        source.write_bytes(b"\x89PNG" + bytes([index]))
        spool.add(source)

    assert spool.is_full() is True
    with pytest.raises(SpoolFull):
        extra = tmp_path / "img-extra.png"
        extra.write_bytes(b"\x89PNG-extra")
        spool.add(extra)


def test_is_full_by_bytes(tmp_path: Path, png_file: Path) -> None:
    spool = _spool(tmp_path, max_bytes=10)

    assert spool.is_full(additional_bytes=11) is True
    with pytest.raises(SpoolFull):
        spool.add(png_file)


def test_complete_removes_the_entry_and_sidecar(tmp_path: Path, png_file: Path) -> None:
    spool = _spool(tmp_path)
    entry = spool.add(png_file)

    spool.complete(entry)

    assert not entry.path.exists()
    assert not entry.sidecar.exists()
    assert spool.depth() == 0


def test_quarantine_moves_the_entry_with_a_reason(tmp_path: Path, png_file: Path) -> None:
    spool = _spool(tmp_path)
    entry = spool.add(png_file)

    spool.quarantine(entry, "rejected (422)")

    assert not entry.path.exists()
    quarantined = spool.failed_dir / entry.path.name
    assert quarantined.exists()
    assert (spool.failed_dir / f"{entry.checksum}.reason").read_text(encoding="utf-8").strip() == "rejected (422)"
    assert spool.depth() == 0


def test_pending_ignores_sidecars_and_directories(tmp_path: Path, png_file: Path) -> None:
    spool = _spool(tmp_path)
    spool.add(png_file)
    spool.ensure()

    pending = spool.pending()

    assert len(pending) == 1
    assert pending[0].path.suffix == ".png"
