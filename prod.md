# YouTube Analytics Tool - Proje Dokümantasyonu

## Genel Bakış

YouTube Analytics Tool (YMT), YouTube üzerinde kelime araması yapabilen, kanal videolarını analiz edebilen ve AI destekli içerik optimizasyonu öneren bir web uygulamasıdır. XAMPP üzerinde PHP ile geliştirilmiştir ve RapidAPI ile Codefast AI servislerini kullanır.

### Temel Özellikler

- **Kelime Bazlı Arama**: YouTube'da kelime araması yapıp sonuçları listeler
- **Kanal Analizi**: Bir YouTube kanalının en çok izlenen video/short'larını görüntüler
- **Etiket Toplama**: Videoların etiketlerini (tags) toplar ve kopyalanabilir hale getirir
- **AI Analiz**: Başlık, açıklama, etiket ve SEO analizleri yapar
- **Önbellek Sistemi**: API çağrılarını önbelleğe alarak hız ve kota optimizasyonu sağlar
- **Geçmiş Takibi**: Yapılan aramaları ve analizleri kaydeder
- **JSON Export**: Sonuçları kısa/uzun JSON formatlarında dışa aktarır

---

## Sistem Mimarisi

### Dosya Yapısı

```
ymt/
├── index.php              # Ana sayfa - Kelime araması
├── channel.php            # Kanal video listesi
├── analyze.php            # AI analiz sayfası
├── aitest.php             # AI API test sayfası
├── history.php            # Arama geçmişi
├── export.php             # JSON export endpoint
├── config.php             # Konfigürasyon dosyası
├── README.md              # Proje tanıtımı
├── lib/
│   ├── RapidApiClient.php # RapidAPI HTTP istemcisi
│   ├── Cache.php          # Dosya bazlı cache sistemi
│   └── History.php        # Arama geçmişi yönetimi
├── services/
│   └── YoutubeService.php # YouTube API servis katmanı
├── assets/
│   └── styles.css         # Özel stiller
├── output/                # Dışa aktarılan dosyalar ve analizler
│   ├── channel_{id}/      # Kanal bazlı çıktılar
│   └── search_{query}/    # Arama bazlı çıktılar
└── storage/
    ├── cache/             # Önbellek dosyaları
    └── history.jsonl      # Arama geçmişi (JSONL)
```

---

## Ana Bileşenler

### 1. index.php - Kelime Araması

**İşlevi:**
- YouTube'da kelime araması yapar
- Sonuçları kartlar halinde gösterir (thumbnail, başlık, kanal, metrikler)
- Her video için etiketleri getirir
- İzlenme/beğeni/tarih bazlı sıralama (client-side)
- Etiketlere tıklayarak koleksiyona ekleme
- Kısa/uzun JSON export ve AI analizi

**Anahtar Özellikler:**
- Arama sonuçları önbelleğe alınır (`search:host:r=region:q=query`)
- Tag koleksiyonu localStorage ile saklanır
- JavaScript ile client-side sıralama
- Form parametreleri: `q` (query), `sort` (relevance/views/likes/date), `debug`

**API Çağrıları:**
```
GET /search?query={q}&type=video&geo={region}&lang=tr
GET /video/info?id={videoId}  (her video için)
```

### 2. channel.php - Kanal Video/Short Listesi

**İşlevi:**
- Kanal URL'sinden kanal ID'sini çözer
- Kanalın videolarını veya shorts'larını listeler
- İzlenme/beğeni/tarih bazlı sıralama
- İlk 30 video için detaylı bilgi (tags, açıklama) çeker
- Canlı yayınlar ve prömiyer içerikler filtrelenir

**Anahtar Özellikler:**
- Kanal ID çözümleme önbelleği: `channel:resolve:{md5(url)}`
- Video listesi önbelleği: `channel:list:{channelId}:kind={videos|shorts}`
- Video detayları önbelleği: `video:info:{videoId}`
- "Daha fazla yükle" (+25) özelliği
- Form parametreleri: `url`, `kind` (videos/shorts), `sort`, `limit`

**API Çağrıları:**
```
GET /channel?id={handle|channelId}&geo={region}
GET /channel/videos?id={channelId}&geo={region}
GET /channel/shorts?id={channelId}&geo={region}
GET /video/info?id={videoId}
```

