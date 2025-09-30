<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Cache.php';
require_once __DIR__ . '/lib/History.php';
require_once __DIR__ . '/services/YoutubeService.php';

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function parse_intish($v): int {
    if (is_numeric($v)) return (int)$v;
    if (is_string($v)) {
        $s = str_replace([',', ' '], ['', ''], $v);
        if (preg_match('/^(\d+)([kKmMbB])$/', $s, $m)) {
            $base = (int)$m[1];
            $mul = ['k'=>1e3,'K'=>1e3,'m'=>1e6,'M'=>1e6,'b'=>1e9,'B'=>1e9][$m[2]];
            return (int)round($base * $mul);
        }
        if (is_numeric($s)) return (int)$s;
    }
    return 0;
}

function get_video_id(array $item): ?string {
    return $item['id']
        ?? ($item['videoId'] ?? ($item['video']['videoId'] ?? ($item['id']['videoId'] ?? null)));
}

$rapidKey = $config['rapidapi_key'] ?? '';
$rapidHost = $config['rapidapi_host'] ?? 'yt-api.p.rapidapi.com';
$regionCode = $config['region_code'] ?? 'TR';

$url = isset($_GET['url']) ? trim((string)$_GET['url']) : '';
$kind = isset($_GET['kind']) && $_GET['kind']==='shorts' ? 'shorts' : 'videos';
$sort = isset($_GET['sort']) ? (string)$_GET['sort'] : 'views'; // views|date|likes
$limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 25;
$fetchDetailsTop = 30; // ilk 30 için detay
$cacheTtl = 43200; // 12 saat

$error = '';
$channelId = null;
$items = [];
$detailsMap = [];
$tagsByVideo = [];
$stats = [
    'resolveCached' => false,
    'listCached' => false,
    'detailsCachedCount' => 0,
    'totalFetched' => 0,
    'filteredOut' => ['live' => 0, 'upcoming' => 0],
];

