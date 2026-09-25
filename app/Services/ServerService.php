<?php
declare(strict_types=1);
namespace App\Services;
use App\Repositories\Database;
use App\Middleware\Policy;
use App\Helpers\{HttpError,Crypto};
use App\Validators\Input;
final class ServerService
{
    public function __construct(private Database $db,private Policy $policy) {}
    public function list(bool $picker=false): array {
        if(!$picker) $this->policy->require('servers.view');
        $all=$this->db->all('SELECT * FROM servers WHERE tenant_id=? ORDER BY id DESC',[$this->policy->user['tenant_id']]); $out=[];
        foreach($all as $s) {
            try { $this->policy->server((int)$s['id']); } catch(HttpError) { continue; }
            if($picker) $out[]=['id'=>$s['id'],'name'=>$s['name']];
            else { $s['metrics']=$this->db->one('SELECT * FROM server_metrics WHERE tenant_id=? AND server_id=? ORDER BY id DESC LIMIT 1',[$s['tenant_id'],$s['id']]); unset($s['agent_url']); if($s['last_seen'] && (int)$s['last_seen']<time()-45) $s['status']='offline'; $out[]=$s; }
        }
        return $out;
    }
    public function create(array $d): array {
        $this->policy->require('servers.create'); if(!$this->policy->isAdmin()) throw new HttpError(403,'Operação administrativa.');
        $url=Input::text($d['agent_url']??'','URL do agente',10,255); $parts=parse_url($url);
        $local=getenv('AGENT_ALLOW_HTTP_LOOPBACK')==='1' && in_array($parts['host']??'',['127.0.0.1','localhost'],true);
        if(!$parts || (!$local && ($parts['scheme']??'')!=='https') || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path']??'',['','/'],true)) throw new HttpError(422,'Use https://host:porta sem caminho ou credenciais.');
        $token=Input::text($d['agent_secret']??'','Segredo do agente',32,128);
        return $this->db->transaction(function() use($d,$url,$token) {
            $id=$this->db->insert('servers',['tenant_id'=>$this->policy->user['tenant_id'],'name'=>Input::text($d['name']??'','Nome'),'address'=>Input::text($d['address']??'','IP ou hostname'),'agent_url'=>rtrim($url,'/'),'os'=>Input::text($d['os']??'Ubuntu 24.04','Sistema operacional'),'created_at'=>time()]);
            $this->db->insert('server_credentials',['server_id'=>$id,'secret'=>Crypto::encrypt($token)]);
            Audit::write($this->db,$this->policy->user,'servers.create',(string)$id); return ['id'=>$id,'message'=>'Servidor cadastrado. Aguardando a primeira coleta.'];
        });
    }
    public function details(int $id): array {
        $this->policy->require('servers.edit');
        if(!$this->policy->isAdmin()) throw new HttpError(403,'Operação administrativa.');
        return ['data'=>$this->policy->server($id)];
    }
    public function update(int $id,array $d): array {
        $this->policy->require('servers.edit');
        if(!$this->policy->isAdmin()) throw new HttpError(403,'Operação administrativa.');
        $current=$this->policy->server($id);
        $name=Input::text($d['name']??$current['name'],'Nome');
        $address=Input::text($d['address']??$current['address'],'IP ou hostname',1,190);
        if(!filter_var($address,FILTER_VALIDATE_IP)) $address=Input::domain($address);
        $os=Input::text($d['os']??$current['os'],'Sistema operacional',1,80);
        $url=Input::text($d['agent_url']??$current['agent_url'],'URL do agente',10,255);
        $parts=parse_url($url);
        $local=getenv('AGENT_ALLOW_HTTP_LOOPBACK')==='1' && in_array($parts['host']??'',['127.0.0.1','localhost'],true);
        if(!$parts || !in_array($parts['scheme']??'',$local?['http','https']:['https'],true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path']??'',['','/'],true)) throw new HttpError(422,'Use https://host:porta sem caminho ou credenciais.');
        $url=rtrim($url,'/');
        $secret=null;
        if(array_key_exists('agent_secret',$d) && $d['agent_secret']!=='') $secret=Input::text($d['agent_secret'],'Segredo do agente',32,128);
        return $this->db->transaction(function() use($id,$current,$name,$address,$os,$url,$secret){
            $this->db->lockTenant((int)$this->policy->user['tenant_id']);
            $connectionChanged=$url!==$current['agent_url'] || $secret!==null;
            if($connectionChanged && $this->db->scalar("SELECT COUNT(*) FROM jobs WHERE tenant_id=? AND server_id=? AND status IN ('pending','running')",[$current['tenant_id'],$id])) throw new HttpError(409,'Aguarde as operações do servidor antes de alterar a conexão do agente.');
            $this->db->query('UPDATE servers SET name=?,address=?,os=?,agent_url=? WHERE tenant_id=? AND id=?',[$name,$address,$os,$url,$current['tenant_id'],$id]);
            if($secret!==null) $this->db->query('UPDATE server_credentials SET secret=? WHERE server_id=?',[Crypto::encrypt($secret),$id]);
            if($connectionChanged) $this->db->query("UPDATE servers SET status='unknown',last_seen=NULL WHERE id=?",[$id]);
            Audit::write($this->db,$this->policy->user,'servers.update',(string)$id);
            return ['message'=>'Servidor atualizado.'.($connectionChanged?' Aguardando a próxima coleta para validar a conexão.':'')];
        });
    }
    public function grant(int $id,array $d): array {
        $this->policy->require('servers.edit'); if(!$this->policy->isAdmin()) throw new HttpError(403,'Operação administrativa.');
        $this->policy->server($id); $u=$this->policy->owner(Input::integer($d['user_id']??null,'Conta'));
        if(($d['allowed']??true)===false) $this->db->query('DELETE FROM server_users WHERE server_id=? AND user_id=?',[$id,$u['id']]);
        elseif(!$this->db->one('SELECT server_id FROM server_users WHERE server_id=? AND user_id=?',[$id,$u['id']])) $this->db->insert('server_users',['tenant_id'=>$u['tenant_id'],'server_id'=>$id,'user_id'=>$u['id']]);
        Audit::write($this->db,$this->policy->user,'servers.grant',"$id:{$u['id']}"); return ['message'=>'Acesso ao servidor atualizado.'];
    }
    public function operation(int $id,array $d): array {
        $this->policy->require('services.manage'); if(!$this->policy->isAdmin()) throw new HttpError(403,'Operação administrativa.'); $this->policy->server($id);
        $service=Input::choice($d['service']??'',['nginx','php7.4-fpm','php8.0-fpm','php8.1-fpm','php8.2-fpm','php8.3-fpm','php8.4-fpm','mariadb','redis-server','docker','cron'],'Serviço');
        $action=Input::choice($d['action']??'',['start','stop','restart'],'Ação');
        $job=(new Jobs($this->db))->enqueue($this->policy->user,$id,'service_action',['service'=>$service,'action'=>$action]);
        Audit::write($this->db,$this->policy->user,'services.'.$action,$service,'queued'); return ['job_id'=>$job,'message'=>'Operação adicionada à fila.'];
    }
}
