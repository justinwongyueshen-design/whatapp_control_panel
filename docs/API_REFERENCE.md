# REST API Reference — WhatsApp Bot Control Panel

All API endpoints return JSON formatted as:

```json
{
  "success": true,
  "message": "Operation description",
  "data": {}
}
```

---

## 1. Web Management APIs (`web/api/`)

### 1.1 `GET /api/csrf.php`
- **Auth**: None
- **Returns**: Active session CSRF token.

### 1.2 `GET|POST /api/contacts.php`
- **Auth**: Session Cookie
- **Actions**:
  - `?action=list`: Query contacts with optional `search`, `group_id`, `status`.
  - `?action=save` (POST): Create or update contact (`name`, `phone`, `company`, `status`, `group_ids`).
  - `?action=delete` (POST): Delete contact by `id`.
  - `?action=import_csv` (POST): Upload CSV file, maps columns `name`, `phone`, `company`.
  - `?action=export_csv`: Downloads sanitized CSV export.

### 1.3 `GET|POST /api/contact_groups.php`
- **Auth**: Session Cookie
- **Actions**:
  - `?action=list`: Returns all contact groups with member counts.
  - `?action=save` (POST): Create or edit contact group (`name`, `description`).
  - `?action=delete` (POST): Remove contact group.

### 1.4 `GET|POST /api/templates.php`
- **Auth**: Session Cookie
- **Actions**:
  - `?action=list`: Returns list of templates.
  - `?action=save` (POST): Create or update template (`title`, `content`). Validates `{{name}}`, `{{phone}}`, `{{company}}`.
  - `?action=preview` (POST): Renders preview with sample data.
  - `?action=delete` (POST): Deletes template by `id`.

### 1.5 `GET|POST /api/campaigns.php`
- **Auth**: Session Cookie
- **Actions**:
  - `?action=list`: Returns campaigns with statistics and progress.
  - `?action=create` (POST): Creates campaign and atomically inserts queued jobs.
  - `?action=pause` (POST): Pauses running/queued campaign.
  - `?action=resume` (POST): Resumes paused campaign.
  - `?action=cancel` (POST): Cancels campaign and remaining pending jobs.
  - `?action=quick_send` (POST): Immediately queues a direct single message.

### 1.6 `POST|GET /api/media.php`
- **Auth**: Session Cookie
- **Actions**:
  - `?action=upload` (POST): Upload media attachment. Validates MIME type and generates randomized filename.
  - `?action=download&id=X`: Authenticated download stream for browser users.

### 1.7 `GET|POST /api/settings.php`
- **Auth**: Admin Session
- **Actions**:
  - `?action=get`: Retrieve operational settings.
  - `?action=save` (POST): Save updated rate limits and delays.

### 1.8 `GET|POST /api/users.php`
- **Auth**: Admin Session
- **Actions**:
  - `?action=list`: List system users and roles.
  - `?action=save` (POST): Create or edit user.
  - `?action=delete` (POST): Delete user account.

---

## 2. Worker Automation APIs (`web/api/worker/`)

All worker endpoints require HTTP header:
```http
Authorization: Bearer <worker-token>
```

### 2.1 `POST /api/worker/heartbeat.php`
- **Payload**:
  ```json
  {
    "whatsapp_status": "connected",
    "os_info": "Windows 11",
    "browser_info": "Playwright (chrome)",
    "python_version": "3.12.0",
    "worker_version": "1.0.0"
  }
  ```
- **Response** (HTTP 200):
  ```json
  {
    "success": true,
    "message": "Heartbeat acknowledged",
    "worker_id": 1,
    "server_utc": "2026-09-17 08:00:00",
    "settings": {
      "min_delay_seconds": 5,
      "max_delay_seconds": 15,
      "max_messages_per_batch": 20,
      "pause_between_batches_seconds": 60
    }
  }
  ```

### 2.2 `POST /api/worker/claim_job.php`
- **Payload**: Empty
- **Response** (HTTP 200 with job):
  ```json
  {
    "success": true,
    "message": "Job claimed successfully",
    "job": {
      "id": 42,
      "campaign_id": 3,
      "phone": "60123456789",
      "rendered_message": "Hello John, your appointment is confirmed.",
      "media": null,
      "attempt": 1,
      "max_attempts": 3
    }
  }
  ```
- **Response** (HTTP 200 with no claimable jobs):
  ```json
  {
    "success": true,
    "message": "No jobs currently claimable",
    "job": null
  }
  ```

### 2.3 `POST /api/worker/report_job.php`
- **Payload** (Success):
  ```json
  {
    "job_id": 42,
    "result": "sent"
  }
  ```
- **Payload** (Failure):
  ```json
  {
    "job_id": 42,
    "result": "failed",
    "error_message": "Phone number is not registered on WhatsApp"
  }
  ```
- **HTTP Status Codes**:
  - `200`: Report registered successfully.
  - `403`: Forbidden (job not claimed by your worker ID).
  - `404`: Job not found.
  - `422`: Invalid input parameters.

### 2.4 `GET /api/worker/download_media.php?id=X`
- **Response**: Binary stream of media file with appropriate `Content-Type` header.
