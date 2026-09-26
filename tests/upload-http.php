<?php
// Run only on the disposable Ubuntu CI host, through Nginx, PHP and the signed agent.
if(getenv('GITHUB_ACTIONS')!=='true')exit("SKIP disposable CI host required\n");
require '/opt/vpsmanager/current/app/bootstrap.php';
$db=new App\Repositories\Database();$u=$db->one("SELECT * FROM users WHERE role='MASTER' LIMIT 1");
$server=(int)$db->scalar('SELECT id FROM servers WHERE tenant_id=? LIMIT 1',[$u['tenant_id']]);
$token='vpm_'.bin2hex(random_bytes(32));$site=null;$transfer=null;
$key=$db->insert('api_keys',['user_id'=>$u['id'],'name'=>'CI transfer','token_hash'=>hash('sha256',$token),'scopes_json'=>'["websites.create","websites.delete","files.manage"]','created_at'=>time(),'expires_at'=>time()+600]);
function http($path,$data=[],$binary=null,$expected=200,$auth=true){global $token,$site;
 $headers=['Content-Type: '.($binary===null?'application/json':'application/octet-stream')];if($auth)$headers[]='Authorization: Bearer '.$token;
 if($binary!==null)foreach(['Site'=>$site,'Id'=>$data['id'],'Offset'=>$data['offset']] as $k=>$v)$headers[]='X-Upload-'.$k.': '.$v;
 $curl=curl_init('https://127.0.0.1:8443/api/v1'.$path);
 curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>str_starts_with($path,'/websites/')?'DELETE':'POST',CURLOPT_POSTFIELDS=>$binary??json_encode($data),CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>180,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>0]);
 $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
 if($status!==$expected)throw new RuntimeException('Unexpected HTTP '.$status.' for '.$path.': '.substr((string)$body,0,200));return json_decode($body,true);
}
function files($action,$extra=[]){global $site;return http('/files',['website_id'=>$site,'action'=>$action,...$extra]);}
function waitSite($desired){global $db,$site;for($i=0;$i<600;$i++){if($db->scalar('SELECT status FROM websites WHERE id=?',[$site])===$desired)return;usleep(100000);}throw new RuntimeException('Site did not become '.$desired);}
try{
 $site=http('/websites',['server_id'=>$server,'domain'=>'upload-ci.example.invalid','php_version'=>'8.3'])['id'];waitSite('active');
 $data=random_bytes(8*1048576);$tail='binary-tail';$transfer=files('upload_begin',['path'=>'transfer.bin','size'=>strlen($data)+strlen($tail)])['id'];
 $headers=['id'=>$transfer,'offset'=>0];
 http('/files/upload-chunk',$headers,'small',401,false);
 http('/files/upload-chunk',$headers,'',422);
 http('/files/upload-chunk',$headers,$data.'x',413);
 $first=http('/files/upload-chunk',$headers,$data);
 if($first['offset']!==strlen($data))throw new RuntimeException('Wrong offset');
 http('/files/upload-chunk',$headers,$data); // A response was lost; exact retry must not append twice.
 http('/files/upload-chunk',$headers,'different',502);
 http('/files/upload-chunk',['id'=>$transfer,'offset'=>strlen($data)],$tail);
 files('upload_finish',['id'=>$transfer]);$transfer=null;
 $digest=hash_init('sha256');$offset=0;
 do{$part=files('download',['path'=>'transfer.bin','offset'=>$offset]);$raw=base64_decode($part['content']);hash_update($digest,$raw);$offset+=strlen($raw);}while($offset<$part['size']);
 if(hash_final($digest)!==hash('sha256',$data.$tail))throw new RuntimeException('Binary integrity mismatch');
 echo "PASS binary HTTP upload: Nginx 8 MiB, authentication, retry, limits, integrity, signed agent and file publication\n";
}finally{
 try{if($transfer)files('upload_cancel',['id'=>$transfer]);if($site){http('/websites/'.$site);waitSite('deleted');}}finally{$db->query('DELETE FROM api_keys WHERE id=?',[$key]);}
}
