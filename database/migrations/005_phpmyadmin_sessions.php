<?php
declare(strict_types=1);
use App\Repositories\Database;
return static function(Database $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS phpmyadmin_sessions (token_hash VARCHAR(64) PRIMARY KEY, session_id VARCHAR(64) NOT NULL, database_id BIGINT NOT NULL, credential TEXT NOT NULL, ticket_expires BIGINT NOT NULL, expires_at BIGINT NOT NULL, consumed INTEGER NOT NULL DEFAULT 0)');
};
