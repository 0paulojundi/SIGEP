<?php
declare(strict_types=1);

namespace Sigep\Repositories\Contracts;

interface InstituicaoRepositoryInterface
{
    public function get(): ?array;
    public function save(array $dados): array;
}
