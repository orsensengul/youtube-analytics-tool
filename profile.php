<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/UserManager.php';

Database::init($config['database']);
Auth::startSession($config['session']);
Auth::requireLogin();

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$error = '';
$success = '';
$userId = Auth::userId();
$user = Auth::user();

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $error = 'Tüm şifre alanlarını doldurun.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Yeni şifreler eşleşmiyor.';
    } elseif (strlen($newPassword) < 6) {
        $error = 'Yeni şifre en az 6 karakter olmalıdır.';
    } elseif (!password_verify($currentPassword, $user['password_hash'])) {
        $error = 'Mevcut şifre hatalı.';
    } else {
        if (UserManager::updateUser($userId, ['password' => $newPassword])) {
            UserManager::logActivity($userId, 'password_change', ['changed_by' => 'self']);
            $success = 'Şifreniz başarıyla değiştirildi.';
        } else {
            $error = 'Şifre değiştirilemedi.';
        }
    }
}

$stats = UserManager::getUserStats($userId);
$licenseInfo = UserManager::getLicenseInfo($userId);
$recentActivity = UserManager::getUserActivity($userId, ['limit' => 10]);

?><!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Profilim</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body class="bg-gray-50">
<div class="container mx-auto max-w-5xl px-4 py-6">
    <?php include __DIR__ . '/includes/navbar.php'; ?>

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">👤 Profilim</h1>
        <p class="text-sm text-gray-600 mt-1">Hesap bilgilerinizi görüntüleyin ve şifrenizi değiştirin</p>
    </div>

    <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4">
            <?= e($success) ?>
        </div>
    <?php endif; ?>

    <!-- License Warning -->
    <?php if ($licenseInfo['status'] === 'expired'): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">
            ⚠️ Lisansınızın süresi dolmuş! Lütfen yönetici ile iletişime geçin.
        </div>
    <?php elseif ($licenseInfo['status'] === 'expiring_soon'): ?>
        <div class="bg-orange-50 border border-orange-200 text-orange-700 px-4 py-3 rounded-lg mb-4">
            ⚠️ Lisansınızın süresi <?= $licenseInfo['days_remaining'] ?> gün içinde dolacak!
        </div>
    <?php endif; ?>

    <!-- Data Query Limit Warning -->
    <?php if ($stats['data_remaining_daily'] !== -1 && $stats['data_remaining_daily'] < 10): ?>
        <div class="bg-yellow-50 border border-yellow-200 text-yellow-700 px-4 py-3 rounded-lg mb-4">
            ⚠️ Günlük veri sorgu limitinizin %<?= round((($stats['data_query_limit_daily'] - $stats['data_remaining_daily']) / $stats['data_query_limit_daily']) * 100) ?>'sine ulaştınız. Kalan: <?= $stats['data_remaining_daily'] ?>
        </div>
    <?php endif; ?>

    <!-- Analysis Query Limit Warning -->
    <?php if ($stats['analysis_remaining_daily'] !== -1 && $stats['analysis_remaining_daily'] < 10): ?>
        <div class="bg-yellow-50 border border-yellow-200 text-yellow-700 px-4 py-3 rounded-lg mb-4">
            ⚠️ Günlük analiz sorgu limitinizin %<?= round((($stats['analysis_query_limit_daily'] - $stats['analysis_remaining_daily']) / $stats['analysis_query_limit_daily']) * 100) ?>'sine ulaştınız. Kalan: <?= $stats['analysis_remaining_daily'] ?>
        </div>
    <?php endif; ?>

    <div class="space-y-6">
        <!-- Profile Info -->
        <div class="bg-white border border-gray-200 rounded-xl p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4">Profil Bilgileri</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-1">Kullanıcı Adı</label>
                    <div class="text-gray-900 font-medium"><?= e($user['username']) ?></div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-1">E-posta</label>
                    <div class="text-gray-900 font-medium"><?= e($user['email']) ?></div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-1">Tam Ad</label>
                    <div class="text-gray-900 font-medium"><?= e($user['full_name'] ?: '-') ?></div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-1">Rol</label>
                    <div>
                        <span class="inline-block px-2 py-1 rounded-full text-xs font-medium <?= $user['role'] === 'admin' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700' ?>">
                            <?= e(ucfirst($user['role'])) ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Change Password -->
        <div class="bg-white border border-gray-200 rounded-xl p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4">Şifre Değiştir</h2>
            <form method="post" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Mevcut Şifre</label>
                    <input type="password" name="current_password" required class="w-full px-3 py-2 rounded-md border border-gray-300">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Yeni Şifre</label>
                    <input type="password" name="new_password" required minlength="6" class="w-full px-3 py-2 rounded-md border border-gray-300">
                    <p class="text-xs text-gray-500 mt-1">En az 6 karakter</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Yeni Şifre (Tekrar)</label>
                    <input type="password" name="confirm_password" required minlength="6" class="w-full px-3 py-2 rounded-md border border-gray-300">
                </div>
                <button type="submit" name="change_password" class="px-6 py-2 rounded-md border border-indigo-300 bg-indigo-600 text-white hover:bg-indigo-500">
                    Şifreyi Değiştir
                </button>
            </form>
        </div>

        <!-- Usage Statistics -->
        <div class="bg-white border border-gray-200 rounded-xl p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4">Kullanım İstatistikleri</h2>

            <!-- Data Queries Section -->
            <div class="mb-6">
                <h3 class="text-md font-medium text-gray-800 mb-3">📊 Veri Sorguları (Arama & Kanal)</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="text-center p-4 bg-blue-50 rounded-lg">
                        <div class="text-3xl font-bold text-blue-900"><?= $stats['data_queries_today'] ?></div>
                        <div class="text-xs text-blue-700 mt-1">Bugünkü Sorgu</div>
                        <?php if ($stats['data_query_limit_daily'] !== -1): ?>
                            <div class="text-xs text-blue-600 mt-1">/ <?= $stats['data_query_limit_daily'] ?> limit</div>
                        <?php endif; ?>
                    </div>
                    <div class="text-center p-4 bg-green-50 rounded-lg">
                        <div class="text-3xl font-bold text-green-900"><?= $stats['data_queries_month'] ?></div>
                        <div class="text-xs text-green-700 mt-1">Bu Ay</div>
                        <?php if ($stats['data_query_limit_monthly'] !== -1): ?>
                            <div class="text-xs text-green-600 mt-1">/ <?= $stats['data_query_limit_monthly'] ?> limit</div>
                        <?php endif; ?>
                    </div>
                    <div class="text-center p-4 bg-purple-50 rounded-lg">
                        <div class="text-3xl font-bold text-purple-900"><?= $stats['data_queries_total'] ?></div>
                        <div class="text-xs text-purple-700 mt-1">Toplam Sorgu</div>
                    </div>
                    <div class="text-center p-4 bg-orange-50 rounded-lg">
                        <div class="text-3xl font-bold text-orange-900">
                            <?= $stats['data_remaining_daily'] === -1 ? '∞' : $stats['data_remaining_daily'] ?>
                        </div>
                        <div class="text-xs text-orange-700 mt-1">Kalan (Günlük)</div>
                    </div>
                </div>
            </div>

            <!-- Analysis Queries Section -->
            <div>
                <h3 class="text-md font-medium text-gray-800 mb-3">🤖 Analiz Sorguları (AI)</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="text-center p-4 bg-indigo-50 rounded-lg">
                        <div class="text-3xl font-bold text-indigo-900"><?= $stats['analysis_queries_today'] ?></div>
                        <div class="text-xs text-indigo-700 mt-1">Bugünkü Analiz</div>
                        <?php if ($stats['analysis_query_limit_daily'] !== -1): ?>
                            <div class="text-xs text-indigo-600 mt-1">/ <?= $stats['analysis_query_limit_daily'] ?> limit</div>
                        <?php endif; ?>
                    </div>
                    <div class="text-center p-4 bg-teal-50 rounded-lg">
                        <div class="text-3xl font-bold text-teal-900"><?= $stats['analysis_queries_month'] ?></div>
                        <div class="text-xs text-teal-700 mt-1">Bu Ay</div>
                        <?php if ($stats['analysis_query_limit_monthly'] !== -1): ?>
                            <div class="text-xs text-teal-600 mt-1">/ <?= $stats['analysis_query_limit_monthly'] ?> limit</div>
                        <?php endif; ?>
                    </div>
                    <div class="text-center p-4 bg-pink-50 rounded-lg">
                        <div class="text-3xl font-bold text-pink-900"><?= $stats['analysis_queries_total'] ?></div>
                        <div class="text-xs text-pink-700 mt-1">Toplam Analiz</div>
                    </div>
                    <div class="text-center p-4 bg-amber-50 rounded-lg">
                        <div class="text-3xl font-bold text-amber-900">
                            <?= $stats['analysis_remaining_daily'] === -1 ? '∞' : $stats['analysis_remaining_daily'] ?>
                        </div>
                        <div class="text-xs text-amber-700 mt-1">Kalan (Günlük)</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- License Info -->
        <div class="bg-white border border-gray-200 rounded-xl p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4">Lisans Bilgileri</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-600 mb-1">Lisans Durumu</label>
                    <div class="font-medium <?= $licenseInfo['status'] === 'expired' ? 'text-red-600' : ($licenseInfo['status'] === 'expiring_soon' ? 'text-orange-600' : 'text-green-600') ?>">
                        <?php
                        if ($licenseInfo['status'] === 'unlimited') {
                            echo '✓ Sınırsız';
                        } elseif ($licenseInfo['status'] === 'expired') {
                            echo '✗ Süresi Dolmuş';
                        } elseif ($licenseInfo['status'] === 'expiring_soon') {
                            echo '⚠ Yakında Dolacak';
                        } else {
                            echo '✓ Aktif';
                        }
                        ?>
                    </div>
                </div>
                <?php if ($licenseInfo['status'] !== 'unlimited'): ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-600 mb-1">Bitiş Tarihi</label>
                        <div class="text-gray-900 font-medium">
                            <?= date('d.m.Y H:i', strtotime($licenseInfo['expires_at'])) ?>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-600 mb-1">Kalan Gün</label>
                        <div class="text-gray-900 font-medium">
                            <?= $licenseInfo['days_remaining'] ?> gün
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="bg-white border border-gray-200 rounded-xl p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4">Son Aktiviteler</h2>
            <?php if (empty($recentActivity)): ?>
                <p class="text-sm text-gray-500">Henüz aktivite kaydı yok.</p>
            <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($recentActivity as $activity): ?>
                        <div class="flex items-center justify-between py-2 border-b border-gray-100 last:border-0">
                            <div class="flex-1">
                                <span class="text-sm font-medium text-gray-900">
                                    <?php
                                    $actionLabels = [
                                        'login' => '🔐 Giriş yapıldı',
                                        'logout' => '🚪 Çıkış yapıldı',
                                        'query_search' => '🔍 Arama yapıldı',
                                        'query_channel' => '📺 Kanal analizi yapıldı',
                                        'analysis_query' => '🤖 AI analizi yapıldı',
                                        'password_change' => '🔑 Şifre değiştirildi',
                                        'user_updated' => '✏️ Profil güncellendi',
                                        'user_created' => '👤 Kullanıcı oluşturuldu',
                                    ];
                                    echo $actionLabels[$activity['action_type']] ?? $activity['action_type'];
                                    ?>
                                </span>
                                <?php if ($activity['details']): ?>
                                    <?php $details = json_decode($activity['details'], true); ?>
                                    <?php if (isset($details['query'])): ?>
                                        <span class="text-xs text-gray-500 ml-2">"<?= e($details['query']) ?>"</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <div class="text-xs text-gray-500">
                                <?= date('d.m.Y H:i', strtotime($activity['created_at'])) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Account Info -->
        <div class="bg-white border border-gray-200 rounded-xl p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4">Hesap Bilgileri</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <div>
                    <span class="text-gray-600">Kayıt Tarihi:</span>
                    <span class="font-medium text-gray-900 ml-2"><?= date('d.m.Y H:i', strtotime($user['created_at'])) ?></span>
                </div>
                <div>
                    <span class="text-gray-600">Son Giriş:</span>
                    <span class="font-medium text-gray-900 ml-2"><?= $user['last_login'] ? date('d.m.Y H:i', strtotime($user['last_login'])) : 'Hiç giriş yapmadı' ?></span>
                </div>
                <div>
                    <span class="text-gray-600">Hesap Yaşı:</span>
                    <span class="font-medium text-gray-900 ml-2"><?= $stats['account_age_days'] ?> gün</span>
                </div>
                <div>
                    <span class="text-gray-600">Hesap Durumu:</span>
                    <span class="font-medium <?= $user['is_active'] ? 'text-green-600' : 'text-red-600' ?> ml-2">
                        <?= $user['is_active'] ? 'Aktif' : 'Pasif' ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
