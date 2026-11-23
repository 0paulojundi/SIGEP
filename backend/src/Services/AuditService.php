<?php
declare(strict_types=1);

namespace Sigep\Services;

use Sigep\Models\Perfil;
use Sigep\Repositories\Contracts\AuditoriaRepositoryInterface;

/**
 * RF11 — registro de auditoria: quem, o quê, quando e de onde. Só inclusão e consulta.
 * LGPD: o log guarda a AÇÃO (ex.: "Finalizou atendimento A0012"), nunca o conteúdo pedagógico.
 */
final class AuditService
{
    public function __construct(private AuditoriaRepositoryInterface $repo)
    {
    }

    public function log(?array $ator, string $acao, string $cat, ?string $ip = null): void
    {
        $this->repo->registrar([
            'usuarioId' => $ator['id'] ?? null,
            'usuario' => $ator['nome'] ?? 'Sistema',
            'perfil' => isset($ator['perfil']) ? Perfil::rotulo($ator['perfil']) : 'Sistema',
            'acao' => $acao,
            'cat' => $cat,
            'quando' => date('c'),
            'ip' => $ip,
        ]);
    }

    public function buscar(array $q): array
    {
        return $this->repo->buscar([
            'usuarioId' => $q['usuarioId'] ?? null, 'cat' => $q['cat'] ?? null, 'de' => $q['de'] ?? null, 'ate' => $q['ate'] ?? null,
        ]);
    }
}
