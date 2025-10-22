<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/UserManager.php';

Database::init($config['database']);
Auth::startSession($config['session']);
Auth::requireLogin();
Auth::requireAdmin();

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$error = '';
$success = '';
$userId = (int)($_GET['id'] ?? 0);

if ($userId === 0) {
    header('Location: users.php');
    exit;
}

$user = UserManager::getUserById($userId);
if (!$user) {
    header('Location: users.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $updateData = [];

    // Basic info
    if (!empty($_POST['username'])) {
        $updateData['username'] = trim($_POST['username']);
    }
    if (!empty($_POST['email'])) {
        $updateData['email'] = trim($_POST['email']);
    }
    if (isset($_POST['full_name'])) {
        $updateData['full_name'] = trim($_POST['full_name']);
    }
    if (!empty($_POST['role'])) {
        $updateData['role'] = $_POST['role'];
    }

    // Limits and license
    if (isset($_POST['data_query_limit_daily'])) {
        $updateData['data_query_limit_daily'] = (int)$_POST['data_query_limit_daily'];
    }
    if (isset($_POST['data_query_limit_monthly'])) {
        $updateData['data_query_limit_monthly'] = (int)$_POST['data_query_limit_monthly'];
    }
    if (isset($_POST['analysis_query_limit_daily'])) {
        $updateData['analysis_query_limit_daily'] = (int)$_POST['analysis_query_limit_daily'];
    }
    if (isset($_POST['analysis_query_limit_monthly'])) {
        $updateData['analysis_query_limit_monthly'] = (int)$_POST['analysis_query_limit_monthly'];
    }
    if (!empty($_POST['license_expires_at'])) {
        $updateData['license_expires_at'] = $_POST['license_expires_at'];
    } elseif (isset($_POST['license_expires_at'])) {
        $updateData['license_expires_at'] = null;
    }

    // Password
    if (!empty($_POST['password'])) {
        $updateData['password'] = $_POST['password'];
    }

    // Status
    if (isset($_POST['is_active'])) {
        $updateData['is_active'] = (int)$_POST['is_active'];
    }

    // Notes
    if (isset($_POST['notes'])) {
        $updateData['notes'] = trim($_POST['notes']);
    }

    if (UserManager::updateUser($userId, $updateData)) {
        $success = 'Kullanıcı başarıyla güncellendi.';
        $user = UserManager::getUserById($userId); // Refresh data
    } else {
        $error = 'Kullanıcı güncellenemedi.';
    }
}

$stats = UserManager::getUserStats($userId);
$licenseInfo = UserManager::getLicenseInfo($userId);

?><!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kullanıcı Düzenle - <?= e($user['username']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../assets/styles.css">
</head>
<body class="bg-gray-50">
<div class="container mx-auto max-w-5xl px-4 py-6">
    <?php include __DIR__ . '/../includes/navbar.php'; ?>

    <div class="mb-6">
        <a href="users.php" class="text-sm text-indigo-600 hover:text-indigo-700">← Kullanıcı Listesine Dön</a>
        <h1 class="text-2xl font-bold text-gray-900 mt-2">Kullanıcı Düzenle: <?= e($user['username']) ?></h1>
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

    <form method="post" class="space-y-6">
        <!-- Basic Info -->
        <div class="bg-white border border-gray-200 rounded-xl p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4">Temel Bilgiler</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Kullanıcı Adı *</label>
                    <input type="text" name="username" value="<?= e($user['username']) ?>" required class="w-full px-3 py-2 rounded-md border border-gray-300">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">E-posta *</label>
                    <input type="email" name="email" value="<?= e($user['email']) ?>" required class="w-full px-3 py-2 rounded-md border border-gray-300">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Tam Ad</label>
                    <input type="text" name="full_name" value="<?= e($user['full_name'] ?? '') ?>" class="w-full px-3 py-2 rounded-md border border-gray-300">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Rol *</label>
                    <select name="role" class="w-full px-3 py-2 rounded-md border border-gray-300">
                        <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>User</option>
                        <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Limits and License -->
        <div class="bg-white border border-gray-200 rounded-xl p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4">Limitler ve Lisans</h2>

            <!-- Data Query Limits -->
            <div class="mb-6">
                <h3 class="text-md font-medium text-gray-800 mb-3">📊 Veri Sorgu Limitleri (Arama & Kanal)</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Günlük Limit</label>
                        <input type="number" name="data_query_limit_daily" value="<?= $user['data_query_limit_daily'] ?>" min="-1" class="w-full px-3 py-2 rounded-md border border-gray-300">
                        <p class="text-xs text-gray-500 mt-1">-1 = Sınırsız (Admin için otomatik)</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Aylık Limit</label>
                        <input type="number" name="data_query_limit_monthly" value="<?= $user['data_query_limit_monthly'] ?>" min="-1" class="w-full px-3 py-2 rounded-md border border-gray-300">
                        <p class="text-xs text-gray-500 mt-1">-1 = Sınırsız</p>
                    </div>
                </div>
            </div>

            <!-- Analysis Query Limits -->
            <div class="mb-6">
                <h3 class="text-md font-medium text-gray-800 mb-3">🤖 Analiz Sorgu Limitleri (AI)</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Günlük Limit</label>
                        <input type="number" name="analysis_query_limit_daily" value="<?= $user['analysis_query_limit_daily'] ?>" min="-1" class="w-full px-3 py-2 rounded-md border border-gray-300">
                        <p class="text-xs text-gray-500 mt-1">-1 = Sınırsız (Admin için otomatik)</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Aylık Limit</label>
                        <input type="number" name="analysis_query_limit_monthly" value="<?= $user['analysis_query_limit_monthly'] ?>" min="-1" class="w-full px-3 py-2 rounded-md border border-gray-300">
                        <p class="text-xs text-gray-500 mt-1">-1 = Sınırsız</p>
                    </div>
                </div>
            </div>

            <!-- License -->
            <div>
                <h3 class="text-md font-medium text-gray-800 mb-3">📅 Lisans</h3>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Lisans Bitiş Tarihi</label>
                    <input type="datetime-local" name="license_expires_at" value="<?= $user['license_expires_at'] ? date('Y-m-d\TH:i', strtotime($user['license_expires_at'])) : '' ?>" class="w-full px-3 py-2 rounded-md border border-gray-300">
                    <p class="text-xs text-gray-500 mt-1">Boş bırakın = Sınırsız</p>
                </div>
            </div>
        </div>

        <!-- Security -->
        <div class="bg-white border border-gray-200 rounded-xl p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4">Güvenlik</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Yeni Şifre</label>
                    <input type="password" name="password" placeholder="Değiştirmek için yeni şifre girin" class="w-full px-3 py-2 rounded-md border border-gray-300">
                    <p class="text-xs text-gray-500 mt-1">Boş bırakın = Şifre değişmez</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Hesap Durumu</label>
                    <select name="is_active" class="w-full px-3 py-2 rounded-md border border-gray-300">
                        <option value="1" <?= $user['is_active'] ? 'selected' : '' ?>>Aktif</option>
                        <option value="0" <?= !$user['is_active'] ? 'selected' : '' ?>>Pasif</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Notes -->
        <div class="bg-white border border-gray-200 rounded-xl p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4">Admin Notları</h2>
            <textarea name="notes" rows="4" placeholder="Bu kullanıcı hakkında notlar..." class="w-full px-3 py-2 rounded-md border border-gray-300"><?= e($user['notes'] ?? '') ?></textarea>
        </div>

        <!-- Statistics -->
        <div class="bg-white border border-gray-200 rounded-xl p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4">İstatistikler</h2>

            <!-- Data Queries Stats -->
            <div class="mb-6">
                <h3 class="text-md font-medium text-gray-800 mb-3">📊 Veri Sorguları</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="text-center p-3 bg-blue-50 rounded-lg">
                        <div class="text-2xl font-bold text-blue-900"><?= $stats['data_queries_today'] ?></div>
                        <div class="text-xs text-blue-700">Bugün</div>
                    </div>
                    <div class="text-center p-3 bg-green-50 rounded-lg">
                        <div class="text-2xl font-bold text-green-900"><?= $stats['data_queries_month'] ?></div>
                        <div class="text-xs text-green-700">Bu Ay</div>
                    </div>
                    <div class="text-center p-3 bg-purple-50 rounded-lg">
                        <div class="text-2xl font-bold text-purple-900"><?= $stats['data_queries_total'] ?></div>
                        <div class="text-xs text-purple-700">Toplam</div>
                    </div>
                    <div class="text-center p-3 bg-orange-50 rounded-lg">
                        <div class="text-2xl font-bold text-orange-900">
                            <?= $stats['data_remaining_daily'] === -1 ? '∞' : $stats['data_remaining_daily'] ?>
                        </div>
                        <div class="text-xs text-orange-700">Kalan (Günlük)</div>
                    </div>
                </div>
            </div>

            <!-- Analysis Queries Stats -->
            <div class="mb-6">
                <h3 class="text-md font-medium text-gray-800 mb-3">🤖 Analiz Sorguları</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="text-center p-3 bg-indigo-50 rounded-lg">
                        <div class="text-2xl font-bold text-indigo-900"><?= $stats['analysis_queries_today'] ?></div>
                        <div class="text-xs text-indigo-700">Bugün</div>
                    </div>
                    <div class="text-center p-3 bg-teal-50 rounded-lg">
                        <div class="text-2xl font-bold text-teal-900"><?= $stats['analysis_queries_month'] ?></div>
                        <div class="text-xs text-teal-700">Bu Ay</div>
                    </div>
                    <div class="text-center p-3 bg-pink-50 rounded-lg">
                        <div class="text-2xl font-bold text-pink-900"><?= $stats['analysis_queries_total'] ?></div>
                        <div class="text-xs text-pink-700">Toplam</div>
                    </div>
                    <div class="text-center p-3 bg-amber-50 rounded-lg">
                        <div class="text-2xl font-bold text-amber-900">
                            <?= $stats['analysis_remaining_daily'] === -1 ? '∞' : $stats['analysis_remaining_daily'] ?>
                        </div>
                        <div class="text-xs text-amber-700">Kalan (Günlük)</div>
                    </div>
                </div>
            </div>

            <!-- Account Info -->
            <div>
                <h3 class="text-md font-medium text-gray-800 mb-3">👤 Hesap Bilgileri</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                    <div>
                        <span class="text-gray-600">Kayıt Tarihi:</span>
                        <span class="font-medium text-gray-900"><?= date('d.m.Y H:i', strtotime($user['created_at'])) ?></span>
                    </div>
                    <div>
                        <span class="text-gray-600">Son Giriş:</span>
                        <span class="font-medium text-gray-900"><?= $user['last_login'] ? date('d.m.Y H:i', strtotime($user['last_login'])) : 'Hiç giriş yapmadı' ?></span>
                    </div>
                    <div>
                        <span class="text-gray-600">Hesap Yaşı:</span>
                        <span class="font-medium text-gray-900"><?= $stats['account_age_days'] ?> gün</span>
                    </div>
                    <div>
                        <span class="text-gray-600">Lisans Durumu:</span>
                        <span class="font-medium <?= $licenseInfo['status'] === 'expired' ? 'text-red-600' : ($licenseInfo['status'] === 'expiring_soon' ? 'text-orange-600' : 'text-green-600') ?>">
                            <?php
                            if ($licenseInfo['status'] === 'unlimited') {
                                echo 'Sınırsız';
                            } elseif ($licenseInfo['status'] === 'expired') {
                                echo 'Süresi Dolmuş';
                            } elseif ($licenseInfo['status'] === 'expiring_soon') {
                                echo $licenseInfo['days_remaining'] . ' gün kaldı (Yakında dolacak)';
                            } else {
                                echo $licenseInfo['days_remaining'] . ' gün kaldı';
                            }
                            ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="flex gap-3">
            <button type="submit" class="px-6 py-2 rounded-md border border-indigo-300 bg-indigo-600 text-white hover:bg-indigo-500">
                Değişiklikleri Kaydet
            </button>
            <a href="users.php" class="px-6 py-2 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200">
                İptal
            </a>
        </div>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
