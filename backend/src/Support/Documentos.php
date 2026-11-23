<?php
declare(strict_types=1);

namespace Sigep\Support;

/** Validação e formatação de CPF, CNPJ e telefone (RF10 — Validar Dados). */
final class Documentos
{
    public static function digitos(?string $v): string { return preg_replace('/\D/', '', (string) $v) ?? ''; }

    public static function cpfValido(string $v): bool
    {
        $d = self::digitos($v);
        if (strlen($d) !== 11 || preg_match('/^(\d)\1{10}$/', $d)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $d[$i] * (($t + 1) - $i);
            }
            $dv = ((10 * $sum) % 11) % 10;
            if ((int) $d[$t] !== $dv) {
                return false;
            }
        }
        return true;
    }

    public static function cnpjValido(string $v): bool
    {
        $d = self::digitos($v);
        if (strlen($d) !== 14 || preg_match('/^(\d)\1{13}$/', $d)) {
            return false;
        }
        foreach ([12 => [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], 13 => [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $pos => $w) {
            $sum = 0;
            foreach ($w as $i => $peso) {
                $sum += (int) $d[$i] * $peso;
            }
            $dv = $sum % 11 < 2 ? 0 : 11 - ($sum % 11);
            if ((int) $d[$pos] !== $dv) {
                return false;
            }
        }
        return true;
    }

    public static function telefoneValido(string $v): bool
    {
        $n = strlen(self::digitos($v));
        return $n === 10 || $n === 11;
    }

    public static function formatarCpf(?string $v): string
    {
        $d = self::digitos($v);
        return strlen($d) === 11 ? substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9) : (string) $v;
    }

    /** LGPD: minimização na exibição — só os dois últimos dígitos ficam visíveis. */
    public static function mascararCpf(?string $v): string
    {
        $d = self::digitos($v);
        return strlen($d) === 11 ? '***.***.***-' . substr($d, 9) : '';
    }

    public static function formatarCnpj(?string $v): string
    {
        $d = self::digitos($v);
        return strlen($d) === 14 ? substr($d, 0, 2) . '.' . substr($d, 2, 3) . '.' . substr($d, 5, 3) . '/' . substr($d, 8, 4) . '-' . substr($d, 12) : (string) $v;
    }

    public static function formatarTelefone(?string $v): string
    {
        $d = self::digitos($v);
        if (strlen($d) === 11) {
            return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7));
        }
        if (strlen($d) === 10) {
            return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6));
        }
        return (string) $v;
    }

    /** Gera um CPF válido a partir de 9 dígitos-base (usado só no seed de demonstração). */
    public static function cpfDeBase(string $base9): string
    {
        $d = $base9;
        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $d[$i] * (($t + 1) - $i);
            }
            $d .= (string) (((10 * $sum) % 11) % 10);
        }
        return $d;
    }
}
