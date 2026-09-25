<?php
declare(strict_types=1);
use App\Repositories\Database;
use App\Models\Catalog;
return static function (Database $db): void {
    $id = $db->driver() === 'mysql' ? 'BIGINT PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $text = $db->driver() === 'mysql' ? 'LONGTEXT' : 'TEXT';
    $tables = [
        'tenants'=>"id $id, name VARCHAR(190) NOT NULL, created_at BIGINT NOT NULL",
        'plans'=>"id $id, tenant_id BIGINT NOT NULL, owner_id BIGINT NULL, name VARCHAR(190) NOT NULL, limits_json $text NOT NULL, features_json $text NOT NULL, price_cents BIGINT NOT NULL DEFAULT 0, created_at BIGINT NOT NULL, UNIQUE(tenant_id,id), FOREIGN KEY(tenant_id) REFERENCES tenants(id)",
        'users'=>"id $id, tenant_id BIGINT NOT NULL, parent_id BIGINT NULL, plan_id BIGINT NULL, name VARCHAR(190) NOT NULL, email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, role VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'active', totp_secret $text NULL, totp_last_step BIGINT NOT NULL DEFAULT 0, created_at BIGINT NOT NULL, UNIQUE(tenant_id,id), FOREIGN KEY(tenant_id) REFERENCES tenants(id), FOREIGN KEY(tenant_id,parent_id) REFERENCES users(tenant_id,id), FOREIGN KEY(tenant_id,plan_id) REFERENCES plans(tenant_id,id)",
        'roles'=>"id $id, name VARCHAR(30) NOT NULL UNIQUE",
        'permissions'=>"id $id, name VARCHAR(100) NOT NULL UNIQUE",
        'user_roles'=>"user_id BIGINT NOT NULL, role_id BIGINT NOT NULL, PRIMARY KEY(user_id,role_id), FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(role_id) REFERENCES roles(id)",
        'user_permissions'=>"user_id BIGINT NOT NULL, permission VARCHAR(100) NOT NULL, allowed INTEGER NOT NULL, PRIMARY KEY(user_id,permission), FOREIGN KEY(user_id) REFERENCES users(id)",
        'servers'=>"id $id, tenant_id BIGINT NOT NULL, name VARCHAR(190) NOT NULL, address VARCHAR(190) NOT NULL, agent_url VARCHAR(255) NOT NULL, os VARCHAR(80) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'unknown', last_seen BIGINT NULL, created_at BIGINT NOT NULL, UNIQUE(tenant_id,id), FOREIGN KEY(tenant_id) REFERENCES tenants(id)",
        'server_credentials'=>"server_id BIGINT PRIMARY KEY, secret $text NOT NULL, FOREIGN KEY(server_id) REFERENCES servers(id)",
        'server_users'=>"tenant_id BIGINT NOT NULL, server_id BIGINT NOT NULL, user_id BIGINT NOT NULL, PRIMARY KEY(server_id,user_id), FOREIGN KEY(tenant_id,server_id) REFERENCES servers(tenant_id,id), FOREIGN KEY(tenant_id,user_id) REFERENCES users(tenant_id,id)",
        'sessions'=>"id VARCHAR(64) PRIMARY KEY, user_id BIGINT NOT NULL, csrf VARCHAR(64) NOT NULL, ip VARCHAR(45) NOT NULL, user_agent VARCHAR(255) NOT NULL, created_at BIGINT NOT NULL, last_seen BIGINT NOT NULL, expires_at BIGINT NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id)",
        'api_keys'=>"id $id, user_id BIGINT NOT NULL, name VARCHAR(190) NOT NULL, token_hash VARCHAR(64) NOT NULL UNIQUE, scopes_json $text NOT NULL, created_at BIGINT NOT NULL, expires_at BIGINT NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id)",
        'rate_limits'=>"bucket VARCHAR(64) PRIMARY KEY, attempts INTEGER NOT NULL, expires_at BIGINT NOT NULL",
        'password_resets'=>"token_hash VARCHAR(64) PRIMARY KEY, user_id BIGINT NOT NULL, expires_at BIGINT NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id)",
        'jobs'=>"id $id, tenant_id BIGINT NOT NULL, owner_id BIGINT NOT NULL, server_id BIGINT NOT NULL, resource_type VARCHAR(40) NULL, resource_id BIGINT NULL, operation VARCHAR(60) NOT NULL, payload $text NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0, error $text NULL, result_json $text NULL, created_at BIGINT NOT NULL, started_at BIGINT NULL, finished_at BIGINT NULL, FOREIGN KEY(tenant_id,owner_id) REFERENCES users(tenant_id,id), FOREIGN KEY(tenant_id,server_id) REFERENCES servers(tenant_id,id)",
        'audit_logs'=>"id $id, tenant_id BIGINT NOT NULL, user_id BIGINT NOT NULL, action VARCHAR(100) NOT NULL, target VARCHAR(190) NOT NULL, ip VARCHAR(45) NOT NULL, result VARCHAR(30) NOT NULL, created_at BIGINT NOT NULL, FOREIGN KEY(tenant_id,user_id) REFERENCES users(tenant_id,id)",
        'server_metrics'=>"id $id, tenant_id BIGINT NOT NULL, server_id BIGINT NOT NULL, cpu REAL NOT NULL, ram REAL NOT NULL, disk REAL NOT NULL, load_avg REAL NOT NULL, uptime BIGINT NOT NULL, network_rx BIGINT NOT NULL, network_tx BIGINT NOT NULL, created_at BIGINT NOT NULL, FOREIGN KEY(tenant_id,server_id) REFERENCES servers(tenant_id,id)",
        'notifications'=>"id $id, tenant_id BIGINT NOT NULL, user_id BIGINT NOT NULL, title VARCHAR(190) NOT NULL, body $text NOT NULL, read_at BIGINT NULL, created_at BIGINT NOT NULL, FOREIGN KEY(tenant_id,user_id) REFERENCES users(tenant_id,id)",
        'settings'=>"tenant_id BIGINT NOT NULL, name VARCHAR(100) NOT NULL, value_json $text NOT NULL, PRIMARY KEY(tenant_id,name), FOREIGN KEY(tenant_id) REFERENCES tenants(id)",
        'subscriptions'=>"id $id, tenant_id BIGINT NOT NULL, user_id BIGINT NOT NULL, plan_id BIGINT NOT NULL, status VARCHAR(30) NOT NULL, period_end BIGINT NULL, created_at BIGINT NOT NULL, FOREIGN KEY(tenant_id,user_id) REFERENCES users(tenant_id,id), FOREIGN KEY(tenant_id,plan_id) REFERENCES plans(tenant_id,id)",
        'invoices'=>"id $id, subscription_id BIGINT NOT NULL, amount_cents BIGINT NOT NULL, currency VARCHAR(3) NOT NULL DEFAULT 'BRL', status VARCHAR(30) NOT NULL, due_at BIGINT NOT NULL, created_at BIGINT NOT NULL, FOREIGN KEY(subscription_id) REFERENCES subscriptions(id)",
        'payments'=>"id $id, invoice_id BIGINT NOT NULL, provider VARCHAR(60) NOT NULL, external_id VARCHAR(190) NOT NULL UNIQUE, amount_cents BIGINT NOT NULL, status VARCHAR(30) NOT NULL, created_at BIGINT NOT NULL, FOREIGN KEY(invoice_id) REFERENCES invoices(id)",
    ];
    foreach (array_keys(Catalog::RESOURCES) as $table) $tables[$table] = "id $id, tenant_id BIGINT NOT NULL, owner_id BIGINT NOT NULL, server_id BIGINT NOT NULL, name VARCHAR(190) NOT NULL, config_json $text NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'pending', created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, deleted_at BIGINT NULL, UNIQUE(tenant_id,id), UNIQUE(server_id,name), FOREIGN KEY(tenant_id,owner_id) REFERENCES users(tenant_id,id), FOREIGN KEY(tenant_id,server_id) REFERENCES servers(tenant_id,id)";
    foreach ($tables as $name => $definition) $db->exec("CREATE TABLE IF NOT EXISTS `$name` ($definition)" . ($db->driver()==='mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
    foreach (array_keys(Catalog::RESOURCES) as $table) $db->exec("CREATE INDEX idx_{$table}_scope ON `$table`(tenant_id,owner_id,deleted_at)");
    $db->exec('CREATE INDEX idx_jobs_pending ON jobs(status,created_at)');
    $db->exec('CREATE INDEX idx_metrics_history ON server_metrics(tenant_id,server_id,created_at)');
    $db->exec('CREATE INDEX idx_audit_scope ON audit_logs(tenant_id,user_id,created_at)');
    foreach (['MASTER','ADMIN','RESELLER','CLIENT'] as $role) $db->insert('roles',['name'=>$role]);
    foreach (Catalog::permissions() as $p) $db->insert('permissions',['name'=>$p]);
};