### 3. analyze.php - AI Analiz Motoru

**İşlevi:**
- Kısa JSON formatındaki video verilerini AI ile analiz eder
- 4 farklı analiz tipi sunar:
  1. **Açıklama Analizi** - Description SEO analizi ve öneriler
  2. **Etiket Analizi** - Tag kümeleri, eksik etiketler, yeni öneriler
  3. **Başlık Analizi** - CTR optimizasyonu ve örnek başlıklar
  4. **SEO Özet** - Kapsamlı SEO değerlendirmesi

**Akış:**
1. POST ile JSON payload alınır
2. İlk 30 item seçilir
3. Codefast AI API'sine gönderilir
4. Markdown formatında sonuç alınır
5. Sonuç kaydedilebilir (`output/` klasörüne `.md` olarak)

**AI Prompt Yapısı:**
- Model: `gpt-5-chat` (Codefast)
- Türkçe, maddeli ve somut öneriler
- JSON verisi prompt'a dahil edilir

### 4. export.php - JSON Export API

**İşlevi:**
- Arama ve kanal sonuçlarını JSON dosyası olarak kaydeder
- Kısa ve uzun (full) varyantları destekler

**Endpoint:**
```
POST /export.php
  mode: 'search' | 'channel'
  variant: 'short' | 'full'
  json: string (JSON payload)
  q: query (search mode)
  channelId: string (channel mode)
```

**Çıktı:**
```
output/search_{slug}/search_{slug}_{sort}_{timestamp}_{variant}.json
output/channel_{id}/channel_{id}_{kind}_{sort}_{timestamp}_{variant}.json
```

### 5. history.php - Arama Geçmişi

**İşlevi:**
- `storage/history.jsonl` dosyasından geçmişi okur
- Tür filtreleme: Tümü / Kelime / Kanal
- Tersten kronolojik sıralama (en yeni üstte)
- Her kayıt için "Aç" linki

**JSONL Formatı:**
```json
{"ts":1727463600,"query":"ai öğrenme","meta":{"type":"keyword","host":"yt-api.p.rapidapi.com","region":"TR","count":10}}
{"ts":1727463700,"query":"https://youtube.com/@kanal","meta":{"type":"channel","channelId":"UC_123","kind":"videos","sort":"views","count":25}}
```

### 6. aitest.php - AI Test Sayfası

**İşlevi:**
- Codefast AI API'sini test etmek için basit arayüz
- Soru-cevap formatında test
- Ham istek/yanıt gösterimi (debug)

---

## Kütüphaneler ve Servisler

### lib/RapidApiClient.php

**Görev:** RapidAPI isteklerini yöneten HTTP istemcisi

**Metot:**
```php
public function get(string $url, array $query = []): array
```

**Header'lar:**
- `X-RapidAPI-Key`
- `X-RapidAPI-Host`
- `Accept: application/json`

**Hata Yönetimi:**
- cURL hataları: `['error' => 'Curl error: ...']`
- HTTP >= 400: `['error' => 'HTTP {code} from RapidAPI', 'raw' => ...]`
- Geçersiz JSON: `['error' => 'Invalid JSON response', 'raw' => ...]`

### lib/Cache.php

**Görev:** Dosya bazlı basit önbellek sistemi

**Metotlar:**
```php
public function get(string $key, int $ttlSeconds): ?array
public function set(string $key, array $value): void
```

**Önbellek Formatı:**
```json
{
  "ts": 1727463600,
  "data": {...}
}
```

**TTL Kontrolü:**
- `ttlSeconds > 0` ise zaman aşımı kontrolü yapılır
- `ttlSeconds = 0` ise cache kapalı

### lib/History.php

**Görev:** Arama geçmişini JSONL formatında saklar

**Metot:**
```php
public function addSearch(string $query, array $meta = []): void
```

**Kayıt Formatı:**
```json
{"ts":1727463600,"query":"...","meta":{...}}
```

### services/YoutubeService.php

**Görev:** YouTube API çağrılarını soyutlar, farklı RapidAPI providerları (youtube-v31, yt-api) destekler

**Ana Metotlar:**

