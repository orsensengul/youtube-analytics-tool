# Tekli Video Analizi — Tasarım ve Uygulama Planı

Bu belge, tek bir YouTube videosu (ID/URL) için detayların alınması, thumbnail indirme, transkript çıkarma ve AI ile analiz üretme akışını açıklar. RapidAPI “YT API” kullanımı, limit ve log mantıkları bütünleşiktir.

## Amaç
- Tek video için: başlık, açıklama, etiketler, metrikler, yayın tarihi, kanal adı gibi bilgilerle özet görünüm.
- Thumbnail dosyasını yerel olarak indirmek ve saklamak (ileride görsel/thumbnail metni analizi için).
- Transkripti sağlayıp JSON/TXT olarak saklamak (metin-temelli analizler için girdi).
- AI ile analiz (başlık/etiket/açıklama/SEO/thumbnail metni), sonuçları dosya + veritabanına kaydetmek.

## Kullanıcı Akışı
1. Giriş: `video.php?id=<VIDEO_ID>` veya `video.php?url=<YOUTUBE_URL>`
2. Video özeti görüntülenir (thumbnail, başlık, kanal, metrikler, yayın tarihi, etiketler).
3. Aksiyonlar:
   - "Thumbnail’ı indir" (indirildi bilgisi ve dosya yolu)
   - "Transkripti al" (dil seçimi, JSON/TXT indirme linkleri)
   - "AI Analiz": Tür seçimi veya özel istek (chat)
4. Sonuçlar kaydedilebilir ve indirilebilir.

## Girdi ve ID Çıkarma
- Yardımcı: `parseVideoId(string $s): ?string`
  - URL desenleri: `watch?v=`, `youtu.be/`, `embed/`, `shorts/`, parametreli/kısa URL’ler.
  - 11 karakterlik ID doğrulaması (A–Z, a–z, 0–9, -, _).

## Veri Toplama ve Cache
- Servis: `services/YoutubeService.php`
  - Detay: `videosDetails([$id])` (istatistik + etiketler + yayın tarihi; `raw` üzerinden başlık/açıklama türetme)
- Cache: `lib/Cache.php`
  - Anahtar: `video:<host>:<videoId>`
  - TTL: `config.php['cache_ttl_seconds']`
- Debug: `?debug=1` ile ham JSON gösterimi

## Thumbnail İndirme
- Kaynak önceliği:
  1) `details.raw`/`thumbnails` alanları (varsa)
  2) Standart YT URL’leri: `i.ytimg.com/vi/<ID>/{maxresdefault,sddefault,hqdefault,mqdefault}.jpg`
- Kayıt yolları:
  - `output/video_<VIDEOID>/thumb_original.jpg`
- Yardımcı: `AssetDownloader::download(url, destPath)` (cURL + hata kontrolü)
- Limit + log:
  - `UserManager::checkDataQueryLimit()` → `incrementDataQueryCount()` → `logActivity(user, 'fetch_thumbnail', {videoId})`

## Transkript Alma
- Sağlayıcılar (RapidAPI “YT API” üstünden; uç noktaları dokümana göre doğrulayın):
  - Öncelik: Transcript/Captions endpoint (dil: `tr` → `en` → otomatik)
  - Doküman: https://rapidapi.com/ytjar/api/yt-api
- Çıktı biçimi:
  - JSON (segmentler: `start`, `dur`, `text`, `lang`) → `transcript.<lang>.json`
  - Düz metin (birleştirilmiş) → `transcript.<lang>.txt`
- Kayıt yolları:
  - `output/video_<VIDEOID>/transcript.<lang>.json`
  - `output/video_<VIDEOID>/transcript.<lang>.txt`
- UI:
  - "Transkripti Al" butonu, dil seçimi, indirme linkleri, metin arama kutusu
- Limit + log:
  - `UserManager::checkDataQueryLimit()` → `incrementDataQueryCount()` → `logActivity(user, 'fetch_transcript', {videoId, lang})`

## AI Analizleri (Tek Video)
- Sağlayıcı: `lib/AIProvider.php` (priority + fallback)
- Veri bağlamı:
  - Başlık, açıklama (gerekirse 1–2K karaktere kısalt), mevcut etiketler, izlenme/like, yayın tarihi, kanal adı
  - (Opsiyonel) Transkript özeti (çok uzunsa bölünmüş özetleme sonraki sürüm)
- Türler (ilk sürüm):
  - Başlık iyileştirme (CTR odaklı 10–20 öneri)
  - Açıklama iyileştirme (2–3 şablon)
  - Etiket önerileri (TR odaklı 15–25)
  - SEO özeti (güçlü/zayıf yönler, hızlı kazanımlar)
  - Thumbnail metni/hook önerileri
