<?php
declare(strict_types=1);

namespace Sigep\Core;

/** Trava por arquivo (flock): vale entre processos/requisições simultâneas. Reentrante dentro da mesma requisição. */
final class FileAgendaLock implements AgendaLockInterface
{
    private int $nivel = 0;
    /** @var resource|null */
    private $handle = null;

    public function __construct(private string $arquivo, private int $esperaMaxSeg = 10)
    {
        $dir = dirname($arquivo);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
    }

    public function run(callable $fn): mixed
    {
        if ($this->nivel === 0) {
            $this->handle = fopen($this->arquivo, 'cb');
            $limite = microtime(true) + $this->esperaMaxSeg;
            while (!flock($this->handle, LOCK_EX | LOCK_NB)) {
                if (microtime(true) > $limite) {
                    fclose($this->handle);
                    $this->handle = null;
                    throw new HttpException(503, 'agenda_ocupada', 'A agenda está sendo atualizada por outra pessoa. Tente novamente em instantes.', [], ['Retry-After' => '2']);
                }
                usleep(20000);
            }
        }
        $this->nivel++;
        try {
            return $fn();
        } finally {
            $this->nivel--;
            if ($this->nivel === 0 && $this->handle) {
                flock($this->handle, LOCK_UN);
                fclose($this->handle);
                $this->handle = null;
            }
        }
    }
}
