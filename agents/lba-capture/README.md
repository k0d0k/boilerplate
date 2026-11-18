# LBA Capture Agent

A small, portable companion application that watches a microscope / manufacturer
software **export folder** and uploads completed still images (JPEG / PNG / WebP)
to LBA Cloud for a **single, explicitly selected examination**.

It is the real watched-folder client for the `agents/lba-capture/` deliverable of
WS6.1 (P1). It reuses the **existing** server ingestion endpoint — there is no
agent-specific API:

```
POST https://<your-lba-host>/api/lba/ingest
Authorization: Bearer <short-lived credential>
Content-Type: multipart/form-data
  image=<file>
```

Everything about *which* tenant, branch, operator, patient and examination the
image belongs to is decided by the server-issued credential. The agent never
sends a tenant id, patient id or examination id, and never derives any of them
from a filename.

## What it does and does not do

| Does | Does not |
| --- | --- |
| Watch one configured export folder | Pick a tenant / patient / examination |
| Wait until a file is fully written (stabilisation) | Parse identifiers out of filenames |
| Keep a bounded local spool and drain it offline | Stream video |
| Retry transient failures with exponential backoff | Sync images to Google Drive / any third-party drive |
| Quarantine server-rejected files (never retried) | Write into a web-served public directory |
| Never log, print or commit the bearer secret | Hard-code tokens or credentials |
| Report local, secret-free status | Assume every OptoEdu model is UVC |

The browser / manual import path in LBA Cloud is completely independent and keeps
working whether or not this agent runs.

## Requirements

- **Python 3.11 or newer** (stdlib only). `keyring` is an *optional* enhancement
  that stores the credential in the OS secure store (macOS Keychain / Windows
  Credential Manager / Secret Service). Without it the agent uses a
  permission-restricted (`0600`) file in the user's config directory.
- Network access from the agent machine to the LBA Cloud base URL over HTTPS.
- An **ingestion credential** issued for the target examination (see *Setup*).

No administrator rights are required for a per-user install. A machine-wide
install is optional and described below.

## Setup

### 1. Issue a credential in LBA Cloud

1. Sign in to LBA Cloud as a **Clinic Admin / owner** with `exam.update` for the
   relevant branch.
2. Open the **examination** the microscope will import into.
3. Use **“Issue ingestion credential”**.
4. Copy the secret shown — **it is displayed exactly once**. Only its hash is
   stored server-side.

Credentials are **short lived** (default 60 minutes, configurable by the server)
and bound to that one examination, branch and operator. Issue a new one whenever
you start a new capture session.

### 2. Install the agent

#### macOS

```sh
cd agents/lba-capture
./scripts/install-macos.command          # or: bash scripts/install-macos.command
```

This creates a user-local virtual environment under
`~/Library/Application Support/LBACapture/venv`, installs the agent into it and,
if available, installs `keyring` so the credential goes to the Keychain. It does
**not** require `sudo` and writes nothing outside your home directory.

#### Windows (PowerShell)

```powershell
cd agents\lba-capture
powershell -ExecutionPolicy Bypass -File scripts\install-windows.ps1
```

This creates a per-user virtual environment under
`%LOCALAPPDATA%\LBACapture\venv`, installs the agent and (if available) `keyring`
for the Windows Credential Manager. It runs entirely in the current user's
profile.

#### Any platform, from source

```sh
cd agents/lba-capture
python3 -m venv .venv
. .venv/bin/activate            # Windows: .venv\Scripts\Activate.ps1
pip install -e ".[secure]"      # or ".[secure,dev]" for the tests
```

### 3. Configure and provision the credential

Run `setup`. The bearer secret is read with a hidden prompt and is **never**
echoed and never written to the config file:

```sh
lba-capture setup \
  --base-url https://dev.lbacloud.com \
  --watch-dir "/Users/me/OptoEdu/Exports" \
  --instrument-label "OptoEdu bench 1"
```

If you must feed the secret non-interactively (e.g. from a provisioning tool),
pipe it in — it is still never logged:

```sh
printf '%s' "$SECRET" | lba-capture setup --token-stdin \
  --base-url https://dev.lbacloud.com --watch-dir "/path/to/exports"
```

The config file contains **only non-secret** settings (base URL, watch dir,
spool/state/log dirs, tuning). The credential lives in the OS secure store, or,
if no keyring backend is available, in `credential.json` with `0600` permissions
inside the config directory.

## Day-to-day use

| Command | Purpose |
| --- | --- |
| `lba-capture status` | Config, credential presence, running state, local backlog |
| `lba-capture start --detach` | Run in the background (writes a pid file) |
| `lba-capture stop` | Stop a detached agent |
| `lba-capture restart` | Stop, then start again |
| `lba-capture run-once` | Scan + upload once and exit (for cron / Task Scheduler) |
| `lba-capture revoke` | Remove the local credential and print retirement guidance |
| `lba-capture version` | Print the agent version |

`status` output example:

