# WhatsApp Bot Control Panel — Architecture Documentation

## 1. Executive Summary

The WhatsApp Bot Control Panel is an internal, consent-based messaging management platform. It combines a PHP 8.2+ / MySQL control panel with a persistent Windows Python Playwright worker to automate authorized WhatsApp messaging.

```text
+------------------------------------------------------------------------+
| PHP Web Control Panel (web/admin/)                                     |
| Dashboard | Contacts | Groups | Templates | Campaigns | Workers | Logs |
+-----------------------------------+------------------------------------+
                                    |
                                    v
+------------------------------------------------------------------------+
| PHP REST API (web/api/)                                                |
| Session / CSRF (Admin)            | Bearer Token Auth (Workers)        |
| - Contacts, Campaigns, Templates  | - Worker Registration & Heartbeat  |
| - Media Upload / Auth Download    | - Atomic Queue Claiming            |
| - Rate Limiter & Normalization    | - Idempotent Job Result Reporting  |
+-----------------------------------+------------------------------------+
                                    |
                                    v
+------------------------------------------------------------------------+
| MySQL Database (`whatsapp_control_panel`)                              |
| users, workers, contacts, contact_groups, contact_group_members,       |
| message_templates, media_files, campaigns, message_jobs, message_logs, |
| audit_logs, system_settings                                            |
+-----------------------------------+------------------------------------+
                                    | Bearer Token (HTTP REST)
                                    v
+------------------------------------------------------------------------+
| Windows Python Worker (worker/worker.py)                               |
| - Bearer Auth & Polling Loop                                           |
| - Persistent Heartbeat & State Synchronization                         |
| - Secure Media Download & Temp Cleanup                                 |
+-----------------------------------+------------------------------------+
                                    |
                                    v
+------------------------------------------------------------------------+
| WhatsApp Playwright Adapter (worker/whatsapp_adapter.py)               |
| - Persistent Browser Context (Chrome / Chromium profile)               |
| - QR Code & Login Detection (`detect_login`, `detect_qr`)              |
| - Connection & Chat Composer Detection                                 |
| - Safe UI Navigation & Invalid Number Detection                        |
| - Error Diagnostics & Screenshots                                      |
+-----------------------------------+------------------------------------+
                                    |
                                    v
+------------------------------------------------------------------------+
| WhatsApp Web -> Recipient (Consent-Based Messaging)                   |
+------------------------------------------------------------------------+
```

---

## 2. Core Architectural Principles

1. **Strict Separation of Concerns**: The web server never directly interacts with WhatsApp Web. The Windows worker owns the persistent browser session and communicates exclusively through the PHP REST API.
2. **Database Isolation**: The Python worker never connects directly to MySQL. All state updates are mediated through Bearer-authenticated REST endpoints.
3. **Queue Correctness & Concurrency**:
   - Queue claiming uses database transactions with row-level locking (`SELECT ... FOR UPDATE`).
   - Reporting enforces strict worker ownership: a worker can only report results for jobs currently claimed by its own `worker_id`.
   - Idempotent reporting prevents counter corruption from duplicate network transmissions.
4. **Delivery Semantics (Honest Architecture)**:
   - The messaging pipeline operates under **at-least-once job execution with external WhatsApp delivery uncertainty**.
   - Because browser crashes or network timeouts can occur in the window between WhatsApp Web accepting a message and the worker reporting back to the API, exactly-once delivery cannot be guaranteed in unofficial browser automation. The system explicitly accounts for and logs this state.
5. **Responsible, Consent-Based Design**:
   - The system is built for legitimate notifications, appointments, and reminders.
   - Anti-ban evasion, mass spamming, and detection bypass are not implemented.

---

## 3. Queue State Machine

```text
                 [Create Campaign / Quick Send]
                               |
                               v
                         +-----------+
                         |  pending  | <---------------+
                         +-----------+                 |
                               |                       |
                  (Worker Claims via API)       (Retry if attempts < max)
                               |                       |
                               v                       |
                        +------------+                 |
                        | processing | ----------------+
                        +------------+
                          |        |
        (Report: sent) ---+        +--- (Report: failed & attempts >= max)
              |                                        |
              v                                        v
          +------+                                 +--------+
          | sent |                                 | failed |
          +------+                                 +--------+
```

### Stale Job Recovery Mechanism
If a worker crashes or loses power while a job is in the `processing` state:
- Every claim operation executes `QueueManager::recoverStaleJobs()`.
- Jobs with `status = 'processing'` where `claimed_at < NOW() - stale_job_timeout_seconds` AND the claiming worker's heartbeat is older than `worker_heartbeat_timeout_seconds` (or worker is offline) are safely recovered:
  - If `attempts < max_attempts`: returned to `pending` with `worker_id = NULL`.
  - If `attempts >= max_attempts`: marked as `failed` with diagnostic error message.

---

## 4. Timezone Architecture

- **Database Layer**: All timestamps (`created_at`, `scheduled_at`, `sent_at`, `last_heartbeat_at`) are strictly stored in **UTC** (`UTC_TIMESTAMP()`).
- **Application Layer**: User interface displays and inputs use the configured timezone **`Asia/Kuala_Lumpur`** (UTC+8).
- **Worker & API**: All JSON payloads exchange UTC timestamps in ISO 8601 / SQL standard format (`Y-m-d H:i:s`).

---

## 5. Security Controls

- **Web Sessions**: `HttpOnly=true`, `SameSite=Lax`, strict mode enabled, session regenerated on login (`session_regenerate_id(true)`).
- **CSRF Defense**: Cryptographic random token generated per session and enforced on every state-changing POST request.
- **Worker Authentication**: Cryptographically random 32-byte Bearer token (`random_bytes(32)`) stored as a bcrypt hash. Displayed to the administrator once upon generation.
- **Media Upload Defense**:
  - File extension and server-side MIME type verified via `finfo_file` (never client header).
  - Stored under randomized filenames (`bin2hex(random_bytes(16)) . '.' . $ext`).
  - Direct web access to `web/uploads/` denied via `.htaccess` (`Require all denied`).
  - Worker downloads media via Bearer-authenticated streaming endpoint (`/api/worker/download_media.php`).
  - Worker deletes temporary media files immediately after sending.
- **CSV Formula Injection Prevention**:
  - Imported strings starting with `=`, `+`, `-`, `@`, `\t`, `\r` are sanitized by prefixing with a single quote `'` before database storage and export.
