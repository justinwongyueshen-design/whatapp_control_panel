-- ====================================================================
-- WhatsApp Bot Control Panel - Sample Seed Data
-- ====================================================================

USE `whatsapp_control_panel`;

-- Sample Contact Groups
INSERT INTO `contact_groups` (`name`, `description`)
VALUES
('VIP Clients', 'High-priority enterprise customers'),
('Newsletter Subscribers', 'Subscribers who consented to weekly updates'),
('Test Group', 'Internal numbers for deployment testing')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

-- Sample Contacts (Normalized Malaysian numbers e.g. 6012...)
INSERT INTO `contacts` (`name`, `phone`, `company`, `status`)
VALUES
('John Doe', '60123456789', 'Acme Corp', 'active'),
('Jane Smith', '60198765432', 'Global Logistics', 'active'),
('Alice Tan', '60171112233', 'Sunrise Ventures', 'active'),
('Bob Lee', '60164445566', 'Lee & Partners', 'active'),
('Charlie Wong', '60189998877', 'Synergy Tech', 'inactive')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Group Memberships
INSERT IGNORE INTO `contact_group_members` (`group_id`, `contact_id`)
SELECT g.id, c.id FROM `contact_groups` g, `contacts` c
WHERE g.name = 'VIP Clients' AND c.phone IN ('60123456789', '60198765432');

INSERT IGNORE INTO `contact_group_members` (`group_id`, `contact_id`)
SELECT g.id, c.id FROM `contact_groups` g, `contacts` c
WHERE g.name = 'Newsletter Subscribers' AND c.phone IN ('60123456789', '60171112233', '60164445566');

INSERT IGNORE INTO `contact_group_members` (`group_id`, `contact_id`)
SELECT g.id, c.id FROM `contact_groups` g, `contacts` c
WHERE g.name = 'Test Group' AND c.phone IN ('60123456789');

-- Initial System Audit Log Entry
INSERT INTO `audit_logs` (`user_id`, `action`, `details`, `ip_address`)
SELECT id, 'system_initialized', 'Database schema and seed data loaded successfully', '127.0.0.1'
FROM `users` WHERE `username` = 'admin' LIMIT 1;
