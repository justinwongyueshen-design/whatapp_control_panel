<?php
/**
 * Admin Dashboard
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

// Get statistics
$workers = db()->fetchAll('SELECT * FROM workers ORDER BY name ASC');
$active_workers = count(array_filter($workers, fn($w) => $w['is_active']));

$campaigns = db()->fetchOne(
    'SELECT COUNT(*) as total, 
            SUM(CASE WHEN status IN ("queued", "running") THEN 1 ELSE 0 END) as active_count,
            SUM(CASE WHEN status IN ("running") THEN 1 ELSE 0 END) as running_count
     FROM campaigns'
);

$jobs = db()->fetchOne(
    'SELECT COUNT(*) as total,
            SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN status = "processing" THEN 1 ELSE 0 END) as processing_count,
            SUM(CASE WHEN status = "sent" THEN 1 ELSE 0 END) as sent_count,
            SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed_count
     FROM message_jobs'
);

$contacts_count = db()->fetchOne('SELECT COUNT(*) as total FROM contacts WHERE is_active = TRUE');
$groups_count = db()->fetchOne('SELECT COUNT(*) as total FROM contact_groups');

// Recent messages
$recent_logs = db()->fetchAll(
    'SELECT * FROM message_logs ORDER BY created_at DESC LIMIT 10'
);

// Recover stale jobs (run periodically)
if (rand(1, 100) <= 5) { // 5% chance to run
    MessageQueue::recoverStaleJobs();
}

$user = Auth::getCurrentUser();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo APP_NAME; ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            color: #333;
        }
        .navbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .navbar h1 {
            font-size: 24px;
        }
        .navbar-user {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .navbar-user a {
            color: white;
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 5px;
            background: rgba(255,255,255,0.2);
            transition: background 0.3s;
        }
        .navbar-user a:hover {
            background: rgba(255,255,255,0.3);
        }
        .container {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .stat-card h3 {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
            text-transform: uppercase;
        }
        .stat-card .value {
            font-size: 32px;
            font-weight: bold;
            color: #667eea;
        }
        .stat-card .subtext {
            font-size: 12px;
            color: #999;
            margin-top: 10px;
        }
        .section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        .section h2 {
            font-size: 18px;
            margin-bottom: 20px;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }
        .worker-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
        }
        .worker-item {
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            transition: border-color 0.3s;
        }
        .worker-item:hover {
            border-color: #667eea;
        }
        .worker-name {
            font-weight: bold;
            margin-bottom: 10px;
        }
        .worker-status {
            font-size: 12px;
            display: flex;
            gap: 10px;
            flex-direction: column;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: bold;
        }
        .status-online { background: #d4edda; color: #155724; }
        .status-offline { background: #f8d7da; color: #721c24; }
        .status-connected { background: #d1ecf1; color: #0c5460; }
        .status-disconnected { background: #fff3cd; color: #856404; }
        .message-log {
            font-size: 13px;
            border-collapse: collapse;
            width: 100%;
        }
        .message-log th {
            background: #f9f9f9;
            padding: 10px;
            text-align: left;
            border-bottom: 2px solid #ddd;
        }
        .message-log td {
            padding: 10px;
            border-bottom: 1px solid #ddd;
        }
        .status-sent { color: green; font-weight: bold; }
        .status-failed { color: red; font-weight: bold; }
        .timestamp {
            color: #999;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>🤖 <?php echo APP_NAME; ?></h1>
        <div class="navbar-user">
            <span><?php echo htmlspecialchars($user['full_name']); ?> (<?php echo $user['role']; ?>)</span>
            <a href="/logout.php">Logout</a>
        </div>
    </div>
    
    <div class="container">
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Workers</h3>
                <div class="value"><?php echo $active_workers; ?>/<?php echo count($workers); ?></div>
                <div class="subtext">Online / Total</div>
            </div>
            
            <div class="stat-card">
                <h3>Jobs</h3>
                <div class="value"><?php echo $jobs['total'] ?? 0; ?></div>
                <div class="subtext">
                    Pending: <?php echo $jobs['pending_count'] ?? 0; ?> |
                    Processing: <?php echo $jobs['processing_count'] ?? 0; ?>
                </div>
            </div>
            
            <div class="stat-card">
                <h3>Messages</h3>
                <div class="value"><?php echo $jobs['sent_count'] ?? 0; ?></div>
                <div class="subtext">
                    Sent: <?php echo $jobs['sent_count'] ?? 0; ?> |
                    Failed: <?php echo $jobs['failed_count'] ?? 0; ?>
                </div>
            </div>
            
            <div class="stat-card">
                <h3>Campaigns</h3>
                <div class="value"><?php echo $campaigns['running_count'] ?? 0; ?></div>
                <div class="subtext">
                    Running |
                    Active: <?php echo $campaigns['active_count'] ?? 0; ?>
                </div>
            </div>
            
            <div class="stat-card">
                <h3>Contacts</h3>
                <div class="value"><?php echo $contacts_count['total'] ?? 0; ?></div>
                <div class="subtext">Groups: <?php echo $groups_count['total'] ?? 0; ?></div>
            </div>
        </div>
        
        <!-- Workers Section -->
        <div class="section">
            <h2>Worker Status</h2>
            <?php if (!empty($workers)): ?>
                <div class="worker-list">
                    <?php foreach ($workers as $worker): ?>
                        <div class="worker-item">
                            <div class="worker-name"><?php echo htmlspecialchars($worker['name']); ?></div>
                            <div class="worker-status">
                                <span class="status-badge <?php echo $worker['is_active'] ? 'status-online' : 'status-offline'; ?>">
                                    <?php echo $worker['is_active'] ? '🟢 Active' : '🔴 Inactive'; ?>
                                </span>
                                <span class="status-badge <?php echo $worker['is_whatsapp_connected'] ? 'status-connected' : 'status-disconnected'; ?>">
                                    <?php echo $worker['is_whatsapp_connected'] ? '✓ Connected' : '✗ Disconnected'; ?>
                                </span>
                                <div class="timestamp">
                                    Last seen: <?php echo $worker['last_heartbeat'] ? date('M d, H:i', strtotime($worker['last_heartbeat'])) : 'Never'; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p>No workers registered yet.</p>
            <?php endif; ?>
        </div>
        
        <!-- Recent Messages -->
        <div class="section">
            <h2>Recent Messages</h2>
            <?php if (!empty($recent_logs)): ?>
                <table class="message-log">
                    <thead>
                        <tr>
                            <th>Recipient</th>
                            <th>Status</th>
                            <th>Attempt</th>
                            <th>Timestamp</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_logs as $log): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($log['recipient_phone']); ?></td>
                                <td class="status-<?php echo $log['status']; ?>">
                                    <?php echo strtoupper($log['status']); ?>
                                </td>
                                <td><?php echo $log['attempt_number'] ?? 1; ?></td>
                                <td class="timestamp"><?php echo date('M d, H:i:s', strtotime($log['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No message activity yet.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
