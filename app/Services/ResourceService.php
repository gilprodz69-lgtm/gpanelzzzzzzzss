<?php
declare(strict_types=1);
namespace App\Services;
use App\Repositories\Database;
use App\Middleware\Policy;
use App\Validators\Input;
use App\Helpers\HttpError;
use App\Models\Catalog;
final class ResourceService
{
    public function __construct(private Database $db,private Policy $policy) {}
    public function list(string $kind): array {
        $this->kind($kind); $this->policy->require("$kind.view"); [$where,$args]=$this->policy->scope();
        return array_map($this->present(...),$this->db->all("SELECT * FROM `$kind` WHERE $where AND deleted_at IS NULL ORDER BY id DESC LIMIT 500",$args));
    }
    public function find(string $kind,int $id): array {
        $this->kind($kind); [$where,$args]=$this->policy->scope();
        $r=$this->db->one("SELECT * FROM `$kind` WHERE $where AND id=? AND deleted_at IS NULL",array_merge($args,[$id]));
        if(!$r) throw new HttpError(404,'Recurso não encontrado.'); return $r;
    }
    private function kind(string $kind): void { if(!isset(Catalog::RESOURCES[$kind])) throw new HttpError(404,'Módulo não encontrado.'); }
    public function present(array $r): array { $r['config']=json_decode($r['config_json'],true); unset($r['config_json']); return $r; }
    public function create(string $kind,array $data): array {
        $this->kind($kind); $this->policy->require("$kind.create");
        if($kind==='firewall_rules' && !$this->policy->isAdmin()) throw new HttpError(403,'Firewall é uma configuração do servidor, exclusiva da administração.');
        return $this->db->transaction(function() use($kind,$data) {
            $this->db->lockTenant((int)$this->policy->user['tenant_id']);
            $owner=$this->policy->owner(Input::integer($data['owner_id']??$this->policy->user['id'],'Proprietário'));
            $server=$this->policy->server(Input::integer($data['server_id']??null,'Servidor'));
            $this->policy->server((int)$server['id'],(int)$owner['id']);
            if($kind!=='firewall_rules') (new Quota($this->db))->check($owner,$kind);
            [$name,$config,$secret]=$this->validate($kind,$data,$owner,(int)$server['id']);
            $now=time();
            $id=$this->db->insert($kind,['tenant_id'=>$owner['tenant_id'],'owner_id'=>$owner['id'],'server_id'=>$server['id'],'name'=>$name,'config_json'=>json_encode($config,JSON_THROW_ON_ERROR),'created_at'=>$now,'updated_at'=>$now]);
            $payload=array_merge($config,$secret,['tenant_id'=>(int)$owner['tenant_id'],'owner_id'=>(int)$owner['id'],'resource_id'=>$id]);
            $job=(new Jobs($this->db))->enqueue($owner,(int)$server['id'],Catalog::RESOURCES[$kind]['operation'],$payload,$kind,$id);
            Audit::write($this->db,$this->policy->user,"$kind.create",$name,'queued');
            return ['id'=>$id,'job_id'=>$job,'status'=>'pending','message'=>'Operação adicionada à fila do agente.'];
        });
    }
    private function validate(string $kind,array $d,array $owner,int $server): array {
        $c=[]; $secret=[];
        if(isset($d['website_id'])) {
            $site=$this->find('websites',Input::integer($d['website_id'],'Site'));
            if((int)$site['owner_id']!==(int)$owner['id'] || (int)$site['server_id']!==$server || $site['status']!=='active') throw new HttpError(422,'O site deve estar ativo e pertencer à conta e ao servidor selecionados.');
            $c=['website_id'=>(int)$site['id'],'domain'=>$site['name']];
        }
        if(in_array($kind,['domains','ssl_certificates','ftp_accounts','backups','cron_jobs'],true) && !isset($c['website_id'])) throw new HttpError(422,'Selecione um site ativo.');
        switch($kind) {
            case 'websites': $name=filter_var($d['domain']??'',FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)?$d['domain']:Input::domain($d['domain']??''); if(filter_var($name,FILTER_VALIDATE_IP) && (!$this->policy->isAdmin() || $name!==$this->policy->server($server)['address'])) throw new HttpError(422,'O site por IP deve usar o endereço deste servidor e ser criado pelo administrador.'); $c=['domain'=>$name,'php_version'=>Input::choice($d['php_version']??'8.3',\App\Models\PhpVersions::ALL,'PHP'),'email'=>$owner['email']]; break;
            case 'domains': $name=Input::domain($d['domain']??''); $c['alias']=$name; $c['type']=Input::choice($d['type']??'alias',['alias','redirect','parked'],'Tipo'); if($c['type']==='redirect') $c['target']=Input::domain($d['target']??''); break;
            case 'databases': $label=Input::identifier($d['name']??''); $name='u'.$owner['id'].'_'.$label; $c=['name'=>$name]; $secret['password']=Input::password($d['password']??''); break;
            case 'ssl_certificates': $name=$c['domain']; $c['email']=Input::email($d['email']??$owner['email']); break;
            case 'ftp_accounts': $name='s'.$owner['id'].'_'.Input::identifier($d['name']??''); if(strlen($name)>30) throw new HttpError(422,'Nome de conta muito longo.'); $c['name']=$name; $secret['password']=Input::password($d['password']??''); break;
            case 'backups':
                if(isset($d['schedule_id'])) {
                    $schedule=$this->db->one('SELECT id FROM backup_schedules WHERE tenant_id=? AND owner_id=? AND website_id=? AND id=?',[$owner['tenant_id'],$owner['id'],$c['website_id'],Input::integer($d['schedule_id'],'Agendamento')]);
                    if(!$schedule) throw new HttpError(422,'Agendamento não autorizado.');
                    $c['schedule_id']=(int)$schedule['id'];
                }
                $name=$c['domain'].'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)); break;
            case 'cron_jobs': $schedule=Input::text($d['schedule']??'','Agendamento',9,80); if(!preg_match('/^[0-9*,\/-]+(?: [0-9*,\/-]+){4}$/D',$schedule)) throw new HttpError(422,'Use cinco campos cron numéricos.'); $path=Input::text($d['path']??'','Script',5,190); if(!preg_match('/^[a-zA-Z0-9_\/-]+\.php$/D',$path)||str_contains($path,'..')||str_starts_with($path,'/')) throw new HttpError(422,'Use um caminho PHP relativo ao site.'); $c['schedule']=$schedule; $c['path']=$path; $name=$c['domain'].'-'.bin2hex(random_bytes(4)); break;
            case 'firewall_rules': $c['port']=Input::integer($d['port']??null,'Porta',1,65535); if(in_array($c['port'],[22,80,443,9443],true)) throw new HttpError(422,'Porta administrativa ou essencial protegida.'); $c['protocol']=Input::choice($d['protocol']??'tcp',['tcp','udp'],'Protocolo'); $c['source']=Input::text($d['source']??'any','Origem'); if($c['source']!=='any'&&!filter_var($c['source'],FILTER_VALIDATE_IP)) throw new HttpError(422,'Use um IP ou any.'); $c['action']=Input::choice($d['action']??'allow',['allow','deny'],'Ação'); $name=implode('-',array_values($c)); break;
            case 'docker_containers': $name='u'.$owner['id'].'_'.Input::identifier($d['name']??''); $image=Input::text($d['image']??'','Imagem',3,190); if(!preg_match('/^[a-z0-9][a-z0-9._\/-]*:[a-zA-Z0-9_.-]+$/D',$image)) throw new HttpError(422,'Informe imagem:tag, sem opções adicionais.'); $c=['name'=>$name,'image'=>$image,'memory_mb'=>Input::integer($d['memory_mb']??256,'RAM',64,4096),'cpu'=>Input::integer($d['cpu']??1,'CPU',1,4)]; break;
            default: throw new HttpError(422,'Recurso inválido.');
        }
        return [$name,$c,$secret];
    }
    public function delete(string $kind,int $id): array {
        $this->kind($kind); $this->policy->require("$kind.delete");
        return $this->db->transaction(function() use($kind,$id) {
            $this->db->lockTenant((int)$this->policy->user['tenant_id']); $r=$this->find($kind,$id);
            if(!in_array($r['status'],['active','failed'],true)) throw new HttpError(409,'Aguarde a operação atual terminar.');
            if($kind==='websites' && $this->db->one('SELECT id FROM backup_schedules WHERE tenant_id=? AND website_id=?',[$r['tenant_id'],$id])) throw new HttpError(409,'Remova o agendamento de backup antes de excluir o site.');
            if($kind==='websites') foreach(['domains','ssl_certificates','ftp_accounts','backups','cron_jobs'] as $child) {
                foreach($this->db->all("SELECT config_json FROM `$child` WHERE tenant_id=? AND deleted_at IS NULL",[$r['tenant_id']]) as $row) if((int)(json_decode($row['config_json'],true)['website_id']??0)===$id) throw new HttpError(409,'Remova os recursos vinculados ao site antes de excluí-lo.');
            }
            $config=json_decode($r['config_json'],true); $payload=array_merge($config,['tenant_id'=>(int)$r['tenant_id'],'owner_id'=>(int)$r['owner_id'],'resource_id'=>$id]);
            $job=(new Jobs($this->db))->enqueue($this->policy->owner((int)$r['owner_id']),(int)$r['server_id'],'delete_'.substr(Catalog::RESOURCES[$kind]['operation'],7),$payload,$kind,$id);
            $this->db->query("UPDATE `$kind` SET status='deleting',updated_at=? WHERE id=?",[time(),$id]);
            Audit::write($this->db,$this->policy->user,"$kind.delete",$r['name'],'queued'); return ['job_id'=>$job,'message'=>'Exclusão adicionada à fila.'];
        });
    }
}
