<?php
declare(strict_types=1);
// Standalone integration tests against real SQL repositories and service boundaries.
$file=sys_get_temp_dir().'/vpm-test-'.bin2hex(random_bytes(8)).'.sqlite';
putenv('DB_DSN='.(getenv('TEST_MYSQL_DSN')?:'sqlite:'.$file)); putenv('APP_KEY='.base64_encode(random_bytes(32))); putenv('COOKIE_SECURE=0');
require dirname(__DIR__).'/app/bootstrap.php';
use App\Repositories\Database;
use App\Services\{Migrator,Auth,AccountService,ResourceService,Quota,Totp,ServerService};
use App\Middleware\Policy;
use App\Helpers\{HttpError,Crypto};
use App\Models\Catalog;
use App\Validators\Input;
$passed=0; $failed=0;
function check(string $name,callable $fn): void { global $passed,$failed; try{$fn();$passed++;echo "PASS $name\n";}catch(Throwable $e){$failed++;echo "FAIL $name: {$e->getMessage()}\n";} }
function eq(mixed $a,mixed $b): void {if($a!==$b)throw new RuntimeException(var_export($a,true).' !== '.var_export($b,true));}
function denied(callable $fn,int $code): void {try{$fn();}catch(HttpError $e){eq($e->status,$code);return;}throw new RuntimeException("Expected HTTP $code");}
$db=new Database(); Migrator::run($db);
$tenant=$db->insert('tenants',['name'=>'Alpha','created_at'=>time()]); $other=$db->insert('tenants',['name'=>'Beta','created_at'=>time()]);
$limits=array_fill_keys(Catalog::QUOTAS,3);$limits['websites']=2;$limits['users']=3;
$plan=$db->insert('plans',['tenant_id'=>$tenant,'name'=>'Test','limits_json'=>json_encode($limits),'features_json'=>json_encode(['files','ftp_accounts','ssl_certificates','backups']),'created_at'=>time()]);
$makeUser=function($name,$role,$tid,$parent=null)use($db,$plan,$tenant){return $db->insert('users',['tenant_id'=>$tid,'parent_id'=>$parent,'plan_id'=>$tid===$tenant?$plan:null,'name'=>$name,'email'=>$name.'@example.com','password_hash'=>password_hash('SecureTest!123456',PASSWORD_DEFAULT),'role'=>$role,'created_at'=>time()]);};
$masterId=$makeUser('master','MASTER',$tenant);$resellerId=$makeUser('reseller','RESELLER',$tenant,$masterId);$clientId=$makeUser('client','CLIENT',$tenant,$resellerId);$rivalId=$makeUser('rival','RESELLER',$tenant,$masterId);$outsiderId=$makeUser('outsider','MASTER',$other);
$user=fn($id)=>$db->one('SELECT * FROM users WHERE id=?',[$id]);
$master=new Policy($db,$user($masterId));$reseller=new Policy($db,$user($resellerId));$client=new Policy($db,$user($clientId));$outsider=new Policy($db,$user($outsiderId));
$server=$db->insert('servers',['tenant_id'=>$tenant,'name'=>'Server','address'=>'127.0.0.1','agent_url'=>'https://agent.example.com:9443','os'=>'Ubuntu 24.04','created_at'=>time()]);
$db->insert('server_users',['tenant_id'=>$tenant,'server_id'=>$server,'user_id'=>$resellerId]);
check('migrations can be run again',fn()=>Migrator::run($db));
check('password is hashed',fn()=>eq(password_verify('SecureTest!123456',$user($clientId)['password_hash']),true));
check('secretbox roundtrip',fn()=>eq(Crypto::decrypt(Crypto::encrypt('ssh-private-secret')),'ssh-private-secret'));
check('secretbox tamper rejected',function(){try{Crypto::decrypt(base64_encode(random_bytes(64)));}catch(RuntimeException){return;}throw new RuntimeException('Accepted altered ciphertext');});
check('client cannot manage users',fn()=>denied(fn()=>$client->require('users.create'),403));
check('reseller sees own clients only',function()use($reseller,$resellerId,$clientId,$rivalId){eq($reseller->ownerIds(),[$resellerId,$clientId]);denied(fn()=>$reseller->owner($rivalId),404);});
check('master cannot cross tenant',fn()=>denied(fn()=>$master->owner($outsiderId),404));
check('server tenant isolation',fn()=>denied(fn()=>$outsider->server($server),404));
check('client inherits reseller server grant',fn()=>eq((int)$client->server($server)['id'],$server));
check('independent reseller cannot use server',fn()=>denied(fn()=>(new Policy($db,$user($rivalId)))->server($server),403));
check('reseller cannot create admin',fn()=>denied(fn()=>(new AccountService($db,$reseller))->createUser(['role'=>'ADMIN']),403));
check('reseller cannot edit own account',fn()=>denied(fn()=>(new AccountService($db,$reseller))->updateUser($resellerId,['status'=>'suspended']),403));
check('rejected permission change cannot partly suspend account',function()use($db,$reseller,$clientId){denied(fn()=>(new AccountService($db,$reseller))->updateUser($clientId,['status'=>'suspended','permissions'=>['users.create'=>true]]),403);eq($db->scalar('SELECT status FROM users WHERE id=?',[$clientId]),'active');});
check('create subordinate client',function()use($db,$reseller,$plan,$resellerId){$result=(new AccountService($db,$reseller))->createUser(['role'=>'CLIENT','name'=>'New client','email'=>'new@example.com','password'=>'SecureTest!123456','plan_id'=>$plan]);eq((int)$db->scalar('SELECT parent_id FROM users WHERE id=?',[$result['id']]),$resellerId);});
check('domain command injection rejected',fn()=>denied(fn()=>Input::domain('site.com;reboot'),422));
check('domain config injection rejected',fn()=>denied(fn()=>Input::domain("site.com\ninclude /etc/passwd;"),422));
check('identifier SQL injection rejected',fn()=>denied(fn()=>Input::identifier("db';DROP DATABASE test;--"),422));
$resource=new ResourceService($db,$reseller);
check('site reservation and job are atomic',function()use($resource,$server,$clientId,$db){$r=$resource->create('websites',['server_id'=>$server,'owner_id'=>$clientId,'domain'=>'client.example.com','php_version'=>'8.3']);eq($r['status'],'pending');eq((int)$db->scalar('SELECT COUNT(*) FROM jobs WHERE id=?',[$r['job_id']]),1);});
check('site in another tenant is hidden',fn()=>denied(fn()=>(new ResourceService($db,$outsider))->find('websites',1),404));
check('site ownership IDOR rejected',fn()=>denied(fn()=>$resource->create('websites',['server_id'=>$server,'owner_id'=>$outsiderId,'domain'=>'forbidden.example.com']),404));
check('pending sites count toward reseller quota',function()use($resource,$server,$resellerId,$clientId){$resource->create('websites',['server_id'=>$server,'owner_id'=>$resellerId,'domain'=>'reseller.example.com']);denied(fn()=>$resource->create('websites',['server_id'=>$server,'owner_id'=>$clientId,'domain'=>'excess.example.com']),409);});
check('failed creation does not insert site or job',function()use($db){eq((int)$db->scalar('SELECT COUNT(*) FROM websites'),2);eq((int)$db->scalar('SELECT COUNT(*) FROM jobs'),2);});
check('database credential encrypted in job',function()use($resource,$server,$db){$r=$resource->create('databases',['server_id'=>$server,'name'=>'app','password'=>'DatabaseSecret!123']);$job=$db->one('SELECT * FROM jobs WHERE id=?',[$r['job_id']]);eq(str_contains($job['payload'],'DatabaseSecret!123'),false);eq(json_decode(Crypto::decrypt($job['payload']),true)['password'],'DatabaseSecret!123');$record=$db->one('SELECT * FROM `databases` WHERE id=?',[$r['id']]);eq(str_contains($record['config_json'],'DatabaseSecret'),false);});
check('SSL requires active owned site',fn()=>denied(fn()=>$resource->create('ssl_certificates',['server_id'=>$server,'owner_id'=>$clientId,'website_id'=>1,'email'=>'test@example.com']),422));
check('backup requires active owned site',fn()=>denied(fn()=>$resource->create('backups',['server_id'=>$server,'owner_id'=>$clientId,'website_id'=>1]),422));
check('CSRF rejects missing token',fn()=>denied(fn()=>(new Auth($db))->csrf(),419));
check('rate limiter blocks ninth attempt',function()use($db){$auth=new Auth($db);for($i=0;$i<8;$i++)$auth->rateLimit('test');denied(fn()=>$auth->rateLimit('test'),429);});
check('TOTP RFC 6238 vector',fn()=>eq(Totp::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',1),'287082'));
check('TOTP rejects replayed step',function(){$secret=Totp::secret();$step=(int)floor(time()/30);$code=Totp::code($secret,$step);eq(Totp::verify($secret,$code,$step),null);});
check('Bearer token intersects account permissions',function()use($db,$clientId){$policy=new Policy($db,$db->one('SELECT * FROM users WHERE id=?',[$clientId]),['users.create','websites.view']);eq($policy->permissions(),['websites.view']);});
check('individual permission revoke',function()use($db,$client,$clientId){$db->insert('user_permissions',['user_id'=>$clientId,'permission'=>'websites.create','allowed'=>0]);denied(fn()=>$client->require('websites.create'),403);});
check('composite foreign key rejects cross tenant owner',function()use($db,$other,$clientId,$server){try{$db->insert('websites',['tenant_id'=>$other,'owner_id'=>$clientId,'server_id'=>$server,'name'=>'cross.example.com','config_json'=>'{}','created_at'=>time(),'updated_at'=>time()]);}catch(PDOException){return;}throw new RuntimeException('Cross-tenant foreign key accepted');});
check('audit records exclude credentials',function()use($db){$audit=json_encode($db->all('SELECT * FROM audit_logs'));eq(str_contains($audit,'SecureTest'),false);eq(str_contains($audit,'DatabaseSecret'),false);});
echo "\n$passed passed; $failed failed\n";
unset($db); // Test DB is outside project and uniquely named; retained only if OS keeps a handle.
@unlink($file); @unlink($file.'-wal'); @unlink($file.'-shm');
exit($failed?1:0);
