<?php
if(getenv('GITHUB_ACTIONS')!=='true')exit("SKIP disposable CI host required\n");
require '/opt/vpsmanager/current/app/bootstrap.php';
$db=new App\Repositories\Database();$u=$db->one("SELECT * FROM users WHERE role='MASTER' LIMIT 1");$server=(int)$db->scalar('SELECT id FROM servers LIMIT 1');
$token='vpm_'.bin2hex(random_bytes(32));$site=null;$databases=[];
$key=$db->insert('api_keys',['user_id'=>$u['id'],'name'=>'CI WordPress','token_hash'=>hash('sha256',$token),'scopes_json'=>'["websites.view","websites.edit","websites.create","websites.delete","databases.create","databases.delete","files.manage"]','created_at'=>time(),'expires_at'=>time()+600]);
function wpRequest($path,$data=[],$method='POST',$expected=200){global $token;
 $c=curl_init('https://127.0.0.1:8443/api/v1'.$path);curl_setopt_array($c,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_POSTFIELDS=>json_encode($data),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$token],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>180,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>0]);
 $raw=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);if($status!==$expected)throw new RuntimeException('WordPress API '.$path.' HTTP '.$status.': '.substr($raw,0,250));return json_decode($raw,true);
}
function awaitJob($id,$status='completed'){global $db;for($i=0;$i<1800;$i++){$j=$db->one('SELECT status,error,result_json FROM jobs WHERE id=?',[$id]);if(in_array($j['status'],['completed','failed'],true)){if($j['status']!==$status)throw new RuntimeException('Job '.$id.': '.$j['error']);return $j;}usleep(100000);}throw new RuntimeException('Job timeout');}
try{
 $r=wpRequest('/websites',['server_id'=>$server,'domain'=>'wordpress-ci.example.invalid','php_version'=>'8.3']);$site=$r['id'];awaitJob($r['job_id']);
 $public='/srv/vpsmanager/t'.$u['tenant_id'].'/s'.$site.'/public_html';
 wpRequest('/files',['website_id'=>$site,'action'=>'write','path'=>'existing.txt','content'=>base64_encode('preserve-my-site')]);
 $data=['plugin'=>'wordpress','website_id'=>$site,'admin_email'=>'wordpress@example.invalid','admin_password'=>'WordPressTest!25841'];
 $failed=wpRequest('/plugins/install',$data);$failure=awaitJob($failed['job_id'],'failed');
 if(!str_contains($failure['error'],'site vazio'))throw new RuntimeException('Expected actionable occupied-site error');
 if(file_get_contents($public.'/existing.txt')!=='preserve-my-site')throw new RuntimeException('Existing file modified');
 if(file_exists($public.'/wp-config.php'))throw new RuntimeException('Installation overwrote occupied site');
 echo "PASS WordPress refuses occupied site without changing existing content\n";
 $failedDb=(int)$db->scalar('SELECT database_id FROM plugin_installations WHERE job_id=?',[$failed['job_id']]);
 $removed=wpRequest('/databases/'.$failedDb,[],'DELETE');awaitJob($removed['job_id']);
 wpRequest('/files',['website_id'=>$site,'action'=>'delete','path'=>'existing.txt']);
 $r=wpRequest('/plugins/install',$data);$j=awaitJob($r['job_id']);
 $database=(int)$db->scalar('SELECT database_id FROM plugin_installations WHERE job_id=?',[$r['job_id']]);$databases[]=$database;
 if($db->scalar('SELECT status FROM `databases` WHERE id=?',[$database])!=='active')throw new RuntimeException('Automatic database not active');
 $result=json_decode($j['result_json'],true);if(empty($result['version']))throw new RuntimeException('WordPress version absent');
 $dbName=$db->scalar('SELECT name FROM `databases` WHERE id=?',[$database]);$root=new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname='.$dbName,'root','');
 if((int)$root->query('SELECT COUNT(*) FROM wp_users')->fetchColumn()!==1)throw new RuntimeException('Administrator missing');
 $webGroup=posix_getgrnam('www-data')['gid'];
 foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($public,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST) as $file){
  if($file->getGroup()!==$webGroup||($file->isDir()&&($file->getPerms()&02000)===0))throw new RuntimeException('WordPress web-group inheritance lost: '.$file->getFilename());
 }
 $c=curl_init('https://wordpress-ci.example.invalid/wp-login.php');$headers='';
 curl_setopt_array($c,[CURLOPT_RESOLVE=>['wordpress-ci.example.invalid:443:127.0.0.1'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['log'=>$data['admin_email'],'pwd'=>$data['admin_password'],'wp-submit'=>'Log In']),CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>0,CURLOPT_HEADERFUNCTION=>function($c,$h)use(&$headers){$headers.=$h;return strlen($h);}]);curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);
 if($status!==302||!str_contains($headers,'wordpress_logged_in_'))throw new RuntimeException('WordPress email/password login failed: '.$status);
 $c=curl_init('https://wordpress-ci.example.invalid/');curl_setopt_array($c,[CURLOPT_RESOLVE=>['wordpress-ci.example.invalid:443:127.0.0.1'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>0]);$html=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);if($status!==200||!str_contains($html,'wordpress-ci.example.invalid'))throw new RuntimeException('WordPress homepage failed');
 wpRequest('/plugins/install',$data,'POST',409);
 echo "PASS official WordPress download, automatic isolated DB, admin creation, HTTPS homepage, email/password login and duplicate guard\n";
}finally{
 foreach($databases as $id){$r=wpRequest('/databases/'.$id,[],'DELETE');awaitJob($r['job_id']);}
 if($site){$r=wpRequest('/websites/'.$site,[],'DELETE');awaitJob($r['job_id']);}
 $db->query('DELETE FROM api_keys WHERE id=?',[$key]);
}
