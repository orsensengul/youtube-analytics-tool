# YouTube Arama + Etiketler (RapidAPI, XAMPP, PHP)

YouTube'da arama yapıp, çıkan videoların etiketlerini (tags) listeler. XAMPP üzerinde çalışan basit bir PHP uygulamasıdır ve RapidAPI üzerinden ytjar'in “YT API” (yt-api) uç noktalarını kullanır.

## Plan
- [x] Config ve HTTP istemcisi oluşturma
- [x] YouTube servis mantığını ekleme
- [x] `index.php` arama arayüzünü kurma
- [x] Minimal stiller ekleme
- [x] Kurulum ve kullanım bilgisini yazma
- [x] Etiket toplayıcı (virgülle biriktirme)
- [x] Önbellek (dosya bazlı) ve arama geçmişi
- [x] Sıralama (izlenme/like/tarih) — istemci tarafında

## Özellikler
- Arama formu ile video arama (bölge kodu destekli)
- Sonuç kartlarında görsel, başlık, kanal, açıklama ve metrikler (izlenme/like)
- Her video için etiketleri toplu istekle çekme ve listeleme
- Etiketlere tıklayınca üstte virgüllerle biriktirme, kopyalama/temizleme
- Sıralama (izlenme/like/tarih) — sayfada yeniden sorgu yapmadan (JS ile)
- Önbellek ve arama geçmişi (JSONL)

## Gereksinimler
- XAMPP (PHP 7.4+ önerilir) ve cURL eklentisi etkin olmalı
- RapidAPI hesabı ve ytjar “YT API” (yt-api) için API anahtarı

## Kurulum
1. Bu klasörü XAMPP `htdocs` içine yerleştirin (örnek: `htdocs/ymt`).
2. RapidAPI anahtarınızı tanımlayın:
   - `config.php` içinde `rapidapi_key` değerini kendi anahtarınızla değiştirin, veya
   - Sunucu ortamına `RAPIDAPI_KEY` ortam değişkeni verin (varsa dosyadaki değerin yerine geçer).
3. Tarayıcıda `http://localhost/ymt` adresine gidin ve arama yapın.

İsteğe bağlı:
- `config.php` içindeki `region_code` ile bölge kodunu değiştirin (varsayılan `TR`).
- `results_per_page` ile sayfa başına sonuç sayısını ayarlayın.

## Konfigürasyon
`config.php` değerleri:
- `rapidapi_key`: RapidAPI anahtarınız. Varsayılan: `YOUR_RAPIDAPI_KEY` (değiştirin).
- `rapidapi_host`: Varsayılan `yt-api.p.rapidapi.com` (ytjar / YT API).
- `results_per_page`: Varsayılan `10`.
- `region_code`: Varsayılan `TR`.
- `cache_ttl_seconds`: Önbellek süresi (sn). Örn: `21600` = 6 saat.

Ayrıca şu ortam değişkenleri desteklenir: `RAPIDAPI_KEY`, `RAPIDAPI_HOST`.

## Nasıl Çalışır
- Arama: `GET https://yt-api.p.rapidapi.com/search` (parametreler: `query`, `type=video`, opsiyonel `geo`, `lang`).
- Etiketler: Her video için `GET https://yt-api.p.rapidapi.com/video/info?id=VIDEO_ID` çağrılır ve dönen yanıttaki `tags` veya `keywords` alanları etiket olarak kullanılır.

## Dosya Yapısı
- `index.php` — Arama formu ve sonuçların listelenmesi (etiketler dahil)
- `config.php` — API anahtarı/host ve varsayılan ayarlar
- `lib/RapidApiClient.php` — RapidAPI HTTP istemcisi (cURL)
- `services/YoutubeService.php` — Arama ve video detayları servis katmanı
- `assets/styles.css` — Basit stiller (koyu tema)
- `lib/Cache.php` — Dosya bazlı JSON cache
- `lib/History.php` — Arama geçmişini `storage/history.jsonl` olarak tutar
- `storage/cache` — Önbellek dosyalarının yazıldığı klasör

## Notlar / Sorun Giderme
- cURL kapalıysa `php.ini` içinde etkinleştirin (XAMPP ile genelde açık gelir).
- Bazı videolar etiket döndürmeyebilir; bu normaldir.
- RapidAPI kota veya yetkilendirme hatalarında üst kısımda hata mesajı görünür.

## Geliştirme Fikirleri
- Sayfalama (nextPageToken) ve filtreler (tarih/süre/sıralama)
- Kanal/istatistik bilgilerini daha zengin gösterme
- Basit bir dosya tabanlı cache ile hız/kota optimizasyonu