1. **search(string $query, int $maxResults, string $regionCode): array**
   - Kelime araması yapar
   - `yt-api` veya `youtube-v31` şemasına göre uyarlanır

2. **videosDetails(array $ids): array**
   - Video detaylarını (tags, stats, publishedAt) getirir
   - Her video için `/video/info` endpoint'i kullanılır (yt-api)
   - Ortak şemaya normalize edilir:
   ```php
   [
     'id' => '...',
     'snippet' => ['tags' => [...], 'publishedAt' => '...'],
     'statistics' => ['viewCount' => 123, 'likeCount' => 45],
     'raw' => {...}
   ]
   ```

3. **resolveChannelId(string $input, string $regionCode): ?string**
   - Kanal URL'sinden ID çözer
   - Desteklenen formatlar:
     - `/channel/UC...`
     - `/@handle`
     - `/c/custom` veya `/user/username`
   - Regex eşleşmesi + API araması kombinasyonu

4. **channelVideosList(string $channelId, string $kind, string $regionCode): array**
   - Kanal videolarını/shorts'larını listeler
   - Endpoint: `/channel/videos` veya `/channel/shorts`

**Yardımcı Metotlar:**
- `extractYtApiTags()` - Tags/keywords alanını normalize eder
- `extractYtApiStats()` - viewCount/likeCount parse eder (1.2M → 1200000)
- `extractYtApiPublishedAt()` - Tarih alanını bulur
- `isEmptyResult()` - Boş sonuç kontrolü

---

## Konfigürasyon (config.php)

```php
return [
    // RapidAPI (YouTube)
    'rapidapi_key' => getenv('RAPIDAPI_KEY') ?: 'YOUR_KEY',
    'rapidapi_host' => getenv('RAPIDAPI_HOST') ?: 'yt-api.p.rapidapi.com',
    'results_per_page' => 10,
    'region_code' => 'TR',
    'cache_ttl_seconds' => 21600, // 6 saat

    // Codefast AI
    'ai_endpoint' => getenv('CODEFAST_API_ENDPOINT') ?: 'https://api.codefast.app/v1/chat/completions',
    'ai_model' => getenv('CODEFAST_MODEL') ?: 'gpt-5-chat',
    'ai_api_key' => getenv('CODEFAST_API_KEY') ?: 'YOUR_KEY',
];
```

**Ortam Değişkenleri:**
- `RAPIDAPI_KEY`
- `RAPIDAPI_HOST`
- `CODEFAST_API_ENDPOINT`
- `CODEFAST_MODEL`
- `CODEFAST_API_KEY`

---

## API Entegrasyonları

### 1. RapidAPI - YT API (ytjar)

**Host:** `yt-api.p.rapidapi.com`

**Endpoint'ler:**

| Endpoint | Metot | Parametreler | Açıklama |
|----------|-------|--------------|----------|
| `/search` | GET | query, type, geo, lang | Video/kanal araması |
| `/video/info` | GET | id | Video detayları, tags, stats |
| `/channel` | GET | id, geo, lang | Kanal bilgisi |
| `/channel/videos` | GET | id, geo, lang | Kanal videoları |
| `/channel/shorts` | GET | id, geo, lang | Kanal shorts'ları |

**Örnek Yanıt (video/info):**
```json
{
  "title": "Video Başlığı",
  "channelTitle": "Kanal Adı",
  "viewCount": "1234567",
  "likeCount": "45678",
  "tags": ["tag1", "tag2"],
  "description": "Video açıklaması...",
  "publishDate": "2024-01-15T10:00:00Z"
}
```

### 2. Codefast AI

**Host:** `api.codefast.app`

**Endpoint:** `/v1/chat/completions`

**İstek Formatı:**
```json
{
  "model": "gpt-5-chat",
  "messages": [
    {"role": "user", "content": "Prompt..."}
  ],
  "stream": false
}
```

**Yanıt Formatı:**
```json
{
  "choices": [
    {
      "message": {
        "role": "assistant",
        "content": "AI yanıtı..."
      }
    }
  ]
}
```

---

## Kullanım Senaryoları

### Senaryo 1: Kelime Araması ve Etiket Toplama

