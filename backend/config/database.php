<?php
declare(strict_types=1);

use Sigep\Core\Env;

/** Configuração PDO (só usada com STORAGE_DRIVER=pdo). Tudo vem do .env — nenhuma credencial no código. */
return [
    'driver' => Env::get('DB_DRIVER', 'mysql'),
    'host' => Env::get('DB_HOST', '127.0.0.1'),
    'port' => Env::int('DB_PORT', 3306),
    'name' => (string) Env::get('DB_NAME', ''),
    'user' => (string) Env::get('DB_USER', ''),
    'pass' => (string) Env::get('DB_PASS', ''),
    'charset' => Env::get('DB_CHARSET', 'utf8mb4'),
];
