<?php
declare(strict_types=1);

/** Recria os dados de demonstração: php backend/bin/seed.php  (apaga backend/storage/data/*.json) */
require __DIR__ . '/../bootstrap.php';

use Sigep\Core\Env;
use Sigep\Seed\DemoData;
use Sigep\Storage\JsonFileStore;

$dir = __DIR__ . '/../storage/data';
foreach (glob($dir . '/*.json') ?: [] as $f) {
    unlink($f);
}
DemoData::semear(new JsonFileStore($dir), (string) Env::get('DEMO_PASSWORD', ''));
echo "Dados de demonstração criados em storage/data.\n";
