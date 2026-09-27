<?php
declare(strict_types=1);
namespace App\Services;
use App\Repositories\Database;
use App\Middleware\Policy;
use App\Helpers\{HttpError,Crypto};
use App\Validators\Input;
final class Auth
{
    public ?array $session = null;
    public bool $bearer = false;
    public function __construct(private Database $db) {}
    public function rateLimit(string $key,int $limit=8,int $window=900): void {
        $blocked=$this->db->transaction(function() use($key,$limit,$window) {
            $hash=hash('sha256',$key); $now=time();
            if($this->db->driver()==='mysql') $this->db->query('INSERT IGNORE INTO rate_limits(bucket,attempts,expires_at) VALUES (?,0,?)',[$hash,$now+$window]);
            else $this->db->query('INSERT OR IGNORE INTO rate_limits(bucket,attempts,expires_at) VALUES (?,0,?)',[$hash,$now+$window]);
            $row=$this->db->one('SELECT * FROM rate_limits WHERE bucket=?' . ($this->db->driver()==='mysql'?' FOR UPDATE':''),[$hash]);
            $count=$row['expires_at']<$now ? 1 : (int)$row['attempts']+1;
            $this->db->query('UPDATE rate_limits SET attempts=?,expires_at=? WHERE bucket=?',[$count,$row['expires_at']<$now?$now+$window:$row['expires_at'],$hash]);
            return $count>$limit;
        });
        if($blocked) throw new HttpError(429,'Muitas tentativas. Aguarde 15 minutos.');
    }
    public function login(array $data): array {
        $this->clearChallenge();
        $email=Input::email($data['email']??'');
        $this->rateLimit('login-ip:'.($_SERVER['REMOTE_ADDR']??'local'),40);
        $this->rateLimit('login-account:'.$email);
        $u=$this->db->one('SELECT * FROM users WHERE email=?',[$email]);
        $password=is_string($data['password']??null)?$data['password']:'';
        $valid=password_verify($password,$u['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        if(!$u || !$valid || !AccountValidity::available($this->db,$u)) {
            if($u) Audit::write($this->db,$u,'auth.login','','denied');
            throw new HttpError(401,'E-mail, senha ou código inválidos.');
        }
        if(password_needs_rehash($u['password_hash'],PASSWORD_DEFAULT)) {
            $u['password_hash']=password_hash($password,PASSWORD_DEFAULT);
            $this->db->query('UPDATE users SET password_hash=? WHERE id=?',[$u['password_hash'],$u['id']]);
        }
        if($u['totp_secret']) {
            $raw=bin2hex(random_bytes(32)); $expires=time()+300;
            $this->db->query('DELETE FROM login_challenges WHERE expires_at<=?',[time()]);
            $this->db->insert('login_challenges',['token_hash'=>hash('sha256',$raw),'user_id'=>$u['id'],'security_stamp'=>$this->securityStamp($u),'expires_at'=>$expires,'attempts'=>0]);
            $this->challengeCookie($raw,$expires);
            return ['requires_2fa'=>true,'expires_in'=>300];
        }
        return $this->finishLogin($this->newSession($u));
    }
    private function securityStamp(array $u): string { return hash('sha256',$u['password_hash'].'|'.$u['totp_secret']); }
    private function challengeCookie(string $token,int $expires): void {
        setcookie('vps_login_challenge',$token,['expires'=>$expires,'path'=>'/api/v1/auth/','secure'=>getenv('COOKIE_SECURE')!=='0','httponly'=>true,'samesite'=>'Strict']);
    }
    private function clearChallenge(): void {
        $raw=$_COOKIE['vps_login_challenge']??'';
        if(is_string($raw)&&$raw!=='') {
            $this->db->query('DELETE FROM login_challenges WHERE token_hash=?',[hash('sha256',$raw)]);
            $this->challengeCookie('',time()-3600);
        }
    }
    public function verifyTwoFactor(array $data): array {
        $this->rateLimit('login-2fa-ip:'.($_SERVER['REMOTE_ADDR']??'local'),40);
        $raw=$_COOKIE['vps_login_challenge']??'';
        if(!is_string($raw)||!preg_match('/^[a-f0-9]{64}$/D',$raw)) throw new HttpError(410,'A tentativa expirou. Volte e entre com e-mail e senha novamente.');
        $result=$this->db->transaction(function()use($raw,$data){
            $hash=hash('sha256',$raw);$lock=$this->db->driver()==='mysql'?' FOR UPDATE':'';
            $challenge=$this->db->one('SELECT * FROM login_challenges WHERE token_hash=?'.$lock,[$hash]);
            if(!$challenge||(int)$challenge['expires_at']<=time()) return ['error'=>410];
            if((int)$challenge['attempts']>=5) return ['error'=>429];
            $u=$this->db->one('SELECT * FROM users WHERE id=?'.$lock,[$challenge['user_id']]);
            if(!$u||!$u['totp_secret']||!AccountValidity::available($this->db,$u)||!hash_equals($challenge['security_stamp'],$this->securityStamp($u))) {
                $this->db->query('DELETE FROM login_challenges WHERE token_hash=?',[$hash]);return ['error'=>410];
            }
            // Failed attempts must commit too; do not throw inside this transaction.
            $this->db->query('UPDATE login_challenges SET attempts=attempts+1 WHERE token_hash=?',[$hash]);
            $code=is_string($data['code']??null)?$data['code']:'';
            $step=Totp::verify(Crypto::decrypt($u['totp_secret']),$code,(int)$u['totp_last_step']);
            if(!$step||$this->db->query('UPDATE users SET totp_last_step=? WHERE id=? AND totp_last_step<?',[$step,$u['id'],$step])->rowCount()!==1) {
                Audit::write($this->db,$u,'auth.2fa','','denied');return ['error'=>(int)$challenge['attempts']>=4?429:401];
            }
            $this->db->query('DELETE FROM login_challenges WHERE token_hash=?',[$hash]);
            return ['session'=>$this->newSession($u)];
        });
        if(isset($result['error'])) {
            $status=$result['error'];
            throw new HttpError($status,$status===410?'A tentativa expirou ou o acesso mudou. Volte e entre novamente.':($status===429?'Limite de tentativas atingido. Volte e entre novamente.':'Código inválido ou já utilizado. Tente o código atual do aplicativo.'));
        }
        return $this->finishLogin($result['session']);
    }
    private function newSession(array $u): array {
        $raw=bin2hex(random_bytes(32)); $now=time(); $ttl=(int)(getenv('SESSION_TTL')?:3600);
        $this->db->insert('sessions',['id'=>hash('sha256',$raw),'user_id'=>$u['id'],'csrf'=>bin2hex(random_bytes(32)),'ip'=>$_SERVER['REMOTE_ADDR']??'127.0.0.1','user_agent'=>substr($_SERVER['HTTP_USER_AGENT']??'CLI',0,255),'created_at'=>$now,'last_seen'=>$now,'expires_at'=>$now+$ttl]);
        Audit::write($this->db,$u,'auth.login');
        return ['token'=>$raw,'expires'=>$now+$ttl];
    }
    private function finishLogin(array $session): array {
        $this->cookie($session['token'],$session['expires']);
        $this->challengeCookie('',time()-3600);
        return ['message'=>'Autenticado.'];
    }
    private function cookie(string $token,int $expires): void {
        setcookie('vps_session',$token,['expires'=>$expires,'path'=>'/','secure'=>getenv('COOKIE_SECURE')!=='0','httponly'=>true,'samesite'=>'Strict']);
    }
    public function authenticate(): Policy {
        $header=$_SERVER['HTTP_AUTHORIZATION']??'';
        $scopes=null;
        if(str_starts_with($header,'Bearer ')) {
            $token=substr($header,7); $this->bearer=true;
            $key=$this->db->one('SELECT * FROM api_keys WHERE token_hash=? AND expires_at>?',[hash('sha256',$token),time()]);
            if(!$key) throw new HttpError(401,'Token inválido ou expirado.');
            $uid=$key['user_id']; $scopes=json_decode($key['scopes_json'],true,512,JSON_THROW_ON_ERROR);
        } else {
            $raw=$_COOKIE['vps_session']??'';
            $s=$this->db->one('SELECT * FROM sessions WHERE id=? AND expires_at>?',[hash('sha256',$raw),time()]);
            if(!$s) throw new HttpError(401,'Sua sessão expirou. Entre novamente.');
            $uid=$s['user_id']; $this->session=$s;
            $this->db->query('UPDATE sessions SET last_seen=? WHERE id=?',[time(),$s['id']]);
        }
        $u=$this->db->one('SELECT * FROM users WHERE id=? AND status=?',[$uid,'active']);
        if(!$u || !AccountValidity::available($this->db,$u)) throw new HttpError(401,'Conta indisponível, suspensa ou com validade encerrada.');
        if($u['parent_id']) {
            $parent=$this->db->one('SELECT status FROM users WHERE tenant_id=? AND id=?',[$u['tenant_id'],$u['parent_id']]);
            if(!$parent || $parent['status']!=='active') throw new HttpError(401,'Conta principal suspensa.');
        }
        return new Policy($this->db,$u,$scopes);
    }
    public function csrf(): void {
        if(!$this->bearer && (!$this->session || !hash_equals($this->session['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??''))) throw new HttpError(419,'Token de segurança inválido. Atualize a página.');
    }
    public function sessionOnly(): void { if($this->bearer) throw new HttpError(403,'Use uma sessão interativa para administrar credenciais.'); }
    public function logout(array $u): array {
        $this->sessionOnly(); $this->db->query('DELETE FROM sessions WHERE id=?',[$this->session['id']]); $this->cookie('',time()-3600); Audit::write($this->db,$u,'auth.logout'); return ['message'=>'Sessão encerrada.'];
    }
    public static function publicUser(array $u): array { $result=array_intersect_key($u,array_flip(['id','tenant_id','parent_id','plan_id','name','email','role','status','created_at','expires_at'])); $result['expired']=AccountValidity::expired($u); return $result; }
}
