<?php
declare(strict_types=1);
namespace App\Services;
use App\Repositories\Database;
use App\Middleware\Policy;
use App\Validators\Input;
use App\Models\Catalog;
use App\Helpers\HttpError;
final class AccountService
{
    public function __construct(private Database $db,private Policy $policy) {}
    public function users(): array {
        $this->policy->require('users.view'); [$where,$args]=$this->policy->scope('id');
        return array_map(Auth::publicUser(...),$this->db->all("SELECT * FROM users WHERE $where ORDER BY id DESC LIMIT 500",$args));
    }
    public function createUser(array $d): array {
        $this->policy->require('users.create');
        return $this->db->transaction(function() use($d) {
            $actor=$this->policy->user; $this->db->lockTenant((int)$actor['tenant_id']);
            $role=Input::choice($d['role']??'CLIENT',['MASTER','ADMIN','RESELLER','CLIENT'],'Perfil');
            if($role==='MASTER' || ($actor['role']!=='MASTER' && $role!=='CLIENT')) throw new HttpError(403,'Você só pode criar clientes subordinados.');
            $parent=$actor;
            if($this->policy->isAdmin() && isset($d['parent_id'])) $parent=$this->policy->owner(Input::integer($d['parent_id'],'Responsável'));
            if(!in_array($parent['role'],['MASTER','ADMIN','RESELLER'],true) || ($parent['role']==='RESELLER' && $role!=='CLIENT')) throw new HttpError(422,'Hierarquia inválida.');
            (new Quota($this->db))->check($parent,'users');
            $plan=$this->findPlan(Input::integer($d['plan_id']??null,'Plano'));
            if(!$this->policy->isAdmin() && $plan['owner_id']!==null && (int)$plan['owner_id']!==(int)$actor['id']) throw new HttpError(403,'Plano não autorizado.');
            $expires=$role==='RESELLER'?time()+Input::integer($d['validity_days']??30,'Validade em dias',1,365000)*86400:null;
            $id=$this->db->insert('users',['tenant_id'=>$actor['tenant_id'],'parent_id'=>$parent['id'],'plan_id'=>$plan['id'],'name'=>Input::text($d['name']??'','Nome'),'email'=>Input::email($d['email']??''),'password_hash'=>password_hash(Input::password($d['password']??''),PASSWORD_DEFAULT),'role'=>$role,'expires_at'=>$expires,'created_at'=>time()]);
            $rid=$this->db->scalar('SELECT id FROM roles WHERE name=?',[$role]); $this->db->insert('user_roles',['user_id'=>$id,'role_id'=>$rid]);
            Audit::write($this->db,$actor,'users.create',(string)$id); return ['id'=>$id,'message'=>'Conta criada.'];
        });
    }
    public function updateUser(int $id,array $d): array {
        $this->policy->require('users.edit'); $u=$this->db->one('SELECT * FROM users WHERE tenant_id=? AND id=?',[$this->policy->user['tenant_id'],$id]);
        if(!$u||!in_array($id,$this->policy->ownerIds(),true)) throw new HttpError(404,'Conta não encontrada.');
        if($id===(int)$this->policy->user['id'] || $u['role']==='MASTER' || (!$this->policy->isAdmin() && $u['role']!=='CLIENT')) throw new HttpError(403,'Esta conta não pode ser alterada por você.');
        $status=isset($d['status'])?Input::choice($d['status'],['active','suspended'],'Status'):null;
        $plan=isset($d['plan_id'])?$this->findPlan(Input::integer($d['plan_id'],'Plano')):null;
        $name=isset($d['name'])?Input::text($d['name'],'Nome'):null;
        $email=isset($d['email'])?Input::email($d['email']):null;
        $password=isset($d['password'])?password_hash(Input::password($d['password']),PASSWORD_DEFAULT):null;
        $expires=null;
        if(isset($d['validity_days'])||isset($d['renew_days'])) {
            if(!$this->policy->isAdmin()||$u['role']!=='RESELLER') throw new HttpError(403,'Somente a administração pode alterar a validade de revendedores.');
            if(isset($d['validity_days'],$d['renew_days'])) throw new HttpError(422,'Escolha alterar ou renovar a validade.');
            $days=Input::integer($d['renew_days']??$d['validity_days'],'Validade em dias',1,365000);
            $expires=(isset($d['renew_days'])?max(time(),(int)($u['expires_at']??0)):time())+$days*86400;
        }
        if(isset($d['permissions'])) {
            if($this->policy->user['role']!=='MASTER') throw new HttpError(403,'Somente MASTER pode configurar permissões individuais.');
            if(!is_array($d['permissions'])||array_diff(array_keys($d['permissions']),Catalog::permissions())) throw new HttpError(422,'Permissões inválidas.');
            foreach($d['permissions'] as $allowed) if(!is_bool($allowed)) throw new HttpError(422,'Use true ou false para permissões.');
        }
        $this->db->transaction(function() use($d,$id,$status,$plan,$name,$email,$password,$expires){
            $this->db->lockTenant((int)$this->policy->user['tenant_id']);
            if(isset($d['renew_days'])) $expires=max(time(),(int)$this->db->scalar('SELECT expires_at FROM users WHERE id=?',[$id]))+(int)$d['renew_days']*86400;
            if($status!==null) { $this->db->query('UPDATE users SET status=? WHERE id=?',[$status,$id]); if($status==='suspended') $this->db->query('DELETE FROM sessions WHERE user_id=?',[$id]); }
            if($plan!==null) $this->db->query('UPDATE users SET plan_id=? WHERE id=?',[$plan['id'],$id]);
            if($name!==null) $this->db->query('UPDATE users SET name=? WHERE id=?',[$name,$id]);
            if($email!==null) $this->db->query('UPDATE users SET email=? WHERE id=?',[$email,$id]);
            if($expires!==null) $this->db->query('UPDATE users SET expires_at=? WHERE id=?',[$expires,$id]);
            if($password!==null) {
                $this->db->query('UPDATE users SET password_hash=? WHERE id=?',[$password,$id]);
                foreach(['sessions','api_keys','password_resets'] as $table) $this->db->query("DELETE FROM $table WHERE user_id=?",[$id]);
            }
            foreach(($d['permissions']??[]) as $p=>$allowed) { $this->db->query('DELETE FROM user_permissions WHERE user_id=? AND permission=?',[$id,$p]); $this->db->insert('user_permissions',['user_id'=>$id,'permission'=>$p,'allowed'=>(int)$allowed]); }
            Audit::write($this->db,$this->policy->user,'users.edit',(string)$id);
        });
        return ['message'=>'Conta atualizada.'];
    }
    public function userDetails(int $id): array {
        $this->policy->require('users.view');
        if(!in_array($id,$this->policy->ownerIds(),true)) throw new HttpError(404,'Conta não encontrada.');
        $u=$this->db->one('SELECT * FROM users WHERE tenant_id=? AND id=?',[$this->policy->user['tenant_id'],$id]);
        if(!$u) throw new HttpError(404,'Conta não encontrada.');
        return ['user'=>Auth::publicUser($u),'plan_name'=>$this->db->scalar('SELECT name FROM plans WHERE tenant_id=? AND id=?',[$u['tenant_id'],$u['plan_id']]),'clients'=>(int)$this->db->scalar("SELECT COUNT(*) FROM users WHERE tenant_id=? AND parent_id=? AND role='CLIENT'",[$u['tenant_id'],$id]),'permissions'=>(new Policy($this->db,$u))->permissions(),'available_permissions'=>Catalog::permissions()];
    }
    public function plans(): array {
        $this->policy->require('plans.view'); $sql='SELECT * FROM plans WHERE tenant_id=?'; $args=[$this->policy->user['tenant_id']];
        if(!$this->policy->isAdmin()) { $sql.=' AND (owner_id=? OR id=?)'; $args[]=$this->policy->user['id']; $args[]=$this->policy->user['plan_id']; }
        return array_map(static function($p){$p['limits']=json_decode($p['limits_json'],true);$p['features']=json_decode($p['features_json'],true);unset($p['limits_json'],$p['features_json']);return $p;},$this->db->all($sql.' ORDER BY id DESC',$args));
    }
    public function findPlan(int $id): array {
        $p=$this->db->one('SELECT * FROM plans WHERE tenant_id=? AND id=?',[$this->policy->user['tenant_id'],$id]);
        if(!$p || (!$this->policy->isAdmin() && (int)$p['owner_id']!==(int)$this->policy->user['id'] && $id!==(int)$this->policy->user['plan_id'])) throw new HttpError(404,'Plano não encontrado.'); return $p;
    }
    public function savePlan(array $d,?int $id=null): array {
        $this->policy->require($id?'plans.edit':'plans.create');
        if($id) { $old=$this->findPlan($id); if(!$this->policy->isAdmin() && (int)$old['owner_id']!==(int)$this->policy->user['id']) throw new HttpError(403,'Plano da conta principal não pode ser alterado.'); }
        $limits=[];
        foreach(Catalog::QUOTAS as $key) $limits[$key]=Input::integer($d['limits'][$key]??0,"Limite $key",0,100000000);
        $features=$d['features']??[];
        if(!is_array($features)||array_diff($features,['docker_containers','ftp_accounts','ssl_certificates','backups','files','terminal'])) throw new HttpError(422,'Recursos do plano inválidos.');
        if(!$this->policy->isAdmin()) {
            $parent=(new Quota($this->db))->plan($this->policy->user); if(!$parent) throw new HttpError(409,'Conta sem plano.');
            foreach($limits as $key=>$value) if($value>(int)($parent['limits'][$key]??0)) throw new HttpError(422,'O subplano excede o limite da revenda.');
            if(array_diff($features,$parent['features'])) throw new HttpError(403,'Recursos não autorizados no plano principal.');
        }
        $values=['name'=>Input::text($d['name']??'','Nome'),'limits_json'=>json_encode($limits),'features_json'=>json_encode(array_values($features)),'price_cents'=>Input::integer($d['price_cents']??0,'Preço',0)];
        if($id) $this->db->query('UPDATE plans SET name=?,limits_json=?,features_json=?,price_cents=? WHERE id=? AND tenant_id=?',array_merge(array_values($values),[$id,$this->policy->user['tenant_id']]));
        else $id=$this->db->insert('plans',array_merge($values,['tenant_id'=>$this->policy->user['tenant_id'],'owner_id'=>$this->policy->user['id'],'created_at'=>time()]));
        Audit::write($this->db,$this->policy->user,'plans.save',(string)$id); return ['id'=>$id,'message'=>'Plano salvo.'];
    }
}
