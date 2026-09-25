<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
$db=new App\Repositories\Database();
$host=parse_url(getenv('APP_URL'),PHP_URL_HOST);
$email=$db->scalar("SELECT email FROM users WHERE role='MASTER' AND status='active' ORDER BY id LIMIT 1");
App\Validators\Input::email($email);
if(!$host || (!filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4) && !preg_match('/^[a-z0-9.-]+$/D',$host))) throw new RuntimeException('APP_URL inválida.');
echo json_encode(['host'=>$host,'email'=>$email],JSON_THROW_ON_ERROR);
