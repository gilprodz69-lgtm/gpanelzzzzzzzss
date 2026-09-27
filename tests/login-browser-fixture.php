<?php
require dirname(__DIR__).'/app/bootstrap.php';
$db=new App\Repositories\Database();
if($db->driver()!=='sqlite')throw new RuntimeException('Local SQLite fixture only');
$action=$argv[1]??'';
if($action==='create'){
 $parent=$db->one("SELECT * FROM users WHERE role='MASTER' ORDER BY id LIMIT 1");
 $email='login-browser-'.bin2hex(random_bytes(6)).'@example.invalid';$secret=App\Services\Totp::secret();$password=bin2hex(random_bytes(20));
 $id=$db->insert('users',['tenant_id'=>$parent['tenant_id'],'parent_id'=>$parent['id'],'plan_id'=>$parent['plan_id'],'name'=>'Login browser fixture','email'=>$email,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'role'=>'CLIENT','totp_secret'=>App\Helpers\Crypto::encrypt($secret),'created_at'=>time()]);
 echo json_encode(['id'=>$id,'email'=>$email,'password'=>$password]);exit;
}
$id=(int)($argv[2]??0);$u=$db->one("SELECT * FROM users WHERE id=? AND email LIKE 'login-browser-%@example.invalid'",[$id]);
if(!$u)throw new RuntimeException('Fixture not found');
if($action==='code')echo App\Services\Totp::code(App\Helpers\Crypto::decrypt($u['totp_secret']),(int)floor(time()/30));
elseif($action==='disable')$db->query('UPDATE users SET totp_secret=NULL WHERE id=?',[$id]);
elseif($action==='cleanup'){
 foreach(['sessions','login_challenges','audit_logs'] as $table)$db->query("DELETE FROM $table WHERE user_id=?",[$id]);
 $db->query('DELETE FROM users WHERE id=?',[$id]);
}
