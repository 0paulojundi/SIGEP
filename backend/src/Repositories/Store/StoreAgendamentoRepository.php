<?php
declare(strict_types=1);

namespace Sigep\Repositories\Store;

use Sigep\Models\StatusAtendimento;
use Sigep\Repositories\Contracts\AgendamentoRepositoryInterface;
use Sigep\Support\Tempo;

final class StoreAgendamentoRepository extends StoreRepository implements AgendamentoRepositoryInterface
{
    protected string $collection = 'agendamentos';
    protected string $prefix = 'A';
    protected int $pad = 4;

    public function byOrientador(string $id): array { return $this->where(fn ($a) => $a['orientadorId'] === $id); }
    public function byResponsavel(string $id): array { return $this->where(fn ($a) => $a['responsavelId'] === $id); }
    public function byAluno(string $rm): array { return $this->where(fn ($a) => $a['alunoRm'] === $rm); }

    public function conflitosDoOrientador(string $orientadorId, string $data, string $horario, int $janelaMin, ?string $ignorarId = null): array
    {
        return $this->where(fn ($a) => $a['orientadorId'] === $orientadorId && $a['data'] === $data
            && in_array($a['status'], StatusAtendimento::ATIVOS, true) && $a['id'] !== $ignorarId
            && abs(Tempo::minutos($a['horario']) - Tempo::minutos($horario)) < $janelaMin);
    }

    public function conflitosDoAluno(string $rm, string $data, string $horario, int $janelaMin, ?string $ignorarId = null): array
    {
        return $this->where(fn ($a) => $a['alunoRm'] === $rm && $a['data'] === $data
            && in_array($a['status'], StatusAtendimento::ABERTOS, true) && $a['id'] !== $ignorarId
            && abs(Tempo::minutos($a['horario']) - Tempo::minutos($horario)) < $janelaMin);
    }
}
