<?php
declare(strict_types=1);

namespace Sigep\Repositories\Contracts;

/** Registro de auditoria: só inclusão e consulta (nada é alterado ou apagado). */
interface AuditoriaRepositoryInterface
{
    public function registrar(array $registro): array;
    /** Filtros: usuarioId, cat, de (AAAA-MM-DD), ate (AAAA-MM-DD). Mais recentes primeiro. */
    public function buscar(array $filtros): array;
}
