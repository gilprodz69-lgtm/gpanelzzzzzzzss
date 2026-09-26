<?php
declare(strict_types=1);
namespace App\Services;
use App\Repositories\Database;
use App\Middleware\Policy;
use App\Validators\Input;
use App\Helpers\HttpError;

final class DomainService
{
    public function __construct(private Database $db,private Policy $policy) {}
    public function update(int $id,array $data): array {
        $this->policy->require('domains.edit');
        if(array_diff(array_keys($data),['domain','target'])) throw new HttpError(422,'Edite somente o domínio e o destino.');
        return $this->db->transaction(function()use($id,$data){
            $this->db->lockTenant((int)$this->policy->user['tenant_id']);
            $resources=new ResourceService($this->db,$this->policy);$r=$resources->find('domains',$id);$c=json_decode($r['config_json'],true);
            if($r['status']!=='active' && !($r['status']==='failed' && isset($c['pending_update']))) throw new HttpError(409,'Aguarde a operação atual do domínio.');
            $site=$resources->find('websites',(int)$c['website_id']);
            if($site['status']!=='active') throw new HttpError(409,'Aguarde a operação atual do site.');
            $this->policy->server((int)$r['server_id'],(int)$r['owner_id']);
            $owner=$this->policy->owner((int)$r['owner_id']);
            $name=Input::domain($data['domain']??$r['name']);
            if(strlen($name)>190) throw new HttpError(422,'Domínio muito longo.');
            $target=$c['type']==='redirect'?Input::domain($data['target']??$c['target']):null;
            if($target===$name) throw new HttpError(422,'O destino deve ser diferente do domínio.');
            if($name!==$r['name']) {
                WebsiteService::available($this->db,(int)$r['server_id'],$name,$id,'domains');
                foreach($this->db->all('SELECT config_json FROM domains WHERE server_id=? AND deleted_at IS NULL',[$r['server_id']]) as $row) if((json_decode($row['config_json'],true)['parent_domain']??null)===$r['name']) throw new HttpError(409,'Este domínio tem subdomínios vinculados. Mantenha seu endereço ou remova os vínculos primeiro.');
                if($c['type']==='subdomain') {
                    $suffix='.'.($c['parent_domain']??'');
                    if(!str_ends_with($name,$suffix)||str_contains(substr($name,0,-strlen($suffix)),'.')) throw new HttpError(422,'O subdomínio deve manter o domínio principal selecionado na criação.');
                }
            }
            if($r['status']==='active'&&$name===$r['name']&&$target===($c['target']??null)) return ['message'=>'Nenhuma alteração necessária.'];
            $payload=['tenant_id'=>(int)$r['tenant_id'],'owner_id'=>(int)$r['owner_id'],'resource_id'=>$id,'website_id'=>(int)$c['website_id'],'alias'=>$name,'previous_domain'=>$r['name'],'type'=>$c['type'],'target'=>$target,'email'=>$owner['email']];
            $job=(new Jobs($this->db))->enqueue($owner,(int)$r['server_id'],'update_domain',$payload,'domains',$id);
            $c['pending_update']=['domain'=>$name,'target'=>$target];
            $this->db->query("UPDATE domains SET config_json=?,status='pending',updated_at=? WHERE tenant_id=? AND id=?",[json_encode($c),time(),$r['tenant_id'],$id]);
            Audit::write($this->db,$this->policy->user,'domains.update',$r['name'],'queued');
            return ['job_id'=>$job,'message'=>'Alteração do domínio enviada ao servidor.'];
        });
    }
}
