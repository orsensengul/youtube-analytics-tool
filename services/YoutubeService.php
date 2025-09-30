<?php

require_once __DIR__ . '/../lib/RapidApiClient.php';

class YoutubeService
{
    private RapidApiClient $client;
    private string $host;

    public function __construct(RapidApiClient $client, string $host)
    {
        $this->client = $client;
        $this->host = $host;
    }

    private function isYtApi(): bool
    {
        return stripos($this->host, 'yt-api') !== false;
    }

    public function search(string $query, int $maxResults = 10, string $regionCode = 'TR'): array
    {
        $base = 'https://' . $this->host . '/search';
        if ($this->isYtApi()) {
            // Önce video odaklı dene, boş sonuçta genel aramaya düş
            $common = [
                'query' => $query,
                'geo' => $regionCode,
                'lang' => 'tr',
                'q' => $query,
            ];
            $first = $this->client->get($base, $common + ['type' => 'video']);
            if ($this->isEmptyResult($first)) {
                $fallback = $this->client->get($base, $common);
                return $fallback;
            }
            return $first;
        }
        // youtube-v31 şeması
        return $this->client->get($base, [
            'q' => $query,
            'part' => 'snippet,id',
            'maxResults' => $maxResults,
            'order' => 'relevance',
            'regionCode' => $regionCode,
            'safeSearch' => 'none',
            'type' => 'video',
        ]);
    }

    public function videosDetails(array $ids): array
    {
        if (empty($ids)) return [];
        // yt-api: her video için /video/info çağrısı yapacağız ve ortak şemaya dönüştüreceğiz
        if ($this->isYtApi()) {
            $map = [];
            foreach ($ids as $vid) {
                $base = 'https://' . $this->host . '/video/info';
                $info = $this->client->get($base, [ 'id' => $vid ]);
                if (!empty($info['error'])) {
                    // En ilk hatayı üst katmana iletmek için kısa devre etmeyelim, sadece boş etiket bırakıyoruz
                    $tags = [];
                    $stats = [];
                    $publishedAt = null;
                } else {
                    $tags = $this->extractYtApiTags($info);
                    $stats = $this->extractYtApiStats($info);
                    $publishedAt = $this->extractYtApiPublishedAt($info);
                }
                $map[$vid] = [
                    'id' => $vid,
                    'snippet' => [ 'tags' => $tags ] + ($publishedAt ? ['publishedAt' => $publishedAt] : []),
                    'statistics' => $stats,
                    'raw' => $info,
                ];
            }
            return $map;
        }

        // youtube-v31 toplu videos endpoint
        $base = 'https://' . $this->host . '/videos';
        $data = $this->client->get($base, [
            'part' => 'snippet,contentDetails,statistics',
            'id' => implode(',', $ids),
        ]);
        if (!empty($data['error'])) return $data;
        $map = [];
        foreach ($data['items'] ?? [] as $item) {
            $vid = $item['id'] ?? null;
            if ($vid) {
                $map[$vid] = $item;
            }
        }
        return $map;
    }

    private function extractYtApiTags(array $info): array
    {
        // yt-api farklı şemalarda dönebilir; olası alanları sırasıyla deneyelim
        $tags = $info['tags'] ?? $info['keywords'] ?? ($info['video']['keywords'] ?? null);
        if (is_array($tags)) {
            return array_values(array_filter(array_map('strval', $tags), fn($t) => $t !== ''));
        }
        if (is_string($tags)) {
            $parts = array_map('trim', preg_split('/,\s*/', $tags));
            return array_values(array_filter($parts, fn($t) => $t !== ''));
        }
        return [];
    }

    private function extractYtApiStats(array $info): array
    {
        $views = $info['viewCount']
            ?? ($info['video']['viewCount'] ?? null)
            ?? ($info['stats']['views'] ?? null)
            ?? ($info['video']['stats']['views'] ?? null)
            ?? ($info['view_count'] ?? null);
        $likes = $info['likeCount']
            ?? ($info['video']['likeCount'] ?? null)
            ?? ($info['stats']['likes'] ?? null)
            ?? ($info['video']['stats']['likes'] ?? null)
            ?? ($info['like_count'] ?? null);
        $norm = function($v) {
            if (is_string($v)) {
                // 1,234,567 veya "1.2M" gibi değerler gelebilir
                $s = str_replace([',', ' '], ['', ''], $v);
                if (preg_match('/^(\d+)([kKmMbB])$/', $s, $m)) {
                    $base = (int)$m[1];
                    $mul = ['k'=>1e3,'K'=>1e3,'m'=>1e6,'M'=>1e6,'b'=>1e9,'B'=>1e9][$m[2]];
                    return (int)round($base * $mul);
                }
                if (is_numeric($s)) return (int)$s;
                return 0;
            }
            if (is_numeric($v)) return (int)$v;
            return 0;
        };
        return [
            'viewCount' => $norm($views),
            'likeCount' => $norm($likes),
        ];
    }

