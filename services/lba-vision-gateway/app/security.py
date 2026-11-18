"""HMAC request authentication with timestamp + nonce replay protection.

The Laravel caller signs the canonical string

    {timestamp}.{nonce}.{sha256_hex(raw_request_body)}

and sends it in ``X-LBA-Signature`` alongside ``X-LBA-Key-Id``,
``X-LBA-Timestamp`` and ``X-LBA-Nonce``. This module re-computes it and
enforces the replay window.
"""

from __future__ import annotations

import hashlib
import hmac
import time
from dataclasses import dataclass

from .config import NonceStore, Settings


class AuthenticationError(Exception):
    """Raised when a request cannot be authenticated."""


@dataclass(frozen=True)
class SignedHeaders:
    key_id: str
    timestamp: int
    nonce: str
    signature: str


def _canonical(timestamp: str, nonce: str, body: bytes) -> str:
    """The exact string both sides sign. Must match the PHP side."""

    return f"{timestamp}.{nonce}.{hashlib.sha256(body).hexdigest()}"


def parse_headers(headers: dict[str, str]) -> SignedHeaders:
    def value(name: str) -> str:
        return (headers.get(name) or headers.get(name.lower()) or "").strip()

    key_id = value("X-LBA-Key-Id")
    timestamp = value("X-LBA-Timestamp")
    nonce = value("X-LBA-Nonce")
    signature = value("X-LBA-Signature")

    if not (key_id and timestamp and nonce and signature):
        raise AuthenticationError("missing authentication headers")

    try:
        ts = int(timestamp)
    except ValueError as exc:
        raise AuthenticationError("invalid timestamp") from exc

    return SignedHeaders(key_id=key_id, timestamp=ts, nonce=nonce, signature=signature)


def authenticate(
    headers: dict[str, str],
    body: bytes,
    settings: Settings,
    nonces: NonceStore,
    *,
    now: int | None = None,
) -> SignedHeaders:
    """Verify signature, replay window and nonce uniqueness (or raise)."""

    signed = parse_headers(headers)
    current = int(time.time()) if now is None else now

    if abs(current - signed.timestamp) > settings.signature_ttl:
        raise AuthenticationError("timestamp outside the allowed window")

    secret = settings.keys.get(signed.key_id)
    if not secret:
        raise AuthenticationError("unknown key id")

    expected = hmac.new(
        secret.encode("utf-8"),
        _canonical(str(signed.timestamp), signed.nonce, body).encode("utf-8"),
        hashlib.sha256,
    ).hexdigest()

    if not hmac.compare_digest(expected, signed.signature):
        raise AuthenticationError("signature mismatch")

    # The nonce check runs last so an unauthenticated request never consumes a
    # nonce. A repeated nonce inside the window is a replay.
    if not nonces.remember(signed.nonce, current):
        raise AuthenticationError("nonce replayed")

    return signed
