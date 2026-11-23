<?php
declare(strict_types=1);

namespace Sigep\Repositories\Contracts;

/** Controle de tentativas de login (proteção contra força bruta). */
interface LoginThrottleRepositoryInterface
{
    public function tentativasRecentes(string $chave, int $janelaSeg): int;
    public function registrar(string $chave): void;
    public function limpar(string $chave): void;
}
