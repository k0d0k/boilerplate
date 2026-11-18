"""Uploader: outcome mapping against a fake HTTP server + multipart format."""

from __future__ import annotations

from pathlib import Path

from lba_capture.uploader import Outcome, Uploader, encode_multipart


def _uploader(server) -> Uploader:
    return Uploader(server.base_url)


def test_new_original_returns_stored(ingest_server, png_file: Path, fake_response) -> None:
    ingest_server.default = fake_response(201, {"data": {"duplicate": False}})

    result = _uploader(ingest_server).upload(png_file, "secret")

    assert result.outcome is Outcome.STORED
    assert result.status == 201
    assert result.outcome.is_success is True


def test_duplicate_returns_duplicate(ingest_server, png_file: Path, fake_response) -> None:
    ingest_server.default = fake_response(200, {"data": {"duplicate": True}})

    result = _uploader(ingest_server).upload(png_file, "secret")

    assert result.outcome is Outcome.DUPLICATE
    assert result.outcome.is_success is True


def test_validation_failure_returns_rejected(ingest_server, png_file: Path, fake_response) -> None:
    ingest_server.default = fake_response(422, {"error": {"code": "rejected", "message": "bad file"}})

    result = _uploader(ingest_server).upload(png_file, "secret")

    assert result.outcome is Outcome.REJECTED
    assert result.retryable is False
    assert result.message == "bad file"


def test_missing_examination_returns_rejected(ingest_server, png_file: Path, fake_response) -> None:
    ingest_server.default = fake_response(404, {"error": {"code": "not_found", "message": "gone"}})

    result = _uploader(ingest_server).upload(png_file, "secret")

    assert result.outcome is Outcome.REJECTED


def test_bad_credential_returns_unauthorized(ingest_server, png_file: Path, fake_response) -> None:
    ingest_server.default = fake_response(401, {"error": {"code": "unauthenticated"}})

    result = _uploader(ingest_server).upload(png_file, "secret")

    assert result.outcome is Outcome.UNAUTHORIZED
    assert result.retryable is False


def test_server_error_is_transient(ingest_server, png_file: Path, fake_response) -> None:
    ingest_server.default = fake_response(503, {"error": {"code": "unavailable"}})

    result = _uploader(ingest_server).upload(png_file, "secret")

    assert result.outcome is Outcome.TRANSIENT
    assert result.retryable is True


def test_rate_limit_is_transient(ingest_server, png_file: Path, fake_response) -> None:
    ingest_server.default = fake_response(429, {})

    result = _uploader(ingest_server).upload(png_file, "secret")

    assert result.outcome is Outcome.TRANSIENT


def test_network_error_is_transient(tmp_path: Path, png_file: Path) -> None:
    # Nothing is listening on this port.
    uploader = Uploader("http://127.0.0.1:1", timeout=1.0)

    result = uploader.upload(png_file, "secret")

    assert result.outcome is Outcome.TRANSIENT
    assert result.retryable is True


def test_request_carries_bearer_and_image_field(ingest_server, png_file: Path, fake_response) -> None:
    ingest_server.default = fake_response(201, {"data": {"duplicate": False}})

    _uploader(ingest_server).upload(png_file, "top-secret", filename="frame.png")

    recorded = ingest_server.requests[-1]
    assert recorded["path"] == "/api/lba/ingest"
    assert recorded["headers"]["Authorization"] == "Bearer top-secret"
    assert recorded["headers"]["Content-Type"].startswith("multipart/form-data; boundary=")
    body = recorded["body"]
    assert b'name="image"' in body
    assert b'filename="frame.png"' in body
    assert b"Content-Type: image/png" in body


def test_uploader_url_joins_path() -> None:
    assert Uploader("https://dev.lbacloud.com").url() == "https://dev.lbacloud.com/api/lba/ingest"
    assert Uploader("https://dev.lbacloud.com/").url() == "https://dev.lbacloud.com/api/lba/ingest"


def test_encode_multipart_structure() -> None:
    body = encode_multipart("image", "a.png", b"DATA", "image/png", "BOUNDARY")

    assert body.startswith(b"--BOUNDARY\r\n")
    assert b'name="image"; filename="a.png"' in body
    assert b"DATA" in body
    assert body.rstrip().endswith(b"--BOUNDARY--")
