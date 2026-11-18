"""Environment-driven configuration.

Nothing here is hard-coded: every credential and endpoint comes from the
environment, so no secret ever ships in the repository.
"""

from __future__ import annotations

import json
import os
from dataclasses import dataclass, field


def _int_env(name: str, default: int) -> int:
    raw = os.environ.get(name)
    if raw is None or raw.strip() == "":
        return default
    try:
        return int(raw)
    except ValueError:
        return default


def _key_map() -> dict[str, str]:
    """Resolve the accepted key id -> shared secret pairs.

    Prefers a JSON map in ``LBA_GATEWAY_KEYS``; falls back to the single
    ``LBA_GATEWAY_KEY_ID`` / ``LBA_GATEWAY_SECRET`` pair. An empty map means the
    gateway is not configured and every authenticated request is refused.
    """

    keys: dict[str, str] = {}

    raw = os.environ.get("LBA_GATEWAY_KEYS", "").strip()
    if raw:
        try:
            parsed = json.loads(raw)
        except json.JSONDecodeError:
            parsed = None
        if isinstance(parsed, dict):
            for key_id, secret in parsed.items():
                if isinstance(key_id, str) and isinstance(secret, str) and key_id and secret:
                    keys[key_id] = secret

    if not keys:
        key_id = os.environ.get("LBA_GATEWAY_KEY_ID", "").strip()
        secret = os.environ.get("LBA_GATEWAY_SECRET", "").strip()
        if key_id and secret:
            keys[key_id] = secret

    return keys


@dataclass(frozen=True)
class Settings:
    keys: dict[str, str]
    signature_ttl: int
    provider: str
    model_name: str
    model_version: str
    protocol_version: str

    @classmethod
    def from_env(cls) -> "Settings":
        return cls(
            keys=_key_map(),
            signature_ttl=_int_env("LBA_GATEWAY_SIGNATURE_TTL", 300),
            provider=os.environ.get("LBA_GATEWAY_PROVIDER", "mock").strip() or "mock",
            model_name=os.environ.get("LBA_GATEWAY_MODEL_NAME", "lba-vision-synthetic"),
            model_version=os.environ.get("LBA_GATEWAY_MODEL_VERSION", "0.0.0-synthetic"),
            protocol_version=os.environ.get("LBA_GATEWAY_PROTOCOL_VERSION", "1"),
        )


@dataclass
class NonceStore:
    """A tiny in-memory nonce store providing replay protection.

    Adequate for a single-process reference gateway. A multi-instance
    deployment would move this to a shared, expiring store.
    """

    ttl: int
    seen: dict[str, int] = field(default_factory=dict)

    def remember(self, nonce: str, now: int) -> bool:
        """Record a nonce; return False when it was already used (a replay)."""

        self._evict(now)
        if nonce in self.seen:
            return False
        self.seen[nonce] = now
        return True

    def _evict(self, now: int) -> None:
        stale = [nonce for nonce, at in self.seen.items() if now - at > self.ttl]
        for nonce in stale:
            del self.seen[nonce]
