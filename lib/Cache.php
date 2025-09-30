<?php

class Cache
{
    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, DIRECTORY_SEPARATOR);
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0777, true);
        }
    }

    private function pathFor(string $key): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_:\-.]/', '_', $key);
        return $this->dir . DIRECTORY_SEPARATOR . $safe . '.json';
    }

    public function get(string $key, int $ttlSeconds): ?array
    {
        $path = $this->pathFor($key);
        if (!is_file($path)) return null;
        $raw = @file_get_contents($path);
        if ($raw === false) return null;
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['ts'])) return null;
        if ($ttlSeconds > 0 && (time() - (int)$data['ts']) > $ttlSeconds) return null;
        return $data['data'] ?? null;
    }

    public function set(string $key, array $value): void
    {
        $path = $this->pathFor($key);
        $payload = json_encode(['ts' => time(), 'data' => $value], JSON_UNESCAPED_UNICODE);
        @file_put_contents($path, $payload);
    }
}

