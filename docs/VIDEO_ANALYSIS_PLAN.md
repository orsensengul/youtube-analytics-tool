# 🎬 Tekli Video Analizi - Serüven Planı

## Genel Bakış

YouTube'da tekli video analizi yapabilmek için kapsamlı bir özellik seti. Kullanıcı bir video URL'si veya ID'si girdiğinde, adım adım ilerleyen bir serüven gibi video hakkında detaylı bilgiler toplanacak ve AI destekli analizler yapılacak.

## 1. Yeni Sayfa: `video-analyze.php`

### Özellikler
- Video URL/ID girişi için form
- Adım adım ilerleme göstergesi (progress bar)
- Her adımın sonuçlarını görsel kartlarla gösterme
- Responsive tasarım (Tailwind CSS)
- Real-time güncelleme (AJAX/Fetch API)

### UI Bileşenleri
```
┌─────────────────────────────────────────┐
│  🎬 Video Analizi                       │
├─────────────────────────────────────────┤
│  Video URL/ID: [________________] [Analiz Et] │
├─────────────────────────────────────────┤
│  İlerleme: ████████░░░░░░░░ 50%        │
├─────────────────────────────────────────┤
│  ✓ Video Bilgileri                      │
│  ✓ Metrikler                            │
│  ✓ Etiketler                            │
│  ⏳ Transkript Çekiliyor...             │
│  ⏸ Benzer Videolar                      │
│  ⏸ AI Analizi                           │
└─────────────────────────────────────────┘
```

### Form Alanları
- Video URL veya ID girişi
- Analiz türü seçimi (Hızlı / Detaylı / Tam)
- Benzer video sayısı (5-20 arası)
- Transkript dili seçimi (TR/EN/Auto)

## 2. Backend Servisi: `services/VideoAnalysisService.php`

### Sınıf Yapısı
```php
class VideoAnalysisService
{
    private RapidApiClient $client;
    private YoutubeService $youtubeService;
    private AIProvider $aiProvider;
    private string $host;

    // Ana analiz metodu
    public function analyzeVideo(string $videoId, array $options = []): array

    // Adım metodları
    private function getVideoInfo(string $videoId): array
    private function getVideoMetrics(string $videoId): array
    private function getVideoTags(string $videoId): array
    private function getVideoThumbnails(string $videoId): array
    private function getVideoTranscript(string $videoId, string $lang = 'tr'): array
    private function findSimilarVideos(array $videoData, int $limit = 10): array

    // AI analiz metodları
    private function analyzeTranscript(string $transcript): array
    private function generateSEORecommendations(array $videoData): array
    private function compareWithSimilar(array $videoData, array $similarVideos): array
    private function predictPerformance(array $videoData): array
}
```

### Analiz Adımları

#### Adım 1: Video Temel Bilgileri
- Video ID
- Başlık
- Açıklama
- Kanal adı ve ID
- Yayın tarihi
- Video süresi
- Kategori

#### Adım 2: Video Metrikleri
- İzlenme sayısı
- Beğeni sayısı
- Yorum sayısı
- Beğeni/İzlenme oranı
- Günlük ortalama izlenme (yayından bu yana)

#### Adım 3: Etiketler ve Kategoriler
- Video etiketleri (tags)
- Kategori bilgisi
- Hashtag'ler
- Etiket analizi (popülerlik, alakalılık)

#### Adım 4: Thumbnail/Görsel Analizi
- Thumbnail URL'leri (default, medium, high, maxres)
- Görsel boyutları
- Görsel kalite skoru (varsa)

#### Adım 5: Transkript Çekme
- RapidAPI üzerinden transkript endpoint'i
- Alternatif: YouTube Data API v3
- Fallback: Manuel transkript yükleme
- Dil tespiti
- Zaman damgalı metin

#### Adım 6: Benzer Video Araması
- Video etiketlerine göre arama
- Başlık benzerliğine göre arama
- Aynı kategorideki videolar
- Aynı kanaldaki benzer videolar
- Rakip kanalların benzer içerikleri

