<?php
// End-to-end integration only on the disposable installation VM, never customer data.
if(getenv('GITHUB_ACTIONS')!=='true')exit("SKIP disposable CI host required\n");
require '/opt/vpsmanager/current/app/bootstrap.php';
$db=new App\Repositories\Database();$u=$db->one("SELECT * FROM users WHERE role='MASTER' LIMIT 1");
$server=$db->one('SELECT * FROM servers WHERE tenant_id=? LIMIT 1',[$u['tenant_id']]);
$session=bin2hex(random_bytes(32));$csrf=bin2hex(random_bytes(32));$jar=tempnam(sys_get_temp_dir(),'pma-ci-');
$db->insert('sessions',['id'=>hash('sha256',$session),'user_id'=>$u['id'],'csrf'=>$csrf,'ip'=>'127.0.0.1','user_agent'=>'CI','created_at'=>time(),'last_seen'=>time(),'expires_at'=>time()+600]);
$resource=null;
function req($path,$body=null,$form=false,$follow=false,$cookie=true,$origin=null):array{global $session,$csrf,$jar;
 $c=curl_init('https://127.0.0.1:8443'.$path);
 $headers=['Origin: '.($origin??rtrim(getenv('APP_URL'),'/')),'X-CSRF-Token: '.$csrf];
 if($body!==null)$headers[]='Content-Type: '.($form?'application/x-www-form-urlencoded':'application/json');
 curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>$follow,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>0,CURLOPT_TIMEOUT=>40,CURLOPT_HTTPHEADER=>$headers,CURLOPT_COOKIEFILE=>$jar,CURLOPT_COOKIEJAR=>$jar]);
 if($cookie)curl_setopt($c,CURLOPT_COOKIE,'vps_session='.$session);
 if($body!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$form?http_build_query($body):json_encode($body)]);
 $raw=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);return [$status,$raw];
}
function ensure($condition,$label){if(!$condition)throw new RuntimeException($label);}
try{
 $policy=new App\Middleware\Policy($db,$u);$rs=new App\Services\ResourceService($db,$policy);
 $resource=$rs->create('databases',['server_id'=>(int)$server['id'],'name'=>'pma_ci','username'=>'pma_ci','password'=>'DisposableDatabase!17428'])['id'];
 for($i=0;$i<300;$i++){if($db->scalar('SELECT status FROM `databases` WHERE id=?',[$resource])==='active')break;usleep(100000);}
 ensure($db->scalar('SELECT status FROM `databases` WHERE id=?',[$resource])==='active','DB creation');
 [$status,$raw]=req('/api/v1/databases/'.$resource.'/phpmyadmin',[]);ensure($status===200,'Ticket issuance HTTP '.$status);
 $ticket=json_decode($raw,true)['ticket'];ensure(!str_contains($raw,'password')&&!str_contains($raw,'vpm_sso_'),'No browser credentials');
 [$status]=req('/api/v1/phpmyadmin/open',['ticket'=>$ticket],true,false,true,'https://foreign.invalid');ensure($status===403,'Origin guard');
 [$status]=req('/api/v1/phpmyadmin/open',['ticket'=>$ticket],true);ensure($status===303,'SSO consume');
 [$status]=req('/api/v1/phpmyadmin/open',['ticket'=>$ticket],true);ensure($status===403,'Replay rejected');
 $row=$db->one('SELECT * FROM phpmyadmin_sessions WHERE session_id=?',[hash('sha256',$session)]);
 $secret=json_decode(App\Helpers\Crypto::decrypt($row['credential']),true);
 [$status,$html]=req('/phpmyadmin/index.php?server=2&db='.rawurlencode($secret['database']),null,false,true);
 ensure($status===200&&str_contains($html,$secret['database'])&&!str_contains($html,'name="pma_password"'),'phpMyAdmin opens authenticated');
 ensure(!str_contains($html,$secret['password']),'SQL password never in HTML');
 // Verify SQL grants using the exact temporary account, including denial of global/user management.
 $pdo=new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname='.$secret['database'],$secret['username'],$secret['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $pdo->exec('CREATE TABLE sso_probe (id INT)');$pdo->exec('DROP TABLE sso_probe');
 try{$pdo->query('SELECT * FROM mysql.user');throw new RuntimeException('Cross database access allowed');}catch(PDOException){}
 try{$pdo->exec("CREATE USER 'forbidden_sso'@'localhost'");throw new RuntimeException('Global privilege allowed');}catch(PDOException){}
 $pdo=null;
 $public=req('/api/v1/branding');ensure($public[0]===200,'Public branding');
 $im=imagecreatetruecolor(20,10);ob_start();imagepng($im);$png=ob_get_clean();
 [$status]=req('/api/v1/settings',['name'=>'branding','value'=>['name'=>'CI panel','logo'=>'data:image/png;base64,'.base64_encode($png)]]);ensure($status===200,'Logo upload');
 [$status,$raw]=req('/api/v1/branding');ensure($status===200&&json_decode($raw,true)['name']==='CI panel','Branding persisted');
 req('/api/v1/settings',['name'=>'branding','value'=>['name'=>'VPS Manager','logo'=>null]]);
 $db->query('DELETE FROM sessions WHERE id=?',[hash('sha256',$session)]);
 [$status,$html]=req('/phpmyadmin/index.php?server=2&db='.rawurlencode($secret['database']),null,false,false);
 ensure($status===302||$status===303||($status===200&&str_contains($html,'/#/databases')),'Panel logout revokes phpMyAdmin');
 // Expire and clean up real SQL users, exercising the timer's exact entrypoint.
 $inventory=new PDO('sqlite:/var/lib/vpsmanager-agent/inventory.sqlite');$q=$inventory->prepare('UPDATE pma_leases SET expires=? WHERE username=?');$q->execute([time()-1,$secret['username']]);
 passthru('/usr/bin/python3 /opt/vpsmanager/current/agent/pma.py',$code);ensure($code===0,'Lease cleanup');
 try{new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock',$secret['username'],$secret['password']);throw new RuntimeException('Expired SQL user still connects');}catch(PDOException){}
 echo "PASS phpMyAdmin real SSO, replay/origin guards, scoped SQL grants, credential secrecy, logout revocation, expiry cleanup and branding\n";
}finally{
 if($resource)(new App\Services\ResourceService($db,new App\Middleware\Policy($db,$u)))->delete('databases',(int)$resource);
 $db->query('DELETE FROM sessions WHERE id=?',[hash('sha256',$session)]);$db->query('DELETE FROM phpmyadmin_sessions WHERE session_id=?',[hash('sha256',$session)]);@unlink($jar);
}
