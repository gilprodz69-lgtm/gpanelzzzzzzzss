<?php
check('subdomain folders validate names, reserve distinct paths and preserve default folder',function()use($db,$master,$server){
    $r=new \App\Services\ResourceService($db,$master);
    $site=$r->create('websites',['server_id'=>$server,'domain'=>'folder-test.example.com'])['id'];$db->query("UPDATE websites SET status='active' WHERE id=?",[$site]);
    $data=['website_id'=>$site,'type'=>'subdomain','parent_domain'=>'folder-test.example.com','prefix'=>'blog','document_root'=>'my_blog'];
    foreach(['../escape','/root','two/folders','.hidden','bad;name',[],str_repeat('a',191)] as $bad)denied(fn()=>$r->create('domains',array_replace($data,['document_root'=>$bad])),422);
    $id=$r->create('domains',$data)['id'];$row=$r->present($r->find('domains',$id));eq($row['config']['document_root'],'my_blog');
    denied(fn()=>$r->create('domains',array_replace($data,['prefix'=>'other'])),409);
    $default=$r->create('domains',array_replace($data,['prefix'=>'auto','document_root'=>'']))['id'];eq($r->present($r->find('domains',$default))['config']['document_root'],'auto.folder-test.example.com');
});
check('domain PHP overrides validate scope, permissions, content type and serialize requests',function()use($db,$master,$client,$server){
    $r=new \App\Services\ResourceService($db,$master);$service=new \App\Services\DomainService($db,$master);
    $site=$r->create('websites',['server_id'=>$server,'domain'=>'php-domain.example.com','php_version'=>'8.3'])['id'];$db->query("UPDATE websites SET status='active' WHERE id=?",[$site]);
    $id=$r->create('domains',['website_id'=>$site,'type'=>'alias','domain'=>'alias-php.example.com'])['id'];$db->query("UPDATE domains SET status='active' WHERE id=?",[$id]);
    denied(fn()=>(new \App\Services\DomainService($db,$client))->changePhp($id,['php_version'=>'8.1']),404);
    $limited=new \App\Middleware\Policy($db,$master->user,['domains.edit']);
    denied(fn()=>(new \App\Services\DomainService($db,$limited))->changePhp($id,['php_version'=>'8.1']),403);
    denied(fn()=>$service->changePhp($id,['php_version'=>'9.9']),422);
    $job=$service->changePhp($id,['php_version'=>'8.1']);$row=$db->one('SELECT * FROM jobs WHERE id=?',[$job['job_id']]);
    eq($row['operation'],'change_domain_php');eq($row['resource_type'],'domains');
    $p=json_decode(\App\Helpers\Crypto::decrypt($row['payload']),true);eq($p['website_id'],$site);eq($p['php_version'],'8.1');
    eq($r->present($r->find('websites',$site))['config']['php_version'],'8.3');eq($r->find('websites',$site)['status'],'active');
    denied(fn()=>$service->changePhp($id,['php_version'=>'8.2']),409);
    foreach(['redirect','parked'] as $type){$id2=$r->create('domains',['website_id'=>$site,'type'=>$type,'domain'=>$type.'-php.example.com','target'=>'dest.example.com'])['id'];$db->query("UPDATE domains SET status='active' WHERE id=?",[$id2]);denied(fn()=>$service->changePhp($id2,['php_version'=>'8.1']),422);}
});
