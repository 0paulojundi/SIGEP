<?php
declare(strict_types=1);

namespace Sigep\Repositories\Store;

use Sigep\Repositories\Contracts\LoginThrottleRepositoryInterface;
use Sigep\Storage\Store;

final class StoreLoginThrottleRepository implements LoginThrottleRepositoryInterface
{
    private const COLLECTION = 'login_attempts';

    public function __construct(private Store $store) {}

    public function tentativasRecentes(string $chave, int $janelaSeg): int
    {
        $limite = time() - $janelaSeg;
        return count(array_filter($this->store->all(self::COLLECTION), fn ($r) => $r['chave'] === $chave && $r['quando'] >= $limite));
    }

    public function registrar(string $chave): void
    {
        $this->store->insert(self::COLLECTION, [
            'id' => $this->store->nextId(self::COLLECTION, 'F', 8), 'chave' => $chave, 'quando' => time(),
        ]);
    }

    public function limpar(string $chave): void
    {
        foreach ($this->store->all(self::COLLECTION) as $r) {
            if ($r['chave'] === $chave) {
                $this->store->delete(self::COLLECTION, $r['id']);
            }
        }
    }
}
