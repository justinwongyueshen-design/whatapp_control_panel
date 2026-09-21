# WhatsApp Bot Control Panel - Quick Start Guide

## First Time Setup

### 1. Import Database Schema

```bash
mysql -u root -p < database/schema.sql
```

Or if using XAMPP:

```bash
"C:\xampp\mysql\bin\mysql.exe" -u root < database\schema.sql
```

### 2. Create Admin User

Create a file `setup_admin.php` in the web folder:

```php
<?php
require 'includes/bootstrap.php';

$username = 'admin';
$password = 'YourSecurePassword123!';
$email = 'admin@example.com';
$full_name = 'Administrator';

try {
    $password_hash = Auth::hashPassword($password);
    
    db()->execute(
        'INSERT INTO users (username, email, password_hash, full_name, role, is_active)
         VALUES (?, ?, ?, ?, ?, ?)',
        [$username, $email, $password_hash, $full_name, 'admin', true]
    );
    
    echo "✓ Admin user created successfully!<br>";
    echo "Username: $username<br>";
    echo "Password: $password<br>";
    echo "Email: $email<br>";
    echo "<br><a href='login.php'>Go to Login</a>";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage();
}
?>
```

Visit: `http://localhost/setup_admin.php`

Then delete the file for security.

### 3. Register Worker

1. Login with admin credentials
2. Go to admin panel
3. Click "Register Worker"
4. Copy the token (shown only once!)
5. Put in `worker/worker_config.json`

### 4. Start Worker

Windows:
```
cd worker
1_START_WORKER.bat
```

Linux/macOS:
```bash
cd worker
python3 whatsapp_worker.py worker_config.json
```

### 5. Scan WhatsApp QR Code

- Browser window will appear
- Scan QR with phone
- Worker will connect to WhatsApp Web
- Dashboard should show "Connected"

## Folder Structure

```
whatapp_control_panel/
├── web/                      # PHP Web Application
│   ├── config.php            # Configuration (edit this!)
│   ├── includes/             # Classes and utilities
│   │   ├── bootstrap.php     # Initialize app
│   │   ├── Database.php      # PDO connection
│   │   ├── Auth.php          # Authentication
│   │   ├── Validator.php     # Validation
│   │   └── MessageQueue.php  # Job queue logic
│   ├── api/v1/               # REST API endpoints
│   │   └── index.php         # Worker API
│   ├── admin/                # Admin panel
│   │   └── dashboard.php     # Main dashboard
│   ├── uploads/              # User media files
│   ├── logs/                 # Application logs
│   ├── login.php             # Login page
│   ├── logout.php            # Logout handler
│   ├── index.php             # Landing page (redirect to login)
│   └── .htaccess             # Apache security rules
│
├── worker/                   # Python Worker
│   ├── whatsapp_worker.py    # Main worker code
│   ├── worker_config.json    # Configuration (edit this!)
│   ├── 1_START_WORKER.bat    # Windows startup script
│   └── logs/                 # Worker logs
│
├── database/                 # Database
│   └── schema.sql            # Database schema
│
└── README.md                 # Documentation
```

## Configuration Files to Edit

### 1. `web/config.php`

Database credentials and application settings:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'whatsapp_bot');
define('DB_PASS', 'YOUR_SECURE_PASSWORD');
define('DB_NAME', 'whatsapp_bot');
```

### 2. `worker/worker_config.json`

Worker configuration:

```json
{
  "api_url": "http://localhost/api/v1",
  "worker_token": "YOUR_TOKEN_FROM_ADMIN_PANEL",
  "browser_type": "chromium",
  "profile_path": "./whatsapp_session"
}
```

## Common Issues

### "Database connection failed"
- Check MySQL is running
- Verify credentials in config.php
- Run: `mysql -u root -p whatsapp_bot -e "SELECT 1"`

### "Worker token invalid"
- Regenerate in admin panel
- Make sure you copied the full token
- Tokens shown only once - regenerate if lost

### "WhatsApp authentication_required"
- Scan the QR code with your phone
- Wait for "Connected" status on dashboard
- If stuck, delete `worker/whatsapp_session` folder and restart

### "Jobs not being processed"
- Check worker is running (should see heartbeats)
- Check worker log: `worker/logs/worker.log`
- Verify WhatsApp status is "Connected"
- Check rate limits not exceeded

### "Can't attach media files"
- Check file type is allowed (images, PDF, Office docs)
- Check file size < 50MB
- Check `uploads/` folder has write permissions

## XAMPP Specific Setup

### Enable mod_rewrite

1. Open `C:\xampp\apache\conf\httpd.conf`
2. Find: `#LoadModule rewrite_module modules/mod_rewrite.so`
3. Remove the `#` at the start (uncomment)
4. Restart Apache

### MySQL Credentials

Default XAMPP MySQL:
- User: `root`
- Password: (empty)

### Test Access

- Web: `http://localhost/whatapp_control_panel/web/login.php`
- API: `http://localhost/whatapp_control_panel/web/api/v1/worker/status` (will need token)

## Production Deployment Checklist

- [ ] Set `APP_ENV = 'production'` in config.php
- [ ] Set `APP_DEBUG = false` in config.php
- [ ] Generate SSL certificate (HTTPS)
- [ ] Set secure session cookies
- [ ] Create strong admin passwords
- [ ] Backup database daily
- [ ] Monitor error logs
- [ ] Set file permissions correctly (750 for sensitive dirs)
- [ ] Store credentials in environment variables
- [ ] Test backup/restore procedure
- [ ] Document emergency recovery steps

## Testing the System

### Test 1: Admin Login
1. Go to `http://localhost/whatapp_control_panel/web/login.php`
2. Login with admin credentials
3. You should see dashboard with 0 workers

### Test 2: Register Worker
1. Admin panel → Workers → Register
2. Copy token
3. Put in `worker_config.json`

### Test 3: Start Worker
1. Run `1_START_WORKER.bat` (Windows)
2. Browser window should open to `web.whatsapp.com`
3. Scan QR code with phone
4. Wait for "Connected" (takes 5-30 seconds)
5. Dashboard should show worker as online + connected

### Test 4: Create & Send Campaign
1. Add test contact (your own phone number)
2. Create campaign with test message
3. Click "Send Now"
4. Check message logs for "sent" status
5. Check your phone for message

## Next Steps

- Read [README.md](README.md) for full documentation
- Set up scheduled campaigns
- Add contact groups
- Create message templates with variables
- Configure rate limiting
- Set up backup strategy
- Monitor audit logs

## Support

Check logs if anything fails:
- Web: `web/logs/php-errors.log`
- Worker: `worker/logs/worker.log`
- Database: `audit_logs` table for action history

Good luck! 🤖