## 3. AI Analiz Entegrasyonu

### Analiz Türleri

#### 3.1 Transkript Analizi
```
Prompt: "Aşağıdaki video transkriptini analiz et:
- Ana konular ve temalar
- Anahtar kelimeler (keyword density)
- Duygusal ton (pozitif/negatif/nötr)
- İçerik yapısı (giriş/gelişme/sonuç)
- Hedef kitle tahmini
- İçerik kalitesi değerlendirmesi"
```

#### 3.2 SEO Önerileri
```
Prompt: "Video verilerini analiz ederek SEO önerileri sun:
- Başlık optimizasyonu (mevcut vs önerilen)
- Açıklama optimizasyonu
- Etiket önerileri (eksik/fazla/alakasız)
- Thumbnail önerileri
- Hashtag stratejisi
- Yayın zamanı önerileri"
```

#### 3.3 Rakip Analizi
```
Prompt: "Bu videoyu benzer videolarla karşılaştır:
- Güçlü yönler
- Zayıf yönler
- Fark yaratan özellikler
- Rakiplerin kullandığı ama bu videoda olmayan stratejiler
- Rekabet avantajı önerileri"
```

#### 3.4 İçerik Boşlukları ve Fırsatlar
```
Prompt: "Video içeriğini analiz ederek fırsatları belirle:
- Eksik kalan konular
- Derinleştirilebilecek noktalar
- Seri içerik fikirleri
- İlgili konu önerileri
- Trend fırsatları"
```

#### 3.5 Performans Tahmini
```
Prompt: "Video verilerini kullanarak performans tahmini yap:
- Potansiyel izlenme tahmini
- Viral olma olasılığı
- Hedef kitleye ulaşma skoru
- Etkileşim tahmini
- Başarı faktörleri
- Risk faktörleri"
```

## 4. Veritabanı Migration: `006_video_analysis.sql`

```sql
-- Video analiz kayıtları
CREATE TABLE IF NOT EXISTS video_analyses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    video_id VARCHAR(20) NOT NULL,
    video_title VARCHAR(500),
    channel_id VARCHAR(50),
    channel_title VARCHAR(200),

    -- Video verileri
    view_count BIGINT,
    like_count INT,
    comment_count INT,
    published_at DATETIME,
    duration VARCHAR(20),
    category_id INT,

    -- Analiz verileri
    analysis_type ENUM('quick', 'detailed', 'full') DEFAULT 'detailed',
    has_transcript BOOLEAN DEFAULT FALSE,
    similar_videos_count INT DEFAULT 0,

    -- AI analiz sonuçları
    transcript_analysis TEXT,
    seo_recommendations TEXT,
    competitor_analysis TEXT,
    content_gaps TEXT,
    performance_prediction TEXT,

    -- Meta
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_user_video (user_id, video_id),
    INDEX idx_video_id (video_id),
    INDEX idx_created_at (created_at),

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transkript cache
CREATE TABLE IF NOT EXISTS video_transcripts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    video_id VARCHAR(20) NOT NULL UNIQUE,
    language VARCHAR(10) DEFAULT 'tr',
    transcript_text LONGTEXT,
    transcript_json JSON,
    word_count INT,
    duration_seconds INT,

    -- Cache bilgileri
    source VARCHAR(50), -- 'rapidapi', 'youtube_api', 'manual'
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP,
    hit_count INT DEFAULT 0,
    last_accessed TIMESTAMP,

    INDEX idx_video_id (video_id),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Benzer video sonuçları
CREATE TABLE IF NOT EXISTS video_similar_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    analysis_id INT NOT NULL,
    source_video_id VARCHAR(20) NOT NULL,
    similar_video_id VARCHAR(20) NOT NULL,

    -- Benzer video bilgileri
    title VARCHAR(500),
    channel_title VARCHAR(200),
    view_count BIGINT,
    like_count INT,
    published_at DATETIME,

    -- Benzerlik metrikleri
    similarity_score DECIMAL(5,2), -- 0-100 arası
    similarity_type ENUM('tags', 'title', 'category', 'channel', 'content'),
    common_tags JSON,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_analysis_id (analysis_id),
    INDEX idx_source_video (source_video_id),
    INDEX idx_similarity_score (similarity_score),

    FOREIGN KEY (analysis_id) REFERENCES video_analyses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kullanıcı limitleri için yeni sütun ekle
ALTER TABLE users
ADD COLUMN IF NOT EXISTS daily_video_analysis_limit INT DEFAULT 10,
ADD COLUMN IF NOT EXISTS monthly_video_analysis_limit INT DEFAULT 100;

-- Kullanıcı aktivite loguna yeni tip ekle
-- (Mevcut user_activity_log tablosu varsa)
```

