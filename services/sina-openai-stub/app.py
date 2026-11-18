#!/usr/bin/env python3
"""Deterministic OpenAI-compatible chat stub for SINA staging.

The SINA resolver only ships two adapters — `ollama` and `openai_compatible`.
This service is the private, deterministic `openai_compatible` endpoint used for
staging verification: it speaks the `/chat/completions` contract that
`OpenAiCompatibleChatProvider` expects and returns the SAME reply and the SAME
token counts for the SAME input, so a slash-retry and a wallet capture can be
asserted exactly.

It runs NO model and returns clearly-marked SYNTHETIC text. It never gives
clinical advice, never asserts a diagnosis and must only ever be reachable on a
private network (loopback in the sandbox, a protected gateway on the AI host).

Usage:

    SINA_STUB_TOKEN=dev-token python3 app.py

Laravel then points at it (sandbox only):

    LBA_SINA_ENABLED=true
    LBA_SINA_PROVIDER=openai_compatible
    LBA_SINA_OPENAI_URL=http://127.0.0.1:8098
    LBA_SINA_OPENAI_MODEL=sina-stub-deterministic
"""

from __future__ import annotations

import json
import os
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

HOST = os.environ.get("SINA_STUB_HOST", "127.0.0.1")
PORT = int(os.environ.get("SINA_STUB_PORT", "8098"))
MODEL = os.environ.get("SINA_STUB_MODEL", "sina-stub-deterministic")
TOKEN = os.environ.get("SINA_STUB_TOKEN", "").strip()

REPLY_PREFIX = "[SYNTHETIC SINA-STUB] "


def _word_count(text: str) -> int:
    return len([token for token in text.split() if token])


def _reply_for(messages: list[dict]) -> str:
    """The deterministic assistant reply for a request.

    Echoes the LAST user message so a test can assert exactly what it sent,
    prefixed with a marker that makes the synthetic origin unmistakable.
    """
    last_user = ""
    for message in messages:
        if str(message.get("role", "")) == "user":
            last_user = str(message.get("content", ""))

    return f"{REPLY_PREFIX}{last_user}"


def _completion(messages: list[dict]) -> dict:
    content = _reply_for(messages)
    prompt_tokens = sum(_word_count(str(m.get("content", ""))) for m in messages)
    completion_tokens = _word_count(content)

    return {
        "id": "chatcmpl-sina-stub",
        "object": "chat.completion",
        "model": MODEL,
        "choices": [
            {
                "index": 0,
                "message": {"role": "assistant", "content": content},
                "finish_reason": "stop",
            }
        ],
        "usage": {
            "prompt_tokens": prompt_tokens,
            "completion_tokens": completion_tokens,
            "total_tokens": prompt_tokens + completion_tokens,
        },
    }


class Handler(BaseHTTPRequestHandler):
    server_version = "SinaOpenAiStub/1.0"

    def _send(self, status: int, payload: dict) -> None:
        body = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _authorized(self) -> bool:
        if TOKEN == "":
            return True

        header = self.headers.get("Authorization", "")
        return header == f"Bearer {TOKEN}"

    def do_GET(self) -> None:  # noqa: N802 (stdlib callback name)
        if self.path.rstrip("/") == "/health":
            self._send(200, {"status": "ok", "model": MODEL, "synthetic": True})
            return

        self._send(404, {"error": {"message": "not found"}})

    def do_POST(self) -> None:  # noqa: N802 (stdlib callback name)
        if not self.path.rstrip("/").endswith("/chat/completions"):
            self._send(404, {"error": {"message": "not found"}})
            return

        if not self._authorized():
            self._send(401, {"error": {"message": "unauthorized"}})
            return

        length = int(self.headers.get("Content-Length", "0"))
        raw = self.rfile.read(length) if length > 0 else b"{}"

        try:
            payload = json.loads(raw or b"{}")
        except json.JSONDecodeError:
            self._send(400, {"error": {"message": "invalid json"}})
            return

        messages = payload.get("messages", [])
        if not isinstance(messages, list) or not messages:
            self._send(400, {"error": {"message": "messages required"}})
            return

        self._send(200, _completion(messages))

    def log_message(self, *args) -> None:
        # Keep the stub quiet: it never logs prompt content.
        return


if __name__ == "__main__":
    ThreadingHTTPServer((HOST, PORT), Handler).serve_forever()
