# LBA Examination — Manual / AI / Hybrid and Human Review

## User flow

1. Authorized clinic staff locates/creates patient record in correct tenant and branch.
2. Obtain and record informed examination and image processing consent.
3. Create LBA exam: capture date/time, instrument, magnification, operator, notes.
4. Upload one or more microscopy images into **private object storage**. Validate magic bytes/MIME/dimensions/size/checksum, remove unsafe metadata as policy requires. Original immutable.
5. Select **Manual** (0 AI credits), **AI**, or **Hybrid** with the configured credit estimate shown **before** triggering a billable service.
6. **Manual:** annotate directly, no AI job required, no wallet reserve. **AI/Hybrid:** reserve credits and dispatch opaque job via authenticated queue to private CV FastAPI; persist provenance, model version, confidence, geometry and quality flags.
7. Authorized human reviewer evaluates images and annotations, edits or rejects as necessary; reviewer must be qualified. An operator cannot self-approve by default.
8. Reviewer approves; system generates versioned, immutable report in private storage, publishes only after authorization; patient receives self-only expiring signed link.
9. Corrections to published results create a new version, preserving audit trail.

## State machine

```text
draft -> uploaded -> (manual_annotation OR queued -> processing -> results_ready)
      -> review_required -> approved -> published
                             \-> rejected/returned_for_changes
queued/processing -> failed (retryable per policy)
any permitted state -> cancelled (audited)
```

**Manual must not be blocked by AI server outage.** AI outage leaves queue/retryable errors and releases credits according to explicit business policy.

## Dark microscope workbench (Filament Custom Page)

- Dark canvas `#0C1725`, image navigation and thumbnails, zoom (50–300% as prototype target), pan, image brightness/contrast where allowed, scale/calibration indicator when known.
- Manual drawing tools: select, rectangle, polygon, point, labels; edit/delete undo history; non-destructive annotations attached to image coordinates and author.
- AI overlay toggle, label filter, detection confidence, model id/version, quality flags; always distinguish model suggestions from reviewed findings.
- Reviewer inbox and approve/reject/return notes, signature/attribution, report version list, audit and consent status.
- Never use unvalidated medical interpretations such as automatic diagnosis or treatment claims. Viewer may show experimental/not-diagnostic disclaimer when applicable.
- JS canvas/viewer coordinates remain stable under zoom, pan and responsive layout; persistence goes through scoped Laravel services, not direct client writes.

## Token policy

Manual **0**. AI and Hybrid unit pricing **configurable**, not fixed at any prototype example. Reserve/capture/release with idempotency and available/reserved accounting. Duplicated callbacks, retries, cancelled/failed job must not double charge. Keep tenant AI billing separate from patient SINA/chat wallet.

## Acceptance tests

Manual annotation works when AI server offline and without `job_id`; file URLs not public; auto-publish impossible; operator cannot approve own work; cross-tenant image/report download fails; model result schema validated; repeated AI callback changes neither report nor ledger twice.