## 5. UI/UX Özellikleri

### 5.1 Adım Adım İlerleme
```html
<div class="progress-container">
    <div class="step completed">
        <div class="step-icon">✓</div>
        <div class="step-label">Video Bilgileri</div>
    </div>
    <div class="step active">
        <div class="step-icon">⏳</div>
        <div class="step-label">Transkript</div>
    </div>
    <div class="step pending">
        <div class="step-icon">⏸</div>
        <div class="step-label">AI Analizi</div>
    </div>
</div>
```

### 5.2 Sonuç Kartları
Her adımın sonucu katlanabilir (collapsible) kartlarda gösterilecek:

```
┌─────────────────────────────────────────┐
│ ▼ Video Bilgileri                    ✓  │
├─────────────────────────────────────────┤
│ Başlık: ...                             │
│ Kanal: ...                              │
│ Yayın Tarihi: ...                       │
│ Süre: ...                               │
└─────────────────────────────────────────┘

┌─────────────────────────────────────────┐
│ ▼ Metrikler                          ✓  │
├─────────────────────────────────────────┤
│ 👁 İzlenme: 1.2M                        │
│ 👍 Beğeni: 45K (3.75%)                  │
│ 💬 Yorum: 2.3K                          │
└─────────────────────────────────────────┘

┌─────────────────────────────────────────┐
│ ▼ AI Analizi - SEO Önerileri        ✓  │
├─────────────────────────────────────────┤
│ [AI tarafından üretilen içerik]         │
└─────────────────────────────────────────┘
```

### 5.3 Kaydetme Seçenekleri
- 💾 Tüm Analizi Kaydet (MD)
- 📄 PDF Olarak İndir
- 📊 Excel Raporu
- 🔗 Paylaşılabilir Link Oluştur

### 5.4 Benzer Videolar Tablosu
```
┌──────────────────────────────────────────────────────────┐
│ Benzer Videolar (10 sonuç)                              │
├────────┬──────────────┬──────────┬──────────┬───────────┤
│ Başlık │ Kanal        │ İzlenme  │ Benzerlik│ Tarih     │
├────────┼──────────────┼──────────┼──────────┼───────────┤
│ ...    │ ...          │ 1.5M     │ 87%      │ 2 ay önce │
│ ...    │ ...          │ 890K     │ 82%      │ 1 ay önce │
└────────┴──────────────┴──────────┴──────────┴───────────┘
```

## 6. Navbar Güncellemesi

`includes/navbar.php` dosyasına yeni link eklenecek:

```php
<a class="text-sm px-3 py-2 rounded-md border <?= navIsActive('video-analyze') ?>"
   href="<?= $baseUrl ?>video-analyze.php">
    🎬 Video Analizi
</a>
```

Mobil menüye de aynı şekilde eklenecek.

## 7. Ek Özellikler

### 7.1 Cache Sistemi
- Aynı video 24 saat içinde tekrar analiz edilirse cache'den dönülecek
- Transkriptler 7 gün cache'lenecek
- Benzer video sonuçları 6 saat cache'lenecek
- Cache hit/miss istatistikleri tutulacak

