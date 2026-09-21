<?php
/**
 * Navigation Bar Component
 */
$currentPage = basename($_SERVER['PHP_SELF']);
$isAdmin = ($currentUser['role'] ?? '') === 'admin';
?>
<aside class="app-sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path>
            </svg>
        </div>
        <div class="brand-text">
            <h2>WhatsApp Bot</h2>
            <span class="badge badge-subtle">Control Panel</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-title">Messaging Management</div>
        <a href="<?= BASE_URL ?>/admin/index.php" class="nav-item <?= $currentPage === 'index.php' ? 'active' : '' ?>">
            <span class="nav-icon">📊</span>
            <span>Dashboard</span>
        </a>
        <a href="<?= BASE_URL ?>/admin/contacts.php" class="nav-item <?= $currentPage === 'contacts.php' ? 'active' : '' ?>">
            <span class="nav-icon">👥</span>
            <span>Contacts</span>
        </a>
        <a href="<?= BASE_URL ?>/admin/contact_groups.php" class="nav-item <?= $currentPage === 'contact_groups.php' ? 'active' : '' ?>">
            <span class="nav-icon">📁</span>
            <span>Contact Groups</span>
        </a>
        <a href="<?= BASE_URL ?>/admin/templates.php" class="nav-item <?= $currentPage === 'templates.php' ? 'active' : '' ?>">
            <span class="nav-icon">📝</span>
            <span>Message Templates</span>
        </a>
        <a href="<?= BASE_URL ?>/admin/campaigns.php" class="nav-item <?= in_array($currentPage, ['campaigns.php', 'campaign_create.php']) ? 'active' : '' ?>">
            <span class="nav-icon">🚀</span>
            <span>Campaigns</span>
        </a>

        <div class="nav-section-title">Operations & Workers</div>
        <a href="<?= BASE_URL ?>/admin/workers.php" class="nav-item <?= $currentPage === 'workers.php' ? 'active' : '' ?>">
            <span class="nav-icon">⚡</span>
            <span>Workers & WhatsApp</span>
        </a>
        <a href="<?= BASE_URL ?>/admin/logs.php" class="nav-item <?= $currentPage === 'logs.php' ? 'active' : '' ?>">
            <span class="nav-icon">📜</span>
            <span>Logs & Audits</span>
        </a>

        <?php if ($isAdmin): ?>
        <div class="nav-section-title">Administration</div>
        <a href="<?= BASE_URL ?>/admin/settings.php" class="nav-item <?= $currentPage === 'settings.php' ? 'active' : '' ?>">
            <span class="nav-icon">⚙️</span>
            <span>System Settings</span>
        </a>
        <a href="<?= BASE_URL ?>/admin/users.php" class="nav-item <?= $currentPage === 'users.php' ? 'active' : '' ?>">
            <span class="nav-icon">🔐</span>
            <span>User Management</span>
        </a>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="user-profile">
            <div class="avatar-circle"><?= strtoupper(substr($currentUser['username'] ?? 'U', 0, 1)) ?></div>
            <div class="user-meta">
                <span class="user-name"><?= htmlspecialchars($currentUser['full_name'] ?? 'User') ?></span>
                <span class="user-role badge badge-<?= $isAdmin ? 'primary' : 'secondary' ?>"><?= ucfirst($currentUser['role'] ?? 'operator') ?></span>
            </div>
        </div>
        <a href="<?= BASE_URL ?>/logout.php" class="btn-logout" title="Sign Out">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                <polyline points="16 17 21 12 16 7"></polyline>
                <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
        </a>
    </div>
</aside>

<main class="app-main">
    <header class="top-header">
        <div class="header-left">
            <h1 class="page-heading"><?= htmlspecialchars($pageTitle) ?></h1>
            <span class="timezone-indicator">🕒 Timezone: <?= htmlspecialchars(APP_TIMEZONE) ?></span>
        </div>
        <div class="header-right">
            <div id="connectionPill" class="status-pill status-pill-neutral">
                <span class="status-dot"></span>
                <span id="connectionText">Checking status...</span>
            </div>
        </div>
    </header>
    <div class="content-body">
