<?php
check('WordPress reserves an automatic database and encrypted install job; no credential input required',function()use($db,$master,$server){
    $site=(new \App\Services\ResourceService($db,$master))->create('websites',['server_id'=>$server,'domain'=>'plugins.example.com','php_version'=>'8.3'])['id'];
    $db->query("UPDATE websites SET status='active' WHERE id=?",[$site]);
    $store=new \App\Services\PluginStore($db,$master);
    $data=['plugin'=>'wordpress','website_id'=>$site,'admin_email'=>'wp@example.com','admin_password'=>'WordPressTest!12789'];
    $result=$store->install($data);$job=$db->one('SELECT * FROM jobs WHERE id=?',[$result['job_id']]);
    $payload=json_decode(\App\Helpers\Crypto::decrypt($job['payload']),true);
    eq($job['operation'],'install_wordpress');eq(str_contains($job['payload'],'WordPressTest'),false);
    eq(strlen($payload['database_password']),48);eq($payload['admin_email'],$data['admin_email']);
    $database=$db->one('SELECT * FROM `databases` WHERE id=?',[$payload['database_id']]);
    eq($database['name'],$payload['database_name']);eq((int)$database['owner_id'],(int)$master->user['id']);
    eq(str_contains($database['config_json'],$payload['database_password']),false);
    eq(str_contains(json_encode($store->listing()),'WordPressTest'),false);
    $count=(int)$db->scalar('SELECT COUNT(*) FROM `databases`');
    denied(fn()=>$store->install($data),409);eq((int)$db->scalar('SELECT COUNT(*) FROM `databases`'),$count);
});
check('plugin installation enforces ownership, runtime and create-database permission before reservation',function()use($db,$master,$outsider,$server){
    $site=(new \App\Services\ResourceService($db,$master))->create('websites',['server_id'=>$server,'domain'=>'plugins-old.example.com','php_version'=>'7.4'])['id'];
    $db->query("UPDATE websites SET status='active' WHERE id=?",[$site]);
    $data=['plugin'=>'wordpress','website_id'=>$site,'admin_email'=>'wp@example.com','admin_password'=>'WordPressTest!12789'];
    $count=(int)$db->scalar('SELECT COUNT(*) FROM `databases`');
    denied(fn()=>(new \App\Services\PluginStore($db,$master))->install($data),422);
    denied(fn()=>(new \App\Services\PluginStore($db,$outsider))->install($data),404);
    $limited=new \App\Middleware\Policy($db,$master->user,['websites.view','websites.edit','files.manage']);
    denied(fn()=>(new \App\Services\PluginStore($db,$limited))->install($data),403);
    denied(fn()=>(new \App\Services\PluginStore($db,$master))->install(array_merge($data,['plugin'=>'arbitrary-url'])),422);
    eq((int)$db->scalar('SELECT COUNT(*) FROM `databases`'),$count);
});
