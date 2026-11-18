"""HTTPS upload of a single spooled image to the scoped ingest endpoint.

The agent reuses the EXISTING server endpoint (``POST {base_url}/api/lba/ingest``)
— there is no agent-specific server API. The request is a minimal multipart
``image`` upload with the bearer credential in the ``Authorization`` header.

The server classifies the result; the agent only interprets it:

* ``201`` new original stored                -> :attr:`Outcome.STORED`
* ``200`` duplicate of an existing original  -> :attr:`Outcome.DUPLICATE`
* ``422`` rejected (bad file / gate)         -> :attr:`Outcome.REJECTED`
* ``404`` examination no longer available    -> :attr:`Outcome.REJECTED`
* ``401`` credential invalid / expired       -> :attr:`Outcome.UNAUTHORIZED`
* ``429`` / ``5xx`` / network error          -> :attr:`Outcome.TRANSIENT`
"""

from __future__ import annotations

import json
import ssl
import urllib.error
import urllib.request
import uuid
from dataclasses import dataclass
from enum import Enum
from pathlib import Path

INGEST_PATH = "/api/lba/ingest"


class Outcome(str, Enum):
    STORED = "stored"
    DUPLICATE = "duplicate"
    REJECTED = "rejected"
    UNAUTHORIZED = "unauthorized"
    TRANSIENT = "transient"

    @property
    def is_success(self) -> bool:
        return self in (Outcome.STORED, Outcome.DUPLICATE)


@dataclass
class UploadResult:
    outcome: Outcome
    status: int | None
    message: str = ""

    @property
    def retryable(self) -> bool:
        return self.outcome is Outcome.TRANSIENT


def encode_multipart(field_name: str, filename: str, content: bytes, content_type: str, boundary: str) -> bytes:
    """Build a multipart/form-data body without any third-party dependency."""
    delimiter = f"--{boundary}".encode("ascii")
    head = (
        b"Content-Disposition: form-data; name=\""
        + field_name.encode("utf-8")
        + b"\"; filename=\""
        + filename.encode("utf-8")
        + b"\"\r\nContent-Type: "
        + content_type.encode("ascii")
        + b"\r\n\r\n"
    )
    return b"\r\n".join([delimiter, head + content, delimiter + b"--", b""])


class Uploader:
    """Stateless uploader; retry/backoff lives in the agent loop."""

    def __init__(self, base_url: str, *, verify_tls: bool = True, timeout: float = 60.0) -> None:
        self.base_url = base_url.rstrip("/")
        self.verify_tls = verify_tls
        self.timeout = timeout

    def url(self) -> str:
        return self.base_url + INGEST_PATH

    def upload(self, path: Path, secret: str, *, filename: str | None = None) -> UploadResult:
        """POST one file. ``secret`` is used once and never logged."""
        filename = filename or path.name
        content = path.read_bytes()
        content_type = _content_type_for(filename)

        boundary = uuid.uuid4().hex
        body = encode_multipart("image", filename, content, content_type, boundary)

        request = urllib.request.Request(
            self.url(),
            data=body,
            method="POST",
            headers={
                "Content-Type": f"multipart/form-data; boundary={boundary}",
                "Accept": "application/json",
                "Authorization": f"Bearer {secret}",
                "User-Agent": "lba-capture-agent/1.0",
            },
        )

        context = None if self.verify_tls else ssl._create_unverified_context()

        try:
            with urllib.request.urlopen(request, timeout=self.timeout, context=context) as response:
                payload = _safe_json(response.read())
                return UploadResult(_success_outcome(response.status, payload), response.status)
        except urllib.error.HTTPError as error:
            payload = _safe_json(error.read())
            return _classify_http_error(error.code, payload)
        except (urllib.error.URLError, TimeoutError, OSError) as error:
            return UploadResult(Outcome.TRANSIENT, None, f"network error: {error}")


def _success_outcome(status: int, payload: dict) -> Outcome:
    duplicate = bool(payload.get("data", {}).get("duplicate")) if isinstance(payload, dict) else False
    return Outcome.DUPLICATE if duplicate or status == 200 else Outcome.STORED


def _classify_http_error(status: int, payload: dict) -> UploadResult:
    code = ""
    if isinstance(payload, dict):
        code = str(payload.get("error", {}).get("code", ""))
    message = ""
    if isinstance(payload, dict):
        message = str(payload.get("error", {}).get("message", ""))

    if status == 401:
        return UploadResult(Outcome.UNAUTHORIZED, status, message or "credential rejected (re-provision it)")
    if status in (422, 404):
        return UploadResult(Outcome.REJECTED, status, message or f"rejected ({code or status})")
    # 429 and every 5xx are transient: back off and retry.
    return UploadResult(Outcome.TRANSIENT, status, message or f"temporary failure ({status})")


def _content_type_for(filename: str) -> str:
    lowered = filename.lower()
    if lowered.endswith((".jpg", ".jpeg")):
        return "image/jpeg"
    if lowered.endswith(".png"):
        return "image/png"
    if lowered.endswith(".webp"):
        return "image/webp"
    return "application/octet-stream"


def _safe_json(raw: bytes) -> dict:
    try:
        data = json.loads(raw.decode("utf-8"))
    except (ValueError, UnicodeDecodeError):
        return {}
    return data if isinstance(data, dict) else {}
