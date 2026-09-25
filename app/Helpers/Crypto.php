<?php
declare(strict_types=1);
namespace App\Helpers;
final class Crypto
{
    private static function key(): string {
        $key = base64_decode(getenv('APP_KEY') ?: '', true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new \RuntimeException('APP_KEY ausente ou inválida.');
        return $key;
    }
    public static function encrypt(string $value): string {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($value, $nonce, self::key()));
    }
    public static function decrypt(string $value): string {
        $bytes = base64_decode($value, true);
        if (!$bytes || strlen($bytes) < 40) throw new \RuntimeException('Credencial inválida.');
        $plain = sodium_crypto_secretbox_open(substr($bytes, 24), substr($bytes, 0, 24), self::key());
        if ($plain === false) throw new \RuntimeException('Falha na autenticação da credencial.');
        return $plain;
    }
}
