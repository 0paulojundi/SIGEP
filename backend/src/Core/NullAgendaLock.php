<?php
declare(strict_types=1);

namespace Sigep\Core;

/** Sem trava (testes em memória, que rodam num processo só). */
final class NullAgendaLock implements AgendaLockInterface
{
    public function run(callable $fn): mixed
    {
        return $fn();
    }
}
