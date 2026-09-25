<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use App\Repositories\Database;
use App\Services\{Migrator,Worker,BackupScheduler};
use App\Models\Catalog;
use App\Validators\Input;
if(PHP_SAPI!=='cli') exit(1);
try {
    $command=$argv[1]??'help';
    if($command==='key:generate') { echo base64_encode(random_bytes(32)).PHP_EOL; exit; }
    $db=new Database();
    if($command==='migrate') { Migrator::run($db); echo "Migrations aplicadas.\n"; }
    elseif($command==='install') {
        Migrator::run($db);
        $email=Input::email(getenv('ADMIN_EMAIL')?:''); $password=Input::password(getenv('ADMIN_PASSWORD')?:'');
        if($db->scalar('SELECT COUNT(*) FROM users')) throw new RuntimeException('Instalação já inicializada.');
        $db->transaction(function() use($db,$email,$password){
            $tenant=$db->insert('tenants',['name'=>'Minha organização','created_at'=>time()]);
            $limits=array_fill_keys(Catalog::QUOTAS,10); $limits['storage_mb']=20480; $limits['traffic_mb']=102400; $limits['ram_mb']=1024; $limits['cpu']=1; $limits['users']=5;
            $plan=$db->insert('plans',['tenant_id'=>$tenant,'name'=>'Essencial','limits_json'=>json_encode($limits),'features_json'=>json_encode(['ftp_accounts','ssl_certificates','backups','files']),'price_cents'=>4990,'created_at'=>time()]);
            $uid=$db->insert('users',['tenant_id'=>$tenant,'plan_id'=>$plan,'name'=>'Administrador','email'=>$email,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'role'=>'MASTER','created_at'=>time()]);
            $db->insert('user_roles',['user_id'=>$uid,'role_id'=>$db->scalar("SELECT id FROM roles WHERE name='MASTER'")]);
        });
        echo "Organização, plano e administrador criados.\n";
    }
    elseif($command==='worker') { $w=new Worker($db); $once=in_array('--once',$argv,true); do { $worked=$w->runOne(); if(!$once&&!$worked) sleep(2); } while(!$once); }
    elseif($command==='queue:wait') {
        $deadline=time()+300;
        while($db->scalar("SELECT COUNT(*) FROM jobs WHERE status IN ('pending','running')")) {
            if(time()>=$deadline) throw new RuntimeException('Ainda existem operações pendentes/em execução. Verifique a fila antes de atualizar.');
            sleep(1);
        }
        echo "Fila concluída; atualização pode prosseguir.\n";
    }
    elseif($command==='metrics') { (new Worker($db))->collect(); (new BackupScheduler($db))->tick(); echo "Coleta e agendamentos concluídos.\n"; }
    elseif($command==='password:reset-link') {
        $u=$db->one('SELECT * FROM users WHERE email=?',[Input::email($argv[2]??'')]); if(!$u) throw new RuntimeException('Conta não encontrada.');
        $token=bin2hex(random_bytes(32)); $db->query('DELETE FROM password_resets WHERE user_id=?',[$u['id']]); $db->insert('password_resets',['token_hash'=>hash('sha256',$token),'user_id'=>$u['id'],'expires_at'=>time()+900]);
        echo rtrim(getenv('APP_URL')?:'http://127.0.0.1:8080','/').'/?reset='.$token.PHP_EOL;
    }
    else echo "Comandos: key:generate, migrate, install, worker [--once], metrics, password:reset-link EMAIL\n";
} catch(Throwable $e) { fwrite(STDERR,$e->getMessage().PHP_EOL); exit(1); }
