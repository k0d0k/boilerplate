"""Shared test fixtures.

Everything here is SYNTHETIC and NON-PATIENT: images are generated in-memory
from base64 constants and the "server" is a local fake HTTP endpoint. No real
clinical data, credentials or network resources are involved.
"""

from __future__ import annotations

import base64
import http.server
import json
import threading
from pathlib import Path

import pytest

# A 1x1 transparent PNG (synthetic, NON-PATIENT).
PNG_1X1 = base64.b64decode(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=="
)

# A minimal 1x1 JPEG (synthetic, NON-PATIENT).
JPEG_1X1 = base64.b64decode(
    "/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwc"
    "KDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAA"
    "AAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=="
)


class FakeResponse:
    def __init__(self, status: int = 201, body: dict | None = None) -> None:
        self.status = status
        self.body = body if body is not None else {"data": {"duplicate": False}}


class _Handler(http.server.BaseHTTPRequestHandler):
    def do_POST(self) -> None:
        length = int(self.headers.get("Content-Length", 0))
        body = self.rfile.read(length)

        self.server.requests.append(
            {
                "path": self.path,
                "headers": dict(self.headers.items()),
                "body": body,
            }
        )

        response = self.server.next_response()
        payload = json.dumps(response.body).encode("utf-8")

        self.send_response(response.status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def log_message(self, *args: object) -> None:  # silence the test output
        return


class FakeIngestServer(http.server.HTTPServer):
    """A local HTTP server that mimics ``POST /api/lba/ingest``."""

    def __init__(self) -> None:
        super().__init__(("127.0.0.1", 0), _Handler)
        self.requests: list[dict] = []
        self._responses: list[FakeResponse] = []
        self.default = FakeResponse()

    def queue(self, *responses: FakeResponse) -> None:
        self._responses.extend(responses)

    def next_response(self) -> FakeResponse:
        if self._responses:
            return self._responses.pop(0)
        return self.default

    @property
    def base_url(self) -> str:
        host, port = self.server_address
        return f"http://{host}:{port}"


@pytest.fixture
def fake_response():
    """Factory for scripted server responses."""

    def _make(status: int = 201, body: dict | None = None) -> FakeResponse:
        return FakeResponse(status, body)

    return _make


@pytest.fixture
def ingest_server() -> FakeIngestServer:
    server = FakeIngestServer()
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        yield server
    finally:
        server.shutdown()
        thread.join(timeout=5)


@pytest.fixture
def png_file(tmp_path: Path) -> Path:
    path = tmp_path / "sample.png"
    path.write_bytes(PNG_1X1)
    return path


@pytest.fixture
def jpeg_file(tmp_path: Path) -> Path:
    path = tmp_path / "sample.jpg"
    path.write_bytes(JPEG_1X1)
    return path
