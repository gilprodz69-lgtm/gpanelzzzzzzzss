<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli') exit(1);
use App\Repositories\Database;
use App\Helpers\Crypto;
$db=new Database();
$u=$db->one("SELECT id,tenant_id FROM users WHERE role='MASTER' ORDER BY id LIMIT 1");
if(!$u) throw new RuntimeException('Administrador não encontrado.');
if($db->scalar('SELECT COUNT(*) FROM servers')) throw new RuntimeException('Servidor já cadastrado.');
$secret=trim(file_get_contents('/etc/vpsmanager/agent.secret'));
if(strlen($secret)<32) throw new RuntimeException('Segredo do agente inválido.');
$db->transaction(function()use($db,$u,$secret){
    $id=$db->insert('servers',['tenant_id'=>$u['tenant_id'],'name'=>gethostname()?:'VPS principal','address'=>parse_url(getenv('APP_URL'),PHP_URL_HOST),'agent_url'=>'http://127.0.0.1:9081','os'=>'Ubuntu '.(str_contains(file_get_contents('/etc/os-release'),'24.04')?'24.04':'22.04'),'created_at'=>time()]);
    $db->insert('server_credentials',['server_id'=>$id,'secret'=>Crypto::encrypt($secret)]);
});
echo "Agente local vinculado ao painel.\n";
