<?php
use App\Services\ResourceService;
use App\Helpers\Crypto;
check('SSL retry reuses reservation, creates a new job and blocks repeated clicks',function()use($db,$master,$server){
    $service=new ResourceService($db,$master);
    $site=$service->create('websites',['server_id'=>$server,'domain'=>'retry-ssl.example.com'])['id'];
    $db->query("UPDATE websites SET status='active' WHERE id=?",[$site]);
    $created=$service->create('ssl_certificates',['server_id'=>$server,'website_id'=>$site]);
    $id=$created['id'];
    denied(fn()=>$service->retrySsl($id),409);
    $db->query("UPDATE ssl_certificates SET status='failed' WHERE id=?",[$id]);
    $db->query("UPDATE jobs SET status='failed' WHERE id=?",[$created['job_id']]);
    $count=(int)$db->scalar('SELECT COUNT(*) FROM ssl_certificates');
    $result=$service->retrySsl($id);eq($result['id'],$id);eq($result['status'],'pending');eq($result['job_id']>$created['job_id'],true);
    eq((int)$db->scalar('SELECT COUNT(*) FROM ssl_certificates'),$count);
    $job=$db->one('SELECT * FROM jobs WHERE id=?',[$result['job_id']]);
    eq($job['operation'],'create_ssl');eq((int)$job['resource_id'],$id);
    $payload=json_decode(Crypto::decrypt($job['payload']),true);eq($payload['website_id'],$site);eq($payload['domain'],'retry-ssl.example.com');
    eq((int)$db->scalar('SELECT COUNT(*) FROM audit_logs WHERE action=?',['ssl_certificates.retry']),1);
    denied(fn()=>$service->retrySsl($id),409);
    denied(fn()=>$service->create('ssl_certificates',['server_id'=>$server,'website_id'=>$site]),409);
    $db->query("UPDATE ssl_certificates SET status='active' WHERE id=?",[$id]);
    denied(fn()=>$service->retrySsl($id),409);
});
check('SSL retry preserves account isolation and rejects deletion failures or unavailable sites',function()use($db,$master,$client,$outsider,$server){
    $service=new ResourceService($db,$master);
    $site=$service->create('websites',['server_id'=>$server,'domain'=>'retry-guard.example.com'])['id'];
    $db->query("UPDATE websites SET status='active' WHERE id=?",[$site]);
    $created=$service->create('ssl_certificates',['server_id'=>$server,'website_id'=>$site]);$id=$created['id'];
    $db->query("UPDATE ssl_certificates SET status='failed' WHERE id=?",[$id]);
    $db->query("UPDATE jobs SET status='failed' WHERE id=?",[$created['job_id']]);
    denied(fn()=>(new ResourceService($db,$client))->retrySsl($id),404);
    denied(fn()=>(new ResourceService($db,$outsider))->retrySsl($id),404);
    $db->query("UPDATE websites SET status='failed' WHERE id=?",[$site]);denied(fn()=>$service->retrySsl($id),409);
    $db->query("UPDATE websites SET status='active',name='renamed.example.com' WHERE id=?",[$site]);denied(fn()=>$service->retrySsl($id),409);
    $db->query("UPDATE websites SET name='retry-guard.example.com' WHERE id=?",[$site]);
    $db->query("UPDATE jobs SET operation='delete_ssl' WHERE id=?",[$created['job_id']]);denied(fn()=>$service->retrySsl($id),409);
    $db->query("UPDATE jobs SET operation='create_ssl',status='running' WHERE id=?",[$created['job_id']]);denied(fn()=>$service->retrySsl($id),409);
});
