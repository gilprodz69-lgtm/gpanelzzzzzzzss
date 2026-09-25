<?php
declare(strict_types=1);
use App\Repositories\Database;
return static function(Database $db): void {
    $columns=$db->driver()==='mysql'?array_column($db->all('SHOW COLUMNS FROM users'),'Field'):array_column($db->all('PRAGMA table_info(users)'),'name');
    if(!in_array('expires_at',$columns,true)) $db->exec('ALTER TABLE users ADD COLUMN expires_at BIGINT NULL');
    // Existing accounts retain their current access until an administrator sets a validity.
};