### 7.2 Kullanıcı Limitleri
- Günlük video analiz limiti (varsayılan: 10)
- Aylık video analiz limiti (varsayılan: 100)
- Admin kullanıcılar için sınırsız
- Limit aşımında uyarı mesajı

### 7.3 Geçmiş Analiz Sonuçları
- Kullanıcının daha önce yaptığı analizleri listeleme
- Tarih, video başlığı, analiz türü filtreleme
- Eski analizleri yeniden görüntüleme
- Karşılaştırma için seçme

### 7.4 Karşılaştırma Modu
- 2 videoyu yan yana analiz etme
- Metrik karşılaştırması (tablo ve grafik)
- Etiket benzerliği analizi
- Hangisi daha iyi performans gösteriyor?
- Fark yaratan faktörler

### 7.5 Toplu Analiz (Gelecek Özellik)
- Birden fazla video URL'si yükleme
- Toplu analiz yapma
- Karşılaştırmalı rapor oluşturma
- Excel/CSV export

## 8. API Endpoint'leri (AJAX için)

### 8.1 Video Analiz Başlatma
```
POST /api/video-analyze.php
Body: {
    "video_id": "dQw4w9WgXcQ",
    "analysis_type": "detailed",
    "options": {
        "include_transcript": true,
        "similar_count": 10,
        "transcript_lang": "tr"
    }
}
Response: {
    "success": true,
    "analysis_id": 123,
    "steps": ["info", "metrics", "tags", "thumbnails", "transcript", "similar", "ai"]
}
```

### 8.2 Adım Durumu Sorgulama
```
GET /api/video-analyze-status.php?analysis_id=123
Response: {
    "success": true,
    "current_step": "transcript",
    "completed_steps": ["info", "metrics", "tags", "thumbnails"],
    "progress": 57,
    "data": { ... }
}
```

### 8.3 Analiz Sonucu Alma
```
GET /api/video-analyze-result.php?analysis_id=123
Response: {
    "success": true,
    "analysis": { ... },
    "cached": false,
    "created_at": "2025-10-23 14:30:00"
}
```

## 9. Hata Yönetimi

### Olası Hatalar ve Çözümler
- **Video bulunamadı**: Kullanıcıya net hata mesajı
- **Transkript yok**: "Bu video için transkript mevcut değil" uyarısı, devam et
- **API limiti aşıldı**: Fallback API'ye geç veya cache'den dön
- **Timeout**: Uzun süren işlemler için background job
- **Geçersiz video ID**: Format kontrolü ve örnek göster

## 10. Performans Optimizasyonu

