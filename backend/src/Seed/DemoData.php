<?php
declare(strict_types=1);

namespace Sigep\Seed;

use Sigep\Storage\Store;
use Sigep\Support\Documentos;
use Sigep\Support\Senha;

/**
 * Dados de DEMONSTRAÇÃO (temporários) para testar o back-end sem banco. Mesmos nomes e cursos do front.
 * A senha de todos os usuários demo vem do .env (DEMO_PASSWORD) — não existe senha no código.
 * Os CPFs são gerados válidos; o CNPJ de exemplo também é válido (os do front não passam na validação dos dígitos).
 * TODO(DB): este seed deixa de existir quando o banco real estiver em uso (SEED_DEMO=false).
 */
final class DemoData
{
    private const ALUNOS = ['Ana Beatriz Souza', 'Bruno Carvalho Lima', 'Camila Ferreira Dias', 'Diego Santos Rocha', 'Eduarda Martins Alves', 'Felipe Nogueira Costa', 'Gabriela Pereira Melo', 'Henrique Barbosa Reis', 'Isabela Ramos Teixeira', 'João Vitor Cardoso', 'Larissa Gonçalves Pinto', 'Matheus Araújo Freitas', 'Natália Correia Batista', 'Otávio Nunes Moreira', 'Paula Cristina Farias', 'Rafael Duarte Vieira', 'Sabrina Lopes Azevedo', 'Thiago Moura Cavalcante', 'Vitória Ribeiro Sales', 'William Torres Guimarães'];
    private const RESPONSAVEIS = ['Marcos Souza', 'Renata Carvalho', 'Cláudio Dias', 'Fernanda Rocha', 'Roberto Martins', 'Simone Costa', 'Alexandre Melo', 'Patrícia Reis'];
    private const ORIENTADORES = ['Prof. Marcelo Antunes', 'Profa. Juliana Prado', 'Prof. Ricardo Fontes', 'Profa. Camila Xavier', 'Prof. André Salgado'];
    private const CURSOS = ['Desenvolvimento de Sistemas', 'Administração', 'Nutrição e Dietética', 'Enfermagem', 'Logística', 'Meio Ambiente', 'Recursos Humanos'];
    private const MOTIVOS = ['Orientação de TCC', 'Dificuldade de aprendizagem', 'Acompanhamento pedagógico', 'Orientação vocacional', 'Questões disciplinares', 'Acompanhamento de frequência', 'Reunião de desempenho', 'Orientação de estágio'];

