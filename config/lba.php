<?php

/**
 * LBA Cloud platform configuration (Phase 1B-4).
 *
 * Values that describe the *platform* itself — as distinct from the modules it
 * hosts. Modules declare a `min_platform` they are built for; the FeatureGate
 * compares it against the version below.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | System name (fallback)
    |--------------------------------------------------------------------------
    |
    | The platform's display name used in the application title and the
    | Principal header. Global Settings may persist a different name centrally;
    | this value is the fallback for an installation that never changed it, so
    | existing installs keep showing the name they shipped with.
    |
    */
    'system_name' => env('APP_NAME', 'LBA Cloud'),

    /*
    |--------------------------------------------------------------------------
    | Platform version
    |--------------------------------------------------------------------------
    |
    | The semantic version of the LBA Cloud platform contract. Module manifests
    | declare the minimum they require, and an incompatible module fails closed.
    |
    */
    'platform' => [
        'version' => env('LBA_PLATFORM_VERSION', '3.0.0'),

        /*
        |----------------------------------------------------------------------
        | Platform capabilities
        |----------------------------------------------------------------------
        |
        | The `platform.*` dependencies a module may declare in its manifest
        | `requires` list. They name a capability the platform itself provides
        | (never a business module) and are validated against this list, so an
        | unknown platform capability fails closed.
        |
        */
        'capabilities' => [
            'platform.identity',
            'platform.tenancy',
            'platform.authorization',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Modules path
    |--------------------------------------------------------------------------
    |
    | Where module packages live. Kept here so the registry never has to reach
    | into the third-party modules package for its own path resolution.
    |
    */
    'modules_path' => base_path('Modules'),

    /*
    |--------------------------------------------------------------------------
    | Trusted module package distribution (Phase 14)
    |--------------------------------------------------------------------------
    |
    | The Principal Module Manager may accept an APPROVED, signed module package
    | for review. Uploading NEVER executes package code and NEVER installs into
    | `Modules/`: the archive is written verbatim to a PRIVATE quarantine and
    | only its `module.json` manifest is read (as JSON) for review. A package is
    | trusted only when its detached signature verifies against one of the
    | approved publisher public keys below AND the manifest is a valid `lba`
    | declaration.
    |
    | No key is configured by default, which means NO package can ever be
    | verified — an unverifiable/unsigned upload fails closed.
    |
    */
    'module_packages' => [
        // Private quarantine directory (no public URL). Never inside `Modules/`.
        'quarantine_path' => env('LBA_MODULE_QUARANTINE_PATH', storage_path('app/module-quarantine')),

        // Maximum accepted package archive size, in kilobytes.
        'max_kilobytes' => (int) env('LBA_MODULE_MAX_KB', 51200),

        /*
        |----------------------------------------------------------------------
        | Approved publisher public keys
        |----------------------------------------------------------------------
        |
        | A map of publisher id => PEM public key. A package carries the id of
        | the publisher that signed it; the matching key verifies the detached
        | SHA-256 signature over the raw archive bytes. An empty map trusts no
        | publisher, so every upload is rejected.
        |
        */
        'publishers' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Private microscopy images
    |--------------------------------------------------------------------------
    |
    | Limits and the private disk used for LBA examination images. Every value
    | is configurable so an operator can tighten (never silently loosen) the
    | accepted upload surface. `max_pixels` guards against decompression bombs.
    |
    */
    'images' => [
        'disk' => env('LBA_IMAGE_DISK', 'lba'),
        'max_kilobytes' => (int) env('LBA_IMAGE_MAX_KB', 20480),
        'max_pixels' => (int) env('LBA_IMAGE_MAX_PIXELS', 40_000_000),
        'max_per_upload' => (int) env('LBA_IMAGE_MAX_PER_UPLOAD', 10),
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI vision gateway
    |--------------------------------------------------------------------------
    |
    | The Laravel side of the private AI vision gateway. Inference itself runs in
    | a SEPARATE Python/FastAPI service; it is never embedded in Laravel and has
    | nothing to do with the SINA text chat / Ollama surface.
    |
    | Production AI processing is DISABLED by default: `enabled` must stay false
    | until a real image-capable model, a validated adapter and an approved
    | billing/authorization policy exist (Phase 6). Sandbox (`local`/`testing`)
    | may run the clearly-marked SYNTHETIC mock adapter non-billably.
    |
    | No secret is ever hard-coded: the gateway shared secret and key id come
    | from the environment, and the gateway host must be explicitly allowlisted.
    |
    */
    'ai' => [
        'enabled' => env('LBA_AI_ENABLED', false),

        // Non-billable sandbox inference, only outside production.
        'sandbox' => env('LBA_AI_SANDBOX', env('APP_ENV', 'production') !== 'production'),

        // `mock` (synthetic) or `fastapi` (the reference gateway).
        'provider' => env('LBA_AI_PROVIDER', 'mock'),

        'protocol_version' => env('LBA_AI_PROTOCOL_VERSION', '1'),

        'max_attempts' => (int) env('LBA_AI_MAX_ATTEMPTS', 3),

        'timeout' => (int) env('LBA_AI_TIMEOUT', 30),

        'model' => [
            'name' => env('LBA_AI_MODEL_NAME', 'lba-vision-synthetic'),
            'version' => env('LBA_AI_MODEL_VERSION', '0.0.0-synthetic'),
        ],

        'gateway' => [
            'url' => env('LBA_AI_GATEWAY_URL'),
            'key_id' => env('LBA_AI_GATEWAY_KEY_ID'),
            'secret' => env('LBA_AI_GATEWAY_SECRET'),
            'allowed_hosts' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('LBA_AI_GATEWAY_ALLOWED_HOSTS', '')),
            ))),
            'signature_ttl' => (int) env('LBA_AI_SIGNATURE_TTL', 300),
        ],

        'billing' => [
            // Phase 6 owns the tenant AI credit wallets. Billing stays OFF by
            // default: production inference (and therefore any real charge) is
            // disabled until the Product Owner approves a real model, an
            // explicit pricing policy and a funded wallet. While this is false
            // NO credit is ever reserved, captured or deducted — sandbox,
            // manual and synthetic inference are always non-billable.
            'enabled' => env('LBA_AI_BILLING_ENABLED', false),

            // The integer credit currency label; wallet and ledger are integer
            // units only and never floating-point token counts.
            'currency' => env('LBA_AI_BILLING_CURRENCY', 'ai_credit'),

            // A reservation/complete older than this many minutes is treated as
            // stranded by the reconciliation command.
            'reconcile_after_minutes' => (int) env('LBA_AI_RECONCILE_AFTER_MINUTES', 15),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SINA AI consultation chat (Workstream 2)
    |--------------------------------------------------------------------------
    |
    | The patient-facing SINA chat: a plain TEXT chat/completion surface built on
    | Ollama or any OpenAI-compatible endpoint. It is deliberately SEPARATE from
    | the LBA vision gateway above — text chat never touches the FastAPI vision
    | service. Each tenant may override the endpoint through a central
    | `ai_provider_configs` row (see the Principal AI-provider settings); the
    | values below are the platform fallback.
    |
    | No secret is ever hard-coded: the platform fallback carries no credential,
    | and a tenant credential is stored encrypted on its config row.
    |
    */
    'sina' => [
        // Disabled by default in production until an approved endpoint exists;
        // sandbox/local (and the test suite) may exercise it.
        'enabled' => env('LBA_SINA_ENABLED', env('APP_ENV', 'production') !== 'production'),

        // ollama | openai_compatible — used when a tenant has no config row.
        'default_provider' => env('LBA_SINA_PROVIDER', 'openai_compatible'),

        'timeout' => (int) env('LBA_SINA_TIMEOUT', 30),

        'max_tokens' => (int) env('LBA_SINA_MAX_TOKENS', 512),

        'temperature' => (float) env('LBA_SINA_TEMPERATURE', 0.3),

        // The platform fallback endpoint per provider (no credential).
        'defaults' => [
            'ollama' => [
                'base_url' => env('LBA_SINA_OLLAMA_URL', 'http://127.0.0.1:11434'),
                'model' => env('LBA_SINA_OLLAMA_MODEL', 'llama3.1'),
            ],
            'openai_compatible' => [
                'base_url' => env('LBA_SINA_OPENAI_URL', 'https://api.openai.com/v1'),
                'model' => env('LBA_SINA_OPENAI_MODEL', 'gpt-4o-mini'),
            ],
        ],

        // Billing: when false NO credit is reserved or captured — the chat still
        // runs, it is simply not metered.
        'billing' => [
            'enabled' => env('LBA_SINA_BILLING_ENABLED', true),
            'currency' => env('LBA_SINA_BILLING_CURRENCY', 'sina_credit'),
            // Minimum credits reserved before a turn, released/captured after.
            'reserve_credits' => (int) env('LBA_SINA_RESERVE_CREDITS', 1),
            // Integer credits charged per 1,000 tokens of a captured turn.
            'credits_per_1k_tokens' => (int) env('LBA_SINA_CREDITS_PER_1K_TOKENS', 1),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Consultation tariff and cancellation/refund disclosure (Workstream 2)
    |--------------------------------------------------------------------------
    |
    | The patient must be able to "see the tariff before starting" and receive
    | cancellation/refund details (Phase 13). The CONCRETE fee is a business
    | decision — DECISIONS.md requires it to be configured, never guessed — so
    | the amounts below are empty by default and NOTHING is disclosed until the
    | owner sets them. A channel with no configured tariff simply shows no
    | price (never a fabricated one).
    |
    | SINA chat is metered per turn in the patient's own credit wallet: its
    | configured fee unit is disclosed from the `lba.sina.billing` block above,
    | not from here.
    |
    */
    'consultations' => [
        // IDR | MYR — the currency a human-consultation tariff is quoted in.
        'currency' => env('LBA_CONSULTATION_CURRENCY', 'IDR'),

        // Business-owner set tariff (major units) per channel. Empty ⇒ hidden.
        'tariff' => [
            'in_person' => env('LBA_CONSULTATION_TARIFF_IN_PERSON'),
            'teleconsult' => env('LBA_CONSULTATION_TARIFF_TELECONSULT'),
            'sina_chat' => env('LBA_CONSULTATION_TARIFF_SINA_CHAT'),
        ],

        // Cancellation / refund terms disclosed to the patient.
        'cancellable_before_start' => env('LBA_CONSULTATION_CANCELLABLE_BEFORE_START', true),
        'refundable' => env('LBA_CONSULTATION_REFUNDABLE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Digital LBA observation assessment
    |--------------------------------------------------------------------------
    |
    | Phase 7A. The indicator catalog is CENTRAL, non-patient reference metadata
    | and the digital observation form is built against one catalog VERSION. The
    | four categories are internal observation/review priorities — never
    | validated disease-risk probabilities or diagnostic classifications — so
    | they live in the localization layer, not in code constants exposed to
    | patients. The legacy reference catalog is provisioned by
    | `lba:assessment:import-catalog`.
    |
    */
    'assessment' => [
        // The stable key of the legacy reference catalog version.
        'catalog_key' => 'lba-legacy-form-v1',

        // The four assessment options. `unassessed` is the default and an
        // unassessed item is NEVER assumed green.
        'categories' => ['green', 'yellow', 'red', 'unassessed'],

        // The default program phase (a program descriptor, not a medical one).
        'default_program_phase' => 'not_specified',
    ],

    /*
    |--------------------------------------------------------------------------
    | Account profile photo
    |--------------------------------------------------------------------------
    |
    | A self-service account photo is uploaded from the "Profil Akun" page. It is
    | validated server-side (image type + size) and written to a PRIVATE disk
    | with no public URL; it is only read back through the authenticated
    | account-photo streaming endpoint.
    |
    */
    'accounts' => [
        // The private disk holding account profile photos.
        'photo_disk' => env('LBA_ACCOUNT_PHOTO_DISK', 'account-photos'),

        // Photos live under this prefix on the private disk.
        'photo_prefix' => 'photos',

        // Maximum accepted upload size, in kilobytes.
        'photo_max_kilobytes' => (int) env('LBA_ACCOUNT_PHOTO_MAX_KB', 2048),
    ],

    /*
    |--------------------------------------------------------------------------
    | Human review + versioned PDF reports
    |--------------------------------------------------------------------------
    |
    | Phase 7B. A report is a patient-facing clinical document that may only be
    | issued after an EXPLICIT human approval — AI never approves a report and a
    | submitted assessment is never auto-published. Reports are written to a
    | PRIVATE disk with no public URL; a revision creates a NEW immutable version.
    |
    */
    'reports' => [
        // The private disk holding generated PDFs and private brand logos.
        'disk' => env('LBA_REPORT_DISK', 'lba-reports'),

        // Logos live under this prefix on the same private disk.
        'logo_prefix' => 'brand-logos',

        // Reports live under this prefix.
        'prefix' => 'reports',

        // Separation of duties (DECISIONS.md LBA-02). When false (the default)
        // an operator may NEVER approve an examination they recorded or that
        // they operate — a distinct human reviewer must sign off.
        'allow_self_approval' => env('LBA_REPORT_ALLOW_SELF_APPROVAL', false),

        // Locales a report may be rendered in.
        'locales' => ['id', 'ms-MY', 'en'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Patient REST API (Phase 8B)
    |--------------------------------------------------------------------------
    |
    | The mobile/consumer API surface. Access tokens are stateless bearer
    | secrets; only their hash is stored. TTL bounds how long a token stays
    | usable before the client must re-authenticate.
    |
    */
    'patient_api' => [
        'token_ttl_days' => (int) env('LBA_PATIENT_API_TOKEN_TTL_DAYS', 30),
        'per_page_default' => (int) env('LBA_PATIENT_API_PER_PAGE', 15),
        'per_page_max' => (int) env('LBA_PATIENT_API_PER_PAGE_MAX', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scoped agent ingestion (WS6.B)
    |--------------------------------------------------------------------------
    |
    | A manufacturer-software export / watched-folder agent imports still images
    | through an authenticated, scoped, revocable machine credential. The
    | credential is bound to ONE tenant, branch, operator and a single selected
    | editable examination; only its SHA-256 hash is ever stored and it is
    | SHORT LIVED. There is NO public ingest endpoint: every request must present
    | a currently-usable credential or it fails closed. The credential can never
    | choose a tenant, patient or examination from untrusted input.
    |
    */
    'ingest' => [
        // How long an issued agent credential stays usable before the operator
        // must issue a new one (short-lived by design).
        'credential_ttl_minutes' => (int) env('LBA_INGEST_CREDENTIAL_TTL_MINUTES', 60),

        // Maximum accepted ingest requests per minute per credential (per IP
        // once no credential resolves). A second line of defence on top of the
        // per-file size and MIME limits enforced by the image store.
        'rate_per_minute' => (int) env('LBA_INGEST_RATE_PER_MINUTE', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | SINA MEDCloud knowledge base (WS9)
    |--------------------------------------------------------------------------
    |
    | The retrieval-augmented knowledge base behind SINA. Approved PDF e-books,
    | journal articles and clinical educational references are ingested as
    | PRIVATE, page-aware chunks and used as RETRIEVAL context — they never
    | retrain the model weights and never override the system safety prompt.
    |
    | Raw PDFs are written to a PRIVATE disk (`serve` is off, no public URL) and
    | are only ever read back through an authenticated, tenant-scoped stream.
    | Ingestion is bounded (size, pages) and de-duplicated by SHA-256 within a
    | scope. Retrieval defaults to a deterministic LEXICAL index so SINA stays
    | fully functional with NO embedding service configured; an embedding
    | provider may be enabled per deployment only after its availability and
    | licence are verified.
    |
    | `enabled` is true wherever `lba.sina` is enabled; retrieval for a request
    | additionally requires the tenant to have the knowledge_base module active.
    |
    */
    'knowledge' => [
        'enabled' => env('LBA_KNOWLEDGE_ENABLED', true),

        // Private disk holding the immutable source PDFs (no public URL).
        'disk' => env('LBA_KNOWLEDGE_DISK', 'lba-knowledge'),

        'prefix' => 'knowledge',

        // Bounded intake: a single upload may not exceed this size / page count.
        'max_kilobytes' => (int) env('LBA_KNOWLEDGE_MAX_KB', 51200),
        'max_pages' => (int) env('LBA_KNOWLEDGE_MAX_PAGES', 1000),

        // Locales a knowledge document may declare.
        'locales' => ['id', 'ms-MY', 'en'],

        /*
        |----------------------------------------------------------------------
        | Page-aware chunking (WS9.B)
        |----------------------------------------------------------------------
        |
        | Extracted page text is split into chunks that NEVER cross a page
        | boundary, so every chunk (and every downstream citation) carries the
        | REAL source page. Sizes are characters (approximate tokens ×4) and the
        | overlap is bounded so a very small `target` can never loop forever.
        |
        */
        'chunking' => [
            'target_chars' => (int) env('LBA_KNOWLEDGE_CHUNK_CHARS', 1200),
            'overlap_chars' => (int) env('LBA_KNOWLEDGE_CHUNK_OVERLAP', 200),
            'min_chars' => (int) env('LBA_KNOWLEDGE_CHUNK_MIN_CHARS', 40),
        ],

        /*
        |----------------------------------------------------------------------
        | Retrieval (WS9.B)
        |----------------------------------------------------------------------
        |
        | Retrieval is always bound to the trusted server-side tenant context —
        | never to client input. `top_k`, `min_relevance` (0..1) and
        | `max_prompt_chars` bound the answer surface. Platform-scope sources are
        | included ONLY when `include_platform` is on and they are published.
        |
        */
        'retrieval' => [
            'top_k' => (int) env('LBA_KNOWLEDGE_TOP_K', 6),
            'min_relevance' => (float) env('LBA_KNOWLEDGE_MIN_RELEVANCE', 0.12),
            'max_prompt_chars' => (int) env('LBA_KNOWLEDGE_MAX_PROMPT_CHARS', 6000),
            'include_platform' => env('LBA_KNOWLEDGE_INCLUDE_PLATFORM', true),
        ],

        /*
        |----------------------------------------------------------------------
        | Embeddings (WS9.B)
        |----------------------------------------------------------------------
        |
        | DISABLED by default (`provider` = none): SINA then uses the
        | DETERMINISTIC lexical index below, so retrieval works with NO external
        | embedding service. An operator may enable an embedding provider
        | (`ollama` | `openai_compatible`) only after its availability, license
        | and dimension are verified; the model id, version and dimensions are
        | PINNED so a reindex is exact. An embedding provider is NEVER required
        | for an answer: if it fails, retrieval falls back to lexical search.
        |
        */
        'embedding' => [
            'provider' => env('LBA_KNOWLEDGE_EMBEDDING_PROVIDER', 'none'),
            'timeout' => (int) env('LBA_KNOWLEDGE_EMBEDDING_TIMEOUT', 30),

            'defaults' => [
                'ollama' => [
                    'base_url' => env('LBA_KNOWLEDGE_EMBEDDING_OLLAMA_URL', env('LBA_SINA_OLLAMA_URL', 'http://127.0.0.1:11434')),
                    'model' => env('LBA_KNOWLEDGE_EMBEDDING_OLLAMA_MODEL', 'bge-m3'),
                    'dimensions' => (int) env('LBA_KNOWLEDGE_EMBEDDING_OLLAMA_DIMS', 1024),
                ],
                'openai_compatible' => [
                    'base_url' => env('LBA_KNOWLEDGE_EMBEDDING_OPENAI_URL', env('LBA_SINA_OPENAI_URL', 'https://api.openai.com/v1')),
                    'model' => env('LBA_KNOWLEDGE_EMBEDDING_OPENAI_MODEL', 'text-embedding-3-small'),
                    'dimensions' => (int) env('LBA_KNOWLEDGE_EMBEDDING_OPENAI_DIMS', 1536),
                    'api_key' => env('LBA_KNOWLEDGE_EMBEDDING_OPENAI_KEY'),
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Staging deployment (WS6.F)
    |--------------------------------------------------------------------------
    |
    | Read-only pre-deploy backup settings. The private media directory holds
    | user-uploaded clinical artefacts (microscopy originals, reports); it is
    | archived by `lba:staging:backup` before any migration runs. Overridable so
    | the behaviour is testable and so an operator can point the archive at an
    | alternate media root without editing source.
    |
    */
    'deploy' => [
        'backup_media_path' => env('LBA_DEPLOY_BACKUP_MEDIA_PATH', storage_path('app/private')),

        // MySQL logical-dump client used by `lba:staging:backup`. Overridable so
        // a host with a versioned binary (e.g. `mysqldump8`) can be supported
        // and so the streaming behaviour is testable with a controlled binary.
        'mysqldump_binary' => env('LBA_DEPLOY_MYSQLDUMP_BINARY', 'mysqldump'),
    ],

];
