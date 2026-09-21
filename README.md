# WhatsApp Bot Control Panel - Documentation

## Overview

The WhatsApp Bot Control Panel is a secure internal messaging management platform for sending WhatsApp messages via browser automation. It consists of:

1. **Web Control Panel** (PHP) - Admin interface and REST API
2. **Python Worker** - Playwright browser automation
3. **MySQL Database** - Message queue, jobs, logs, audit trail
4. **Playwright Browser** - Chrome/Edge automation

## Architecture

```
Web Admin Panel (PHP)
        ↓
REST API (PHP) ← Bearer Token Auth
        ↓
Python Worker (async)
        ↓
Playwright (Browser)
        ↓
WhatsApp Web
        ↓
Recipient
```

## Critical Safety Features

### 1. Message Queue Safety (At-Least-Once Delivery)

- **Atomic Job Claiming**: Only one worker can claim a job (SELECT FOR UPDATE)
- **Worker Verification**: Worker must own the job to report results
- **Idempotent Reporting**: Duplicate reports don't corrupt counters
- **Stale Job Recovery**: Jobs stuck in `processing` are recovered if worker crashes
- **Honest Semantics**: The system is at-least-once, not exactly-once
  - If worker crashes after WhatsApp accepts message but before reporting, that's unknown
  - Design logs and UX to reflect this limitation

### 2. Authentication & Authorization

- **Web Users**: Session-based with password hashing (bcrypt, cost 12)
- **Worker Tokens**: Bearer token (SHA256 hashed) stored in database
- **CSRF Protection**: All state-changing requests require CSRF token
- **Role-Based Access**: admin vs operator
- **Audit Logging**: Every important action logged

### 3. Input Validation

- All user inputs validated and sanitized
- Prepared statements for all queries (PDO)
- Phone numbers normalized consistently
- Template variables validated
- File uploads: MIME type, extension, size checked
- No file traversal, no executable uploads

### 4. Media Security

- Files stored outside web root
- Authenticated download required
- Randomized stored filenames
- MIME type validation post-upload
- Worker must authenticate to download media

### 5. Worker Reliability

- Heartbeat every 30 seconds
- Stale worker detection (60 second timeout)
- Browser crash recovery
- Persistent browser session (survives restart)
- Automatic reconnection on API errors
- Comprehensive error logging

## Database Schema

### Core Tables

- **users** - Admin/operator accounts
- **workers** - Registered Python workers
- **contacts** - Phone contacts
- **contact_groups** - Grouping for bulk messaging
- **message_templates** - Reusable templates with variables
- **campaigns** - Message campaigns
- **message_jobs** - Queue of individual messages (CRITICAL TABLE)
- **message_logs** - Audit trail of all messages
- **media_files** - Uploaded attachments
- **audit_logs** - All user actions
- **system_settings** - Configuration

### Critical Constraints

```sql
-- Prevent duplicate jobs per campaign/contact
UNIQUE KEY `uq_campaign_contact` (`campaign_id`, `contact_id`)

-- Prevent duplicate group memberships
UNIQUE KEY `uq_group_contact` (`group_id`, `contact_id`)

-- Enforce worker ownership on job results
CONSTRAINT `fk_job_worker` FOREIGN KEY (`worker_id`) 
    REFERENCES `workers` (`id`)

-- Foreign key cascades for cleanup
CONSTRAINT `fk_job_campaign` FOREIGN KEY (`campaign_id`) 
    REFERENCES `campaigns` (`id`) ON DELETE CASCADE
```

## API Endpoints (Worker)

All worker endpoints require `Authorization: Bearer <token>` header.

### `POST /api/v1/worker/heartbeat`
Send worker heartbeat with connection status.

```json
{
  "is_whatsapp_connected": true,
  "browser_type": "chromium",
  "python_version": "3.12.1",
  "worker_version": "1.0"
}
```

### `GET /api/v1/worker/status`
Get worker status.

Response:
```json
{
  "worker_id": 1,
  "name": "worker-1",
  "is_active": true,
  "is_whatsapp_connected": true,
  "current_job_id": null,
  "last_heartbeat": "2024-01-15T10:30:00Z"
}
```

### `POST /api/v1/jobs/claim`
Claim next job from queue (atomic operation).

Response:
```json
{
  "job_id": 123,
  "campaign_id": 5,
  "recipient_phone": "+60123456789",
  "recipient_name": "John Doe",
  "message_content": "Hello {{name}}, this is a test.",
  "media_file_id": null,
  "attempt": 1,
  "max_attempts": 3
}
```