```
lba-capture 1.0.0
Base URL         : https://dev.lbacloud.com
Watch directory  : /Users/me/OptoEdu/Exports
Spool directory  : /Users/me/Library/Application Support/LBACapture/spool
Credential       : present (store: os-keyring)
Agent            : running (pid 51234)
State            : running
Spool pending    : 0
Spool failed     : 1
Uploaded         : 42
Duplicates       : 3
Rejected         : 1
Last error       : IMG_0043.png: rejected (422)
```

### Run automatically at login

- **macOS:** the installer can register a launchd agent
  (`scripts/com.lbacloud.capture.plist`). Load it with
  `launchctl load -w ~/Library/LaunchAgents/com.lbacloud.capture.plist`.
- **Windows:** register a Task Scheduler task that runs `lba-capture run-once`
  every few minutes (see `scripts/install-windows.ps1 -RegisterScheduledTask`).

Both simply invoke the same CLI; behaviour is identical.

## How it behaves under adverse conditions

- **Half-written files:** a candidate is only picked up once its size is
  unchanged across several consecutive polls *and* it has not been modified for
  the stabilisation window (`stabilize_seconds`, default 5s). A file still being
  written is never read.
- **Offline clinics:** uploads that fail with a network error, `429` or `5xx`
  are left in the spool and retried with exponential backoff (capped at
  `backoff_max_seconds`). The spool survives restarts, so a backlog drains
  automatically once connectivity returns.
- **Bounded disk use:** the spool stops ingesting once `max_spool_items`
  (default 1000) or `max_spool_bytes` (default 5 GiB) is reached. The agent never
  fills the disk.
- **Duplicates:** identical content is de-duplicated locally by SHA-256 and,
  server-side, by the `(examination_id, checksum)` unique index. A repeat upload
  returns `200` with `duplicate: true` and creates nothing new; an existing
  original is never overwritten.
- **Rejected files:** a `422` / `404` moves the file to `<spool>/failed/` with a
  `.reason` sidecar. It is never retried.
- **Revoked / expired credential:** a `401` marks the agent
  `needs_provisioning` and stops the drain until a fresh credential is set up.
  The backlog is preserved.

## Tuning

Edit the non-secret config file (default location shown by `lba-capture status`,
or `lba-capture --config <path> status`):

| Key | Default | Meaning |
| --- | --- | --- |
| `stabilize_seconds` | `5.0` | Quiet period before a file is considered complete |
| `stabilize_checks` | `2` | Consecutive stable-size polls required |
| `poll_interval_seconds` | `5.0` | Scan interval |
| `max_spool_items` | `1000` | Max queued uploads |
| `max_spool_bytes` | `5368709120` | Max queued bytes (5 GiB) |
| `max_retries` | `5` | (informational; backoff is time-based) |
| `backoff_base_seconds` / `backoff_max_seconds` | `2.0` / `300.0` | Backoff bounds |
| `http_timeout_seconds` | `60.0` | Per-request timeout |
| `verify_tls` | `true` | Verify the server certificate — leave `true` |
| `extensions` | `jpg, jpeg, png, webp` | Accepted export extensions |

The agent refuses to start if `base_url` is not `https://` (only
`http://localhost` is allowed for local testing) or if a writable directory
resolves inside a web-served path (`public`, `public_html`, `www`, `wwwroot`,
`htdocs`, `webroot`).

## Retrieving a file the server rejected

Rejected files are kept under `<spool>/failed/` together with a `.reason` file.
Leave them for a support investigation or delete them; the agent never uploads
them again.

## Security notes

- The bearer secret is stored with `keyring` when available; otherwise in a
  `0600` file. It is redacted from all log output as defence in depth and is
  never written to the config file or Git.
- The agent binds to one examination via the server-issued credential; it cannot
  address another examination, branch, tenant or patient.
- The agent writes only inside its own config / spool / state / log directories.

## Tests

```sh
cd agents/lba-capture
pip install -e ".[dev]"
pytest
```

The suite includes a fake HTTP server and synthetic **NON-PATIENT** images
(a generated 1×1 PNG / JPEG) so no clinical data is required. It covers spool
idempotency and bounds, file stabilisation, upload outcome mapping, credential
stores, and the offline / backoff / duplicate / rejected agent flows.

## Troubleshooting

| Symptom | Likely cause / fix |
| --- | --- |
| `state: needs_provisioning` | No credential, or a `401`. Re-run `lba-capture setup` with a fresh secret. |
| `rejected (422)` in status | The examination is no longer editable, its consent was withdrawn, the file failed validation, or the module is not entitled. Check the examination in LBA Cloud. |
| Nothing is uploaded | Confirm the manufacturer software writes into the configured `watch_dir` and uses an accepted extension; check `lba-capture status`. |
| `spool full` note | The backlog exceeded a bound. Restore connectivity so it drains, or raise `max_spool_items` / `max_spool_bytes`. |
| Agent not running | `lba-capture status`, then `lba-capture start --detach`; check logs in the log directory. |

## Compatibility

This agent watches a directory that **any** manufacturer software writes
exports to (JPEG / PNG / WebP). It makes **no** claim about specific microscope
models or UVC support — no specific device has been certified with it yet. As
long as the instrument software can export still images to a folder, the agent
can ingest them. The manual import fallback in LBA Cloud always works.
