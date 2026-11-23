<?php
declare(strict_types=1);

namespace Sigep\Database;

use Sigep\Core\Env;

/**
 * Conexão PDO — PREPARADA, mas só é aberta quando STORAGE_DRIVER=pdo.
 * Credenciais vêm do .env (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS), nunca do código.
 * TODO(DB): preencher o .env quando o banco existir.
 */
final class Connection
{
    private ?\PDO $pdo = null;

    public function pdo(): \PDO
    {
        if ($this->pdo === null) {
            $cfg = require __DIR__ . '/../../config/database.php';
            if ($cfg['name'] === '' || $cfg['user'] === '') {
                throw new \RuntimeException('Banco não configurado: preencha DB_NAME, DB_USER e DB_PASS no .env (TODO(DB)).');
            }
            $dsn = sprintf('%s:host=%s;port=%d;dbname=%s;charset=%s', $cfg['driver'], $cfg['host'], $cfg['port'], $cfg['name'], $cfg['charset']);
            $this->pdo = new \PDO($dsn, $cfg['user'], $cfg['pass'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false, // prepared statements reais: proteção contra SQL Injection
            ]);
        }
        return $this->pdo;
    }
}
