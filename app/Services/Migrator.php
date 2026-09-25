<?php
declare(strict_types=1);
namespace App\Services;
use App\Repositories\Database;
final class Migrator
{
    public static function run(Database $db): void {
        $db->exec('CREATE TABLE IF NOT EXISTS migrations (name VARCHAR(190) PRIMARY KEY, applied_at BIGINT NOT NULL)');
        foreach (glob(BASE_PATH . '/database/migrations/*.php') as $file) {
            $name = basename($file);
            if ($db->one('SELECT name FROM migrations WHERE name=?', [$name])) continue;
            (require $file)($db);
            $db->insert('migrations',['name'=>$name,'applied_at'=>time()]);
        }
    }
}
