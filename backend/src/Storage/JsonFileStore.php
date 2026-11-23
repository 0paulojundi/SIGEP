<?php
declare(strict_types=1);

namespace Sigep\Storage;

/**
 * Persistência TEMPORÁRIA em arquivos JSON (backend/storage/data/*.json), com trava de arquivo.
 * Existe só para o back-end funcionar e ser testado sem banco. NÃO é para produção.
 * TODO(DB): trocar por repositórios PDO (veja README, seção "Conectando o MySQL").
 */
final class JsonFileStore extends ArrayStore
{
    public function __construct(private string $dir)
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
    }

    private function file(string $collection): string
    {
        return $this->dir . '/' . preg_replace('/[^a-z0-9_]/i', '', $collection) . '.json';
    }

    protected function load(string $collection): array
    {
        $file = $this->file($collection);
        if (!is_file($file)) {
            return [];
        }
        $h = fopen($file, 'rb');
        flock($h, LOCK_SH);
        $raw = stream_get_contents($h);
        flock($h, LOCK_UN);
        fclose($h);
        $rows = json_decode((string) $raw, true);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $out[(string) $r['id']] = $r;
        }
        return $out;
    }

    protected function save(string $collection, array $rows): void
    {
        $file = $this->file($collection);
        $h = fopen($file, 'cb');
        flock($h, LOCK_EX);
        ftruncate($h, 0);
        fwrite($h, json_encode(array_values($rows), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        fflush($h);
        flock($h, LOCK_UN);
        fclose($h);
    }
}
