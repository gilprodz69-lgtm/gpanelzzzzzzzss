<?php
declare(strict_types=1);
namespace App\Services;
use App\Repositories\Database;
use App\Helpers\Crypto;
final class Jobs
{
    public function __construct(private Database $db) {}
    public function enqueue(array $u,int $server,string $op,array $payload,?string $type=null,?int $resource=null): int {
        return $this->db->insert('jobs',['tenant_id'=>$u['tenant_id'],'owner_id'=>$u['id'],'server_id'=>$server,'resource_type'=>$type,'resource_id'=>$resource,'operation'=>$op,'payload'=>Crypto::encrypt(json_encode($payload,JSON_THROW_ON_ERROR)),'created_at'=>time()]);
    }
}