1. `index.php` sayfasına git
2. Arama kutusuna kelime yaz (ör: "yapay zeka")
3. "Ara" butonuna tıkla
4. Sonuçlar kartlar halinde görüntülenir
5. İstediğin etiketlere tıkla → üstteki "Seçilen Etiketler" kutusuna eklenir
6. "Kopyala" ile etiketleri panoya kopyala

### Senaryo 2: Kanal Analizi

1. `channel.php` sayfasına git
2. Kanal URL'sini yapıştır (ör: `https://youtube.com/@kanaladi`)
3. "Videolar" veya "Shorts" seç
4. Sıralama türünü seç (İzlenme/Like/Tarih)
5. "Listele" butonuna tıkla
6. En çok izlenen videolar görüntülenir
7. "Daha fazla yükle" ile 25'er 25'er yükle

### Senaryo 3: AI Analizi

1. Arama veya kanal sayfasında sonuçlar yüklendiğinde
2. Üst kısımda "Kısa JSON'u Analiz Et" butonuna tıkla
3. `analyze.php` sayfasına yönlendirilirsin
4. 4 analiz türünden birini seç:
   - Açıklamaları analiz et
   - Etiketleri analiz et
   - Başlıkları analiz et
   - SEO özet + öneriler
5. Sonuç görüntülenir
6. "Sonucu Kaydet (MD)" ile markdown olarak diske kaydet

### Senaryo 4: JSON Export

1. Arama/kanal sayfasında sonuçlar yüklendiğinde
2. "Kısa JSON'u Kaydet" veya "Uzun JSON'u Kaydet" butonuna tıkla
3. Dosya `output/` klasörüne kaydedilir
4. Alert ile dosya yolu gösterilir

---

## Veri Akışı

### Arama Akışı (index.php)

```
Kullanıcı → Form (q, sort) → index.php
  ↓
Cache kontrol (search:host:r=region:q=query)
  ↓ (miss)
YoutubeService::search() → RapidAPI /search
  ↓
Video ID'leri toplama
  ↓
YoutubeService::videosDetails() → RapidAPI /video/info (her video için)
  ↓
Normalize edilmiş data:
  - results (liste item'ları)
  - detailsMap (video detayları)
  - tagsByVideo (etiketler)
  ↓
Cache'e kaydet
  ↓
History'ye kaydet (JSONL)
  ↓
HTML render + JSON hazırlama
  ↓
Kullanıcıya görüntüle
```

### Kanal Akışı (channel.php)

```
Kullanıcı → Form (url, kind, sort, limit) → channel.php
  ↓
Cache kontrol (channel:resolve:{url_hash})
  ↓ (miss)
YoutubeService::resolveChannelId() → RapidAPI /channel veya /search
  ↓ (channelId elde edildi)
Cache'e kaydet
  ↓
Cache kontrol (channel:list:{channelId}:kind={kind})
  ↓ (miss)
YoutubeService::channelVideosList() → RapidAPI /channel/videos|shorts
  ↓
Canlı/prömiyer filtreleme
  ↓
Sıralama (views/likes/date)
  ↓
Limit uygula
  ↓
İlk 30 video için detay çek:
  Cache kontrol (video:info:{videoId})
    ↓ (miss)
  RapidAPI /video/info
    ↓
  Cache'e kaydet
  ↓
Normalize tags + stats
  ↓
History'ye kaydet
  ↓
HTML render + JSON hazırlama
  ↓
Kullanıcıya görüntüle
```

### AI Analiz Akışı (analyze.php)

```
Kullanıcı → Form submit (mode, variant, payload, analysis) → analyze.php
  ↓
JSON decode payload
  ↓
İlk 30 item seç
  ↓
build_prompt(analysisType, data, mode)
  ↓
Codefast AI API çağrısı (POST /v1/chat/completions)
  ↓
AI yanıtı parse et (choices[0].message.content)
  ↓
Kullanıcıya göster
  ↓
(İsteğe bağlı) Kaydet → output/{slug}/analysis_{timestamp}_{type}.md
```

---

## JSON Şemaları

### Kısa JSON (Short)

