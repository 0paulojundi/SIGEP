<?php
declare(strict_types=1);

namespace Sigep\Support;

/** Datas "AAAA-MM-DD" e horários "HH:MM" (o front envia "8:00" ou "08:00"; aqui tudo é normalizado). */
final class Tempo
{
    public const SLOT_MIN = 30;

    public static function hhmm(string $v): ?string
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($v), $m)) {
            return null;
        }
        $h = (int) $m[1];
        $mi = (int) $m[2];
        return ($h > 23 || $mi > 59) ? null : sprintf('%02d:%02d', $h, $mi);
    }

    public static function minutos(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', (string) self::hhmm($hhmm)));
        return $h * 60 + $m;
    }

    public static function deMinutos(int $min): string
    {
        return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
    }

    public static function dataValida(string $v): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** Dois intervalos [a1,a2) e [b1,b2) (em minutos) se sobrepõem de verdade? Encostar (fim = início) NÃO é conflito. */
    public static function sobrepoe(int $a1, int $a2, int $b1, int $b2): bool
    {
        return $a1 < $b2 && $b1 < $a2;
    }

    public static function hoje(): string { return date('Y-m-d'); }
    public static function agora(): string { return date('c'); }

    public static function diaSemana(string $data): int
    {
        return (int) (new \DateTimeImmutable($data . ' 12:00:00'))->format('w');
    }

    public static function mes(string $data): string { return substr($data, 0, 7); }

    /** Minutos passados desde o horário marcado (negativo = ainda vai acontecer). */
    public static function atrasoMin(string $data, string $horario): int
    {
        $marcado = new \DateTimeImmutable($data . ' ' . (self::hhmm($horario) ?? '00:00') . ':00');
        return (int) floor((time() - $marcado->getTimestamp()) / 60);
    }

    public static function passou(string $data, string $horario): bool
    {
        return (new \DateTimeImmutable($data . ' ' . (self::hhmm($horario) ?? '00:00') . ':00')) < new \DateTimeImmutable('now');
    }
}
