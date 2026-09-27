<?php
declare(strict_types=1);
namespace App\Services;
use App\Repositories\Database;
use App\Middleware\Policy;
use App\Helpers\{HttpError,Crypto};

final class PhpMyAdmin
{
    public function __construct(private Database $db,private Auth $auth,private Policy $policy) {}
    private function resource(int $id): array {
        $this->auth->sessionOnly();
        if(!$this->auth->session) throw new HttpError(401,'Entre no painel novamente.');
        $this->policy->require('databases.view'); $this->policy->require('databases.edit');
        $r=(new ResourceService($this->db,$this->policy))->find('databases',$id);
        if($r['status']!=='active') throw new HttpError(409,'O banco precisa estar ativo.');
        $this->policy->owner((int)$r['owner_id']);
        $server=$this->policy->server((int)$r['server_id']);
        if(!in_array(parse_url($server['agent_url'],PHP_URL_HOST),['127.0.0.1','localhost'],true)) throw new HttpError(409,'O acesso automático está disponível para bancos da VPS do painel.');
        return [$r,$server];
    }
    public function issue(int $id): array {
        [$r,$server]=$this->resource($id);
        if(parse_url(getenv('APP_URL')?:'',PHP_URL_SCHEME)!=='https') throw new HttpError(409,'Use HTTPS para abrir o phpMyAdmin com segurança.');
        $this->auth->rateLimit('pma:'.$this->policy->user['id'],12,900);
        $this->db->query('DELETE FROM phpmyadmin_sessions WHERE expires_at<=?',[time()]);
        $ticket=bin2hex(random_bytes(32)); $password=bin2hex(random_bytes(32));
        $username='vpm_sso_'.bin2hex(random_bytes(10));
        $expires=min(time()+900,(int)$this->auth->session['expires_at']);
        // Only a password digest reaches the agent journal. Clear credentials stay encrypted here.
        (new AgentClient($this->db))->call($server,'database_signon',[
            'tenant_id'=>(int)$r['tenant_id'],'owner_id'=>(int)$r['owner_id'],'resource_id'=>$id,
            'username'=>$username,'password_hash'=>'*'.strtoupper(sha1(sha1($password,true))),'expires_at'=>$expires
        ],bin2hex(random_bytes(16)));
        $this->db->insert('phpmyadmin_sessions',['token_hash'=>hash('sha256',$ticket),'session_id'=>$this->auth->session['id'],'database_id'=>$id,
            'credential'=>Crypto::encrypt(json_encode(['username'=>$username,'password'=>$password,'database'=>$r['name']],JSON_THROW_ON_ERROR)),
            'ticket_expires'=>time()+45,'expires_at'=>$expires,'consumed'=>0]);
        Audit::write($this->db,$this->policy->user,'databases.phpmyadmin',$r['name']);
        return ['ticket'=>$ticket,'action'=>'/api/v1/phpmyadmin/open'];
    }
    private function lease(string $token,bool $consumed): array {
        if(!preg_match('/^[a-f0-9]{64}$/D',$token)) throw new HttpError(403,'Acesso ao phpMyAdmin inválido.');
        $s=$this->db->one('SELECT * FROM phpmyadmin_sessions WHERE token_hash=? AND session_id=? AND expires_at>? AND consumed=?',
            [hash('sha256',$token),$this->auth->session['id']??'',time(),$consumed?1:0]);
        if(!$s) throw new HttpError(403,'Acesso expirado. Abra o phpMyAdmin novamente pelo painel.');
        $this->resource((int)$s['database_id']);
        return $s;
    }
    public function consume(string $ticket): string {
        $s=$this->lease($ticket,false);
        $token=bin2hex(random_bytes(32));
        $updated=$this->db->query('UPDATE phpmyadmin_sessions SET token_hash=?,consumed=1 WHERE token_hash=? AND consumed=0 AND ticket_expires>?',
            [hash('sha256',$token),$s['token_hash'],time()])->rowCount();
        if($updated!==1) throw new HttpError(403,'Link expirado ou já utilizado. Abra novamente pelo painel.');
        setcookie('vpm_pma',$token,['expires'=>(int)$s['expires_at'],'path'=>'/phpmyadmin/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
        $c=json_decode(Crypto::decrypt($s['credential']),true,8,JSON_THROW_ON_ERROR);
        return '/phpmyadmin/index.php?server=2&db='.rawurlencode($c['database']);
    }
    // Called only by the private loopback bridge, never by the public API.
    public function credentials(string $token): array {
        $s=$this->lease($token,true);
        return json_decode(Crypto::decrypt($s['credential']),true,8,JSON_THROW_ON_ERROR);
    }
}
