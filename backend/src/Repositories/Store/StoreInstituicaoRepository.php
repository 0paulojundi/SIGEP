<?php
declare(strict_types=1);

namespace Sigep\Repositories\Store;

use Sigep\Repositories\Contracts\InstituicaoRepositoryInterface;
use Sigep\Storage\Store;

final class StoreInstituicaoRepository implements InstituicaoRepositoryInterface
{
    public function __construct(private Store $store) {}

    public function get(): ?array { return $this->store->find('instituicao', '1'); }

    public function save(array $dados): array
    {
        $dados['id'] = '1';
        return $this->store->find('instituicao', '1')
            ? $this->store->update('instituicao', '1', $dados)
            : $this->store->insert('instituicao', $dados);
    }
}
