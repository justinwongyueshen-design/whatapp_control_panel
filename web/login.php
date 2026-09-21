<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

// Redirect if already logged in
if (Auth::check()) {
    header('Location: ' . BASE_URL . '/admin/index.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($token)) {
        $error = 'Security check failed. Please refresh the page and try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'Please provide both username and password.';
        } else {
            $result = Auth::login($username, $password);
            if ($result['success']) {
                header('Location: ' . BASE_URL . '/admin/index.php');
                exit;
            } else {
                $error = $result['message'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .login-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            padding: 20px;
        }
        .login-card {
            width: 100%;
            max-width: 420px;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            overflow: hidden;
        }
        .login-header {
            padding: 32px 32px 20px;
            text-align: center;
        }
        .login-brand-icon {
            width: 52px;
            height: 52px;
            margin: 0 auto 16px;
            border-radius: 14px;
            background: linear-gradient(135deg, #25d366, #059669);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
        }
        .login-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .login-subtitle {
            font-size: 0.85rem;
            color: #64748b;
        }
        .login-body {
            padding: 20px 32px 32px;
        }
        .disclaimer-box {
            margin-top: 20px;
            padding: 12px;
            background-color: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            font-size: 0.75rem;
            color: #64748b;
            line-height: 1.4;
            text-align: center;
        }
    </style>
</head>
<body>
<div class="login-wrapper">
    <div class="login-card">
        <div class="login-header">
            <div class="login-brand-icon">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path>
                </svg>
            </div>
            <h1 class="login-title">WhatsApp Bot Control Panel</h1>
            <p class="login-subtitle">Sign in to manage messaging and workers</p>
        </div>

        <div class="login-body">
            <?php if ($error): ?>
                <div class="alert alert-danger" style="margin-bottom: 18px;">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">

                <div class="form-group">
                    <label class="form-label" for="username">Username</label>
                    <input type="text" id="username" name="username" class="form-control" placeholder="admin or operator" required autofocus>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 11px; margin-top: 8px;">
                    Sign In to Dashboard
                </button>
            </form>

            <div class="disclaimer-box">
                <strong>Responsible Use Notice:</strong> This internal management system is strictly intended for consent-based, legitimate business messaging. Unsolicited messages and spam are strictly prohibited.
            </div>
        </div>
    </div>
</div>
</body>
</html>
