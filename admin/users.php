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

// Handle user deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId > 0 && $userId !== Auth::userId()) {
        if (UserManager::deleteUser($userId)) {
            $success = 'Kullanıcı başarıyla silindi.';
        } else {
            $error = 'Kullanıcı silinemedi.';
        }
    } else {
        $error = 'Kendi hesabınızı silemezsiniz.';
    }
}

// Handle user activation/deactivation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_active'])) {
    $userId = (int)($_POST['user_id'] ?? 0);
    $isActive = (int)($_POST['is_active'] ?? 0);
    if ($userId > 0 && $userId !== Auth::userId()) {
        if (UserManager::updateUser($userId, ['is_active' => $isActive ? 0 : 1])) {
            $success = $isActive ? 'Kullanıcı pasif hale getirildi.' : 'Kullanıcı aktif hale getirildi.';
        } else {
            $error = 'Kullanıcı durumu güncellenemedi.';
        }
    }
}

// Get filters
$filters = [];
if (!empty($_GET['role'])) {
    $filters['role'] = $_GET['role'];
}
if (isset($_GET['is_active']) && $_GET['is_active'] !== '') {
    $filters['is_active'] = (int)$_GET['is_active'];
}
if (!empty($_GET['license_status'])) {
    $filters['license_status'] = $_GET['license_status'];
}
if (!empty($_GET['search'])) {
    $filters['search'] = $_GET['search'];
}

// Get users
$users = UserManager::getAllUsers($filters);
$stats = UserManager::getSystemStats();

