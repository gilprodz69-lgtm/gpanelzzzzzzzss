<?php
declare(strict_types=1);
namespace App\Services;
use App\Repositories\Database;
use App\Helpers\Crypto;
use App\Models\Catalog;
final class Worker
{
    public function __construct(private Database $db) {}
    public function runOne(): bool {
        $job=$this->db->transaction(function(){
            $j=$this->db->one("SELECT * FROM jobs WHERE status='pending' ORDER BY id LIMIT 1".($this->db->driver()==='mysql'?' FOR UPDATE SKIP LOCKED':''));
            if(!$j) return null;
            $this->db->query("UPDATE jobs SET status='running',attempts=attempts+1,started_at=? WHERE id=?",[time(),$j['id']]); return $j;
        });
        if(!$job) return false;
        try {
            $server=$this->db->one('SELECT * FROM servers WHERE tenant_id=? AND id=?',[$job['tenant_id'],$job['server_id']]);
            $payload=json_decode(Crypto::decrypt($job['payload']),true,32,JSON_THROW_ON_ERROR);
            $result=(new AgentClient($this->db))->call($server,$job['operation'],$payload,'job-'.hash('sha256',$job['tenant_id'].':'.$job['id']));
            $this->db->transaction(function() use($job,$result){
                $this->db->query("UPDATE jobs SET status='completed',result_json=?,finished_at=?,payload=? WHERE id=?",[json_encode($result),time(),Crypto::encrypt('{}'),$job['id']]);
                if(isset(Catalog::RESOURCES[$job['resource_type']??''])) {
                    $table=$job['resource_type']; $delete=str_starts_with($job['operation'],'delete_');
                    $this->db->query("UPDATE `$table` SET status=?,updated_at=?,deleted_at=? WHERE id=? AND tenant_id=?",[$delete?'deleted':'active',time(),$delete?time():null,$job['resource_id'],$job['tenant_id']]);
                    if($delete) $this->db->query("UPDATE `$table` SET name=? WHERE id=? AND tenant_id=?",['deleted-'.$job['resource_id'].'-'.bin2hex(random_bytes(5)),$job['resource_id'],$job['tenant_id']]);
                }
                $u=$this->db->one('SELECT * FROM users WHERE id=? AND tenant_id=?',[$job['owner_id'],$job['tenant_id']]);
                Audit::write($this->db,$u,'agent.'.$job['operation'],(string)$job['resource_id']);
                $this->notify($job,'Operação concluída',$job['operation']);
            });
        } catch(\Throwable $e) {
            // No automatic destructive retry: ambiguous outcomes require reconciliation by operator.
            $this->db->transaction(function() use($job,$e){
                $message=substr($e->getMessage(),0,500);
                $this->db->query("UPDATE jobs SET status='failed',error=?,finished_at=? WHERE id=?",[$message,time(),$job['id']]);
                if(isset(Catalog::RESOURCES[$job['resource_type']??''])) $this->db->query('UPDATE `'.$job['resource_type']."` SET status='failed',updated_at=? WHERE tenant_id=? AND id=?",[time(),$job['tenant_id'],$job['resource_id']]);
                $u=$this->db->one('SELECT * FROM users WHERE tenant_id=? AND id=?',[$job['tenant_id'],$job['owner_id']]); Audit::write($this->db,$u,'agent.'.$job['operation'],(string)$job['resource_id'],'failed');
                $this->notify($job,'Operação falhou',$job['operation'].': '.$message);
            });
        }
        return true;
    }
    private function notify(array $j,string $title,string $body): void { $this->db->insert('notifications',['tenant_id'=>$j['tenant_id'],'user_id'=>$j['owner_id'],'title'=>$title,'body'=>$body,'created_at'=>time()]); }
    public function collect(): void {
        foreach($this->db->all('SELECT * FROM servers') as $s) {
            try {
                $m=(new AgentClient($this->db))->call($s,'metrics',[],bin2hex(random_bytes(16)));
                $row=['tenant_id'=>$s['tenant_id'],'server_id'=>$s['id'],'created_at'=>time()];
                foreach(['cpu','ram','disk','load_avg','uptime','network_rx','network_tx'] as $key) { if(!isset($m[$key]) || !is_numeric($m[$key]) || $m[$key]<0) throw new \RuntimeException('Métrica inválida.'); $row[$key]=$m[$key]; }
                $this->db->insert('server_metrics',$row); $this->db->query("UPDATE servers SET status='online',last_seen=? WHERE id=?",[time(),$s['id']]);
                $thresholds=json_decode($this->db->scalar("SELECT value_json FROM settings WHERE tenant_id=? AND name='alerts'",[$s['tenant_id']])?:'{"cpu":90,"ram":90,"disk":85}',true);
                foreach(['cpu','ram','disk'] as $key) if($m[$key]>=($thresholds[$key]??90)) $this->alert($s,strtoupper($key).' acima do limite',round($m[$key],1).'% em '.$s['name']);
            } catch(\Throwable) { $this->db->query("UPDATE servers SET status='offline' WHERE id=?",[$s['id']]); $this->alert($s,'Servidor indisponível',$s['name']); }
        }
        $this->db->query('DELETE FROM server_metrics WHERE created_at<?',[time()-30*86400]);
        $this->db->query('DELETE FROM sessions WHERE expires_at<?',[time()]); $this->db->query('DELETE FROM rate_limits WHERE expires_at<?',[time()]);
    }
    private function alert(array $s,string $title,string $body): void {
        foreach($this->db->all("SELECT id FROM users WHERE tenant_id=? AND role='MASTER' AND status='active'",[$s['tenant_id']]) as $u) {
            if(!$this->db->one('SELECT id FROM notifications WHERE tenant_id=? AND user_id=? AND title=? AND body=? AND created_at>?',[$s['tenant_id'],$u['id'],$title,$body,time()-3600])) $this->db->insert('notifications',['tenant_id'=>$s['tenant_id'],'user_id'=>$u['id'],'title'=>$title,'body'=>$body,'created_at'=>time()]);
        }
    }
}
