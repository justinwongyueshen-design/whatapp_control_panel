# WhatsApp Bot Control Panel — Deployment & Hardening Guide

## 1. System Requirements

- **Server**: Windows / Linux with Apache (or XAMPP)
- **PHP**: 8.2 or 8.3+ with `pdo_mysql`, `curl`, `mbstring`, `fileinfo`
- **Database**: MySQL 8.0+ or MariaDB 10.4+
- **Worker Host**: Windows 10/11 with Python 3.11 or 3.12+
- **Browser**: Google Chrome, Microsoft Edge, or Playwright Chromium

---

## 2. Step-by-Step Deployment

### Step 1: Web Server & Database Setup (XAMPP)

1. Place the project folder into `c:\xampp\htdocs\whatapp_control_panel`.
2. Start Apache and MySQL in XAMPP Control Panel.
3. Import database schema and default accounts:
   ```cmd
   mysql -u root < database\schema.sql
   mysql -u root < database\seed.sql
   ```
4. Access the Control Panel at:
   ```text
   http://localhost/whatapp_control_panel/web/
   ```
5. Log in with the default administrator credentials:
   - **Username**: `admin`
   - **Password**: `Admin@123456`
   *(Operator account also created: `operator` / `Operator@123456`)*

---

### Step 2: Register Worker & Configure Token

1. In the Web Control Panel, navigate to **Workers & WhatsApp**.
2. Click **+ Register New Worker**.
3. Enter a friendly name (e.g., `Office-Worker-01`) and click **Generate Token**.
4. Copy the generated Bearer token.
5. Open `worker\config.ini` in a text editor and paste the token:
   ```ini
   [worker]
   token = YOUR_COPIED_TOKEN_HERE
   ```

---

### Step 3: Worker Environment Installation

1. Navigate to the `worker\` folder.
2. Double-click `1_INSTALL.bat` to automatically:
   - Verify Python installation.
   - Create a Python virtual environment (`venv`).
   - Install dependencies (`requests`, `playwright`).
   - Download Playwright Chromium binaries.

---

### Step 4: WhatsApp Account Linking

1. Double-click `2_LINK_WHATSAPP.bat`.
2. A headful browser window will open displaying `web.whatsapp.com`.
3. On your mobile phone, open WhatsApp:
   - Go to **Settings** -> **Linked Devices** -> **Link a Device**.
   - Scan the QR code displayed on screen.
4. The script will detect the chat list and save your session into the persistent profile directory `worker\whatsapp_profile\`.
5. Once confirmed, close the window.

---

### Step 5: Start the Automation Worker

1. Double-click `3_RUN_WORKER.bat`.
2. The worker will start polling for jobs, reporting heartbeats to the web panel, and dispatching queued WhatsApp messages according to configured delays and batch limits.

---

## 3. Production Hardening Checklist

1. **HTTPS Enforcement**:
   - Enable SSL/TLS on Apache.
   - Session cookies will automatically enable the `Secure` flag over HTTPS.
2. **Password Rotation**:
   - Change the default passwords for `admin` and `operator` immediately in **User Management**.
3. **Uploads Directory Security**:
   - Ensure `web/uploads/.htaccess` is present to block direct HTTP access to uploaded documents and images.
4. **Credential Isolation**:
   - Ensure `worker/config.ini` and `worker/whatsapp_profile/` are never committed to version control.
5. **Rate Limiting & Anti-Abuse**:
   - Adjust `max_messages_per_hour` and `max_messages_per_day` in **System Settings** according to your organization's messaging volume.