### Stratejiler
- Lazy loading (adım adım yükleme)
- Paralel API çağrıları (mümkün olduğunda)
- Agresif cache kullanımı
- Database indexleme
- CDN kullanımı (thumbnail'ler için)
- Pagination (benzer videolar için)

## 11. Güvenlik

### Önlemler
- Video ID sanitization
- Rate limiting (IP bazlı)
- CSRF token kontrolü
- SQL injection koruması (prepared statements)
- XSS koruması (output escaping)
- Kullanıcı yetkilendirmesi
- API key güvenliği

## 12. Test Senaryoları

### Test Edilecek Durumlar
1. Normal video analizi (tüm veriler mevcut)
2. Transkripti olmayan video
3. Çok eski video (düşük metrikler)
4. Viral video (çok yüksek metrikler)
5. Yeni yayınlanan video (az veri)
6. Silinmiş/private video
7. Cache'den dönen analiz
8. Limit aşımı durumu
9. API hatası durumu
10. Eşzamanlı çoklu analiz

## 13. Geliştirme Sırası

### Faz 1: Temel Altyapı (2-3 saat)
1. Database migration oluştur ve çalıştır
2. `VideoAnalysisService.php` sınıfını oluştur
3. `YoutubeService.php`'ye yeni metodlar ekle
4. Temel video bilgisi çekme fonksiyonunu test et

### Faz 2: UI Geliştirme (2-3 saat)
1. `video-analyze.php` sayfasını oluştur
2. Form ve input validasyonu
3. Progress bar ve adım göstergesi
4. Sonuç kartları tasarımı
5. Navbar'a link ekle

### Faz 3: Transkript ve Benzer Videolar (2-3 saat)
1. Transkript API entegrasyonu
2. Benzer video arama algoritması
3. Cache sistemi implementasyonu
4. Sonuçları gösterme

### Faz 4: AI Analiz Entegrasyonu (2-3 saat)
1. AI prompt'larını hazırla
2. Her analiz türü için fonksiyon yaz
3. Sonuçları formatlama
4. Kaydetme fonksiyonları

### Faz 5: Test ve İyileştirme (1-2 saat)
1. Tüm senaryoları test et
2. Hata yönetimini iyileştir
3. Performans optimizasyonu
4. UI/UX iyileştirmeleri

## 14. Dosya Yapısı

```
youtube-analytics-tool/
├── video-analyze.php (YENİ)
├── services/
│   └── VideoAnalysisService.php (YENİ)
├── api/ (YENİ)
│   ├── video-analyze.php
│   ├── video-analyze-status.php
│   └── video-analyze-result.php
├── database/
│   └── migrations/
│       └── 006_video_analysis.sql (YENİ)
├── includes/
│   └── navbar.php (GÜNCELLEME)
├── lib/
│   └── YoutubeService.php (GÜNCELLEME)
├── output/
│   └── video_analyses/ (YENİ)
│       └── [video_id]/
│           ├── analysis_[timestamp].md
│           └── analysis_[timestamp].json
└── assets/
    ├── js/
    │   └── video-analyze.js (YENİ)
    └── css/
        └── video-analyze.css (YENİ - opsiyonel)
```

## 15. Örnek Kullanım Akışı

```
1. Kullanıcı video-analyze.php sayfasına gider
2. Video URL'sini yapıştırır: https://youtube.com/watch?v=dQw4w9WgXcQ
3. "Detaylı Analiz" seçeneğini işaretler
4. "Analiz Et" butonuna tıklar

5. Sayfa AJAX ile analizi başlatır
6. Progress bar gösterilir: 0%

7. Adım 1: Video bilgileri çekilir (✓) - 15%
8. Adım 2: Metrikler çekilir (✓) - 30%
9. Adım 3: Etiketler çekilir (✓) - 45%
10. Adım 4: Thumbnail'ler çekilir (✓) - 60%
11. Adım 5: Transkript çekilir (✓) - 75%
12. Adım 6: Benzer videolar bulunur (✓) - 85%
13. Adım 7: AI analizi yapılır (✓) - 100%

14. Tüm sonuçlar kartlar halinde gösterilir
15. Kullanıcı "Kaydet" butonuna tıklar
16. Analiz MD ve JSON olarak kaydedilir
17. Başarı mesajı gösterilir
```

## 16. Gelecek İyileştirmeler

- 📊 Grafik ve görselleştirmeler (Chart.js)
- 🔔 Analiz tamamlandığında bildirim
- 📧 Email ile rapor gönderme
- 🤖 Otomatik periyodik analiz (takip edilen videolar)
- 🏆 Video performans skoru (0-100)
- 📱 Mobil uygulama API'si
- 🌐 Çoklu dil desteği
- 🎨 Özelleştirilebilir rapor şablonları
- 🔄 Video karşılaştırma timeline'ı
- 📈 Trend analizi (zaman içinde performans)

---

**Tahmini Toplam Geliştirme Süresi:** 10-15 saat
**Zorluk Seviyesi:** Orta-İleri
**Öncelik:** Yüksek
**Bağımlılıklar:** RapidAPI (YT API), AI Provider, Mevcut altyapı
