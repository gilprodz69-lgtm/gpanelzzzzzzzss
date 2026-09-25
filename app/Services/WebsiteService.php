<?php
declare(strict_types=1);
namespace App\Services;
use App\Repositories\Database;
use App\Middleware\Policy;
use App\Validators\Input;
use App\Helpers\HttpError;
use App\Models\PhpVersions;

final class WebsiteService
{
    public function __construct(private Database $db,private Policy $policy) {}

    public static function available(Database $db,int $server,string $host,?int $except=null): void {
        foreach(['websites','domains'] as $table) foreach($db->all("SELECT id,name,config_json FROM `$table` WHERE server_id=? AND deleted_at IS NULL",[$server]) as $r) {
            if($table==='websites' && (int)$r['id']===$except) continue;
            $config=json_decode($r['config_json'],true);
            if($r['name']===$host || ($config['pending_update']['domain']??null)===$host) throw new HttpError(409,'Este endereço já está cadastrado ou reservado no servidor.');
        }
    }

    public function update(int $id,array $data): array {
        $this->policy->require('websites.edit');
        if(array_diff(array_keys($data),['domain','php_version'])) throw new HttpError(422,'Edite somente o domínio e a versão PHP neste formulário.');
        return $this->db->transaction(function()use($id,$data){
            $this->db->lockTenant((int)$this->policy->user['tenant_id']);
            $r=(new ResourceService($this->db,$this->policy))->find('websites',$id);
            $config=json_decode($r['config_json'],true);
            if($r['status']!=='active' && !($r['status']==='failed' && isset($config['pending_update']))) throw new HttpError(409,'Aguarde a operação atual do site. Consulte Operações se houve uma falha.');
            $owner=$this->policy->owner((int)$r['owner_id']);
            $server=$this->policy->server((int)$r['server_id'],(int)$r['owner_id']);
            $host=$data['domain']??$r['name'];
            if(filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) {
                if($host!==$r['name'] && (!$this->policy->isAdmin() || $host!==$server['address'])) throw new HttpError(422,'Somente a administração pode usar o IP deste servidor como endereço do site.');
            } else $host=Input::domain($host);
            if(strlen($host)>190) throw new HttpError(422,'Domínio muito longo (máximo de 190 caracteres).');
            $version=Input::choice($data['php_version']??$config['php_version'],PhpVersions::ALL,'PHP');
            self::available($this->db,(int)$r['server_id'],$host,$id);
            if($r['status']==='active' && $host===$r['name'] && $version===$config['php_version']) return ['message'=>'Nenhuma alteração necessária.'];
            foreach($this->db->all("SELECT resource_type,resource_id FROM jobs WHERE tenant_id=? AND server_id=? AND status IN ('pending','running')",[$r['tenant_id'],$r['server_id']]) as $job) {
                // Related provisioning must finish before changing the site's configuration.
                if(!in_array($job['resource_type'],['domains','ssl_certificates','cron_jobs','ftp_accounts','backups'],true)) continue;
                $child=$this->db->scalar('SELECT config_json FROM `'.$job['resource_type'].'` WHERE id=? AND tenant_id=?',[$job['resource_id'],$r['tenant_id']]);
                if((int)(json_decode($child?:'{}',true)['website_id']??0)===$id) throw new HttpError(409,'Aguarde as operações vinculadas a este site terminarem.');
            }
            $payload=['tenant_id'=>(int)$r['tenant_id'],'owner_id'=>(int)$r['owner_id'],'resource_id'=>$id,'domain'=>$host,'previous_domain'=>$r['name'],'php_version'=>$version,'email'=>$owner['email']];
            $job=(new Jobs($this->db))->enqueue($owner,(int)$r['server_id'],'update_site',$payload,'websites',$id);
            $config['pending_update']=['domain'=>$host,'php_version'=>$version];
            $this->db->query("UPDATE websites SET status='pending',config_json=?,updated_at=? WHERE id=? AND tenant_id=?",[json_encode($config),time(),$id,$r['tenant_id']]);
            Audit::write($this->db,$this->policy->user,'websites.update',$r['name'],'queued');
            return ['job_id'=>$job,'message'=>'Alteração enviada ao servidor. Acompanhe em Operações.'];
        });
    }

    public static function complete(Database $db,array $payload,array $result): void {
        $id=$payload['resource_id']; $tenant=$payload['tenant_id'];
        $config=json_decode($db->scalar('SELECT config_json FROM websites WHERE id=? AND tenant_id=?',[$id,$tenant]),true);
        unset($config['pending_update']); $config['domain']=$payload['domain']; $config['php_version']=$payload['php_version'];
        $db->query('UPDATE websites SET name=?,config_json=? WHERE id=? AND tenant_id=?',[$payload['domain'],json_encode($config),$id,$tenant]);
        foreach(['domains','ssl_certificates','cron_jobs','ftp_accounts','backups'] as $table) foreach($db->all("SELECT id,config_json FROM `$table` WHERE tenant_id=? AND deleted_at IS NULL",[$tenant]) as $r) {
            $c=json_decode($r['config_json'],true);
            if((int)($c['website_id']??0)!==$id) continue;
            $c['domain']=$payload['domain'];
            if($table==='ssl_certificates' && $payload['domain']!==$payload['previous_domain']) {
                $c['trusted']=$result['trusted']??false;
                $db->query('UPDATE ssl_certificates SET name=? WHERE id=? AND tenant_id=?',[$payload['domain'],$r['id'],$tenant]);
            }
            $db->query("UPDATE `$table` SET config_json=?,updated_at=? WHERE id=? AND tenant_id=?",[json_encode($c),time(),$r['id'],$tenant]);
        }
    }
}