**Arama (Search):**
```json
{
  "query": {
    "q": "kelime",
    "sort": "views",
    "limit": 10
  },
  "meta": {
    "region": "TR",
    "generatedAt": "2024-09-27T12:00:00+03:00"
  },
  "items": [
    {
      "id": "videoId123",
      "url": "https://www.youtube.com/watch?v=videoId123",
      "title": "Video Başlığı",
      "channel": {
        "title": "Kanal Adı",
        "id": "UC_kanalId"
      },
      "metrics": {
        "views": 123456,
        "likes": 4567
      },
      "published": {
        "iso": "2024-01-15T10:00:00Z",
        "display": "1 month ago"
      },
      "description": "Video açıklaması...",
      "tags": ["tag1", "tag2", "tag3"]
    }
  ]
}
```

**Kanal (Channel):**
```json
{
  "query": {
    "inputUrl": "https://youtube.com/@kanal",
    "channelId": "UC_kanalId",
    "kind": "videos",
    "sort": "views",
    "limit": 25
  },
  "meta": {
    "region": "TR",
    "generatedAt": "2024-09-27T12:00:00+03:00"
  },
  "items": [
    {
      "id": "videoId123",
      "url": "https://www.youtube.com/watch?v=videoId123",
      "title": "Video Başlığı",
      "channel": {
        "title": "Kanal Adı",
        "id": "UC_kanalId"
      },
      "metrics": {
        "views": 123456,
        "likes": 4567
      },
      "published": {
        "iso": "2024-01-15T10:00:00Z",
        "display": "2 weeks ago"
      },
      "type": "video",
      "description": "Video açıklaması...",
      "tags": ["tag1", "tag2"]
    }
  ]
}
```

### Uzun JSON (Full)

Kısa JSON'a ek olarak:

```json
{
  "meta": {
    "providerHost": "yt-api.p.rapidapi.com",
    "cache": {
      "ttlSeconds": 21600,
      "channelResolveCached": true,
      "listCached": false,
      "detailsCachedCount": 10
    }
  },
  "summary": {
    "totalFetched": 50,
    "filteredOut": {
      "live": 2,
      "upcoming": 1
    },
    "returned": 25,
    "detailsLoadedFor": 25,
    "hasMore": true
  },
  "items": [
    {
      "...": "kısa JSON içeriği",
      "thumbnails": [
        {"url": "https://...", "width": 320, "height": 180}
      ],
      "raw": {
        "listItem": {...},
        "details": {...}
      }
    }
  ],
  "errors": []
}
```

---

## Önbellek Stratejisi

### Cache Key Yapısı

| Tip | Key Formatı | TTL |
|-----|-------------|-----|
| Arama | `search:{host}:r={region}:q={query}` | 6 saat |
| Kanal Resolve | `channel:resolve:{md5(url)}` | 12 saat |
| Kanal Liste | `channel:list:{channelId}:kind={videos\|shorts}` | 12 saat |
| Video Detay | `video:info:{videoId}` | 12 saat |

### Cache Mantığı

1. **Önce cache kontrol**: `Cache::get($key, $ttl)`
2. **Cache miss ise API çağrısı**
3. **Yanıtı cache'e yaz**: `Cache::set($key, $data)`
4. **TTL aşıldıysa yeniden çek**

### Cache Dosyaları

Konum: `storage/cache/`

Format:
```json
{
  "ts": 1727463600,
  "data": {
    "...": "cached data"
  }
}
```

Dosya adı: `{safe_key}.json` (özel karakterler `_` ile değiştirilir)

---

## Frontend Özellikleri

### UI Framework

- **Tailwind CSS** (CDN)
- Özel CSS: `assets/styles.css`
- Tema: Açık tema (beyaz kartlar, gri arka plan)

### JavaScript İşlevleri

1. **Tag Toplama** (index.php, channel.php)
   - Etiket click → localStorage'a ekle
   - Kopyala/Temizle butonları
   - Virgülle ayrılmış liste

2. **Client-Side Sıralama** (index.php)
   - Sort select değiştiğinde JavaScript ile DOM manipülasyonu
   - Sunucuya yeni istek gönderilmez

3. **Export/Analyze** (index.php, channel.php)
   - `exportSearch()`, `exportJson()`: FormData ile POST → export.php
   - `analyzeSearchNow()`, `analyzeJson()`: Hidden form submit → analyze.php

---

## Güvenlik ve Best Practices

### 1. API Anahtarları

