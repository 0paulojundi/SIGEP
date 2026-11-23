<?php
declare(strict_types=1);

namespace Sigep\Core;

/**
 * Exclusão mútua das operações que reservam horário (solicitar, aprovar, agendar, alterar, criar reunião).
 * Sem ela, duas pessoas poderiam reservar o MESMO horário ao mesmo tempo: as duas leriam "livre" e as duas gravariam.
 * TODO(DB): com o MySQL, trocar a implementação por uma transação com SELECT ... FOR UPDATE nas linhas do orientador
 * (ou GET_LOCK) — as regras de negócio não mudam, só esta classe.
 */
interface AgendaLockInterface
{
    public function run(callable $fn): mixed;
}
