<?php

class History
{
    private string $dir;
    private string $file;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, DIRECTORY_SEPARATOR);
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0777, true);
        }
        $this->file = $this->dir . DIRECTORY_SEPARATOR . 'history.jsonl';
    }

    public function addSearch(string $query, array $meta = []): void
    {
        $row = [
            'ts' => time(),
            'query' => $query,
            'meta' => $meta,
        ];
        @file_put_contents($this->file, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    }
}

