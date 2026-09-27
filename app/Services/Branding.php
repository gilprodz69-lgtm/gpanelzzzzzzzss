<?php
declare(strict_types=1);
namespace App\Services;
use App\Repositories\Database;
use App\Helpers\HttpError;
use App\Validators\Input;
final class Branding
{
    // The sign-in page belongs to the installation's first organization, never a query parameter.
    public static function read(Database $db,?int $tenant=null): array {
        $tenant??=(int)$db->scalar('SELECT id FROM tenants ORDER BY id LIMIT 1');
        $raw=$db->scalar("SELECT value_json FROM settings WHERE tenant_id=? AND name='branding'",[$tenant]);
        $value=$raw?json_decode($raw,true):[];
        return ['name'=>$value['name']??'VPS Manager','logo'=>$value['logo']??null];
    }
    public static function validate(array $value): array {
        $name=Input::text($value['name']??'VPS Manager','Nome',1,40);
        $logo=$value['logo']??null;
        if($logo===null||$logo==='') return ['name'=>$name,'logo'=>null];
        if(!is_string($logo)||strlen($logo)>700000||!preg_match('#^data:image/(png|jpeg|webp);base64,([A-Za-z0-9+/=]+)$#D',$logo,$m)) throw new HttpError(422,'Envie uma imagem PNG, JPEG ou WebP de até 500 KB.');
        $bytes=base64_decode($m[2],true); $info=$bytes===false?false:@getimagesizefromstring($bytes);
        if(!$info||strlen($bytes)>512000||$info[0]>2000||$info[1]>2000||$info[0]<1||$info[1]<1||$info['mime']!=='image/'.$m[1]) throw new HttpError(422,'Imagem inválida. Use até 2000 × 2000 pixels e 500 KB.');
        // Decode and re-encode pixels, stripping embedded content, metadata and animation.
        $image=@imagecreatefromstring($bytes);
        if(!$image) throw new HttpError(422,'Não foi possível ler a imagem.');
        imagesavealpha($image,true); ob_start(); imagepng($image); $png=ob_get_clean();
        if(strlen($png)>512000) throw new HttpError(422,'Reduza as dimensões da logo para ficar abaixo de 500 KB.');
        return ['name'=>$name,'logo'=>'data:image/png;base64,'.base64_encode($png)];
    }
}
