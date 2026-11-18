# Versioned API, FastAPI Computer Vision, SINA and External Integrations

## API `/api/v1`

Security: Laravel Sanctum scoped tokens for first-party clients as appropriate; policy + active tenant/context mandatory; rate limits, pagination, consistent `request_id`, `Idempotency-Key` on billable/mutating calls. Native mobile auth needs its own threat review if necessary. All dates ISO-8601 UTC in payloads. Never expose raw DB IDs as an access grant.

**Illustrative routes — versioned and finalized per implementing module:**

```text
GET    /api/v1/patient/me
GET    /api/v1/patient/examinations
GET    /api/v1/patient/reports
GET    /api/v1/patient/reports/{id}/download
GET    /api/v1/patient/locations?city=&postcode=&lat=&lng=
GET    /api/v1/patient/locations/{id}/sessions
POST   /api/v1/patient/bookings
GET    /api/v1/patient/bookings
POST   /api/v1/patient/consultations
POST   /api/v1/patient/consultations/{id}/messages
GET    /api/v1/patient/products
POST   /api/v1/patient/orders
GET    /api/v1/patient/wallet
POST   /api/v1/patient/payments/intents
POST   /api/v1/webhooks/payments/{provider}
GET    /api/v1/tenant/patients
POST   /api/v1/tenant/examinations/{id}/analysis
```

Principal module-management endpoints must be independently authenticated as internal platform scope and never available with patient tokens.

## AI service contract (private)

`Laravel worker → FastAPI /jobs`: `opaque_job_id`, schema version, short-lived signed image reference, safe capture metadata, intended model, requested mode. **No name, patient number, phone, diagnosis or tenant DB secrets.** Authenticated private HTTPS + HMAC/mTLS and replay protection. FastAPI responds with `schema_version`, opaque job ID, status, `model.id/version`, `image_quality`, `detections[]` label/code/normalized geometry/confidence, optional notes, checksum. Laravel validates schema, job ownership, model/version, image ref and callback signature; updates state/credits once idempotently.

**AI Mac mini M4 is trial/private**, not the hosted production SLA. Laravel must continue in manual mode and queue/retry gracefully while offline. Ollama/SINA language model is **not assumed** to be an LBA image-detection model. Vision model requires separate validation and responsible clinical framing.

## GoodBarber and mobile

GoodBarber is a **future client**, not a separate application backend; use authorized, versioned API with no embedded master keys or direct tenant DB access. The secure patient-identity and tenancy model must be resolved before exposing patient login at Phase 8.
