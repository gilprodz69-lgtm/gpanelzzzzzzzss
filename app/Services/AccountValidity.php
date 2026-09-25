<?php
declare(strict_types=1);
namespace App\Services;
use App\Repositories\Database;
final class AccountValidity
{
    public static function expired(array $u): bool {
        return $u['role']==='RESELLER' && isset($u['expires_at']) && (int)$u['expires_at']<=time();
    }
    public static function available(Database $db,array $u): bool {
        $seen=[];
        while(true) {
            if($u['status']!=='active'||self::expired($u)||isset($seen[$u['id']])) return false;
            $seen[$u['id']]=true;
            if(!$u['parent_id']) return true;
            $u=$db->one('SELECT * FROM users WHERE tenant_id=? AND id=?',[$u['tenant_id'],$u['parent_id']]);
            if(!$u) return false;
        }
    }
}
