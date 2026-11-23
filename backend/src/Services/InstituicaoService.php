<?php
declare(strict_types=1);

namespace Sigep\Services;

use Sigep\Core\HttpException;
use Sigep\Core\Validator;
use Sigep\Models\Categoria;
use Sigep\Repositories\Contracts\InstituicaoRepositoryInterface;
use Sigep\Support\Documentos;

final class InstituicaoService
{
    public function __construct(private InstituicaoRepositoryInterface $repo, private AuditService $audit)
    {
    }

    public function obter(): array
    {
        $i = $this->repo->get();
        if (!$i) {
            throw HttpException::notFound('Instituição ainda não cadastrada.');
        }
        return $this->apresentar($i);
    }

    public function atualizar(array $ator, array $in): array
    {
        $d = Validator::validate($in, [
            'nome' => 'required|string|min:3|max:160', 'cnpj' => 'required|cnpj', 'endereco' => 'required|string|max:200',
            'telefone' => 'required|phone', 'email' => 'required|email|max:160',
        ]);
        $d['cnpj'] = Documentos::digitos($d['cnpj']);
        $d['telefone'] = Documentos::digitos($d['telefone']);
        $i = $this->repo->save($d);
        $this->audit->log($ator, 'Atualizou os dados da instituição', Categoria::INSTITUICAO);
        return $this->apresentar($i);
    }

    private function apresentar(array $i): array
    {
        return [
            'nome' => $i['nome'], 'cnpj' => Documentos::formatarCnpj($i['cnpj']), 'endereco' => $i['endereco'],
            'telefone' => Documentos::formatarTelefone($i['telefone']), 'email' => $i['email'],
        ];
    }
}