- **GİZLİ TUT**: `config.php` dosyasını `.gitignore`'a ekle
- **Ortam Değişkenleri**: Sunucu ortamında `RAPIDAPI_KEY`, `CODEFAST_API_KEY` kullan
- **Varsayılan Değerler**: `YOUR_KEY` değerleri placeholder, güvenli değil

### 2. XSS Koruması

- Tüm kullanıcı girdileri `htmlspecialchars()` ile escape edilir:
  ```php
  function e(string $s): string {
      return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
  }
  ```

### 3. Dosya İzinleri

- `storage/`, `output/` klasörleri yazılabilir olmalı (0777)
- `@mkdir()`, `@file_put_contents()` ile hata sessizleştirme

### 4. Rate Limiting

- RapidAPI kota limitleri var
- Önbellek sistemi kullanarak API çağrılarını minimize et
- TTL ayarlarını optimize et

### 5. JSON Validation

- `json_decode()` sonrası `json_last_error()` kontrolü
- Geçersiz JSON durumunda hata döndür

---

## Hata Yönetimi

### API Hataları

**RapidApiClient:**
```php
['error' => 'Curl error: ...']
['error' => 'HTTP 403 from RapidAPI', 'raw' => '...']
['error' => 'Invalid JSON response', 'raw' => '...']
```

**YoutubeService:**
```php
if (!empty($data['error'])) {
    $error = 'Arama sırasında hata: ' . $data['error'];
}
```

### UI Hata Gösterimi

```html
<?php if ($error): ?>
    <div class="alert error"><?= e($error) ?></div>
<?php endif; ?>
```

---

## Geliştirme Fikirleri

### Kısa Vadeli

- [ ] Sayfalama (nextPageToken) desteği
- [ ] Video süre/tarih filtreleme
- [ ] Toplu etiket seçimi (tümünü seç/kaldır)
- [ ] Karanlık tema toggle
- [ ] Export formatları (CSV, Excel)

### Orta Vadeli

- [ ] Kullanıcı hesapları ve favoriler
- [ ] Kanal karşılaştırma (A vs B)
- [ ] Trend analizi (zaman içinde izlenme değişimi)
- [ ] Otomatik raporlama (haftalık/aylık)
- [ ] Slack/Discord webhook entegrasyonu

### Uzun Vadeli

- [ ] Veritabanı entegrasyonu (MySQL)
- [ ] RESTful API geliştirme
- [ ] React/Vue.js frontend rewrite
- [ ] Real-time veri güncelleme (WebSocket)
- [ ] Multi-tenant sistem (SaaS)

---

## Sorun Giderme

### cURL Hatası

**Problem:** `Curl error: Could not resolve host`

**Çözüm:**
- İnternet bağlantısını kontrol et
- DNS ayarlarını kontrol et
- Firewall/antivirus kontrolü

### RapidAPI 403 Forbidden

**Problem:** `HTTP 403 from RapidAPI`

**Çözüm:**
- API anahtarını kontrol et
- RapidAPI hesabında kota/limit kontrolü
- Host adını doğrula (`yt-api.p.rapidapi.com`)

### Cache Yazılamıyor

**Problem:** `Write failed` veya dosya oluşturulamıyor

**Çözüm:**
- `storage/cache/` klasörü izinlerini kontrol et (0777)
- Disk alanı kontrolü
- PHP `open_basedir` kısıtlaması kontrolü

### AI Analiz Çalışmıyor

**Problem:** `config.php içinde ai_api_key ayarlı değil`

**Çözüm:**
- `config.php` içinde `ai_api_key` değerini gir
- Codefast hesabı ve API key kontrolü
- Endpoint/model adı doğrulaması

---

## Performans Optimizasyonu

### 1. Önbellek Ayarları

- **cache_ttl_seconds**: Varsayılan 6 saat (21600s), ihtiyaca göre artır/azalt
- Sık değişen veriler için TTL'yi azalt
- Statik veriler için TTL'yi artır

### 2. Toplu API Çağrıları

- Video detayları paralel çekilmiyor (sıralı foreach)
- **İyileştirme**: `curl_multi_*` kullanarak paralel çağrı

### 3. Veritabanı Kullanımı