### `POST /api/v1/jobs/report`
Report job result (CRITICAL - worker ownership verified).

```json
{
  "job_id": 123,
  "status": "sent",
  "error": ""
}
```

### `GET /api/v1/media/download?media_id=5`
Download media file (authenticated).

### `POST /api/v1/worker/diagnostics`
Send diagnostic information for debugging.

```json
{
  "current_url": "https://web.whatsapp.com",
  "page_title": "WhatsApp",
  "error_message": "Timeout waiting for selector",
  "browser_log": "..."
}
```

## Installation & Deployment

### Prerequisites

1. **Web Server**
   - Apache 2.4+ with mod_rewrite
   - PHP 8.3+
   - MySQL 8.0+
   - HTTPS (production)

2. **Python Worker**
   - Windows/Linux/macOS
   - Python 3.12+
   - Chrome/Microsoft Edge/Chromium
   - Playwright
   - requests library

### Step 1: Database Setup

```bash
# Login to MySQL
mysql -u root -p

# Create database and user
CREATE DATABASE whatsapp_bot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'whatsapp_bot'@'localhost' IDENTIFIED BY 'secure_password';
GRANT ALL PRIVILEGES ON whatsapp_bot.* TO 'whatsapp_bot'@'localhost';
FLUSH PRIVILEGES;

# Import schema
USE whatsapp_bot;
SOURCE /path/to/database/schema.sql;
```

### Step 2: Web Application Setup

```bash
# Extract to web root
cp -r web/* /var/www/html/whatsapp-bot/

# Create directories
mkdir -p /var/www/html/whatsapp-bot/uploads/temp
mkdir -p /var/www/html/whatsapp-bot/logs
mkdir -p /var/www/html/whatsapp-bot/temp

# Set permissions
chmod 755 /var/www/html/whatsapp-bot
chmod 755 /var/www/html/whatsapp-bot/{uploads,logs,temp}
chmod 644 /var/www/html/whatsapp-bot/.htaccess

# Configure
cp web/config.php web/config.php.example
# Edit config.php with database credentials and settings
```

### Step 3: Create Admin User

```php
<?php
require 'includes/bootstrap.php';

$username = 'admin';
$password = 'ChangeMe@123456';
$email = 'admin@example.com';
$full_name = 'Administrator';

$password_hash = Auth::hashPassword($password);

db()->execute(
    'INSERT INTO users (username, email, password_hash, full_name, role, is_active)
     VALUES (?, ?, ?, ?, ?, ?)',
    [$username, $email, $password_hash, $full_name, 'admin', true]
);

echo "User created: $username\n";
?>
```

### Step 4: Worker Registration

1. Login to web panel
2. Go to Workers section
3. Click "Register New Worker"
4. Copy the token (shown only once!)
5. Put token in `worker_config.json`

### Step 5: Python Worker Setup

```bash
# Install Python dependencies
pip install playwright requests

# Install browsers
playwright install

# Configure worker
cp worker/worker_config.json worker/worker_config.json.example
# Edit: set api_url and worker_token

# Test the worker
cd worker
python whatsapp_worker.py worker_config.json
```

On Windows, run: `1_START_WORKER.bat`

## Security Hardening (Production)

### 1. HTTPS/TLS
- Generate certificate: `certbot certonly --standalone -d yourdomain.com`
- Enable SSL in Apache and .htaccess
- Set `SESSION_COOKIE_SECURE = true`

### 2. PHP Configuration
```php
// config.php
define('APP_ENV', 'production');
define('APP_DEBUG', false);
```

### 3. Database Credentials
- Use environment variables, not hardcoded values
- Restrict database user to SELECT/INSERT/UPDATE only
- Use long, random password

### 4. File Permissions
```bash
# Web root
chmod 755 /var/www/html/whatsapp-bot
chmod 644 /var/www/html/whatsapp-bot/*.php

# Sensitive directories
chmod 750 /var/www/html/whatsapp-bot/includes
chmod 750 /var/www/html/whatsapp-bot/database
chmod 770 /var/www/html/whatsapp-bot/logs
chmod 770 /var/www/html/whatsapp-bot/uploads
chmod 770 /var/www/html/whatsapp-bot/temp
```

### 5. Backup Strategy
```bash
# Daily database backup
mysqldump -u whatsapp_bot -p whatsapp_bot > backup_$(date +%Y%m%d).sql

# Keep 30 days of backups
find . -name "backup_*.sql" -mtime +30 -delete
```

### 6. Log Monitoring
- Monitor `logs/php-errors.log`
- Monitor `logs/audit_logs` table
- Alert on failed logins, unauthorized API calls
- Rotate logs monthly

