<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Repositories\Database;
use App\Middleware\Policy;
use App\Services\{Auth,AccountService,ServerService,ResourceService,Quota,Audit,Totp,Jobs,AgentClient,BackupScheduler};
use App\Models\Catalog;
use App\Helpers\{HttpError,Crypto};
use App\Validators\Input;
final class ApiController
{
    private Auth $auth;
    private Policy $policy;
    public function __construct(private Database $db) { $this->auth=new Auth($db); }
    public function handle(string $method,string $path,array $data): array {
        if($method==='POST' && $path==='/auth/login') return $this->auth->login($data);
        if($method==='POST' && $path==='/auth/reset') return $this->reset($data);
        $this->policy=$this->auth->authenticate();
        if($method!=='GET') $this->auth->csrf();
        $u=$this->policy->user;
        $accounts=new AccountService($this->db,$this->policy); $servers=new ServerService($this->db,$this->policy); $resources=new ResourceService($this->db,$this->policy);
        if($path==='/auth/me' && $method==='GET') return ['user'=>Auth::publicUser($u),'permissions'=>$this->policy->permissions(),'csrf'=>$this->auth->session['csrf']??null,'quotas'=>(new Quota($this->db))->summary($u),'two_factor'=>(bool)$u['totp_secret'],'servers'=>$servers->list(true),'catalog'=>Catalog::RESOURCES];
        if($path==='/auth/logout' && $method==='POST') return $this->auth->logout($u);
        if($path==='/dashboard' && $method==='GET') return $this->dashboard();
        if($path==='/users') { if($method==='GET') return ['data'=>$accounts->users()]; if($method==='POST') return $accounts->createUser($data); }
        if(preg_match('#^/users/(\d+)$#D',$path,$m) && $method==='PATCH') return $accounts->updateUser((int)$m[1],$data);
        if(preg_match('#^/users/(\d+)$#D',$path,$m) && $method==='GET') return $accounts->userDetails((int)$m[1]);
        if($path==='/plans') { if($method==='GET') return ['data'=>$accounts->plans()]; if($method==='POST') return $accounts->savePlan($data); }
        if(preg_match('#^/plans/(\d+)$#D',$path,$m) && $method==='PATCH') return $accounts->savePlan($data,(int)$m[1]);
        if($path==='/servers') { if($method==='GET') return ['data'=>$servers->list()]; if($method==='POST') return $servers->create($data); }
        if(preg_match('#^/servers/(\d+)$#D',$path,$m)) {
            if($method==='GET') return $servers->details((int)$m[1]);
            if($method==='PATCH') return $servers->update((int)$m[1],$data);
        }
        if(preg_match('#^/servers/(\d+)/(grants|services|metrics)$#D',$path,$m)) {
            $id=(int)$m[1];
            if($m[2]==='grants' && $method==='POST') return $servers->grant($id,$data);
            if($m[2]==='services' && $method==='POST') return $servers->operation($id,$data);
            if($m[2]==='services' && $method==='GET') { $this->policy->require('services.manage'); if(!$this->policy->isAdmin()) throw new HttpError(403,'Operação administrativa.'); $serversRow=$this->policy->server($id); return (new AgentClient($this->db))->call($serversRow,'services',[],bin2hex(random_bytes(16))); }
            if($m[2]==='metrics' && $method==='GET') {
                $this->policy->require('metrics.view'); $this->policy->server($id); $hours=Input::integer($_GET['hours']??1,'Período',1,720);
                $bucket=max(10,(int)ceil($hours*3600/180));
                $data=$this->db->all('SELECT MAX(created_at) AS created_at,AVG(cpu) AS cpu,AVG(ram) AS ram,AVG(disk) AS disk,AVG(network_rx_bps) AS network_rx_bps,AVG(network_tx_bps) AS network_tx_bps FROM server_metrics WHERE tenant_id=? AND server_id=? AND created_at>=? GROUP BY CAST(created_at / ? AS '.($this->db->driver()==='mysql'?'UNSIGNED':'INTEGER').') ORDER BY created_at',[$u['tenant_id'],$id,time()-$hours*3600,$bucket]);
                return ['data'=>$data,'server_time'=>time(),'hours'=>$hours];
            }
        }
        if(preg_match('#^/([a-z_]+)(?:/(\d+))?$#D',$path,$m) && isset(Catalog::RESOURCES[$m[1]])) {
            $kind=$m[1]; $id=isset($m[2])?(int)$m[2]:null;
            if($method==='GET') { $this->policy->require("$kind.view"); return $id?['data'=>$resources->present($resources->find($kind,$id))]:['data'=>$resources->list($kind)]; }
            if($method==='POST' && !$id) return $resources->create($kind,$data);
            if($method==='DELETE' && $id) return $resources->delete($kind,$id);
        }
        if($path==='/audit_logs' && $method==='GET') { $this->policy->require('audit_logs.view'); [$where,$args]=$this->policy->scope('user_id'); return ['data'=>$this->db->all("SELECT * FROM audit_logs WHERE $where ORDER BY id DESC LIMIT 200",$args)]; }
        if($path==='/jobs' && $method==='GET') { $this->policy->require('jobs.view'); [$where,$args]=$this->policy->scope(); return ['data'=>$this->db->all("SELECT id,server_id,resource_type,resource_id,operation,status,attempts,error,created_at,finished_at FROM jobs WHERE $where ORDER BY id DESC LIMIT 200",$args)]; }
        if($path==='/notifications' && $method==='GET') return ['data'=>$this->db->all('SELECT * FROM notifications WHERE tenant_id=? AND user_id=? ORDER BY id DESC LIMIT 100',[$u['tenant_id'],$u['id']])];
        if(preg_match('#^/notifications/(\d+)/read$#D',$path,$m) && $method==='POST') { $this->db->query('UPDATE notifications SET read_at=? WHERE id=? AND tenant_id=? AND user_id=?',[time(),$m[1],$u['tenant_id'],$u['id']]); return ['message'=>'Notificação lida.']; }
        if($path==='/files' && $method==='POST') return $this->files($data);
        if(preg_match('#^/databases/(\d+)/(access|password)$#D',$path,$m)) {
            $this->policy->require('databases.'.($m[2]==='access'?'view':'edit'));
            $r=$resources->find('databases',(int)$m[1]);
            if($r['status']!=='active') throw new HttpError(409,'Aguarde a criação do banco. Consulte Operações para acompanhar.');
            $server=$this->policy->server((int)$r['server_id']);
            if($m[2]==='access' && $method==='GET') {
                $local=in_array(parse_url($server['agent_url'],PHP_URL_HOST),['localhost','127.0.0.1'],true);
                return ['name'=>$r['name'],'username'=>$r['name'],'host'=>'localhost','port'=>3306,'phpmyadmin_url'=>$local?rtrim(getenv('APP_URL'),'/').'/phpmyadmin/':null];
            }
            if($m[2]==='password' && $method==='POST') {
                $payload=['tenant_id'=>(int)$r['tenant_id'],'owner_id'=>(int)$r['owner_id'],'resource_id'=>(int)$r['id'],'password'=>Input::password($data['password']??'')];
                $job=(new Jobs($this->db))->enqueue($this->policy->owner((int)$r['owner_id']),(int)$r['server_id'],'database_password',$payload);
                Audit::write($this->db,$u,'databases.password',$r['name'],'queued');
                return ['job_id'=>$job,'message'=>'Alteração de senha adicionada à fila.'];
            }
        }
        if(preg_match('#^/websites/(\d+)/php$#D',$path,$m) && $method==='POST') {
            $this->policy->require('websites.edit');
            return $this->db->transaction(function() use($m,$data,$resources,$u){
                $this->db->lockTenant((int)$u['tenant_id']);
                $r=$resources->find('websites',(int)$m[1]);
                if($r['status']!=='active') throw new HttpError(409,'Aguarde a operação atual do site.');
                $version=Input::choice($data['php_version']??'',\App\Models\PhpVersions::ALL,'PHP');
                $payload=['tenant_id'=>(int)$r['tenant_id'],'owner_id'=>(int)$r['owner_id'],'resource_id'=>(int)$r['id'],'php_version'=>$version];
                $job=(new Jobs($this->db))->enqueue($this->policy->owner((int)$r['owner_id']),(int)$r['server_id'],'change_php',$payload,'websites',(int)$r['id']);
                $this->db->query("UPDATE websites SET status='pending',updated_at=? WHERE id=?",[time(),$r['id']]);
                Audit::write($this->db,$u,'websites.php',$r['name'],'queued');
                return ['job_id'=>$job,'message'=>'Troca de PHP adicionada à fila.'];
            });
        }
        if($path==='/backup_schedules') {
            if($method==='GET') return ['data'=>(new BackupScheduler($this->db))->list($this->policy)];
            if($method==='POST') return (new BackupScheduler($this->db))->create($this->policy,$data);
        }
        if(preg_match('#^/backup_schedules/(\d+)$#D',$path,$m) && $method==='DELETE') return (new BackupScheduler($this->db))->delete($this->policy,(int)$m[1]);
        if(preg_match('#^/backups/(\d+)/restore$#D',$path,$m) && $method==='POST') {
            $this->policy->require('backups.restore'); $r=$resources->find('backups',(int)$m[1]);
            if($r['status']!=='active') throw new HttpError(409,'Backup indisponível.');
            if(($data['confirmation']??'')!==$r['name']) throw new HttpError(422,'Confirme o nome do backup antes de restaurar.');
            $config=json_decode($r['config_json'],true); $job=(new Jobs($this->db))->enqueue($this->policy->owner((int)$r['owner_id']),(int)$r['server_id'],'restore_backup',array_merge($config,['resource_id'=>(int)$r['id'],'owner_id'=>(int)$r['owner_id'],'tenant_id'=>(int)$r['tenant_id']]));
            Audit::write($this->db,$u,'backups.restore',$r['name'],'queued'); return ['job_id'=>$job,'message'=>'Restauração adicionada à fila.'];
        }
        if(str_starts_with($path,'/security')) return $this->security($method,$path,$data);
        if($path==='/settings') {
            $this->policy->require('settings.manage');
            if($method==='GET') return ['data'=>$this->db->all('SELECT name,value_json FROM settings WHERE tenant_id=?',[$u['tenant_id']])];
            if($method==='POST') {
                $name=Input::choice($data['name']??'',['branding','alerts','nameservers'],'Configuração');
                if(!is_array($data['value']??null)) throw new HttpError(422,'Configuração inválida.');
                if($name==='branding') $value=['name'=>Input::text($data['value']['name']??'','Nome',1,40)];
                elseif($name==='nameservers') {
                    if(!$this->policy->isAdmin()) throw new HttpError(403,'Operação administrativa.');
                    $value=\App\Services\NameserverSettings::validate($data['value']);
                }
                else { $value=[]; foreach(['cpu','ram','disk'] as $k) $value[$k]=Input::integer($data['value'][$k]??90,$k,1,100); }
                $this->db->transaction(function() use($u,$name,$value){$this->db->query('DELETE FROM settings WHERE tenant_id=? AND name=?',[$u['tenant_id'],$name]);$this->db->insert('settings',['tenant_id'=>$u['tenant_id'],'name'=>$name,'value_json'=>json_encode($value)]);});
                Audit::write($this->db,$u,'settings.save',$name); return ['message'=>'Configuração salva.'];
            }
        }
        throw new HttpError(404,'Endpoint não encontrado.');
    }
    private function dashboard(): array {
        $this->policy->require('dashboard.view'); [$where,$args]=$this->policy->scope(); $counts=[];
        foreach(array_keys(Catalog::RESOURCES) as $k) if(in_array("$k.view",$this->policy->permissions(),true)) $counts[$k]=(int)$this->db->scalar("SELECT COUNT(*) FROM `$k` WHERE $where AND deleted_at IS NULL",$args);
        $servers=in_array('servers.view',$this->policy->permissions(),true)?(new ServerService($this->db,$this->policy))->list():[];
        $activity=[]; if(in_array('audit_logs.view',$this->policy->permissions(),true)) {[$w,$p]=$this->policy->scope('user_id');$activity=$this->db->all("SELECT action,target,result,created_at FROM audit_logs WHERE $w ORDER BY id DESC LIMIT 8",$p);}
        $counts['users']=count($this->policy->ownerIds());
        return ['counts'=>$counts,'servers'=>$servers,'activity'=>$activity,'quotas'=>(new Quota($this->db))->summary($this->policy->user),'pending_jobs'=>(int)$this->db->scalar("SELECT COUNT(*) FROM jobs WHERE $where AND status IN ('pending','running')",$args)];
    }
    private function files(array $d): array {
        $this->policy->require('files.manage'); $u=$this->policy->user;
        foreach((new Quota($this->db))->chain($u) as $account) if($account['role']!=='MASTER' && !in_array('files',(new Quota($this->db))->plan($account)['features']??[],true)) throw new HttpError(403,'Gerenciador de arquivos não permitido pelo plano.');
        $site=(new ResourceService($this->db,$this->policy))->find('websites',Input::integer($d['website_id']??null,'Site'));
        if($site['status']!=='active') throw new HttpError(409,'Site ainda não está ativo.');
        $action=Input::choice($d['action']??'list',['list','read','write','mkdir','delete','rename','copy','zip','unzip','chmod','trash','trash_list','restore','purge','download','upload_begin','upload_chunk','upload_finish','upload_cancel','info'],'Ação');
        $payload=['tenant_id'=>(int)$site['tenant_id'],'owner_id'=>(int)$site['owner_id'],'website_id'=>(int)$site['id'],'domain'=>$site['name'],'action'=>$action,'path'=>$d['path']??'','target'=>$d['target']??'','content'=>$d['content']??'','mode'=>$d['mode']??'644'];
        foreach(['id','offset','size'] as $key) if(array_key_exists($key,$d)) $payload[$key]=$d[$key];
        $server=$this->policy->server((int)$site['server_id']);
        $result=(new AgentClient($this->db))->call($server,'files',$payload,bin2hex(random_bytes(16)));
        Audit::write($this->db,$u,'files.'.$action,$site['name']); return $result;
    }
    private function security(string $method,string $path,array $d): array {
        $this->auth->sessionOnly(); $u=$this->policy->user;
        if($path==='/security/sessions' && $method==='GET') { $rows=$this->db->all('SELECT id,ip,user_agent,created_at,last_seen,expires_at FROM sessions WHERE user_id=? AND expires_at>?',[$u['id'],time()]); foreach($rows as &$row) $row['current']=$row['id']===$this->auth->session['id']; return ['data'=>$rows]; }
        if($path==='/security/sessions' && $method==='DELETE') { $id=Input::text($d['id']??'','Sessão'); if($id==='all') $this->db->query('DELETE FROM sessions WHERE user_id=?',[$u['id']]); else $this->db->query('DELETE FROM sessions WHERE user_id=? AND id=?',[$u['id'],$id]); Audit::write($this->db,$u,'sessions.revoke'); return ['message'=>'Sessão encerrada.']; }
        if($path==='/security/tokens' && $method==='GET') return ['data'=>$this->db->all('SELECT id,name,scopes_json,created_at,expires_at FROM api_keys WHERE user_id=?',[$u['id']])];
        if($method==='POST') {
            $this->auth->rateLimit('security:'.$u['id'],15);
            if(!password_verify((string)($d['password']??''),$u['password_hash'])) throw new HttpError(403,'Confirme sua senha atual.');
        }
        if($path==='/security/tokens' && $method==='POST') {
            $scopes=$d['scopes']??[]; if(!is_array($scopes)||!$scopes||array_diff($scopes,$this->policy->permissions())) throw new HttpError(422,'Selecione permissões autorizadas.');
            $token='vpm_'.bin2hex(random_bytes(32)); $id=$this->db->insert('api_keys',['user_id'=>$u['id'],'name'=>Input::text($d['name']??'','Nome'),'token_hash'=>hash('sha256',$token),'scopes_json'=>json_encode(array_values($scopes)),'created_at'=>time(),'expires_at'=>time()+Input::integer($d['days']??30,'Validade',1,365)*86400]);
            Audit::write($this->db,$u,'tokens.create',(string)$id); return ['token'=>$token,'message'=>'Copie o token agora. Ele não será exibido novamente.'];
        }
        if(preg_match('#^/security/tokens/(\d+)$#D',$path,$m) && $method==='DELETE') { $this->db->query('DELETE FROM api_keys WHERE id=? AND user_id=?',[$m[1],$u['id']]); Audit::write($this->db,$u,'tokens.revoke',$m[1]); return ['message'=>'Token revogado.']; }
        if($path==='/security/password' && $method==='POST') {
            $this->db->transaction(function() use($d,$u){$this->db->query('UPDATE users SET password_hash=? WHERE id=?',[password_hash(Input::password($d['new_password']??''),PASSWORD_DEFAULT),$u['id']]); $this->db->query('DELETE FROM sessions WHERE user_id=?',[$u['id']]); $this->db->query('DELETE FROM api_keys WHERE user_id=?',[$u['id']]);});
            Audit::write($this->db,$u,'auth.password'); return ['message'=>'Senha alterada. Entre novamente.'];
        }
        if($path==='/security/totp/setup' && $method==='POST') {
            if($u['totp_secret']) throw new HttpError(409,'2FA já está ativo.');
            $secret=Totp::secret(); $key='totp_pending_'.$u['id']; $this->db->transaction(function() use($u,$key,$secret){$this->db->query('DELETE FROM settings WHERE tenant_id=? AND name=?',[$u['tenant_id'],$key]);$this->db->insert('settings',['tenant_id'=>$u['tenant_id'],'name'=>$key,'value_json'=>Crypto::encrypt(json_encode(['secret'=>$secret,'expires'=>time()+600]))]);});
            return ['secret'=>$secret,'uri'=>'otpauth://totp/VPS%20Manager:'.rawurlencode($u['email']).'?secret='.$secret.'&issuer=VPS%20Manager'];
        }
        if($path==='/security/totp/enable' && $method==='POST') {
            $key='totp_pending_'.$u['id']; $row=$this->db->one('SELECT value_json FROM settings WHERE tenant_id=? AND name=?',[$u['tenant_id'],$key]);
            $pending=$row?json_decode(Crypto::decrypt($row['value_json']),true):null;
            $step=$pending?Totp::verify($pending['secret'],(string)($d['code']??'')):null;
            if(!$pending || $pending['expires']<time() || !$step) throw new HttpError(422,'Código inválido ou configuração expirada.');
            $this->db->query('UPDATE users SET totp_secret=?,totp_last_step=? WHERE id=?',[Crypto::encrypt($pending['secret']),$step,$u['id']]); $this->db->query('DELETE FROM settings WHERE tenant_id=? AND name=?',[$u['tenant_id'],$key]); Audit::write($this->db,$u,'totp.enable'); return ['message'=>'Autenticação em duas etapas ativada.'];
        }
        if($path==='/security/totp/disable' && $method==='POST') {
            if(!$u['totp_secret'] || !Totp::verify(Crypto::decrypt($u['totp_secret']),(string)($d['code']??''),(int)$u['totp_last_step'])) throw new HttpError(422,'Código inválido.');
            $this->db->query('UPDATE users SET totp_secret=NULL,totp_last_step=0 WHERE id=?',[$u['id']]); Audit::write($this->db,$u,'totp.disable'); return ['message'=>'2FA desativado.'];
        }
        throw new HttpError(404,'Endpoint não encontrado.');
    }
    private function reset(array $d): array {
        $this->auth->rateLimit('reset:'.($_SERVER['REMOTE_ADDR']??'local'));
        $token=Input::text($d['token']??'','Token',64,64); $password=Input::password($d['password']??'');
        return $this->db->transaction(function() use($token,$password){
            $r=$this->db->one('SELECT * FROM password_resets WHERE token_hash=? AND expires_at>?'.($this->db->driver()==='mysql'?' FOR UPDATE':''),[hash('sha256',$token),time()]);
            if(!$r) throw new HttpError(422,'Link inválido ou expirado.');
            $this->db->query('UPDATE users SET password_hash=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$r['user_id']]); $this->db->query('DELETE FROM sessions WHERE user_id=?',[$r['user_id']]); $this->db->query('DELETE FROM api_keys WHERE user_id=?',[$r['user_id']]); $this->db->query('DELETE FROM password_resets WHERE user_id=?',[$r['user_id']]); return ['message'=>'Senha redefinida.'];
        });
    }
}