    private function extractYtApiPublishedAt(array $info): ?string
    {
        $date = $info['publishDate']
            ?? ($info['publishedDate'] ?? null)
            ?? ($info['uploadDate'] ?? null)
            ?? ($info['date'] ?? null);
        if (is_string($date) && $date !== '') return $date;
        return null;
    }

    private function isEmptyResult(array $data): bool
    {
        if (!empty($data['error'])) return false; // hata varsa boş sayma, üst katman gösterir
        if (!empty($data['items']) && is_array($data['items'])) return count($data['items']) === 0;
        if (!empty($data['data']['results']) && is_array($data['data']['results'])) return count($data['data']['results']) === 0;
        if (!empty($data['results']) && is_array($data['results'])) return count($data['results']) === 0;
        if (!empty($data['data']) && is_array($data['data'])) return count($data['data']) === 0;
        // Yapı tanınmıyorsa boş varsayma
        return false;
    }

    public function resolveChannelId(string $input, string $regionCode = 'TR'): ?string
    {
        $input = trim($input);
        if ($input === '') return null;
        if (preg_match('~/(channel)/(?P<id>UC[0-9A-Za-z_-]+)~', $input, $m)) {
            return $m['id'];
        }
        if (preg_match('~@([A-Za-z0-9._-]+)~', $input, $m)) {
            $handle = '@' . $m[1];
            $info = $this->client->get('https://' . $this->host . '/channel', [
                'id' => $handle,
                'geo' => $regionCode,
                'lang' => 'tr',
            ]);
            $cid = $this->extractChannelIdFromChannelResp($info);
            if ($cid) return $cid;
        }
        if (preg_match('~/((c|user))/([^/?#]+)~', $input, $m)) {
            $custom = $m[3];
            $info = $this->client->get('https://' . $this->host . '/channel', [
                'id' => $custom,
                'geo' => $regionCode,
                'lang' => 'tr',
            ]);
            $cid = $this->extractChannelIdFromChannelResp($info);
            if ($cid) return $cid;
            $s = $this->client->get('https://' . $this->host . '/search', [
                'query' => $custom,
                'type' => 'channel',
                'geo' => $regionCode,
                'lang' => 'tr',
            ]);
            $cid = $this->extractChannelIdFromSearchResp($s);
            if ($cid) return $cid;
        }
        $s = $this->client->get('https://' . $this->host . '/search', [
            'query' => $input,
            'type' => 'channel',
            'geo' => $regionCode,
            'lang' => 'tr',
        ]);
        $cid = $this->extractChannelIdFromSearchResp($s);
        if ($cid) return $cid;
        return null;
    }

    private function extractChannelIdFromChannelResp(array $info): ?string
    {
        $id = $info['channelId']
            ?? ($info['id'] ?? null)
            ?? ($info['meta']['channelId'] ?? null)
            ?? ($info['channel']['id'] ?? null);
        if (is_string($id) && strpos($id, 'UC') === 0) return $id;
        return null;
    }

    private function extractChannelIdFromSearchResp(array $s): ?string
    {
        $items = $s['items'] ?? ($s['results'] ?? ($s['data']['results'] ?? ($s['data'] ?? [])));
        if (!is_array($items)) return null;
        foreach ($items as $it) {
            $cid = $it['channelId'] ?? ($it['channel']['channelId'] ?? ($it['id']['channelId'] ?? null));
            if (is_string($cid) && strpos($cid, 'UC') === 0) return $cid;
        }
        return null;
    }

    public function channelVideosList(string $channelId, string $kind = 'videos', string $regionCode = 'TR'): array
    {
        $path = $kind === 'shorts' ? '/channel/shorts' : '/channel/videos';
        $base = 'https://' . $this->host . $path;
        return $this->client->get($base, [
            'id' => $channelId,
            'geo' => $regionCode,
            'lang' => 'tr',
        ]);
    }
}
