<?php
declare(strict_types=1);
// Use an explicitly configured test administrator. Creates a unique empty database.
$host=getenv('TEST_MYSQL_HOST')?:'127.0.0.1'; $port=getenv('TEST_MYSQL_PORT')?:'3307';
$pdo=new PDO("mysql:host=$host;port=$port",getenv('DB_USER')?:'root',getenv('DB_PASSWORD')?:'', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='vpm_test_'.bin2hex(random_bytes(8));
$pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
register_shutdown_function(static function()use($pdo,$name){$pdo->exec("DROP DATABASE `$name`");});
putenv("TEST_MYSQL_DSN=mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4");
require __DIR__.'/run.php';
