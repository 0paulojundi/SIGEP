<?php
declare(strict_types=1);

namespace Sigep\Models;

/**
 * Ciclo de vida de uma solicitação/agendamento/atendimento (mesmos textos do front-end):
 * Pendente → Confirmado → Em Andamento → Finalizado;  Pendente → Recusado;  (Pendente|Confirmado|Em Andamento) → Cancelado.
 */
final class StatusAtendimento
{
    public const PENDENTE = 'Pendente';
    public const CONFIRMADO = 'Confirmado';
    public const EM_ANDAMENTO = 'Em Andamento';
    public const FINALIZADO = 'Finalizado';
    public const CANCELADO = 'Cancelado';
    public const RECUSADO = 'Recusado';

    /** Ocupam a agenda do orientador (geram conflito de horário). */
    public const ATIVOS = [self::CONFIRMADO, self::EM_ANDAMENTO];
    /** Ainda não terminaram. */
    public const ABERTOS = [self::PENDENTE, self::CONFIRMADO, self::EM_ANDAMENTO];

    public static function todos(): array
    {
        return [self::PENDENTE, self::CONFIRMADO, self::EM_ANDAMENTO, self::FINALIZADO, self::CANCELADO, self::RECUSADO];
    }
}
