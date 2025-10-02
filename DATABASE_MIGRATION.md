# YouTube Marketing Tool - Database Migration Guide

## 🚀 Yeni Özellikler

### ✅ Tamamlanan İyileştirmeler
- ✅ MySQL veritabanı entegrasyonu
- ✅ Kullanıcı sistemi (kayıt, giriş, çıkış)
- ✅ Session yönetimi
- ✅ Database tabanlı cache sistemi
- ✅ API key şifreleme
- ✅ Arama geçmişi (database)
- ✅ Analiz sonuçları saklama

## 📋 Kurulum Adımları

### 1. Veritabanı Kurulumu

XAMPP MySQL'i başlatın ve şu adımları izleyin:

#### A. Otomatik Kurulum (Önerilen)
1. Tarayıcınızda şu adresi açın: `http://localhost/ymt-lokal/database/setup.php`
2. Sayfa otomatik olarak veritabanını ve tabloları oluşturacak
3. Başarı mesajını gördükten sonra kurulum tamamdır

#### B. Manuel Kurulum
```bash
# MySQL'e bağlan
mysql -u root -p

# SQL dosyasını çalıştır
source /Applications/XAMPP/xamppfiles/htdocs/ymt-lokal/database/schema.sql
```

### 2. Konfigürasyon

`config.php` dosyasını düzenleyin:

```php
'database' => [
    'host' => 'localhost',
    'port' => 3306,
    'database' => 'ymt_lokal',  // Veritabanı adı
    'username' => 'root',        // MySQL kullanıcı adı
    'password' => '',            // MySQL şifre (XAMPP'de genelde boş)
    'charset' => 'utf8mb4',
],
```

### 3. İlk Giriş

**Varsayılan Admin Hesabı:**
- Kullanıcı: `admin`
- Şifre: `admin123`

⚠️ **Güvenlik:** İlk girişten sonra mutlaka şifreyi değiştirin!

## 🗃️ Veritabanı Şeması

### Tablolar

1. **users** - Kullanıcı hesapları
2. **api_cache** - API yanıtları cache
3. **search_history** - Arama geçmişi
4. **analysis_results** - AI analiz sonuçları
5. **video_metadata** - Video bilgileri
6. **channel_metadata** - Kanal bilgileri
7. **user_sessions** - Aktif oturumlar
8. **user_favorites** - Favoriler
9. **system_logs** - Sistem logları
10. **api_usage_stats** - API kullanım istatistikleri

### Özellikler

- ✅ Otomatik cache temizleme (expired entries)
- ✅ Cache hit counter (en popüler sorgular)
- ✅ User-specific ve shared cache
- ✅ API key encryption
- ✅ Session management
- ✅ Comprehensive logging

## 🔄 Eski Sistemden Geçiş

### Cache Sistemi

**Eski (Dosya Bazlı):**
```php
$cache = new Cache(__DIR__ . '/storage/cache');
$data = $cache->get($key, $ttl);
$cache->set($key, $data);
```

**Yeni (MySQL Bazlı):**
```php
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/CacheDB.php';

Database::init($config['database']);
$cache = new CacheDB($userId, 'yt-api');
$data = $cache->get('search', ['query' => 'test'], $ttl);
$cache->set('search', ['query' => 'test'], $data, $ttl);
```

### History Sistemi

**Eski (JSONL Bazlı):**
```php
$history = new History(__DIR__ . '/storage');
$history->addSearch($query, $metadata);
```

**Yeni (MySQL Bazlı):**
```php
// Otomatik olarak database'e kaydedilir
// History.php sınıfı güncellendi
```

## 🔐 Güvenlik

### API Key Yönetimi

Kullanıcılar kendi API key'lerini hesaplarında saklayabilir:

```php
Auth::updateApiKeys($userId, $rapidApiKey, $aiApiKey);
$keys = Auth::getApiKeys($userId);
```

### Session Güvenliği

- Session token'ları database'de saklanır
- IP ve User-Agent tracking
- Otomatik session cleanup (expired sessions)
- HttpOnly ve SameSite cookie flags

## 📊 Cache İstatistikleri

Cache performansını görmek için:

```php
$stats = CacheDB::getStats();
// Returns:
// - total_entries
// - expired_entries
// - by_type (cache breakdown)
// - top_hits (most accessed cache entries)
```

## 🧹 Bakım

### Cache Temizleme

```php
// Expired cache temizle
CacheDB::cleanupExpired();

// Kullanıcı cache'ini temizle
CacheDB::clearUserCache($userId);

// Tüm cache'i temizle
CacheDB::clearAll();
```

### Session Temizleme

```php
// Expired sessions temizle
Auth::cleanupExpiredSessions();
```

### Otomatik Temizlik (Cron Job)

`cron.php` dosyası oluşturup şunu ekleyin:

```php
<?php
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/CacheDB.php';
require_once __DIR__ . '/lib/Auth.php';

$config = require __DIR__ . '/config.php';
Database::init($config['database']);

// Cleanup
$clearedCache = CacheDB::cleanupExpired();
$clearedSessions = Auth::cleanupExpiredSessions();

echo "Cleaned {$clearedCache} cache entries\n";
echo "Cleaned {$clearedSessions} sessions\n";
```

Crontab'a ekle:
```bash
0 */6 * * * php /Applications/XAMPP/xamppfiles/htdocs/ymt-lokal/cron.php
```

## 🐛 Sorun Giderme

### Veritabanı Bağlantı Hatası

```
Database connection failed: Access denied
```

**Çözüm:** `config.php` içinde MySQL kullanıcı adı ve şifresini kontrol edin.

### Tablo Bulunamadı

```
Table 'ymt_lokal.users' doesn't exist
```

**Çözüm:** Database setup scriptini çalıştırın: `http://localhost/ymt-lokal/database/setup.php`

### Cache Çalışmıyor

```
Call to undefined method CacheDB::get()
```

**Çözüm:** `Database::init()` çağrısının yapıldığından emin olun.

## 📝 TODO

- [ ] Password reset fonksiyonu
- [ ] Email verification
- [ ] Two-factor authentication
- [ ] Admin panel (user management)
- [ ] API rate limiting per user
- [ ] Backup/restore tools
- [ ] Migration script (file cache → DB cache)

## 🔗 Linkler

- [Analiz Fikirleri](analyze-ideas.md)
- [Database Schema](database/schema.sql)
- [Setup Script](database/setup.php)

## 📞 Destek

Sorun yaşarsanız:
1. XAMPP MySQL ve Apache'nin çalıştığından emin olun
2. `database/setup.php` scriptini yeniden çalıştırın
3. Browser console'da hata mesajlarını kontrol edin
4. PHP error log'larına bakın

---

**Versiyon:** 2.0.0
**Son Güncelleme:** 2025-10-02