?><!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kullanıcı Yönetimi - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../assets/styles.css">
</head>
<body class="bg-gray-50">
<div class="container mx-auto max-w-7xl px-4 py-6">
    <?php include __DIR__ . '/../includes/navbar.php'; ?>

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">👥 Kullanıcı Yönetimi</h1>
            <p class="text-sm text-gray-600 mt-1">
                Toplam: <?= $stats['total_users'] ?> |
                Aktif: <?= $stats['active_users'] ?> |
                Admin: <?= $stats['admin_users'] ?>
                <?php if ($stats['expired_licenses'] > 0): ?>
                    | <span class="text-red-600">Süresi Dolmuş: <?= $stats['expired_licenses'] ?></span>
                <?php endif; ?>
            </p>
        </div>
        <a href="user-create.php" class="px-4 py-2 rounded-md border border-indigo-300 bg-indigo-600 text-white hover:bg-indigo-500">
            + Yeni Kullanıcı
        </a>
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

    <!-- Filters -->
    <form method="get" class="bg-white border border-gray-200 rounded-xl p-4 mb-4">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <div>
                <label class="block text-xs text-gray-600 mb-1">Rol</label>
                <select name="role" class="w-full px-3 py-2 rounded-md border border-gray-300 text-sm">
                    <option value="">Tümü</option>
                    <option value="admin" <?= ($filters['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
                    <option value="user" <?= ($filters['role'] ?? '') === 'user' ? 'selected' : '' ?>>User</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">Durum</label>
                <select name="is_active" class="w-full px-3 py-2 rounded-md border border-gray-300 text-sm">
                    <option value="">Tümü</option>
                    <option value="1" <?= isset($filters['is_active']) && $filters['is_active'] === 1 ? 'selected' : '' ?>>Aktif</option>
                    <option value="0" <?= isset($filters['is_active']) && $filters['is_active'] === 0 ? 'selected' : '' ?>>Pasif</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">Lisans</label>
                <select name="license_status" class="w-full px-3 py-2 rounded-md border border-gray-300 text-sm">
                    <option value="">Tümü</option>
                    <option value="active" <?= ($filters['license_status'] ?? '') === 'active' ? 'selected' : '' ?>>Aktif</option>
                    <option value="expiring_soon" <?= ($filters['license_status'] ?? '') === 'expiring_soon' ? 'selected' : '' ?>>Yakında Dolacak</option>
                    <option value="expired" <?= ($filters['license_status'] ?? '') === 'expired' ? 'selected' : '' ?>>Süresi Dolmuş</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">Ara</label>
                <div class="flex gap-2">
                    <input type="text" name="search" value="<?= e($filters['search'] ?? '') ?>" placeholder="Kullanıcı adı veya e-posta" class="flex-1 px-3 py-2 rounded-md border border-gray-300 text-sm">
                    <button type="submit" class="px-4 py-2 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200 text-sm">Filtrele</button>
                </div>
            </div>
        </div>
    </form>

    <!-- Users Table -->
    <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                    <th class="text-left px-4 py-3 text-gray-600 font-medium">Kullanıcı</th>
                    <th class="text-left px-4 py-3 text-gray-600 font-medium">E-posta</th>
                    <th class="text-left px-4 py-3 text-gray-600 font-medium">Rol</th>
                    <th class="text-left px-4 py-3 text-gray-600 font-medium">Sorgu</th>
                    <th class="text-left px-4 py-3 text-gray-600 font-medium">Lisans</th>
                    <th class="text-left px-4 py-3 text-gray-600 font-medium">Durum</th>
                    <th class="text-left px-4 py-3 text-gray-600 font-medium">Aksiyon</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-gray-500">
                            Kullanıcı bulunamadı.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $user): ?>
                        <?php
                        $licenseInfo = UserManager::getLicenseInfo($user['id']);
                        $dataRemaining = UserManager::getRemainingDataQueries($user['id']);
                        $analysisRemaining = UserManager::getRemainingAnalysisQueries($user['id']);
                        ?>
                        <tr class="border-b border-gray-100 hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900"><?= e($user['username']) ?></div>
                                <?php if ($user['full_name']): ?>
                                    <div class="text-xs text-gray-500"><?= e($user['full_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-gray-700"><?= e($user['email']) ?></td>
                            <td class="px-4 py-3">
                                <span class="inline-block px-2 py-1 rounded-full text-xs font-medium <?= $user['role'] === 'admin' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700' ?>">
                                    <?= e(ucfirst($user['role'])) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <?php if ($user['role'] === 'admin' || $user['data_query_limit_daily'] === -1): ?>
                                    <span class="text-gray-500">∞</span>
                                <?php else: ?>
                                    <div class="text-xs">
                                        <div class="<?= $dataRemaining['daily'] < 10 ? 'text-red-600 font-medium' : 'text-gray-700' ?>">
                                            📊 <?= $user['data_query_count_today'] ?>/<?= $user['data_query_limit_daily'] ?>
                                        </div>
                                        <div class="<?= $analysisRemaining['daily'] < 10 ? 'text-orange-600 font-medium' : 'text-gray-600' ?> mt-1">
                                            🤖 <?= $user['analysis_query_count_today'] ?>/<?= $user['analysis_query_limit_daily'] ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <?php if ($licenseInfo['status'] === 'unlimited'): ?>
                                    <span class="text-gray-500">-</span>
                                <?php elseif ($licenseInfo['status'] === 'expired'): ?>
                                    <span class="text-red-600 font-medium">Doldu</span>
                                <?php elseif ($licenseInfo['status'] === 'expiring_soon'): ?>
                                    <span class="text-orange-600 font-medium"><?= $licenseInfo['days_remaining'] ?> gün</span>
                                <?php else: ?>
                                    <span class="text-green-600"><?= $licenseInfo['days_remaining'] ?> gün</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <?php if ($user['is_active']): ?>
                                    <span class="inline-block px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-700">Aktif</span>
                                <?php else: ?>
                                    <span class="inline-block px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-700">Pasif</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex gap-2">
                                    <a href="user-edit.php?id=<?= $user['id'] ?>" class="px-3 py-1 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200 text-xs">
                                        Düzenle
                                    </a>
                                    <?php if ($user['id'] !== Auth::userId()): ?>
                                        <form method="post" class="inline" onsubmit="return confirm('Bu kullanıcıyı <?= $user['is_active'] ? 'pasif' : 'aktif' ?> hale getirmek istediğinize emin misiniz?')">
                                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                            <input type="hidden" name="is_active" value="<?= $user['is_active'] ?>">
                                            <button type="submit" name="toggle_active" class="px-3 py-1 rounded-md border border-gray-300 bg-gray-100 text-gray-800 hover:bg-gray-200 text-xs">
                                                <?= $user['is_active'] ? 'Pasif Yap' : 'Aktif Yap' ?>
                                            </button>
                                        </form>
                                        <form method="post" class="inline" onsubmit="return confirm('Bu kullanıcıyı silmek istediğinize emin misiniz? Bu işlem geri alınamaz!')">
                                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                            <button type="submit" name="delete_user" class="px-3 py-1 rounded-md border border-red-300 bg-red-50 text-red-700 hover:bg-red-100 text-xs">
                                                Sil
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="mt-4 text-sm text-gray-500">
        Toplam <?= count($users) ?> kullanıcı gösteriliyor
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
