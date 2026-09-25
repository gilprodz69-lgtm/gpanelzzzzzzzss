<?php
declare(strict_types=1);
namespace App\Services;
use App\Repositories\Database;
use App\Middleware\Policy;
use App\Validators\Input;
use App\Helpers\HttpError;
final class BackupScheduler
{
    public function __construct(private Database $db) {}
    public function list(Policy $policy): array {
        $policy->require('backups.view'); [$where,$args]=$policy->scope();
        return $this->db->all("SELECT * FROM backup_schedules WHERE $where ORDER BY id DESC",$args);
    }
    public function create(Policy $policy,array $data): array {
        $policy->require('backups.create');
        $site=(new ResourceService($this->db,$policy))->find('websites',Input::integer($data['website_id']??null,'Site'));
        if($site['status']!=='active') throw new HttpError(409,'O site precisa estar ativo.');
        $owner=$policy->owner((int)$site['owner_id']);
        $retention=Input::integer($data['retention']??7,'Retenção',1,30);
        foreach((new Quota($this->db))->chain($owner) as $u) if($u['role']!=='MASTER') {
            $plan=(new Quota($this->db))->plan($u);
            if(!in_array('backups',$plan['features']??[],true)) throw new HttpError(403,'Backups não permitidos no plano.');
            if($retention>(int)($plan['limits']['backups']??0)) throw new HttpError(422,'Retenção excede o limite de backups do plano.');
        }
        $id=$this->db->insert('backup_schedules',['tenant_id'=>$site['tenant_id'],'owner_id'=>$site['owner_id'],'server_id'=>$site['server_id'],'website_id'=>$site['id'],'frequency'=>Input::choice($data['frequency']??'daily',['daily','weekly','monthly'],'Frequência'),'retention'=>$retention,'next_run'=>time(),'created_at'=>time()]);
        Audit::write($this->db,$policy->user,'backups.schedule',(string)$id);
        return ['id'=>$id,'message'=>'Agendamento criado. A primeira cópia será solicitada na próxima execução do agendador.'];
    }
    public function delete(Policy $policy,int $id): array {
        $policy->require('backups.delete'); [$where,$args]=$policy->scope();
        if($this->db->query("DELETE FROM backup_schedules WHERE $where AND id=?",array_merge($args,[$id]))->rowCount()!==1) throw new HttpError(404,'Agendamento não encontrado.');
        Audit::write($this->db,$policy->user,'backups.unschedule',(string)$id); return ['message'=>'Agendamento removido; cópias existentes foram preservadas.'];
    }
    public function tick(): void {
        $due=$this->db->all("SELECT id FROM backup_schedules WHERE status='active' AND next_run<=?",[time()]);
        foreach($due as $candidate) {
            $s=$this->db->transaction(function() use($candidate){
                $s=$this->db->one("SELECT * FROM backup_schedules WHERE id=? AND status='active' AND next_run<=?".($this->db->driver()==='mysql'?' FOR UPDATE':''),[$candidate['id'],time()]);
                if(!$s) return null;
                $this->db->query('UPDATE backup_schedules SET next_run=? WHERE id=?',[time()+300,$s['id']]); return $s;
            });
            if(!$s) continue;
            try {
                $u=$this->db->one("SELECT * FROM users WHERE tenant_id=? AND id=? AND status='active'",[$s['tenant_id'],$s['owner_id']]);
                if(!$u) throw new HttpError(409,'Conta suspensa ou indisponível.');
                if($u['parent_id'] && $this->db->scalar('SELECT status FROM users WHERE tenant_id=? AND id=?',[$u['tenant_id'],$u['parent_id']])!=='active') throw new HttpError(409,'Conta principal suspensa.');
                $policy=new Policy($this->db,$u); $resources=new ResourceService($this->db,$policy);
                $copies=array_values(array_filter($resources->list('backups'),static fn($r)=>(int)($r['config']['schedule_id']??0)===(int)$s['id']));
                if(count($copies)>=(int)$s['retention']) {
                    $oldest=end($copies);
                    if(in_array($oldest['status'],['active','failed'],true)) $resources->delete('backups',(int)$oldest['id']);
                    $this->db->query('UPDATE backup_schedules SET next_run=? WHERE id=?',[time()+60,$s['id']]); continue;
                }
                $resources->create('backups',['server_id'=>(int)$s['server_id'],'owner_id'=>(int)$s['owner_id'],'website_id'=>(int)$s['website_id'],'schedule_id'=>(int)$s['id']]);
                $next=match($s['frequency']){'weekly'=>strtotime('+7 days'),'monthly'=>strtotime('first day of next month 02:00 UTC'),default=>strtotime('+1 day')};
                $this->db->query('UPDATE backup_schedules SET next_run=?,last_error=NULL WHERE id=?',[$next,$s['id']]);
            } catch(\Throwable $e) { $this->db->query('UPDATE backup_schedules SET last_error=? WHERE id=?',[substr($e->getMessage(),0,300),$s['id']]); }
        }
    }
}
