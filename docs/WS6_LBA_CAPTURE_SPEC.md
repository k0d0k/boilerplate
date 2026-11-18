# WS6 — LBA Microscope Capture (Implementation Contract)

Status: design/implementation kickoff. Not a claim of working hardware support.
Base: latest `main` following merged PR #32.
Branch: `feature/v3-ws6-lba-capture`.

## Goal

Allow a verified clinic operator to acquire actual microscopy still images (USB/UVC camera when supported by the browser, or exports from manufacturer software) directly into an existing LBA examination, with no public images, no mixed-tenant access and no automatic AI or clinical diagnosis.

## Reuse before writing

- Existing `Modules/LBA/Filament/Pages/MicroscopeWorkspace.php` and its view.
- `MicroscopyImageStore`: immutable original, content/MIME/size/dimension inspection, private disk, checksum, explicit examination-image-processing consent.
- `ExaminationRegistrar`, `ExaminationWorkflow`, `ExaminationImage`, branch and tenant context, Module FeatureGate/RBAC.
- Existing private-image stream, review, assessment and report lifecycle.

Do not create a competing LBA module, a second image store, or duplicate patient/examination records.

## WS6.A — Browser capture

- In the existing Filament 5/Livewire 4 microscope workspace add device enumeration, start/stop local preview, camera selector, capture-still and upload progress.
- Use `navigator.mediaDevices.getUserMedia` only in HTTPS secure contexts, when the device exposes a compatible camera. Show a clear fallback if no compatible camera exists, permission is denied or browser has no support. Never claim all OptoEdu devices are UVC-compatible.
- Still images must traverse an authenticated, CSRF-protected, authorized upload path that calls `MicroscopyImageStore` and applies its checks. No publicly accessible snapshots or livestream.
- Route to already-selected examination; never implicitly create a new patient/examination from a camera frame.
- Browser preview should be local-only, not stored or broadcast. Stop media tracks on navigation/unmount.
- Optimise desktop and mobile layouts using the existing LBA design system.

## WS6.B — Manufacturer software export / watched-folder bridge

- Support secure explicit file import through the existing upload route as the baseline fallback.
- If a companion/watch-folder agent is implemented, support Windows and macOS via a small separately deployable client; it watches only a user-configured export directory, never assumes the microscope brand SDK exists.
- Agent-to-server ingress must use separate scoped, revocable machine credentials bound to ONE tenant, branch, operator/device and a selected editable examination; store credential hashes only. Require short-lived upload authorization, rate/size limits, audit, idempotency keyed to checksum and examination, and fail closed on expired or revoked credentials.
- A device/agent must never choose arbitrary tenant DB, patient or examination by untrusted raw input.
- Agent must not sync clinical images to Google Drive or third-party storage by default.
- When there is no approved scoped-agent authentication, do not ship a public ingest endpoint. Keep manual import available.

## WS6.C — Operational checks & human review

- Image gallery automatically refreshes after successful upload and preserves existing manual annotation tools and normalized geometry.
- Display image source, capture time, checksum and instrument metadata without leaking patient data in logs.
- Manual LBA works with no AI server, wallets or credits.
- AI processing remains gated by explicit consent, module permission, allowlisted FastAPI gateway and billing policy. AI outputs are unreviewed suggestions, never automated diagnosis or report publication.
- Add actual DB-driven counts where practical; do not introduce fabricated dashboard statistics.

## Acceptance gates

- Clinic A cannot upload, view, overwrite or import to Clinic B's examination.
- Operator cannot bypass branch membership, editability state, consent, file MIME/size, or module authorization.
- Missing/refused/disconnected camera gracefully falls back to file import.
- Upload duplicate policy must be deterministic and documented; no silent overwrite of immutable original.
- Negative tests for authenticated/unauthenticated, revoked device, expired token, mismatch examination, wrong branch, missing consent, bad images, oversized images, CSRF and replay/race; tenant isolation and human review remain unchanged.
- Local/browser manual QA on HTTPS staging. No real hardware compatibility claim until tested with the actual OptoEdu camera/SDK.
- id/en/ms-MY localization parity, focused PHPUnit and Pint; full suite if feasible.

## Delivery procedure

1. Read current implementation; reuse services and contracts.
2. Implement WS6.A first with tests and commit.
3. Implement WS6.B only with secured agent protocol and tests; if unsupported, explicitly mark blocked rather than weakening auth.
4. Integrate UI, logging, localization and WS6.C tests; commit each checkpoint.
5. Push this existing branch; create ONE Draft PR to main; no merge and no deploy.
6. Report commit SHAs, tests, hardware-dependent questions/limits and deploy requirements.
