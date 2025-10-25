<?php

class AssetDownloader
{
    public static function download(string $url, string $destPath, int $timeout = 20): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['success' => false, 'error' => 'cURL error: ' . $err];
        }
        if ($httpCode >= 400 || $data === false) {
            return ['success' => false, 'error' => 'HTTP ' . $httpCode . ' while downloading'];
        }

        $dir = dirname($destPath);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        if (@file_put_contents($destPath, $data) === false) {
            return ['success' => false, 'error' => 'Write failed'];
        }

        return ['success' => true, 'bytes' => strlen($data), 'path' => $destPath];
    }
}