- Prompt kalıbı:
  - Sistem: "YouTube video analiz uzmansın. Türkçe, maddeli, net."
  - Kullanıcı: Video verisi + görev açıklaması (türe göre)
- Limit + log:
  - `UserManager::checkAnalysisQueryLimit()` → `incrementAnalysisQueryCount()` → `logActivity(user, 'video_analysis', {type, videoId})`
- Kayıt:
  - Dosya: `output/video_<VIDEOID>/analysis_<TS>_<TYPE>.md`
  - DB: `analysis_results` kaydı (`mode='video'`, `query=<videoId>`)

## Kalıcılık (Files + DB)
- Dosyalar:
  - `output/video_<VIDEOID>/thumb_original.jpg`
  - `output/video_<VIDEOID>/transcript.tr.json|txt` (veya `en`)
  - `output/video_<VIDEOID>/analysis_<TS>_<TYPE>.md`
- Veritabanı:
  - `analysis_results`:
    - `user_id`, `analysis_type`, `mode='video'`, `query=<videoId>`, `input_data` (tek video özeti), `prompt`, `ai_provider`, `ai_model`, `result`, `is_saved`, `file_path`
  - (Opsiyonel) `video_transcripts` tablosu:
    - `id`, `video_id`, `lang`, `provider`, `segments` (JSON), `text` (LONGTEXT), `created_at`, `updated_at`
- Migration önerisi:
  - `analysis_results.mode` → `ENUM('search','channel','video')`

## RapidAPI “YT API” Entegrasyonu
- Host: `config.php['rapidapi_host']` (varsayılan: `yt-api.p.rapidapi.com`)
- Header’lar: `X-RapidAPI-Key`, `X-RapidAPI-Host`
- Uç noktalar (adlar/parametreler dokümana göre teyit edilecek):
  - Video detayları: `GET https://<host>/video/info?id=<VIDEO_ID>`
  - Transkript: transcript/captions endpoint (dil parametresi: `lang` veya dokümana göre)
  - Arama (gerekirse): `GET /search`
- Kota/Rate-limit: Anlaşılır hata mesajı + `user_activity_log` ile aksiyon logu

## UI/UX
- Üst: Video özeti (thumbnail ön izleme, başlık, kanal, metrikler, yayın tarihi, etiketler)
- Aksiyonlar: Thumbnail indir, Transkript al (durum göstergesi), AI analiz türleri
- Sonuç paneli: Kopyala/indir/kaydet
- Özel istek (chat): Video bazlı geçmiş (session: `chat_video_<id>`)

## Güvenlik
- `Auth::requireLogin()`
- (İlk sürümde hafif) CSRF koruması
- Dosya yollarında traversal koruması

## Hata Yönetimi
- Thumbnail yoksa fallback varyantları dene; hepsi başarısızsa kullanıcıya mesaj + log
- Transkript yoksa anlaşılır mesaj + log
- AI hatasında fallback ve son hata mesajını düzgün göster

## Limit ve Log
- Thumbnail/Transcript → data query limiti
- AI → analysis query limiti
- `UserManager::logActivity()` ile aksiyon bazlı kayıt

## Konfigürasyon
- `config.php`: `rapidapi_key`, `rapidapi_host`, `cache_ttl_seconds`, AI provider listesi ve timeoutları

## Entegrasyon Noktaları
- Yeni sayfa: `video.php`
- Kartlara link: `index.php` sonuç kartlarına “Analiz”
- (Opsiyonel) Navbar’da “Tekli Video” linki (Analiz altında)

## Uygulama Adımları
1) Şema kararı: `analysis_results.mode='video'` (+ opsiyonel `video_transcripts`)
2) `video.php` iskelet: Auth, ID parse, veri çekme, cache, UI
3) Thumbnail indirme helper + UI
4) Transcript alma (RapidAPI) + UI
5) AI analizleri + kaydetme
6) Kartlara “Analiz” linki, log/limit entegrasyonu
7) Testler ve rötuşlar

## Test Kontrol Listesi
- Geçerli ID/URL ile sayfa yüklenmesi, detayların görünmesi
- Thumbnail indirildi mi? Dosya var mı?
- Transkript geldi mi? JSON/TXT indirilebilir mi?
- AI analizleri çalışıyor mu? Limit dolunca engelliyor mu?
- Kaydetme: dosya + DB kaydı
- Geri butonu çalışıyor mu?
- Hata/sınır durumları (rate-limit, key eksik, dil yok) net mi?

> Not: Transcript/captions endpoint ve parametreleri için RapidAPI dokümanını (https://rapidapi.com/ytjar/api/yt-api) mutlaka teyit edin. Bu planda isimler örnek niteliğindedir; gerçek sözleşme dokümana göre bağlanacaktır.

