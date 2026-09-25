<?php
declare(strict_types=1);
use App\Repositories\Database;
return static function(Database $db): void {
    foreach(['cpu_count'=>'INTEGER','memory_used'=>'BIGINT','memory_total'=>'BIGINT','disk_used'=>'BIGINT','disk_total'=>'BIGINT','network_rx_bps'=>'REAL','network_tx_bps'=>'REAL'] as $column=>$type) {
        // Resume safely after a partial migration (MySQL DDL commits immediately).
        $columns=$db->driver()==='mysql'?array_column($db->all('SHOW COLUMNS FROM server_metrics'),'Field'):array_column($db->all('PRAGMA table_info(server_metrics)'),'name');
        if(in_array($column,$columns,true)) continue;
        $db->exec("ALTER TABLE server_metrics ADD COLUMN $column $type NULL");
    }
};
