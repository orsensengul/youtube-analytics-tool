<?php

require_once __DIR__ . '/../lib/RapidApiClient.php';

class TranscriptService
{
    private RapidApiClient $client;
    private string $host;
    private ?RapidApiClient $altClient = null;
    private ?string $altHost = null;

    public function __construct(RapidApiClient $client, string $host, ?RapidApiClient $altClient = null, ?string $altHost = null)
    {
        $this->client = $client;
        $this->host = $host;
        $this->altClient = $altClient;
        $this->altHost = $altHost;
    }

    /**
     * Attempt to fetch transcript in the given language order.
     * Returns ['success'=>bool,'lang'=>string|null,'segments'=>array,'text'=>string,'raw'=>array,'error'=>string|null]
     */
    public function getTranscript(string $videoId, array $langOrder = ['tr','en']): array
    {
        $langs = array_values(array_filter(array_map('strval', $langOrder)));
        $candidates = $langs ?: ['en'];
        // 1) Try YouTube Transcripts (RapidAPI) if configured
        if ($this->altClient && $this->altHost) {
            $alt = $this->tryRapidYouTubeTranscripts($videoId, $candidates);
            if ($alt['success']) return $alt;
        }

        // 2) Try YouTube timedtext tracklist and fetch the best track
        $tracks = $this->fetchTimedTextTrackList($videoId);
        if ($tracks) {
            $order = $candidates;
            foreach ([$order, ['en','tr'], []] as $langPref) {
                $chosen = $this->chooseTrack($tracks, $langPref);
                if ($chosen) {
                    $xml = $this->fetchTimedTextByTrack($videoId, $chosen);
                    if ($xml) {
                        $segments = $this->parseTimedTextXml($xml, $chosen['lang_code'] ?? ($chosen['lang'] ?? ''));
                        if ($segments) {
                            $text = trim(implode(' ', array_map(fn($s) => trim((string)$s['text']), $segments)));
                            return [
                                'success' => true,
                                'lang' => $chosen['lang_code'] ?? ($chosen['lang'] ?? ''),
                                'segments' => $segments,
                                'text' => $text,
                                'raw' => ['source' => 'timedtext', 'track' => $chosen],
                                'error' => null,
                                'provider' => 'yt-timedtext',
                            ];
                        }
                    }
                }
            }
        }

        // 3) Try primary API host provider endpoints as a backup
        $endpoints = ['/transcript', '/captions', '/video/transcript'];
        foreach ($candidates as $lang) {
            foreach ($endpoints as $path) {
                $url = 'https://' . $this->host . $path;
                $resp = $this->client->get($url, ['id' => $videoId, 'lang' => $lang]);
                if (!empty($resp['error'])) continue;
                $parsed = $this->parseTranscript($resp, $lang);
                if ($parsed['segments']) {
                    return [
                        'success' => true,
                        'lang' => $parsed['lang'],
                        'segments' => $parsed['segments'],
                        'text' => $parsed['text'],
                        'raw' => $resp,
                        'error' => null,
                        'provider' => 'rapidapi-yt-api',
                    ];
                }
            }
        }

        return ['success' => false, 'lang' => null, 'segments' => [], 'text' => '', 'raw' => [], 'error' => 'Transcript not available'];
    }

    private function parseTranscript(array $resp, string $lang): array
    {
        $segments = [];
        // Common shapes we might encounter
        if (isset($resp['segments']) && is_array($resp['segments'])) {
            $segments = $this->normalizeSegments($resp['segments'], $lang);
        } elseif (isset($resp['items']) && is_array($resp['items'])) {
            $segments = $this->normalizeSegments($resp['items'], $lang);
        } elseif (isset($resp['data']) && is_array($resp['data'])) {
            $segments = $this->normalizeSegments($resp['data'], $lang);
        }

        $text = '';
        if ($segments) {
            $parts = [];
            foreach ($segments as $seg) {
                $t = trim((string)($seg['text'] ?? ''));
                if ($t !== '') $parts[] = $t;
            }
            $text = trim(implode(' ', $parts));
        }

        return [
            'lang' => $lang,
            'segments' => $segments,
            'text' => $text,
        ];
    }

