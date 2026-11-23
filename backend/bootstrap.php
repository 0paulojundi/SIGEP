<?php
declare(strict_types=1);

/** Autoload (PSR-4 simples, sem Composer — funciona em hospedagem compartilhada) e leitura do .env. */
spl_autoload_register(function (string $class): void {
    $prefix = 'Sigep\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Ideal em hospedagem: deixar o .env FORA da pasta pública e apontar SIGEP_ENV_FILE para ele.
Sigep\Core\Env::load(getenv('SIGEP_ENV_FILE') ?: __DIR__ . '/../.env');
ini_set('display_errors', Sigep\Core\Env::bool('APP_DEBUG', false) ? '1' : '0');

// Extensões exigidas. No PHP do Windows, a "mbstring" vem DESLIGADA: ative no php.ini (extension=mbstring).
foreach (['mbstring', 'json', 'ctype', 'session'] as $extensao) {
    if (!extension_loaded($extensao)) {
        if (PHP_SAPI !== 'cli') {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo "SIGEP: falta a extensão PHP '$extensao'. Ative-a no php.ini (linha extension=$extensao) e reinicie o servidor.\n";
        exit(1);
    }
}

// Avisos/deprecações do PHP nunca devem vazar para o JSON da API (importante ao trocar de versão do PHP).
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/storage/logs/php-error.log');
