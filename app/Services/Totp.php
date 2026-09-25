<?php
declare(strict_types=1);
namespace App\Services;
final class Totp
{
    public static function secret(): string { $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $out=''; for($i=0;$i<32;$i++) $out.=$alphabet[random_int(0,31)]; return $out; }
    public static function code(string $secret,int $step): string {
        $bits=''; foreach(str_split($secret) as $c) $bits.=str_pad(decbin(strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567',$c)),5,'0',STR_PAD_LEFT);
        $key=''; foreach(str_split($bits,8) as $part) if(strlen($part)===8) $key.=chr(bindec($part));
        $h=hash_hmac('sha1',pack('N2',0,$step),$key,true); $offset=ord($h[19]) & 15;
        $n=unpack('N',substr($h,$offset,4))[1] & 0x7fffffff;
        return str_pad((string)($n % 1000000),6,'0',STR_PAD_LEFT);
    }
    public static function verify(string $secret,string $code,int $lastStep=0): ?int {
        if(!preg_match('/^[0-9]{6}$/D',$code)) return null;
        $now=(int)floor(time()/30);
        foreach([$now-1,$now,$now+1] as $step) if($step>$lastStep && hash_equals(self::code($secret,$step),$code)) return $step;
        return null;
    }
}