if ($url !== '') {
    if (!$rapidKey || $rapidKey === 'YOUR_RAPIDAPI_KEY') {
        $error = 'Lütfen RapidAPI anahtarını config.php dosyasında ayarlayın.';
    } else {
        $client = new RapidApiClient($rapidKey, $rapidHost);
        $yt = new YoutubeService($client, $rapidHost);
        $cache = new Cache(__DIR__ . '/storage/cache');

        // Kanal ID çöz
        $cacheKeyResolve = 'channel:resolve:' . md5($url);
        $resolved = $cache->get($cacheKeyResolve, $cacheTtl);
        if (is_array($resolved) && !empty($resolved['channelId'])) {
            $channelId = (string)$resolved['channelId'];
            $stats['resolveCached'] = true;
        }
        if (!$channelId) {
            $channelId = $yt->resolveChannelId($url, $regionCode);
            if ($channelId) $cache->set($cacheKeyResolve, ['channelId' => $channelId]);
        }
        if (!$channelId) {
            $error = 'Kanal ID çözümlenemedi. Lütfen geçerli bir kanal bağlantısı girin.';
        } else {
            // Listeyi al (shorts / videolar)
            $cacheKeyList = 'channel:list:' . $channelId . ':kind=' . $kind;
            $listResp = $cache->get($cacheKeyList, $cacheTtl);
            if (!$listResp) {
                $listResp = $yt->channelVideosList($channelId, $kind, $regionCode);
                $cache->set($cacheKeyList, $listResp);
            } else {
                $stats['listCached'] = true;
            }
            if (!empty($listResp['error'])) {
                $error = 'Kanal listesi alınamadı: ' . e((string)$listResp['error']);
            } else {
                $list = $listResp['items'] ?? ($listResp['results'] ?? ($listResp['data'] ?? []));
                if (!is_array($list)) $list = [];
                $stats['totalFetched'] = count($list);
                // Canlı yayın / prömiyer hariç
                $filtered = [];
                foreach ($list as $it) {
                    $isLive = ($it['isLive'] ?? false) || ($it['live'] ?? false) || in_array('LIVE', $it['badges'] ?? [], true);
                    $isUpcoming = ($it['isUpcoming'] ?? false) || ($it['upcoming'] ?? false) || in_array('UPCOMING', $it['badges'] ?? [], true);
                    if ($isLive) { $stats['filteredOut']['live']++; continue; }
                    if ($isUpcoming) { $stats['filteredOut']['upcoming']++; continue; }
                    $vid = get_video_id((array)$it);
                    if (!$vid) continue;
                    $filtered[] = $it;
                }
                // Sıralama (listeden alınan alanlara göre)
                $keyFn = function($it) use ($sort) {
                    if ($sort === 'date') {
                        $d = $it['publishedTimeText'] ?? ($it['publishedText'] ?? ($it['published'] ?? ''));
                        // Bu alan genelde "2 years ago" gibi, parse zor; 0 ver
                        // Detay çekilen ilk 30'da yayın tarihi data-attribute olarak doldurulacak; JS ile daha doğru sıralanabilir
                        return 0;
                    }
                    if ($sort === 'likes') {
                        $l = $it['stats']['likes'] ?? ($it['likeCount'] ?? 0);
                        return parse_intish($l);
                    }
                    $v = $it['stats']['views'] ?? ($it['viewCount'] ?? ($it['viewCountText'] ?? 0));
                    return parse_intish($v);
                };
                usort($filtered, function($a,$b) use ($keyFn){
                    $av = $keyFn($a); $bv = $keyFn($b);
                    if ($av === $bv) return 0;
                    return $av < $bv ? 1 : -1; // desc
                });

                // Limit uygula
                $items = array_slice($filtered, 0, $limit);

                // İlk 30 için detay (tags + açıklama + metrikleri netleştir)
                $videoIds = [];
                foreach (array_slice($items, 0, min($fetchDetailsTop, count($items))) as $it) {
                    $vid = get_video_id((array)$it);
                    if ($vid) $videoIds[] = $vid;
                }
                if ($videoIds) {
                    $detailsMap = [];
                    // Detayları tek tek alıp cache’leyelim (kümülatif)
                    foreach ($videoIds as $vid) {
                        $cacheKeyInfo = 'video:info:' . $vid;
                        $info = $cache->get($cacheKeyInfo, $cacheTtl);
                        if (!$info) {
                            $base = 'https://' . $rapidHost . '/video/info';
                            $resp = $client->get($base, ['id' => $vid]);
                            if (empty($resp['error'])) {
                                $info = $resp;
                                $cache->set($cacheKeyInfo, $info);
                            }
                        } else { $stats['detailsCachedCount']++; }
                        if ($info) {
                            $detailsMap[$vid] = $info;
                        }
                    }
                    // Etiketler haritası
                    foreach ($videoIds as $vid) {
                        $info = $detailsMap[$vid] ?? [];
                        $tags = $info['tags'] ?? $info['keywords'] ?? ($info['video']['keywords'] ?? []);
                        if (is_string($tags)) {
                            $tags = array_map('trim', preg_split('/,\s*/', $tags));
                        }
                        $tagsByVideo[$vid] = is_array($tags) ? $tags : [];
                    }
                }
                // Geçmişe kaydet
                $hist = new History(__DIR__ . '/storage');
                $hist->addSearch($url, [
                    'type' => 'channel',
                    'channelId' => $channelId,
                    'kind' => $kind,
                    'sort' => $sort,
                    'limit' => $limit,
                    'count' => count($items),
                ]);
            }
        }
    }
}

?><!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kanal Videoları (YT API)</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
<div class="container mx-auto max-w-6xl p-4">
    <div class="flex items-center justify-between mb-3">
        <h1 class="text-xl text-gray-900 font-semibold">Kanalın En Çok İzlenenleri</h1>
        <div class="flex items-center gap-2">
            <?php if ($url !== '' && !$error): ?>
                <button class="px-3 py-1 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200" type="button" onclick="exportJson('channel','short')">Kısa JSON'u Kaydet</button>
                <button class="px-3 py-1 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200" type="button" onclick="exportJson('channel','full')">Uzun JSON'u Kaydet</button>
                <button class="px-3 py-1 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200" type="button" onclick="analyzeJson('channel')">Kısa JSON'u Analiz Et</button>
            <?php endif; ?>
            <a class="text-sm px-3 py-1 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200" href="history.php">Geçmiş</a>
        </div>
    </div>
    <form method="get" class="search-form flex gap-2 items-center mb-4">
        <input class="flex-1 px-3 py-2 rounded-md border border-gray-300 bg-white text-gray-900" type="url" name="url" placeholder="Kanal bağlantısını yapıştırın (örn. https://www.youtube.com/@kanal)" value="<?= e($url) ?>" required>
        <div class="flex gap-2">
            <a class="px-3 py-2 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200 <?= $kind==='videos'?'font-semibold':'' ?>" href="?url=<?= urlencode($url) ?>&kind=videos&limit=<?= (int)$limit ?>&sort=<?= e($sort) ?>">Videolar</a>
            <a class="px-3 py-2 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200 <?= $kind==='shorts'?'font-semibold':'' ?>" href="?url=<?= urlencode($url) ?>&kind=shorts&limit=<?= (int)$limit ?>&sort=<?= e($sort) ?>">Shorts</a>
        </div>
        <select id="sortSelect" name="sort" title="Sırala" class="sort-select px-3 py-2 rounded-md border border-gray-300 bg-white text-gray-900">
            <option value="views" <?= $sort==='views'?'selected':'' ?>>İzlenme (çoktan aza)</option>
            <option value="likes" <?= $sort==='likes'?'selected':'' ?>>Like (çoktan aza)</option>
            <option value="date" <?= $sort==='date'?'selected':'' ?>>Tarih (yeniden eskiye)</option>
        </select>
        <button class="px-4 py-2 rounded-md border border-gray-300 bg-indigo-600 text-white hover:bg-indigo-500" type="submit">Listele</button>
    </form>

    <div class="collector bg-white border border-gray-200 rounded-xl p-3 mb-4">
        <div class="collector-head text-xs text-gray-500 mb-2">Seçilen Etiketler</div>
        <div class="collector-actions flex gap-2 items-center">
            <input class="flex-1 px-3 py-2 rounded-md border border-gray-300 bg-white text-gray-900" type="text" id="collected" placeholder="Etiketler buraya virgülle eklenecek" readonly>
            <button class="px-3 py-2 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200" type="button" id="copyTags">Kopyala</button>
            <button class="px-3 py-2 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200" type="button" id="clearTags">Temizle</button>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

