<?php
declare(strict_types=1);
namespace App\Services;
use App\Repositories\Database;
use App\Middleware\Policy;
use App\Validators\Input;
use App\Helpers\HttpError;
final class PluginStore
{
    public function __construct(private Database $db,private Policy $policy) {}
    public function listing(): array {
        $this->policy->require('websites.view');[$scope,$args]=$this->policy->scope();
        $rows=$this->db->all("SELECT * FROM plugin_installations WHERE $scope ORDER BY id DESC LIMIT 100",$args);
        foreach($rows as &$r){
            $site=$this->db->one('SELECT name,deleted_at FROM websites WHERE tenant_id=? AND id=?',[$r['tenant_id'],$r['website_id']]);
            $job=$r['job_id']?$this->db->one('SELECT status,error,result_json FROM jobs WHERE tenant_id=? AND id=?',[$r['tenant_id'],$r['job_id']]):null;
            $result=$job?json_decode($job['result_json']??'{}',true):[];
            $r['site']=$site['name']??'Site removido';$r['status']=$job['status']??$r['status'];$r['error']=$job['error']??null;
            $r['version']=$result['version']??null;$r['url']=$site&&!$site['deleted_at']?'https://'.$site['name']:null;
        }
        return ['catalog'=>[['id'=>'wordpress','name'=>'WordPress','category'=>'Sites e blogs','description'=>'Crie sites, blogs e lojas com temas e extensões do WordPress.','price'=>'Grátis','source'=>'https://wordpress.org/','minimum_php'=>'8.3']],'installations'=>$rows];
    }
    public function install(array $data): array {
        foreach(['websites.view','websites.edit','files.manage','databases.create'] as $p)$this->policy->require($p);
        Input::choice($data['plugin']??'',['wordpress'],'Plugin');
        return $this->db->transaction(function()use($data){
            $this->db->lockTenant((int)$this->policy->user['tenant_id']);$resources=new ResourceService($this->db,$this->policy);
            $site=$resources->find('websites',Input::integer($data['website_id']??null,'Site'));
            if($site['status']!=='active') throw new HttpError(422,'Escolha um site ativo.');
            $owner=$this->policy->owner((int)$site['owner_id']);$this->policy->server((int)$site['server_id'],(int)$owner['id']);
            $config=json_decode($site['config_json'],true);
            if(!in_array($config['php_version']??'',['8.3','8.4'],true))throw new HttpError(422,'Para instalar WordPress, configure este site com PHP 8.3 ou 8.4.');
            if($this->db->one("SELECT id FROM plugin_installations WHERE tenant_id=? AND website_id=? AND status IN ('pending','completed')",[$site['tenant_id'],$site['id']]))throw new HttpError(409,'Este site já possui uma instalação ou operação em andamento.');
            $login='admin_'.bin2hex(random_bytes(5));
            $payload=['tenant_id'=>(int)$site['tenant_id'],'owner_id'=>(int)$site['owner_id'],'website_id'=>(int)$site['id'],
                'title'=>substr($site['name'],0,100),'admin_user'=>$login,'admin_email'=>Input::email($data['admin_email']??''),
                'admin_password'=>Input::password($data['admin_password']??'')];
            (new Quota($this->db))->check($owner,'databases');
            $name='u'.$owner['id'].'_wp_'.bin2hex(random_bytes(5));Input::identifier($name);
            $database=$this->db->insert('databases',['tenant_id'=>$site['tenant_id'],'owner_id'=>$owner['id'],'server_id'=>$site['server_id'],'name'=>$name,'config_json'=>json_encode(['username'=>$name]),'created_at'=>time(),'updated_at'=>time()]);
            $payload+=['database_id'=>$database,'database_name'=>$name,'database_username'=>$name,'database_password'=>bin2hex(random_bytes(24))];
            $id=$this->db->insert('plugin_installations',['tenant_id'=>$site['tenant_id'],'owner_id'=>$owner['id'],'website_id'=>$site['id'],'database_id'=>$database,'plugin'=>'wordpress','status'=>'pending','created_at'=>time()]);
            $payload['installation_id']=$id;$job=(new Jobs($this->db))->enqueue($owner,(int)$site['server_id'],'install_wordpress',$payload);
            $this->db->query('UPDATE plugin_installations SET job_id=? WHERE id=?',[$job,$id]);Audit::write($this->db,$this->policy->user,'plugins.install',$site['name'],'queued');
            return ['job_id'=>$job,'message'=>'Instalação do WordPress iniciada. Acompanhe o resultado na loja.'];
        });
    }
}