    public static function semear(Store $s, string $senha): void
    {
        if ($senha === '') {
            throw new \RuntimeException('Defina DEMO_PASSWORD no .env para criar os dados de demonstração (ou use SEED_DEMO=false).');
        }
        $hash = Senha::hash($senha);
        $hoje = new \DateTimeImmutable('today');
        $dia = fn (int $n): string => $hoje->modify(sprintf('%+d days', $n))->format('Y-m-d');
        $agora = date('c');

        $s->insert('instituicao', ['id' => '1', 'nome' => 'Etec de Heliópolis – Arquiteto Ruy Ohtake', 'cnpj' => '11222333000181',
            'endereco' => 'Av. República do Líbano, s/n - Heliópolis, São Paulo - SP', 'telefone' => '1128924200', 'email' => 'contato@sigep.com']);

        $usuario = function (string $id, string $perfil, string $nome, string $email, int $n) use ($s, $hash, $agora): void {
            $s->insert('usuarios', [
                'id' => $id, 'perfil' => $perfil, 'nome' => $nome, 'email' => $email,
                'cpf' => Documentos::cpfDeBase(sprintf('%09d', 100000000 + $n)), 'telefone' => '119' . sprintf('%08d', 80000000 + $n),
                'senhaHash' => $hash, 'status' => 'Ativo',
                'prefs' => ['notifEmail' => true, 'notifSistema' => true, 'confirmarExclusao' => true, 'conflitoHorario' => true],
                'criadoEm' => $agora, 'atualizadoEm' => $agora, 'ultimoLogin' => null,
            ]);
        };
        $usuario('INST', 'instituicao', 'Administração SIGEP', 'instituicao@sigep.com', 1);
        $usuario('ADM001', 'instituicao', 'Coordenação Geral', 'coordenacao@sigep.com', 2);
        foreach (self::ORIENTADORES as $i => $nome) {
            $usuario(sprintf('O%03d', $i + 1), 'orientador', $nome, $i === 0 ? 'orientador@sigep.com' : 'orientador' . ($i + 1) . '@sigep.com', 10 + $i);
        }
        foreach (self::RESPONSAVEIS as $i => $nome) {
            $usuario(sprintf('R%03d', $i + 1), 'responsavel', $nome, $i === 0 ? 'responsavel@sigep.com' : 'responsavel' . ($i + 1) . '@sigep.com', 30 + $i);
        }

        foreach (self::ALUNOS as $i => $nome) {
            $rm = (string) (20250000 + $i);
            $s->insert('alunos', ['id' => $rm, 'rm' => $rm, 'nome' => $nome, 'ano' => ['1º Ano', '2º Ano', '3º Ano'][$i % 3],
                'curso' => self::CURSOS[$i % 7], 'periodo' => ['Manhã', 'Tarde', 'Noite'][$i % 3],
                'responsaveisIds' => array_values(array_unique(array_filter([sprintf('R%03d', ($i % 8) + 1), [1 => 'R003', 3 => 'R001', 8 => 'R005'][$i] ?? null]))), 'criadoEm' => $agora]);
        }

        // disponibilidade dos 3 primeiros orientadores (seg/qua de manhã, ter/qui à tarde)
        $n = 1;
        foreach (['O001', 'O002', 'O003'] as $oid) {
            foreach ([[1, '08:00', '12:00'], [3, '08:00', '12:00'], [2, '13:00', '17:00'], [4, '13:00', '17:00']] as [$d, $ini, $fim]) {
                $s->insert('disponibilidades', ['id' => sprintf('D%03d', $n++), 'orientadorId' => $oid, 'dia' => $d, 'inicio' => $ini, 'fim' => $fim, 'intervalos' => []]);
            }
        }

        // agendamentos: [deslocamento em dias, horário, status, origem]
        $modelo = [
            [-10, '08:00', 'Finalizado', 'solicitacao'], [-8, '09:00', 'Finalizado', 'direta'], [-6, '10:00', 'Finalizado', 'solicitacao'],
            [-3, '14:00', 'Cancelado', 'solicitacao'], [-2, '13:30', 'Recusado', 'solicitacao'], [-1, '09:30', 'Finalizado', 'solicitacao'],
            [0, date('H:i'), 'Confirmado', 'solicitacao'], [1, '08:30', 'Confirmado', 'direta'], [2, '10:30', 'Confirmado', 'solicitacao'],
            [3, '09:00', 'Pendente', 'solicitacao'], [4, '14:00', 'Pendente', 'solicitacao'], [5, '13:00', 'Pendente', 'solicitacao'],
        ];
        foreach ($modelo as $i => [$off, $hora, $status, $origem]) {
            $rm = (string) (20250001 + $i); // alunos ativos
            $aluno = $s->find('alunos', $rm);
            $fin = $status === 'Finalizado';
            $s->insert('agendamentos', [
                'id' => sprintf('A%04d', $i + 1), 'alunoRm' => $rm, 'orientadorId' => sprintf('O%03d', ($i % 5) + 1), 'responsavelId' => $aluno['responsaveisIds'][0],
                'data' => $dia($off), 'horario' => $hora, 'motivo' => self::MOTIVOS[$i % 8],
                'descricao' => $fin ? 'Atendimento realizado conforme combinado.' : '', 'observacoes' => '',
                'resultado' => $fin ? 'Aluno orientado; plano de ação definido para as próximas semanas.' : '',
                'status' => $status, 'origem' => $origem, 'notificarResponsavel' => true, 'registroVisivel' => false,
                'horaInicialReal' => $fin ? $hora : null, 'horaFinalReal' => $fin ? \Sigep\Support\Tempo::deMinutos(\Sigep\Support\Tempo::minutos($hora) + 30) : null,
                'criadoEm' => $dia($off - 2), 'atualizadoEm' => $agora,
            ]);
        }

        $s->insert('reunioes', ['id' => 'M001', 'assunto' => 'Alinhamento pedagógico do bimestre', 'descricao' => 'Revisão dos casos em acompanhamento.', 'data' => $dia(2), 'horario' => '10:00', 'horarioFim' => '11:00',
            'participantes' => ['O001', 'O002', 'ADM001'], 'status' => 'Agendada', 'criadoPor' => 'INST', 'criadoEm' => $agora]);
        $s->insert('reunioes', ['id' => 'M002', 'assunto' => 'Reunião com responsáveis do 1º ano', 'descricao' => '', 'data' => $dia(-5), 'horario' => '14:00', 'horarioFim' => '15:00',
            'participantes' => ['O001', 'R001'], 'status' => 'Agendada', 'criadoPor' => 'INST', 'criadoEm' => $agora]);

        foreach ([['R001', 'Seu atendimento foi confirmado.', 'Agendamentos'], ['O001', 'Nova solicitação de atendimento recebida.', 'Solicitações'], ['INST', 'Nova solicitação de atendimento recebida.', 'Solicitações']] as $i => [$u, $t, $c]) {
            $s->insert('notificacoes', ['id' => sprintf('N%06d', $i + 1), 'usuarioId' => $u, 'texto' => $t, 'cat' => $c, 'lida' => false, 'data' => date('Y-m-d'), 'criadoEm' => $agora, 'refTipo' => null, 'refId' => null]);
        }
        $s->insert('auditoria', ['id' => 'L000001', 'usuarioId' => 'INST', 'usuario' => 'Administração SIGEP', 'perfil' => 'Administrador', 'acao' => 'Dados de demonstração criados', 'cat' => 'Usuários', 'quando' => $agora, 'ip' => null]);
    }
}
