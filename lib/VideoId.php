<?php

class VideoId
{
    public static function parse(string $input): ?string
    {
        $s = trim($input);
        if ($s === '') return null;

        // If looks like pure ID
        if (preg_match('~^[A-Za-z0-9_-]{11}$~', $s)) {
            return $s;
        }

        // Try common URL patterns
        $patterns = [
            '~[?&]v=([A-Za-z0-9_-]{11})~',               // watch?v=
            '~youtu\.be/([A-Za-z0-9_-]{11})~',          // youtu.be/
            '~embed/([A-Za-z0-9_-]{11})~',               // embed/
            '~shorts/([A-Za-z0-9_-]{11})~',              // shorts/
            '~v/([A-Za-z0-9_-]{11})~',                   // v/
        ];
        foreach ($patterns as $re) {
            if (preg_match($re, $s, $m)) return $m[1];
        }

        // Fallback: parse URL query string
        if (filter_var($s, FILTER_VALIDATE_URL)) {
            $parts = parse_url($s);
            if (!empty($parts['query'])) {
                parse_str($parts['query'], $q);
                if (!empty($q['v']) && preg_match('~^[A-Za-z0-9_-]{11}$~', $q['v'])) {
                    return $q['v'];
                }
            }
        }

        return null;
    }
}

