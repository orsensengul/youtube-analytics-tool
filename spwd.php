<?php
/**
 * Password Hash Generator
 * Bu dosyayı kullanarak şifreleri hash'leyip veritabanına yazabilirsiniz
 */

// Form gönderildiğinde
$hashedPassword = '';
$plainPassword = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $plainPassword = $_POST['password'];
    $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);
}

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Şifre Hash Oluşturucu</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
    <div class="min-h-screen flex items-center justify-center py-12 px-4">
        <div class="max-w-2xl w-full space-y-8">
            <div>
                <h2 class="text-center text-3xl font-extrabold text-gray-900">
                    🔐 Şifre Hash Oluşturucu
                </h2>
                <p class="mt-2 text-center text-sm text-gray-600">
                    Veritabanına kaydetmek için şifrenizi hash'leyin
                </p>
            </div>

            <div class="bg-white shadow-md rounded-lg p-6">
                <form method="POST" class="space-y-4">
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
                            Şifrenizi Girin:
                        </label>
                        <input
                            type="text"
                            id="password"
                            name="password"
                            required
                            autofocus
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            placeholder="Örn: admin123"
                            value="<?= e($plainPassword) ?>"
                        >
                    </div>

                    <button
                        type="submit"
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg transition duration-200"
                    >
                        Şifrele
                    </button>
                </form>

                <?php if ($hashedPassword): ?>
                    <div class="mt-6 space-y-4">
                        <div class="border-t pt-4">
                            <h3 class="text-lg font-semibold text-gray-900 mb-2">
                                ✅ Hash Oluşturuldu
                            </h3>

                            <div class="bg-gray-50 p-4 rounded-lg">
                                <p class="text-xs text-gray-600 mb-1">Orijinal Şifre:</p>
                                <p class="font-mono text-sm text-gray-900 mb-3 break-all">
                                    <?= e($plainPassword) ?>
                                </p>

                                <p class="text-xs text-gray-600 mb-1">Hash'lenmiş Şifre:</p>
                                <p class="font-mono text-sm text-blue-600 break-all">
                                    <?= e($hashedPassword) ?>
                                </p>
                            </div>
                        </div>

                        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                            <h4 class="font-semibold text-yellow-900 mb-2">📋 Veritabanına Kaydetme:</h4>
                            <p class="text-sm text-yellow-800 mb-3">
                                Aşağıdaki SQL komutunu phpMyAdmin'de çalıştırın:
                            </p>
                            <div class="bg-gray-900 text-green-400 p-3 rounded font-mono text-xs overflow-x-auto">
                                <code>UPDATE users SET password_hash = '<?= e($hashedPassword) ?>' WHERE username = 'admin';</code>
                            </div>
                        </div>

                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                            <h4 class="font-semibold text-blue-900 mb-2">💡 Alternatif: PHP ile Kaydetme</h4>
                            <p class="text-sm text-blue-800 mb-3">
                                Veya bu PHP kodunu çalıştırabilirsiniz:
                            </p>
                            <div class="bg-gray-900 text-green-400 p-3 rounded font-mono text-xs overflow-x-auto">
                                <code><?= e("<?php\n\$config = require 'config.php';\nrequire_once 'lib/Database.php';\nDatabase::init(\$config['database']);\nDatabase::update('users', ['password_hash' => '{$hashedPassword}'], 'username = :u', [':u' => 'admin']);\necho 'Şifre güncellendi!';\n?>") ?></code>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="bg-white shadow-md rounded-lg p-6">
                <h3 class="font-semibold text-gray-900 mb-3">ℹ️ Bilgi</h3>
                <ul class="text-sm text-gray-700 space-y-2">
                    <li>• Bu araç <code class="bg-gray-100 px-1 rounded">password_hash()</code> fonksiyonunu kullanır</li>
                    <li>• Her hash işleminde farklı bir sonuç üretilir (bu normaldir)</li>
                    <li>• Giriş yaparken <code class="bg-gray-100 px-1 rounded">password_verify()</code> ile doğrulanır</li>
                    <li>• Hash'lenmiş şifreyi <code class="bg-gray-100 px-1 rounded">password_hash</code> sütununa kaydedin</li>
                </ul>
            </div>

            <div class="text-center">
                <a href="login.php" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                    ← Giriş Sayfasına Dön
                </a>
            </div>
        </div>
    </div>
</body>
</html>
