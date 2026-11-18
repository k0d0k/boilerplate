# Security, Privacy and Clinical Governance

## Asset protection

- Patient identity, consents, LBA images, annotations, consultation messages and reports are sensitive clinical information. Keep raw records in the tenant DB/private storage. Separate organizations' data.
- Enforce authenticated, audited panel-level + request-level + query-level + object-storage-level policies. Signed image links short-lived; never public bucket paths.
- Security headers, secure cookies, CSRF on web, scoped tokens on API, login throttling and privileged MFA before production.
- Logs redact PII/PHI, credentials, signed URLs, payment secrets, raw images and consultation bodies; store opaque audit references.
- Backup encrypted central + every tenant DB, store off-system, test tenant restore; define incident response and deletion/retention policy with legal advisers.
- Avoid sending actual patient data into TRAE/third-party AI prompts or screenshots. Synthetic records and microscopy imagery only.

## Trust and threat examples

| Threat | Control and evidence |
|---|---|
| Cross-tenant access by direct ULID | Scoped query + policy + negative integration tests |
| User visits another panel directly | `canAccessPanel()` + membership checks + tests |
| Wrong-tenant queued worker | Tenant context set/released per job; negative tests |
| Malicious uploaded microscopy file | MIME/magic-byte/decode/pixel caps, private non-executable storage |
| Forged/duplicate AI callback | Signed request, schema validation, nonce/idempotency, audit |
| Incorrect credit charge | Separate available/reserved projection, transactional ledger, duplicate no-op |
| Forged payment webhook | Provider verification/status query, replay check, idempotency |
| Malicious installed module ZIP | Approved signature/hash/publisher, staged isolated deployment; never execute on upload |
| Operator publishes own report | Qualified separate reviewer; policy test |
| Unvalidated AI clinical claim | Non-diagnostic framing and reviewer gate |
| SINA patient overreliance | Disclaimers, escalation to human, no fabricated microscopy findings |

## Jurisdictions

Indonesia and Malaysia privacy, medical practice, consent and payment requirements need jurisdiction-specific review **before live patient data**. Do not claim full clinical/legal compliance from a framework choice or a working UI. Retention and cross-tenant patient identity sharing policies require explicit legal signoff; default is **no cross-tenant clinical sharing**.

## Staging/production gates

No public staging with real patient data without authentication, encrypted secrets, private image storage, isolation tests, backups, monitoring and owner approval. No live payments without merchant and security approval. No diagnostic claims without independent clinical validation.
