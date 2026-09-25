<?php
declare(strict_types=1);
namespace App\Services;
use App\Repositories\Database;
final class Audit
{
    public static function write(Database $db, array $user, string $action, string $target='', string $result='success'): void {
        $db->insert('audit_logs',['tenant_id'=>$user['tenant_id'],'user_id'=>$user['id'],'action'=>$action,'target'=>substr($target,0,190),'ip'=>$_SERVER['REMOTE_ADDR'] ?? '127.0.0.1','result'=>$result,'created_at'=>time()]);
    }
}
