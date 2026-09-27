<?php
declare(strict_types=1);
use App\Repositories\Database;
return static function(Database $db): void {
    $id=$db->driver()==='mysql'?'BIGINT PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $db->exec("CREATE TABLE IF NOT EXISTS plugin_installations (id $id, tenant_id BIGINT NOT NULL, owner_id BIGINT NOT NULL, website_id BIGINT NOT NULL, database_id BIGINT NOT NULL, plugin VARCHAR(40) NOT NULL, status VARCHAR(20) NOT NULL, job_id BIGINT NULL, created_at BIGINT NOT NULL)");
};
