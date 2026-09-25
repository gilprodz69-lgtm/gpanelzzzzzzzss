<?php
declare(strict_types=1);
use App\Repositories\Database;
return static function(Database $db): void {
    $id=$db->driver()==='mysql'?'BIGINT PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $db->exec("CREATE TABLE backup_schedules (id $id, tenant_id BIGINT NOT NULL, owner_id BIGINT NOT NULL, server_id BIGINT NOT NULL, website_id BIGINT NOT NULL, frequency VARCHAR(20) NOT NULL, retention INTEGER NOT NULL, next_run BIGINT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'active', last_error TEXT NULL, created_at BIGINT NOT NULL, UNIQUE(tenant_id,id), UNIQUE(tenant_id,website_id), FOREIGN KEY(tenant_id,owner_id) REFERENCES users(tenant_id,id), FOREIGN KEY(tenant_id,server_id) REFERENCES servers(tenant_id,id), FOREIGN KEY(tenant_id,website_id) REFERENCES websites(tenant_id,id))");
    $db->exec('CREATE INDEX idx_backup_due ON backup_schedules(status,next_run)');
};
