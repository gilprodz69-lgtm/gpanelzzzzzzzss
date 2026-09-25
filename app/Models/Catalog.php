<?php
declare(strict_types=1);
namespace App\Models;
final class Catalog
{
    public const RESOURCES = [
        'websites' => ['label'=>'Sites','operation'=>'create_site','fields'=>['domain','php_version']],
        'domains' => ['label'=>'Domínios','operation'=>'create_domain','fields'=>['domain','website_id','type','target']],
        'databases' => ['label'=>'Bancos de dados','operation'=>'create_database','fields'=>['name','password']],
        'ssl_certificates' => ['label'=>'Certificados SSL','operation'=>'create_ssl','fields'=>['website_id','email']],
        'ftp_accounts' => ['label'=>'Contas SFTP','operation'=>'create_sftp','fields'=>['website_id','name','password']],
        'backups' => ['label'=>'Backups','operation'=>'create_backup','fields'=>['website_id']],
        'cron_jobs' => ['label'=>'Tarefas agendadas','operation'=>'create_cron','fields'=>['website_id','schedule','path']],
        'firewall_rules' => ['label'=>'Firewall','operation'=>'create_firewall','fields'=>['port','protocol','source','action']],
        'docker_containers' => ['label'=>'Containers','operation'=>'create_container','fields'=>['name','image','memory_mb','cpu']],
    ];
    public const QUOTAS = ['websites','domains','databases','ssl_certificates','ftp_accounts','backups','cron_jobs','docker_containers','users','storage_mb','traffic_mb','cpu','ram_mb'];
    public static function permissions(): array {
        $p = ['dashboard.view','audit_logs.view','settings.manage','files.manage','terminal.access','services.manage','metrics.view','jobs.view','backups.restore'];
        foreach (array_merge(['users','plans','servers'], array_keys(self::RESOURCES)) as $module) foreach (['view','create','edit','delete'] as $verb) $p[] = "$module.$verb";
        return $p;
    }
    public static function defaults(string $role): array {
        if ($role === 'MASTER') return self::permissions();
        $p = ['dashboard.view','jobs.view','files.manage','websites.edit','databases.edit'];
        foreach (array_keys(self::RESOURCES) as $module) {
            if (in_array($module, ['firewall_rules','docker_containers'], true)) continue;
            foreach (['view','create','delete'] as $verb) $p[] = "$module.$verb";
        }
        if ($role === 'RESELLER') $p = array_merge($p, ['users.view','users.create','users.edit','users.delete','plans.view','plans.create','plans.edit','plans.delete']);
        return $p;
    }
}
