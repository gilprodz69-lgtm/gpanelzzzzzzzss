<?php
declare(strict_types=1);
namespace App\Services;
use App\Validators\Input;
use App\Helpers\HttpError;
final class NameserverSettings
{
    public static function validate(array $data): array {
        $ns1=Input::text($data['ns1']??'','NS1',0,253);
        $ns2=Input::text($data['ns2']??'','NS2',0,253);
        if($ns1==='' && $ns2==='') return ['ns1'=>'','ns2'=>'','status'=>'not_configured'];
        $ns1=Input::domain($ns1); $ns2=Input::domain($ns2);
        if($ns1===$ns2) throw new HttpError(422,'NS1 e NS2 devem ser nomes diferentes.');
        // Saving preferences does not provision DNS or change delegation at the registrar.
        return ['ns1'=>$ns1,'ns2'=>$ns2,'status'=>'pending_setup'];
    }
}
