<?php
declare(strict_types=1);
namespace App\Services;
use App\Repositories\Database;
use App\Helpers\{Crypto,HttpError};
final class AgentClient
{
    public function __construct(private Database $db) {}
    public function call(array $server,string $operation,array $payload,string $requestId): array {
        $credential=$this->db->one('SELECT secret FROM server_credentials WHERE server_id=?',[$server['id']]);
        if(!$credential) throw new HttpError(502,'Credencial do agente não configurada.');
        $body=json_encode(['operation'=>$operation,'payload'=>(object)$payload,'request_id'=>$requestId],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $timestamp=(string)time(); $nonce=bin2hex(random_bytes(16));
        $signature=hash_hmac('sha256',"$timestamp\n$nonce\n$body",Crypto::decrypt($credential['secret']));
        $c=curl_init($server['agent_url'].'/v1/execute');
        curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>180,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS|(getenv('AGENT_ALLOW_HTTP_LOOPBACK')==='1'?CURLPROTO_HTTP:0),CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Timestamp: '.$timestamp,'X-Nonce: '.$nonce,'X-Signature: '.$signature]]);
        $ca=getenv('AGENT_CA_FILE'); if($ca) curl_setopt($c,CURLOPT_CAINFO,$ca);
        $raw=curl_exec($c); $error=curl_error($c); $status=curl_getinfo($c,CURLINFO_RESPONSE_CODE); curl_close($c);
        if($raw===false) throw new HttpError(502,'Agente indisponível: '.$error);
        try { $result=json_decode($raw,true,32,JSON_THROW_ON_ERROR); } catch(\JsonException) { throw new HttpError(502,'Resposta inválida do agente.'); }
        if($status!==200 || !is_array($result)) throw new HttpError(502,is_string($result['error']??null)?$result['error']:'Operação rejeitada pelo agente.');
        return $result;
    }
}
