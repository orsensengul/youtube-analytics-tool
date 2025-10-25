<?php

class AppState
{
    public static function storageDir(array $config): string
    {
        $base = $config['storage_dir'] ?? (__DIR__ . '/../output');
        if (!is_dir($base)) @mkdir($base, 0777, true);
        if (!is_dir($base) || !is_writable($base)) {
            $tmp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ymt-output';
            if (!is_dir($tmp)) @mkdir($tmp, 0777, true);
            return $tmp;
        }
        return $base;
    }

    private static function offlineFlagPath(array $config): string
    {
        $dir = self::storageDir($config);
        return rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.offline';
    }

    public static function isOffline(array $config): bool
    {
        return is_file(self::offlineFlagPath($config));
    }

    public static function setOffline(array $config, bool $on): bool
    {
        $path = self::offlineFlagPath($config);
        if ($on) {
            return @file_put_contents($path, (string)time()) !== false;
        }
        if (is_file($path)) return @unlink($path);
        return true;
    }

    public static function storageStatus(array $config): array
    {
        $dir = self::storageDir($config);
        return [
            'dir' => $dir,
            'exists' => is_dir($dir),
            'writable' => is_writable($dir),
        ];
    }
}

