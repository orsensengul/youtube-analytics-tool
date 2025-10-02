<?php
/**
 * Database Setup Script
 * Run this once to create the database and tables
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$config = require __DIR__ . '/../config.php';

$host = $config['database']['host'];
$port = $config['database']['port'];
$dbname = $config['database']['database'];
$username = $config['database']['username'];
$password = $config['database']['password'];
$charset = $config['database']['charset'];

echo "<h1>YMT-Lokal Database Setup</h1>";
echo "<pre>";

try {
    // Connect without database to create it
    $dsn = "mysql:host={$host};port={$port};charset={$charset}";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    echo "✓ MySQL bağlantısı başarılı\n\n";

    // Create database if not exists
    echo "Veritabanı oluşturuluyor: {$dbname}\n";
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbname}` CHARACTER SET {$charset} COLLATE {$charset}_unicode_ci");
    echo "✓ Veritabanı hazır\n\n";

    // Connect to the database
    $pdo->exec("USE `{$dbname}`");

    // Read and execute schema file
    $schemaFile = __DIR__ . '/schema.sql';
    if (!file_exists($schemaFile)) {
        throw new Exception("Schema dosyası bulunamadı: {$schemaFile}");
    }

    echo "Schema dosyası okunuyor...\n";
    $schema = file_get_contents($schemaFile);

    // Split by semicolon and execute each statement
    $statements = array_filter(
        array_map('trim', explode(';', $schema)),
        fn($stmt) => !empty($stmt) && !preg_match('/^\s*--/', $stmt)
    );

    echo "Tablolar oluşturuluyor...\n";
    $successCount = 0;
    foreach ($statements as $statement) {
        if (empty(trim($statement))) continue;

        try {
            $pdo->exec($statement);
            $successCount++;

            // Extract table name for better output
            if (preg_match('/CREATE TABLE.*?`(\w+)`/i', $statement, $matches)) {
                echo "  ✓ Tablo oluşturuldu: {$matches[1]}\n";
            }
        } catch (PDOException $e) {
            // Ignore "table already exists" errors
            if ($e->getCode() != '42S01') {
                echo "  ✗ Hata: " . $e->getMessage() . "\n";
            }
        }
    }

    echo "\n✓ Toplam {$successCount} sorgu başarıyla çalıştırıldı\n\n";

    // Verify tables
    echo "Oluşturulan tablolar:\n";
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $count = $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        echo "  • {$table} ({$count} kayıt)\n";
    }

    echo "\n✓ Veritabanı kurulumu tamamlandı!\n";
    echo "\nVarsayılan admin hesabı:\n";
    echo "  Kullanıcı: admin\n";
    echo "  Şifre: admin123\n";
    echo "  (İlk girişten sonra şifreyi değiştirin!)\n";

} catch (PDOException $e) {
    echo "\n✗ HATA: " . $e->getMessage() . "\n";
    echo "Detay: " . $e->getTraceAsString() . "\n";
} catch (Exception $e) {
    echo "\n✗ HATA: " . $e->getMessage() . "\n";
}

echo "</pre>";
echo "<p><a href='../index.php'>Ana sayfaya dön</a> | <a href='../login.php'>Giriş yap</a></p>";
