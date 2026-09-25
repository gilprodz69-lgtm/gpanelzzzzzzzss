<?php
declare(strict_types=1);
namespace App\Services;
use App\Repositories\Database;
use App\Helpers\HttpError;
use App\Models\Catalog;
final class Quota
{
    public function __construct(private Database $db) {}
    public function plan(array $user): ?array {
        if(!$user['plan_id']) return null;
        $p=$this->db->one('SELECT * FROM plans WHERE tenant_id=? AND id=?',[$user['tenant_id'],$user['plan_id']]);
        if($p) { $p['limits']=json_decode($p['limits_json'],true); $p['features']=json_decode($p['features_json'],true); } return $p;
    }
    public function usage(array $u,string $kind): int {
        if(!in_array($kind,array_merge(array_keys(Catalog::RESOURCES),['users']),true)) return 0;
        $ids=[(int)$u['id']];
        if($u['role']==='RESELLER') $ids=array_merge($ids,array_column($this->db->all('SELECT id FROM users WHERE tenant_id=? AND parent_id=?',[$u['tenant_id'],$u['id']]),'id'));
        if($kind==='users') return (int)$this->db->scalar('SELECT COUNT(*) FROM users WHERE tenant_id=? AND parent_id=?',[$u['tenant_id'],$u['id']]);
        return (int)$this->db->scalar("SELECT COUNT(*) FROM `$kind` WHERE tenant_id=? AND deleted_at IS NULL AND owner_id IN (".implode(',',array_fill(0,count($ids),'?')).')',array_merge([$u['tenant_id']],$ids));
    }
    // Must be called inside the same tenant-locked transaction as reservation/insertion.
    public function check(array $u,string $kind): void {
        foreach($this->chain($u) as $account) {
            if($account['role']==='MASTER') continue;
            $p=$this->plan($account);
            if(!$p) throw new HttpError(409,'A conta precisa de um plano para criar recursos.');
            if(in_array($kind,['docker_containers','ftp_accounts','ssl_certificates','backups'],true) && !in_array($kind,$p['features'],true)) throw new HttpError(403,'Seu plano não permite este recurso.');
            $limit=(int)($p['limits'][$kind]??0);
            if($this->usage($account,$kind)>=$limit) throw new HttpError(409,'Limite de '.(Catalog::RESOURCES[$kind]['label']??'usuários').' atingido para o plano.');
        }
    }
    public function chain(array $u): array {
        $chain=[$u];
        if($u['parent_id']) { $parent=$this->db->one('SELECT * FROM users WHERE tenant_id=? AND id=?',[$u['tenant_id'],$u['parent_id']]); if($parent && $parent['role']==='RESELLER') $chain[]=$parent; }
        return $chain;
    }
    public function summary(array $u): array {
        $p=$this->plan($u); $out=[];
        foreach(array_merge(array_keys(Catalog::RESOURCES),['users']) as $k) $out[$k]=['used'=>$this->usage($u,$k),'limit'=>$u['role']==='MASTER'?null:(int)($p['limits'][$k]??0)];
        return $out;
    }
}