<?php if ($url !== '' && !$error): ?>
        <div class="results grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <?php if (!$items): ?>
                <div class="empty text-gray-500">Sonuç bulunamadı.</div>
            <?php else: ?>
                <?php foreach ($items as $it): ?>
                    <?php
                    $vid = get_video_id((array)$it) ?? '';
                    // Başlık / kanal / görsel
                    $title = $it['title'] ?? '';
                    $channel = $it['channelTitle'] ?? ($it['channel']['title'] ?? '');
                    $thumb = '';
                    if (!empty($it['thumbnail']) && is_array($it['thumbnail'])) {
                        $first = $it['thumbnail'][0] ?? [];
                        $thumb = is_array($first) ? ($first['url'] ?? '') : (string)$first;
                    } elseif (!empty($it['thumbnails']) && is_array($it['thumbnails'])) {
                        $first = $it['thumbnails'][0] ?? [];
                        $thumb = is_array($first) ? ($first['url'] ?? '') : (string)$first;
                    }
                    $views = parse_intish($it['stats']['views'] ?? ($it['viewCount'] ?? ($it['viewCountText'] ?? 0)));
                    $likes = parse_intish($it['stats']['likes'] ?? ($it['likeCount'] ?? 0));
                    $detail = $detailsMap[$vid] ?? [];
                    $desc = $detail['description'] ?? ($detail['video']['description'] ?? '');
                    $publishedIso = $detail['publishDate'] ?? ($detail['uploadDate'] ?? '');
                    $tags = $tagsByVideo[$vid] ?? [];
                    ?>
                    <div class="card bg-white border border-gray-200 rounded-xl overflow-hidden flex flex-col" data-id="<?= e($vid) ?>" data-views="<?= e((string)$views) ?>" data-likes="<?= e((string)$likes) ?>" data-date="<?= e($publishedIso) ?>">
                        <a class="thumb" href="https://www.youtube.com/watch?v=<?= e($vid) ?>" target="_blank" rel="noopener">
                            <?php if ($thumb): ?><img class="w-full block" src="<?= e($thumb) ?>" alt="<?= e($title) ?>"><?php endif; ?>
                        </a>
                        <div class="content p-3 flex flex-col gap-2">
                            <h3 class="title m-0 text-base leading-tight font-medium"><a class="text-gray-900 hover:underline" href="https://www.youtube.com/watch?v=<?= e($vid) ?>" target="_blank" rel="noopener"><?= e($title) ?></a></h3>
                            <div class="flex justify-between items-center text-xs text-gray-500">
                                <div>Kanal: <?= e($channel) ?></div>
                                <div class="flex gap-3">
                                    <span title="İzlenme">👁 <?= number_format($views) ?></span>
                                    <span title="Like">❤️ <?= number_format($likes) ?></span>
                                </div>
                            </div>
                            <?php if ($desc): ?>
                                <details class="text-sm text-gray-700">
                                    <summary class="cursor-pointer select-none">Açıklama</summary>
                                    <div class="mt-1 whitespace-pre-wrap"><?= e($desc) ?></div>
                                </details>
                            <?php endif; ?>
                            <?php if ($tags): ?>
                                <div class="tags flex flex-wrap gap-2">
                                    <?php foreach ($tags as $t): $rt = trim((string)$t); if ($rt==='') continue; ?>
                                        <span class="tag inline-block bg-gray-100 border border-gray-300 text-gray-700 rounded-full px-2 py-1 text-xs cursor-pointer hover:bg-gray-200" data-tag="<?= e($rt) ?>">#<?= e($rt) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="no-tags text-xs text-gray-500">Etiket bulunamadı veya bu video için yüklenmedi.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php if (count($items) >= $limit): ?>
            <div class="mt-4 flex justify-center">
                <a class="px-4 py-2 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200" href="?url=<?= urlencode($url) ?>&kind=<?= e($kind) ?>&sort=<?= e($sort) ?>&limit=<?= (int)($limit+25) ?>">Daha fazla yükle (+25)</a>
            </div>
        <?php endif; ?>
        <?php
        // JSON Kısa ve Uzun yapılarını hazırla
        $nowIso = date('c');
        $shortItems = [];
        $fullItems = [];
        foreach ($items as $it) {
            $vid = get_video_id((array)$it) ?? '';
            $title = $it['title'] ?? '';
            $chanTitle = $it['channelTitle'] ?? ($it['channel']['title'] ?? '');
            $chanId = $it['channelId'] ?? ($it['channel']['channelId'] ?? $channelId);
            $views = parse_intish($it['stats']['views'] ?? ($it['viewCount'] ?? ($it['viewCountText'] ?? 0)));
            $likes = parse_intish($it['stats']['likes'] ?? ($it['likeCount'] ?? 0));
            $detail = $detailsMap[$vid] ?? [];
            $desc = $detail['description'] ?? ($detail['video']['description'] ?? null);
            $tags = $tagsByVideo[$vid] ?? [];
            $pubIso = $detail['publishDate'] ?? ($detail['uploadDate'] ?? null);
            $display = $it['publishedTimeText'] ?? ($it['publishedText'] ?? ($it['published'] ?? null));
            $itemShort = [
                'id' => $vid,
                'url' => 'https://www.youtube.com/watch?v=' . $vid,
                'title' => $title,
                'channel' => ['title' => $chanTitle, 'id' => $chanId],
                'metrics' => ['views' => $views, 'likes' => $likes],
                'published' => ['iso' => $pubIso, 'display' => $display],
                'type' => $kind === 'shorts' ? 'shorts' : 'video',
                'description' => $desc,
                'tags' => $tags,
            ];
            $itemFull = $itemShort;
            // Thumbnail'ları ekle
            $thumbs = [];
            if (!empty($it['thumbnail']) && is_array($it['thumbnail'])) {
                foreach ($it['thumbnail'] as $th) {
                    if (is_array($th) && !empty($th['url'])) $thumbs[] = $th;
                }
            } elseif (!empty($it['thumbnails']) && is_array($it['thumbnails'])) {
                foreach ($it['thumbnails'] as $th) {
                    if (is_array($th) && !empty($th['url'])) $thumbs[] = $th;
                }
            }
            if ($thumbs) $itemFull['thumbnails'] = $thumbs;
            $itemFull['raw'] = ['listItem' => $it, 'details' => $detail ?: null];
            $shortItems[] = $itemShort;
            $fullItems[] = $itemFull;
        }
        $shortJson = [
            'query' => [
                'inputUrl' => $url,
                'channelId' => $channelId,
                'kind' => $kind,
                'sort' => $sort,
                'limit' => $limit,
            ],
            'meta' => [
                'region' => $regionCode,
                'generatedAt' => $nowIso,
            ],
            'items' => $shortItems,
        ];
        $fullJson = [
            'query' => $shortJson['query'],
            'meta' => [
                'region' => $regionCode,
                'providerHost' => $rapidHost,
                'cache' => [
                    'ttlSeconds' => $cacheTtl,
                    'channelResolveCached' => $stats['resolveCached'],
                    'listCached' => $stats['listCached'],
                    'detailsCachedCount' => $stats['detailsCachedCount'],
                ],
                'generatedAt' => $nowIso,
            ],
            'summary' => [
                'totalFetched' => $stats['totalFetched'],
                'filteredOut' => $stats['filteredOut'],
                'returned' => count($items),
                'detailsLoadedFor' => count($detailsMap),
                'hasMore' => count($items) < ($stats['totalFetched'] - ($stats['filteredOut']['live'] + $stats['filteredOut']['upcoming'])),
            ],
            'items' => $fullItems,
            'errors' => $error ? [$error] : [],
        ];
        ?>
        <div class="mt-6">
            <details class="bg-white border border-gray-200 rounded-xl p-3">
                <summary class="cursor-pointer select-none font-medium">Kısa JSON</summary>
                <div class="mt-2">
                    <pre style="white-space:pre-wrap;word-break:break-word;" id="shortJsonChannel"><?= e(json_encode($shortJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                    <button class="mt-2 px-3 py-2 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200" type="button" onclick="exportJson('channel','short')">Kısa JSON'u Kaydet</button>
                </div>
            </details>
        </div>
        <div class="mt-4">
            <details class="bg-white border border-gray-200 rounded-xl p-3">
                <summary class="cursor-pointer select-none font-medium">Uzun JSON</summary>
                <div class="mt-2">
                    <pre style="white-space:pre-wrap;word-break:break-word;" id="fullJsonChannel"><?= e(json_encode($fullJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                    <button class="mt-2 px-3 py-2 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200" type="button" onclick="exportJson('channel','full')">Uzun JSON'u Kaydet</button>
                </div>
            </details>
        </div>
    <?php endif; ?>
</div>

<script>
(function(){
    const input = document.getElementById('collected');
    const copyBtn = document.getElementById('copyTags');
    const clearBtn = document.getElementById('clearTags');
    const sortSelect = document.getElementById('sortSelect');
    const results = document.querySelector('.results');
    const load = () => { try { input.value = localStorage.getItem('collectedTags') || ''; } catch(_) {} };
    const save = () => { try { localStorage.setItem('collectedTags', input.value); } catch(_) {} };
    load();
    document.addEventListener('click', function(ev){
        const el = ev.target.closest('.tag');
        if (!el) return;
        const tag = (el.getAttribute('data-tag') || '').trim();
        if (!tag) return;
        ev.preventDefault();
        const current = input.value.trim();
        const arr = current ? current.split(',').map(s=>s.trim()).filter(Boolean) : [];
        if (!arr.includes(tag)) arr.push(tag);
        input.value = arr.join(', ');
        save();
    });
    copyBtn.addEventListener('click', async () => {
        input.select();
        try { await navigator.clipboard.writeText(input.value); } catch(_) {}
    });
    clearBtn.addEventListener('click', () => { input.value=''; save(); });
    function sortCards(mode){
        if (!results) return;
        const cards = Array.from(results.querySelectorAll('.card'));
        const key = {
            views: c => parseInt(c.getAttribute('data-views')||'0', 10),
            likes: c => parseInt(c.getAttribute('data-likes')||'0', 10),
            date:  c => Date.parse(c.getAttribute('data-date')||'') || 0,
        }[mode];
        if (!key) return;
        cards.sort((a,b)=> key(b) - key(a));
        cards.forEach(c=>results.appendChild(c));
    }
    if (sortSelect && results) {
        sortSelect.addEventListener('change', function(){
            const u = new URL(window.location.href);
            u.searchParams.set('sort', this.value);
            window.location.assign(u.toString());
        });
        if (sortSelect.value && sortSelect.value !== 'views') {
            sortCards(sortSelect.value);
        }
    }
    window.exportJson = async function(mode, variant){
        try {
            const pre = document.getElementById(variant==='short' ? 'shortJsonChannel' : 'fullJsonChannel');
            const json = pre ? pre.textContent : '';
            const form = new FormData();
            form.append('mode', 'channel');
            form.append('variant', variant);
            form.append('json', json);
            form.append('channelId', <?= json_encode($channelId) ?>);
            form.append('kind', <?= json_encode($kind) ?>);
            form.append('sort', <?= json_encode($sort) ?>);
            const res = await fetch('export.php', { method:'POST', body: form });
            const data = await res.json();
            if (!data.ok) throw new Error(data.error||'Export failed');
            alert('Kaydedildi: ' + data.path);
        } catch (e) {
            alert('Hata: ' + e.message);
        }
    }
    window.analyzeJson = function(mode){
        try {
            const pre = document.getElementById('shortJsonChannel');
            const json = pre ? pre.textContent : '';
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'analyze.php';
            const add = (k,v)=>{ const i=document.createElement('input'); i.type='hidden'; i.name=k; i.value=v; form.appendChild(i); };
            add('mode','channel');
            add('variant','short');
            add('payload', json);
            add('channelId', <?= json_encode($channelId) ?>);
            add('back', window.location.href);
            document.body.appendChild(form);
            form.submit();
        } catch(e) { alert('Hata: '+e.message); }
    }
})();
</script>
</body>
</html>
