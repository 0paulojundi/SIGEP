<?php
declare(strict_types=1);

namespace Sigep\Core;

use Sigep\Support\Documentos;
use Sigep\Support\Tempo;

/**
 * Validação no servidor. Regras: required, nullable, string, int, bool, array, email, cpf, cnpj, phone,
 * date, time, min:N, max:N, in:a,b,c.  Retorna só os campos declarados (descarta o resto).
 */
final class Validator
{
    public static function validate(array $data, array $rules): array
    {
        $errors = [];
        $clean = [];
        foreach ($rules as $field => $ruleStr) {
            $rs = explode('|', $ruleStr);
            $present = array_key_exists($field, $data);
            $value = $present ? $data[$field] : null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $empty = $value === null || $value === '';
            if (in_array('required', $rs, true) && ($empty || $value === [])) {
                $errors[$field] = 'Campo obrigatório.';
                continue;
            }
            if ($empty) {
                if ($present && in_array('nullable', $rs, true)) {
                    $clean[$field] = null;
                } elseif ($present && !in_array('required', $rs, true)) {
                    $clean[$field] = $value;
                }
                continue;
            }
            $err = null;
            foreach ($rs as $r) {
                [$name, $arg] = array_pad(explode(':', $r, 2), 2, null);
                $err = match ($name) {
                    'string' => is_string($value) ? null : 'Deve ser um texto.',
                    'int' => (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value))) ? null : 'Deve ser um número inteiro.',
                    'bool' => is_bool($value) ? null : 'Deve ser verdadeiro ou falso.',
                    'array' => is_array($value) ? null : 'Deve ser uma lista.',
                    'email' => (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) ? null : 'E-mail inválido.',
                    'cpf' => Documentos::cpfValido((string) $value) ? null : 'CPF inválido.',
                    'cnpj' => Documentos::cnpjValido((string) $value) ? null : 'CNPJ inválido.',
                    'phone' => Documentos::telefoneValido((string) $value) ? null : 'Telefone inválido.',
                    'date' => Tempo::dataValida((string) $value) ? null : 'Data inválida (use AAAA-MM-DD).',
                    'time' => Tempo::hhmm((string) $value) !== null ? null : 'Horário inválido (use HH:MM).',
                    'min' => (is_string($value) ? mb_strlen($value) : (is_array($value) ? count($value) : (float) $value)) >= (float) $arg ? null : "Mínimo de $arg.",
                    'max' => (is_string($value) ? mb_strlen($value) : (is_array($value) ? count($value) : (float) $value)) <= (float) $arg ? null : "Máximo de $arg.",
                    'in' => in_array((string) $value, explode(',', (string) $arg), true) ? null : 'Valor não permitido.',
                    default => null,
                };
                if ($err) {
                    break;
                }
            }
            if ($err) {
                $errors[$field] = $err;
                continue;
            }
            $clean[$field] = $value;
        }
        if ($errors) {
            throw HttpException::unprocessable($errors);
        }
        return $clean;
    }
}
