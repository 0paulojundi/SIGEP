<?php
declare(strict_types=1);

namespace Sigep\Repositories\Contracts;

/**
 * Solicitações, agendamentos e atendimentos compartilham o mesmo registro (como no front-end);
 * o status diferencia a fase. TODO(DB): no esquema final podem virar 3 tabelas (ver DER da monografia).
 */
interface AgendamentoRepositoryInterface extends RepositoryInterface
{
    public function byOrientador(string $orientadorId): array;
    public function byResponsavel(string $responsavelId): array;
    public function byAluno(string $rm): array;
    /** Agendamentos ATIVOS do orientador a menos de 'janelaMin' minutos do horário informado. */
    public function conflitosDoOrientador(string $orientadorId, string $data, string $horario, int $janelaMin, ?string $ignorarId = null): array;
    /** Agendamentos ABERTOS do aluno a menos de 'janelaMin' minutos do horário informado. */
    public function conflitosDoAluno(string $rm, string $data, string $horario, int $janelaMin, ?string $ignorarId = null): array;
}