### 7. Rate Limiting
Configure in system_settings table:
- `max_messages_per_hour`: 300
- `max_messages_per_day`: 1000
- `max_delay_seconds`: 15
- These are enforced server-side

## Operational Guide

### Managing Contacts

1. **Add Individual Contact**
   - Web panel → Contacts → Add New
   - Phone number is auto-normalized
   - Duplicate phones prevented

2. **CSV Import**
   - Format: name,phone,email,company (optional)
   - Phone numbers auto-normalized
   - Duplicates skipped
   - Transactions ensure all-or-none

3. **Contact Groups**
   - Create groups for bulk messaging
   - Add/remove contacts to groups
   - Use groups when creating campaigns

### Creating & Sending Campaigns

1. **Create Campaign**
   - Name, description
   - Select template OR enter message
   - Supported template variables: {{name}}, {{phone}}, {{company}}
   - Optional: attach media

2. **Send Now**
   - Select contacts or groups
   - Confirm count (large campaigns require confirmation)
   - Jobs created atomically or rolled back

3. **Schedule Campaign**
   - Set future date/time
   - Jobs created as pending
   - Worker picks up when time arrives

4. **Pause/Cancel**
   - Pending jobs canceled immediately
   - Processing jobs may complete (worker dependent)
   - Campaign stats updated

### Worker Troubleshooting

**WhatsApp showing "authentication_required"**
- QR authentication expired
- Run worker script, scan QR code with phone
- Wait for "Connected" status

**Messages failing with timeouts**
- WhatsApp Web selector changed (fragile automation)
- Check browser logs for JavaScript errors
- May need to update selector constants in `WhatsAppAutomation` class
- Send diagnostic info via API

**Worker crashes frequently**
- Check worker logs: `logs/worker.log`
- Check available memory
- Browser profile may be locked - delete `whatsapp_session` folder
- Restart worker: `1_START_WORKER.bat`

**Messages not sending**
- Check worker is running: should see heartbeats in last_heartbeat
- Check WhatsApp Web connected status
- Check rate limits not exceeded
- Check recipient phone numbers valid

## Testing

### Unit Tests
```bash
python -m pytest tests/
```

### Integration Tests
```bash
# Test API endpoints with valid worker token
curl -H "Authorization: Bearer <token>" \
     http://localhost/api/v1/worker/status

# Test job claiming
curl -X POST -H "Authorization: Bearer <token>" \
     http://localhost/api/v1/jobs/claim
```

### Queue Safety Tests
1. Start two workers
2. Verify only one claims same job
3. Crash first worker while processing
4. Verify second worker recovers stale job
5. Verify message not duplicated

## Known Limitations

1. **Unofficial WhatsApp Automation**
   - WhatsApp Web UI can change unpredictably
   - Selectors may break without notice
   - No official API guarantee

2. **At-Least-Once Delivery**
   - Inherent delivery window race condition
   - If worker crashes after WhatsApp accepts but before reporting = unknown state
   - Not suitable for critical OTPs or legal notifications

3. **No Delivery Receipts**
   - "Sent" from worker ≠ recipient received
   - WhatsApp delivery/read receipts not available via Web
   - Receiving read receipts requires building/hacking messaging protocol

4. **Browser Session Dependent**
   - WhatsApp Web requires persistent browser session
   - Session can expire randomly
   - QR re-authentication may be required

5. **Single-Device Only**
   - Each worker controls one WhatsApp account
   - High-volume sending needs multiple accounts/workers
   - Each account should be used for bot-only, not personal

6. **WhatsApp Rate Limiting**
   - WhatsApp may restrict accounts regardless of pacing
   - No official rate limit specification
   - Conservative delays recommended

## Support & Escalation

### Issue Diagnosis Steps

1. Check worker heartbeat: Dashboard → Workers
2. Check WhatsApp connected status
3. Check message logs for errors
4. Check worker.log for diagnostics
5. Check PHP error log
6. Check audit_logs for permission errors

### Common Error Messages

- "WhatsApp Web not connected" → Needs re-authentication
- "Job not found" → Database corruption
- "Worker timeout" → Stale job recovery should handle
- "Invalid phone" → Phone number validation failed
- "File type not allowed" → Media MIME type mismatch

## Version History

### v1.0 (2024-01-15)
- Initial release
- Multi-worker support with atomic queue claiming
- Message templates with variables
- Campaign scheduling
- Media attachments
- Audit logging
- Worker reliability features

## License

Internal use only. Do not redistribute.

## Support

Contact: admin@example.com
