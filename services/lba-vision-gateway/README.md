# LBA Vision Gateway (reference service)

A minimal, independent **Python / FastAPI** service that receives ONE opaque
microscopy-image analysis request from the Laravel application and returns a
structured result.

It is deliberately a **separate process**: AI inference is never embedded in
Laravel, and it has **nothing to do with the SINA text chat / Ollama** surface.
No Ollama endpoint is exposed here.

> The default adapter is a **synthetic mock**. It runs **no model** and returns
> clearly-marked SYNTHETIC output (`"synthetic": true`, labels prefixed
> `synthetic_`). It never asserts, implies or fabricates a diagnosis. Real
> inference may only be enabled once an actual image-capable model and a
> validated adapter exist, together with an approved billing/authorization
> policy (Phase 6).

## Endpoints

| Method | Path               | Purpose                                  |
| ------ | ------------------ | ---------------------------------------- |
| GET    | `/health`          | Liveness + active provider/model status   |
| POST   | `/v1/lba/analyze`  | Analyse one image, returns detections     |

## Privacy

The request carries **only** opaque references (`job_reference`,
`image_reference`), the raw image bytes, their SHA-256 checksum, a content type
and the protocol version. It **never** contains a patient name, email, phone,
address, clinical note or any other patient identity.

## Authentication

Every request is authenticated with an **HMAC-SHA256** signature. The canonical
string signed by the caller is:

```
{timestamp}.{nonce}.{sha256_hex(raw_request_body)}
```

Headers:

```
X-LBA-Key-Id:    <key id>
X-LBA-Timestamp: <unix seconds>
X-LBA-Nonce:     <unique opaque value>
X-LBA-Signature: <hex hmac-sha256 of the canonical string>
```

The gateway rejects a request when the signature is missing/invalid, the
timestamp is outside the replay window, or the nonce has been seen before
(replay protection). Use **HTTPS** for any remote deployment.

## Configuration (environment — never hard-coded)

| Variable                     | Meaning                                             |
| ---------------------------- | --------------------------------------------------- |
| `LBA_GATEWAY_KEYS`           | JSON object `{"key-id": "shared-secret", ...}`       |
| `LBA_GATEWAY_KEY_ID`         | Single key id (fallback when `LBA_GATEWAY_KEYS` unset) |
| `LBA_GATEWAY_SECRET`         | Single shared secret (fallback)                     |
| `LBA_GATEWAY_SIGNATURE_TTL`  | Replay window in seconds (default `300`)            |
| `LBA_GATEWAY_PROVIDER`       | `mock` (default) — synthetic only                   |
| `LBA_GATEWAY_MODEL_NAME`     | Reported model name (default `lba-vision-synthetic`) |
| `LBA_GATEWAY_MODEL_VERSION`  | Reported model version (default `0.0.0-synthetic`)  |

No secret, endpoint or credential ships in this repository.

## Running locally (sandbox)

```sh
cd services/lba-vision-gateway
python3 -m venv .venv && . .venv/bin/activate
pip install -r requirements.txt

export LBA_GATEWAY_KEY_ID=dev-key
export LBA_GATEWAY_SECRET=dev-secret-change-me
uvicorn app.main:app --host 127.0.0.1 --port 8099
```

Laravel then points at it, for sandbox only:

```env
LBA_AI_PROVIDER=fastapi
LBA_AI_GATEWAY_URL=http://127.0.0.1:8099
LBA_AI_GATEWAY_KEY_ID=dev-key
LBA_AI_GATEWAY_SECRET=dev-secret-change-me
```

Loopback (`localhost`, `127.0.0.1`, `::1`) is the only host allowed over plain
HTTP; every non-loopback host must be HTTPS and explicitly allowlisted in
`LBA_AI_GATEWAY_ALLOWED_HOSTS`.
