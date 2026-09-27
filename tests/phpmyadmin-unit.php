<?php
use App\Services\Auth;
use App\Helpers\Crypto;
// Included by run.php using its isolated SQL fixtures.
check('branding refuses active content and excessive image dimensions',function(){
    denied(fn()=>\App\Services\Branding::validate(['logo'=>'data:image/svg+xml;base64,'.base64_encode('<svg/>')]),422);
    denied(fn()=>\App\Services\Branding::validate(['logo'=>'https://external.invalid/image.png']),422);
    denied(fn()=>\App\Services\Branding::validate(['logo'=>'data:image/png;base64,'.base64_encode('<?php echo 1;')]),422);
    eq(\App\Services\Branding::validate(['name'=>'My panel','logo'=>null]),['name'=>'My panel','logo'=>null]);
    if(function_exists('imagecreatetruecolor')){
        $im=imagecreatetruecolor(20,10); ob_start();imagepng($im);$png=ob_get_clean();
        $safe=\App\Services\Branding::validate(['logo'=>'data:image/png;base64,'.base64_encode($png.'<script>bad</script>')]);
        eq(str_contains(base64_decode(explode(',',$safe['logo'])[1]),'<script>'),false);
        $im=imagecreatetruecolor(2001,1); ob_start();imagepng($im);$png=ob_get_clean();
        denied(fn()=>\App\Services\Branding::validate(['logo'=>'data:image/png;base64,'.base64_encode($png)]),422);
    }
});
check('branding authorization requires admin even with delegated settings permission',function()use($db,$clientId,$resellerId,$masterId){
    foreach([$clientId,$resellerId] as $uid){
        $db->query('DELETE FROM user_permissions WHERE user_id=? AND permission=?',[$uid,'settings.manage']);
        $db->insert('user_permissions',['user_id'=>$uid,'permission'=>'settings.manage','allowed'=>1]);
        $raw=bin2hex(random_bytes(32));$csrf=bin2hex(random_bytes(32));
        $db->insert('sessions',['id'=>hash('sha256',$raw),'user_id'=>$uid,'csrf'=>$csrf,'ip'=>'127.0.0.1','user_agent'=>'test','created_at'=>time(),'last_seen'=>time(),'expires_at'=>time()+600]);
        $_COOKIE['vps_session']=$raw;$_SERVER['HTTP_X_CSRF_TOKEN']=$csrf;unset($_SERVER['HTTP_AUTHORIZATION']);
        denied(fn()=>(new \App\Controllers\ApiController($db))->handle('POST','/settings',['name'=>'branding','value'=>['name'=>'Forbidden']]),403);
    }
    unset($_COOKIE['vps_session'],$_SERVER['HTTP_X_CSRF_TOKEN']);
});
check('phpMyAdmin leases enforce database scope, session binding, permission, expiry and single use',function()use($db,$master,$masterId,$outsider,$server){
    $db->query('UPDATE servers SET agent_url=? WHERE id=?',['https://127.0.0.1:9443',$server]);
    $id=(int)$db->scalar('SELECT id FROM `databases` LIMIT 1');$db->query("UPDATE `databases` SET status='active' WHERE id=?",[$id]);
    $auth=new Auth($db);$auth->session=['id'=>hash('sha256','test-session'),'expires_at'=>time()+600];
    $service=new \App\Services\PhpMyAdmin($db,$auth,$master);
    $make=function($overrides=[])use($db,$id,$auth){$token=bin2hex(random_bytes(32));$db->insert('phpmyadmin_sessions',array_merge(['token_hash'=>hash('sha256',$token),'session_id'=>$auth->session['id'],'database_id'=>$id,'credential'=>Crypto::encrypt('{"username":"temporary","password":"never-expose","database":"only_one"}'),'ticket_expires'=>time()+45,'expires_at'=>time()+600,'consumed'=>1],$overrides));return $token;};
    $token=$make();eq($service->credentials($token)['database'],'only_one');
    denied(fn()=>$service->credentials('../file'),403);
    denied(fn()=>$service->credentials($make(['session_id'=>'another-session'])),403);
    denied(fn()=>$service->credentials($make(['expires_at'=>time()-1])),403);
    denied(fn()=>$service->credentials($make(['consumed'=>0])),403);
    denied(fn()=>$service->consume($make(['consumed'=>0,'ticket_expires'=>time()-1])),403);
    denied(fn()=>(new \App\Services\PhpMyAdmin($db,$auth,$outsider))->credentials($token),404);
    $auth->bearer=true;denied(fn()=>$service->credentials($token),403);$auth->bearer=false;
    $db->query("UPDATE `databases` SET status='deleted' WHERE id=?",[$id]);denied(fn()=>$service->credentials($token),409);$db->query("UPDATE `databases` SET status='active' WHERE id=?",[$id]);
    $restricted=new \App\Middleware\Policy($db,$master->user,['databases.view']);
    denied(fn()=>(new \App\Services\PhpMyAdmin($db,$auth,$restricted))->credentials($token),403);
    $ticket=$make(['consumed'=>0]);
    // CLI tests have already printed output; suppress cookie header warning only.
    @$service->consume($ticket);
    denied(fn()=>$service->consume($ticket),403);denied(fn()=>$service->credentials($ticket),403);
});
