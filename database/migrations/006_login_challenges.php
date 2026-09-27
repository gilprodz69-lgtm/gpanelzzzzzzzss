<?php
declare(strict_types=1);
use App\Repositories\Database;
return static function(Database $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS login_challenges (token_hash VARCHAR(64) PRIMARY KEY, user_id BIGINT NOT NULL, security_stamp VARCHAR(64) NOT NULL, expires_at BIGINT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0)');
};
