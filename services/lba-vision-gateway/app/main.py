"""LBA Vision Gateway — FastAPI application.

Endpoints:

* ``GET  /health``           — liveness + active provider/model status
* ``POST /v1/lba/analyze``   — one authenticated, opaque image analysis

The service is intentionally stateless apart from an in-process nonce store for
replay protection, and holds no database and no patient data.
"""

from __future__ import annotations

from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse

from . import __version__
from .adapters import resolve_adapter
from .config import NonceStore, Settings
from .schemas import AnalyzeRequest, AnalyzeResponse, HealthResponse, ModelInfo
from .security import AuthenticationError, authenticate

SERVICE_NAME = "lba-vision-gateway"

settings = Settings.from_env()
nonces = NonceStore(ttl=settings.signature_ttl)

app = FastAPI(
    title="LBA Vision Gateway",
    version=__version__,
    description=(
        "Private AI vision gateway for LBA microscopy images. Inference runs in "
        "a separate service; the default adapter is a clearly-marked synthetic "
        "mock that never returns a diagnosis."
    ),
)


def _adapter():
    """The active adapter, or None when misconfigured (fails closed)."""

    try:
        return resolve_adapter(settings.provider)
    except ValueError:
        return None


@app.get("/health", response_model=HealthResponse)
def health() -> HealthResponse:
    adapter = _adapter()
    name, version = (
        adapter.model_info(settings) if adapter else (settings.model_name, settings.model_version)
    )

    return HealthResponse(
        status="ok",
        service=SERVICE_NAME,
        provider=settings.provider,
        model=ModelInfo(name=name, version=version),
        synthetic=settings.provider == "mock",
        authenticated=bool(settings.keys),
    )


@app.post("/v1/lba/analyze", response_model=AnalyzeResponse)
async def analyze(request: Request) -> JSONResponse:
    body = await request.body()

    # 1. Authenticate the request (HMAC + replay window + nonce) before any work.
    try:
        authenticate(dict(request.headers), body, settings, nonces)
    except AuthenticationError as exc:
        return JSONResponse({"error": "unauthorized", "detail": str(exc)}, status_code=401)

    # 2. Parse and validate the opaque request schema.
    try:
        payload = AnalyzeRequest.model_validate_json(body)
    except Exception:
        return JSONResponse({"error": "invalid_request"}, status_code=422)

    if payload.protocol_version != settings.protocol_version:
        return JSONResponse({"error": "unsupported_protocol_version"}, status_code=422)

    # 3. Dispatch to the active adapter. Unknown providers fail closed.
    try:
        adapter = resolve_adapter(settings.provider)
    except ValueError:
        return JSONResponse({"error": "provider_unavailable"}, status_code=503)

    try:
        result = adapter.analyze(payload, settings)
    except Exception:
        # Never echo request material or internal details back to the caller.
        return JSONResponse({"error": "analysis_failed"}, status_code=500)

    return JSONResponse(result.model_dump(), status_code=200)
