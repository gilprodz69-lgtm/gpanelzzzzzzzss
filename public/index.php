<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if(is_file(BASE_PATH.'/storage/maintenance')) { http_response_code(503); header('Retry-After: 120'); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error'=>'Manutenção programada. Tente novamente em instantes.']); exit; }
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Cache-Control: no-store');
if(getenv('COOKIE_SECURE')!=='0') header('Strict-Transport-Security: max-age=31536000');
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
if(!str_starts_with($path,'/api/v1/')) { require BASE_PATH.'/resources/views/app.php'; exit; }
header('Content-Type: application/json; charset=utf-8');
try {
    $method=$_SERVER['REQUEST_METHOD'];
    if((int)($_SERVER['CONTENT_LENGTH']??0)>2097152) throw new App\Helpers\HttpError(413,'Requisição excede 2 MB.');
    if(isset($_SERVER['HTTP_ORIGIN'])) {
        $expected=rtrim(getenv('APP_URL')?:'http://127.0.0.1:8080','/');
        if($_SERVER['HTTP_ORIGIN']!==$expected) throw new App\Helpers\HttpError(403,'Origem não autorizada.');
    }
    $raw=file_get_contents('php://input',false,null,0,2097153);
    if(strlen($raw)>2097152) throw new App\Helpers\HttpError(413,'Requisição excede 2 MB.');
    $data=[];
    if($raw!=='') {
        if(!str_starts_with($_SERVER['CONTENT_TYPE']??'','application/json')) throw new App\Helpers\HttpError(415,'Envie application/json.');
        $data=json_decode($raw,true,32,JSON_THROW_ON_ERROR); if(!is_array($data)||array_is_list($data)&&$data!==[]) throw new App\Helpers\HttpError(422,'Envie um objeto JSON.');
    }
    $result=(new App\Controllers\ApiController(new App\Repositories\Database()))->handle($method,substr($path,7),$data);
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch(App\Helpers\HttpError $e) { http_response_code($e->status); echo json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE); }
catch(JsonException) { http_response_code(400); echo json_encode(['error'=>'JSON inválido.']); }
catch(PDOException $e) { $conflict=str_starts_with((string)$e->getCode(),'23'); http_response_code($conflict?409:500); error_log($e->getMessage()); echo json_encode(['error'=>$conflict?'Registro duplicado ou com vínculos existentes.':'Erro de persistência. Consulte o log do servidor.']); }
catch(Throwable $e) { http_response_code(500); error_log((string)$e); echo json_encode(['error'=>'Falha interna. Consulte o log do servidor.']); }