- Şu anda dosya bazlı (cache, history)
- **İyileştirme**: MySQL/PostgreSQL kullanarak indeksleme ve sorgu performansı

### 4. Client-Side Optimizasyon

- JavaScript sıralama DOM manipülasyonu ile yapılıyor
- Büyük listelerde (>100 item) virtual scrolling kullan

---

## Test Senaryoları

### 1. Arama Testi

```
1. index.php'ye git
2. "yapay zeka" kelimesini ara
3. Sonuçların geldiğini doğrula
4. Etiketlere tıkla, koleksiyona eklendiğini doğrula
5. Sıralama dropdown'unu değiştir, kartların yeniden sıralandığını doğrula
6. "Kısa JSON'u Kaydet" butonuna tıkla
7. Alert ile dosya yolu geldiğini doğrula
```

### 2. Kanal Testi

```
1. channel.php'ye git
2. Bir YouTube kanal URL'si gir (ör: https://youtube.com/@veritasium)
3. "Videolar" seçili, "İzlenme" sıralaması ile "Listele"
4. İlk 25 videonun geldiğini doğrula
5. "Daha fazla yükle" tıkla, 25 daha geldiğini doğrula
6. "Shorts" sekmesine geç, shorts'ların geldiğini doğrula
```

### 3. AI Analiz Testi

```
1. index.php'de arama yap
2. "Kısa JSON'u Analiz Et" tıkla
3. analyze.php'ye yönlendirildiğini doğrula
4. "Etiketleri analiz et" butonuna tıkla
5. AI yanıtının geldiğini doğrula
6. "Sonucu Kaydet (MD)" tıkla
7. output/ klasöründe dosyanın oluştuğunu doğrula
```

### 4. Cache Testi

```
1. index.php'de arama yap (ör: "test")
2. Sayfayı yenile (aynı arama)
3. Yanıtın anında geldiğini doğrula (cache hit)
4. storage/cache/ klasöründe ilgili cache dosyasını bul
5. Dosyanın ts değerini kontrol et
```

---

## Versiyon Geçmişi

| Versiyon | Tarih | Değişiklikler |
|----------|-------|---------------|
| 1.0 | 2024-09-26 | İlk sürüm: Arama, etiket toplama |
| 1.1 | 2024-09-27 | Kanal analizi, önbellek, geçmiş |
| 1.2 | 2024-09-27 | AI analiz motoru, export, UI iyileştirmeleri |

---

## Ekip ve Katkıda Bulunanlar

- **Geliştirici**: Proje sahibi
- **API Sağlayıcılar**:
  - RapidAPI (ytjar - YT API)
  - Codefast (AI servisleri)
- **UI Framework**: Tailwind CSS

---

## Lisans

Bu proje özel bir proje olup, açık kaynak değildir. Kopyalama, dağıtım ve ticari kullanım izni gereklidir.

---

## İletişim ve Destek

- **Geliştirici**: [Proje sahibinin e-posta/GitHub profili]
- **Dokümantasyon**: README.md, prod.md
- **Kurulum Sorunu**: config.php ayarlarını kontrol et
- **API Sorunu**: RapidAPI dashboard ve Codefast konsolu kontrol et

---

## Ekler

### A. Örnek .env Dosyası

```env
RAPIDAPI_KEY=your_rapidapi_key_here
RAPIDAPI_HOST=yt-api.p.rapidapi.com
CODEFAST_API_KEY=your_codefast_key_here
CODEFAST_API_ENDPOINT=https://api.codefast.app/v1/chat/completions
CODEFAST_MODEL=gpt-5-chat
```

### B. Apache .htaccess (Opsiyonel)

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /ymt/

    # Dizin listesini devre dışı bırak
    Options -Indexes

    # storage/ klasörüne erişimi engelle
    RewriteRule ^storage/ - [F,L]
</IfModule>
```

### C. Örnek Crontab (Otomatik Cache Temizliği)

```cron
# Her gün saat 03:00'te 7 günden eski cache dosyalarını sil
0 3 * * * find /Applications/XAMPP/xamppfiles/htdocs/ymt/storage/cache/ -type f -mtime +7 -delete
```

---

**Son Güncelleme:** 2024-10-01
**Dokümantasyon Versiyonu:** 1.0
