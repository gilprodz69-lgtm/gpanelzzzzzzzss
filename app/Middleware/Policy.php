<?php
declare(strict_types=1);
namespace App\Middleware;
use App\Models\Catalog;
use App\Repositories\Database;
use App\Helpers\HttpError;
final class Policy
{
    public function __construct(private Database $db, public readonly array $user, private ?array $scopes = null) {}
    public function permissions(): array {
        $p = Catalog::defaults($this->user['role']);
        if ($this->user['role'] !== 'MASTER') foreach ($this->db->all('SELECT permission,allowed FROM user_permissions WHERE user_id=?',[$this->user['id']]) as $row) {
            $p = array_values(array_diff($p,[$row['permission']])); if ($row['allowed']) $p[] = $row['permission'];
        }
        return $this->scopes === null ? $p : array_values(array_intersect($p,$this->scopes));
    }
    public function require(string $permission): void { if (!in_array($permission,$this->permissions(),true)) throw new HttpError(403,'Você não tem permissão para esta operação.'); }
    public function isAdmin(): bool { return in_array($this->user['role'], ['MASTER','ADMIN'],true); }
    public function ownerIds(): array {
        if ($this->isAdmin()) return array_column($this->db->all('SELECT id FROM users WHERE tenant_id=?',[$this->user['tenant_id']]),'id');
        $ids = [(int)$this->user['id']];
        if ($this->user['role'] === 'RESELLER') $ids = array_merge($ids, array_column($this->db->all('SELECT id FROM users WHERE tenant_id=? AND parent_id=?',[$this->user['tenant_id'],$this->user['id']]),'id'));
        return array_map('intval',$ids);
    }
    public function scope(string $ownerColumn='owner_id'): array {
        $ids=$this->ownerIds(); return ['tenant_id=? AND ' . $ownerColumn . ' IN (' . implode(',',array_fill(0,count($ids),'?')) . ')',array_merge([$this->user['tenant_id']],$ids)];
    }
    public function owner(int $id): array {
        if (!in_array($id,$this->ownerIds(),true)) throw new HttpError(404,'Conta não encontrada.');
        $u=$this->db->one('SELECT * FROM users WHERE tenant_id=? AND id=?',[$this->user['tenant_id'],$id]);
        if (!$u || !\App\Services\AccountValidity::available($this->db,$u)) throw new HttpError(422,'Conta indisponível ou com validade encerrada.'); return $u;
    }
    public function server(int $id, ?int $owner = null): array {
        $s=$this->db->one('SELECT * FROM servers WHERE tenant_id=? AND id=?',[$this->user['tenant_id'],$id]);
        if (!$s) throw new HttpError(404,'Servidor não encontrado.');
        $u=$owner===null ? $this->user : $this->owner($owner);
        if (!in_array($u['role'],['MASTER','ADMIN'],true)) {
            $allowed=$this->db->one('SELECT server_id FROM server_users WHERE tenant_id=? AND server_id=? AND user_id IN (?,?)',[$u['tenant_id'],$id,$u['id'],$u['parent_id'] ?: $u['id']]);
            if (!$allowed) throw new HttpError(403,'Servidor não autorizado para esta conta.');
        }
        return $s;
    }
}