    private function normalizeSegments(array $items, string $lang): array
    {
        $out = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $text = $it['text'] ?? ($it['caption'] ?? ($it['content'] ?? null));
            if ($text === null || trim((string)$text) === '') continue;
            $start = $it['start'] ?? ($it['startTime'] ?? ($it['offset'] ?? 0));
            $dur = $it['dur'] ?? ($it['duration'] ?? null);
            $out[] = [
                'start' => is_numeric($start) ? (float)$start : 0,
                'dur' => is_numeric($dur) ? (float)$dur : null,
                'text' => (string)$text,
                'lang' => $it['lang'] ?? $lang,
            ];
        }
        return $out;
    }

    private function tryRapidYouTubeTranscripts(string $videoId, array $candidates): array
    {
        // Primary documented endpoint: /transcript-with-url?url=<watch_url>&flat_text=false
        // Build canonical watch URL from videoId
        $watchUrl = 'https://www.youtube.com/watch?v=' . $videoId;
        // Try with candidate languages via additional hint param if supported
        $paths = ['/transcript-with-url'];
        foreach ($paths as $path) {
            // First attempt without lang (API may auto-detect)
            $resp = $this->altClient->get('https://' . $this->altHost . $path, [
                'url' => $watchUrl,
                'flat_text' => 'false',
            ]);
            if (empty($resp['error'])) {
                $segs = $this->parseYouTubeTranscriptsResp($resp, $candidates[0] ?? '');
                if ($segs) {
                    $text = trim(implode(' ', array_map(fn($s) => trim((string)$s['text']), $segs)));
                    return [
                        'success' => true,
                        'lang' => $segs[0]['lang'] ?? ($candidates[0] ?? ''),
                        'segments' => $segs,
                        'text' => $text,
                        'raw' => $resp,
                        'error' => null,
                        'provider' => 'rapidapi-youtube-2-transcript',
                    ];
                }
            }
            // Then try with explicit lang hint for each candidate
            foreach ($candidates as $lang) {
                $resp = $this->altClient->get('https://' . $this->altHost . $path, [
                    'url' => $watchUrl,
                    'flat_text' => 'false',
                    'lang' => $lang,
                ]);
                if (!empty($resp['error'])) continue;
                $segs = $this->parseYouTubeTranscriptsResp($resp, $lang);
                if ($segs) {
                    $text = trim(implode(' ', array_map(fn($s) => trim((string)$s['text']), $segs)));
                    return [
                        'success' => true,
                        'lang' => $lang,
                        'segments' => $segs,
                        'text' => $text,
                        'raw' => $resp,
                        'error' => null,
                        'provider' => 'rapidapi-youtube-2-transcript',
                    ];
                }
            }
        }
        return ['success' => false, 'lang' => null, 'segments' => [], 'text' => '', 'raw' => [], 'error' => ''];
    }

    private function parseYouTubeTranscriptsResp(array $resp, string $lang): array
    {
        $items = [];
        if (isset($resp['transcripts']) && is_array($resp['transcripts'])) {
            $items = $resp['transcripts'];
        } elseif (isset($resp['data']) && is_array($resp['data'])) {
            $items = $resp['data'];
        } elseif (isset($resp['items']) && is_array($resp['items'])) {
            $items = $resp['items'];
        } elseif (isset($resp['segments']) && is_array($resp['segments'])) {
            $items = $resp['segments'];
        } elseif (isset($resp['transcript']) && is_array($resp['transcript'])) {
            $items = $resp['transcript'];
        }
        if (!$items) return [];
        $out = [];
        foreach ($items as $it) {
            $text = $it['text'] ?? ($it['caption'] ?? ($it['content'] ?? null));
            if (!$text || trim((string)$text) === '') continue;
            $start = $it['start'] ?? ($it['startMs'] ?? ($it['offset'] ?? ($it['start_time'] ?? 0)));
            $dur = $it['duration'] ?? ($it['dur'] ?? ($it['endMs'] ?? ($it['duration_ms'] ?? null)));
            if (is_numeric($start) && $start > 1000 && $start < 1e7) { $start = $start/1000.0; }
            if (is_numeric($dur) && $dur > 1000 && $dur < 1e7) { $dur = $dur/1000.0; }
            $out[] = [
                'start' => is_numeric($start) ? (float)$start : 0,
                'dur' => is_numeric($dur) ? (float)$dur : null,
                'text' => (string)$text,
                'lang' => $it['lang'] ?? ($it['language'] ?? $lang),
            ];
        }
        return $out;
    }

    private function fetchTimedTextXml(string $videoId, string $lang): ?string
    {
        $url = 'https://www.youtube.com/api/timedtext?v=' . rawurlencode($videoId) . '&lang=' . rawurlencode($lang);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; YMT/1.0)'
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 300 && is_string($resp) && strpos($resp, '<transcript') !== false) {
            return $resp;
        }
        return null;
    }

    private function parseTimedTextXml(string $xml, string $lang): array
    {
        $segments = [];
        if (!preg_match_all('/<text[^>]*start="([^"]+)"[^>]*dur="([^"]+)"[^>]*>(.*?)<\/text>/si', $xml, $m, PREG_SET_ORDER)) {
            // Alternative attribute names
            preg_match_all('/<text[^>]*t="([^"]+)"[^>]*d="([^"]+)"[^>]*>(.*?)<\/text>/si', $xml, $m, PREG_SET_ORDER);
        }
        foreach ($m as $row) {
            $start = (float)$row[1];
            $dur = is_numeric($row[2]) ? (float)$row[2] : null;
            $txt = html_entity_decode(strip_tags($row[3] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $txt = preg_replace("/\s+/u", ' ', $txt);
            if ($txt !== '') {
                $segments[] = [
                    'start' => $start,
                    'dur' => $dur,
                    'text' => $txt,
                    'lang' => $lang,
                ];
            }
        }
        return $segments;
    }

    private function fetchTimedTextTrackList(string $videoId): array
    {
        $url = 'https://www.youtube.com/api/timedtext?v=' . rawurlencode($videoId) . '&type=list';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; YMT/1.0)'
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code < 200 || $code >= 300 || !is_string($resp)) return [];
        // Parse <track .../>
        $tracks = [];
        if (preg_match_all('/<track\s+([^>]+)\/>/i', $resp, $m)) {
            foreach ($m[1] as $attrs) {
                $tracks[] = [
                    'lang_code' => $this->attr($attrs, 'lang_code'),
                    'lang_translated' => $this->attr($attrs, 'lang_translated'),
                    'name' => $this->attr($attrs, 'name'),
                    'kind' => $this->attr($attrs, 'kind'), // 'asr' for auto
                ];
            }
        }
        return $tracks;
    }

    private function chooseTrack(array $tracks, array $prefLangs): ?array
    {
        // Prefer exact lang match and non-ASR if available
        $candidates = [];
        foreach ($tracks as $t) {
            $lang = strtolower((string)($t['lang_code'] ?? ''));
            $score = 0;
            if ($prefLangs) {
                foreach ($prefLangs as $i => $pl) {
                    if ($lang === strtolower($pl)) { $score += 100 - $i; }
                }
            }
            if (($t['kind'] ?? '') !== 'asr') $score += 10; // prefer human captions
            $candidates[] = [$score, $t];
        }
        usort($candidates, fn($a,$b) => $b[0] <=> $a[0]);
        return $candidates[0][1] ?? null;
    }

    private function fetchTimedTextByTrack(string $videoId, array $track): ?string
    {
        $params = [ 'v' => $videoId, 'lang' => $track['lang_code'] ?? '' ];
        if (!empty($track['kind'])) $params['kind'] = $track['kind'];
        if (!empty($track['name'])) $params['name'] = $track['name'];
        $url = 'https://www.youtube.com/api/timedtext?' . http_build_query($params);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; YMT/1.0)'
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 300 && is_string($resp) && strpos($resp, '<transcript') !== false) {
            return $resp;
        }
        return null;
    }

    private function attr(string $s, string $name): ?string
    {
        if (preg_match('/\b'.preg_quote($name,'/').'="([^"]*)"/i', $s, $m)) return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return null;
    }
}
