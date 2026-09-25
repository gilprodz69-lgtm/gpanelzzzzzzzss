<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli') exit(1);
use App\Repositories\Database;
use App\Services\AgentClient;
try {
    $db=new Database();
    if((int)$db->scalar('SELECT COUNT(*) FROM migrations')<count(glob(BASE_PATH.'/database/migrations/*.php'))) throw new RuntimeException('Migrations incompletas.');
    $server=$db->one('SELECT * FROM servers ORDER BY id LIMIT 1');
    if(!$server) throw new RuntimeException('Servidor local não cadastrado.');
    $metrics=(new AgentClient($db))->call($server,'metrics',[],bin2hex(random_bytes(16)));
    if(!isset($metrics['cpu'],$metrics['ram'],$metrics['disk'])) throw new RuntimeException('Resposta do agente inválida.');
    echo "OK: banco, migrations, credencial criptografada, assinatura do agente e métricas.\n";
} catch(Throwable $e) { fwrite(STDERR,$e->getMessage().PHP_EOL); exit(1); }
