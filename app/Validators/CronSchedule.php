<?php
declare(strict_types=1);
namespace App\Validators;
use App\Helpers\HttpError;

final class CronSchedule
{
    public static function validate(mixed $value): string {
        $value=Input::text($value,'Agendamento',9,80);
        $fields=explode(' ',$value);
        if(count($fields)!==5) throw new HttpError(422,'Use cinco campos cron: minuto, hora, dia, mês e dia da semana.');
        foreach([[0,59],[0,23],[1,31],[1,12],[0,7]] as $index=>[$low,$high]) {
            foreach(explode(',',$fields[$index]) as $item) {
                if(!preg_match('/^(\*|[0-9]+(?:-[0-9]+)?)(?:\/([0-9]+))?$/D',$item,$match)) throw new HttpError(422,'Expressão cron inválida. Use números, *, listas, intervalos ou passos.');
                if(isset($match[2])&&((int)$match[2]<1||(int)$match[2]>$high+1)) throw new HttpError(422,'Passo inválido no agendamento cron.');
                if($match[1]!=='*') {
                    $numbers=array_map('intval',explode('-',$match[1]));
                    if(min($numbers)<$low||max($numbers)>$high||$numbers[0]>end($numbers)) throw new HttpError(422,'Valor fora do intervalo permitido no agendamento cron.');
                }
            }
        }
        return $value;
    }
}
