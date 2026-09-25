<?php
declare(strict_types=1);
namespace App\Validators;
use App\Helpers\HttpError;
final class Input
{
    public static function text(mixed $v, string $field, int $min = 1, int $max = 190): string {
        if (!is_string($v) || strlen(trim($v)) < $min || strlen(trim($v)) > $max || preg_match('/[\x00-\x1F\x7F]/', $v)) throw new HttpError(422, "$field inválido.");
        return trim($v);
    }
    public static function email(mixed $v): string { $v = strtolower(self::text($v, 'E-mail')); if (!filter_var($v, FILTER_VALIDATE_EMAIL)) throw new HttpError(422, 'E-mail inválido.'); return $v; }
    public static function password(mixed $v): string { if (!is_string($v) || strlen($v) < 12 || strlen($v) > 72) throw new HttpError(422, 'A senha deve ter entre 12 e 72 caracteres.'); return $v; }
    public static function integer(mixed $v, string $field, int $min = 1, int $max = 2147483647): int {
        if (filter_var($v, FILTER_VALIDATE_INT) === false || (int)$v < $min || (int)$v > $max) throw new HttpError(422, "$field inválido."); return (int)$v;
    }
    public static function choice(mixed $v, array $options, string $field): string { if (!is_string($v) || !in_array($v, $options, true)) throw new HttpError(422, "$field inválido."); return $v; }
    public static function domain(mixed $v): string {
        $v = strtolower(self::text($v, 'Domínio', 4, 253));
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $v)) throw new HttpError(422, 'Use um domínio válido, sem protocolo ou caminho.'); return $v;
    }
    public static function identifier(mixed $v): string { $v = self::text($v, 'Identificador', 1, 32); if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $v)) throw new HttpError(422, 'Use letras minúsculas, números e sublinhado.'); return $v; }
}
