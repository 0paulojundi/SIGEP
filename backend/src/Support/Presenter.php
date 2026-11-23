<?php
declare(strict_types=1);

namespace Sigep\Support;

use Sigep\Models\Perfil;

/** Monta o JSON devolvido ao front: nunca expõe hash de senha e aplica minimização de dados (LGPD). */
final class Presenter
{
    public static function usuario(array $u, ?array $viewer = null): array
    {
        $veCompleto = $viewer && ($viewer['perfil'] === Perfil::ADMIN || $viewer['id'] === $u['id']);
        return [
            'id' => $u['id'],
            'perfil' => $u['perfil'],
            'perfilRotulo' => Perfil::rotulo($u['perfil']),
            'nome' => $u['nome'],
            'email' => $u['email'],
            'cpf' => $veCompleto ? Documentos::formatarCpf($u['cpf'] ?? '') : Documentos::mascararCpf($u['cpf'] ?? ''),
            'telefone' => Documentos::formatarTelefone($u['telefone'] ?? ''),
            'status' => $u['status'],
            'criadoEm' => $u['criadoEm'] ?? null,
            'ultimoLogin' => $u['ultimoLogin'] ?? null,
        ];
    }

    /** Dados mínimos para listas de escolha (orientadores, participantes). */
    public static function basico(array $u): array
    {
        return ['id' => $u['id'], 'nome' => $u['nome'], 'perfil' => $u['perfil'], 'perfilRotulo' => Perfil::rotulo($u['perfil'])];
    }

    /** @param array<int,array> $responsaveis usuários com perfil Responsável vinculados ao aluno (tabela responsavel_aluno) */
    public static function aluno(array $a, array $responsaveis = []): array
    {
        return [
            'rm' => $a['rm'], 'nome' => $a['nome'], 'ano' => $a['ano'], 'curso' => $a['curso'], 'periodo' => $a['periodo'],
            'turma' => $a['ano'] . ' · ' . $a['curso'] . ' · ' . $a['periodo'],
            'responsaveisIds' => $a['responsaveisIds'] ?? [],
            'responsaveis' => array_map(fn ($r) => ['id' => $r['id'], 'nome' => $r['nome']], $responsaveis),
            'responsavelNome' => implode(', ', array_column($responsaveis, 'nome')),
        ];
    }
}
