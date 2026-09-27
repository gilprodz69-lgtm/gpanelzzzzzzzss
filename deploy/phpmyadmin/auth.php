<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/app/bootstrap.php';
header('Content-Type: application/json'); header('Cache-Control: no-store');
try {
    $key=trim((string)file_get_contents('/etc/vpsmanager-pma-bridge/secret'));
    if(strlen($key)<32 || !hash_equals($key,$_SERVER['HTTP_X_PMA_BRIDGE']??'') || ($_SERVER['REQUEST_METHOD']??'')!=='POST') throw new RuntimeException();
    $db=new App\Repositories\Database(); $auth=new App\Services\Auth($db); $policy=$auth->authenticate();
    echo json_encode((new App\Services\PhpMyAdmin($db,$auth,$policy))->credentials($_COOKIE['vpm_pma']??''),JSON_THROW_ON_ERROR);
} catch(Throwable) { http_response_code(403); echo '{}'; }
