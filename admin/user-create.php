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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'username' => trim($_POST['username'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'password' => trim($_POST['password'] ?? ''),
        'full_name' => trim($_POST['full_name'] ?? ''),
        'role' => $_POST['role'] ?? 'user',
        'query_limit_daily' => (int)($_POST['query_limit_daily'] ?? 100),
        'is_active' => (int)($_POST['is_active'] ?? 1),
        'license_expires_at' => !empty($_POST['license_expires_at']) ? $_POST['license_expires_at'] : null,
        'notes' => trim($_POST['notes'] ?? ''),
        'created_by' => Auth::userId(),
    ];

    $result = UserManager::createUser($data);

    if ($result['success']) {
        $success = 'Kullanıcı başarıyla oluşturuldu.';
        // Redirect to edit page
        header('Location: user-edit.php?id=' . $result['user_id']);
        exit;
    } else {
        $error = $result['error'];
    }
}

?><!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Yeni Kullanıcı Oluştur</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../assets/styles.css">
</head>
<body class="bg-gray-50">
<div class="container mx-auto max-w-5xl px-4 py-6">
    <?php include __DIR__ . '/../includes/navbar.php'; ?>

    <div class="mb-6">
        <a href="users.php" class="text-sm text-indigo-600 hover:text-indigo-700">← Kullanıcı Listesine Dön</a>
        <h1 class="text-2xl font-bold text-gray-900 mt-2">Yeni Kullanıcı Oluştur</h1>
    </div>

    <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <form method="post" class="space-y-6">
        <!-- Basic Info -->
        <div class="bg-white border border-gray-200 rounded-xl p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4">Temel Bilgiler</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Kullanıcı Adı *</label>
                    <input type="text" name="username" required minlength="3" maxlength="50" class="w-full px-3 py-2 rounded-md border border-gray-300" value="<?= e($_POST['username'] ?? '') ?>">
                    <p class="text-xs text-gray-500 mt-1">3-50 karakter</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">E-posta *</label>
                    <input type="email" name="email" required class="w-full px-3 py-2 rounded-md border border-gray-300" value="<?= e($_POST['email'] ?? '') ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Şifre *</label>
                    <input type="password" name="password" required minlength="6" class="w-full px-3 py-2 rounded-md border border-gray-300">
                    <p class="text-xs text-gray-500 mt-1">En az 6 karakter</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Tam Ad</label>
                    <input type="text" name="full_name" class="w-full px-3 py-2 rounded-md border border-gray-300" value="<?= e($_POST['full_name'] ?? '') ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Rol *</label>
                    <select name="role" class="w-full px-3 py-2 rounded-md border border-gray-300">
                        <option value="user" <?= ($_POST['role'] ?? 'user') === 'user' ? 'selected' : '' ?>>User</option>
                        <option value="admin" <?= ($_POST['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Hesap Durumu</label>
                    <select name="is_active" class="w-full px-3 py-2 rounded-md border border-gray-300">
                        <option value="1" <?= ($_POST['is_active'] ?? 1) == 1 ? 'selected' : '' ?>>Aktif</option>
                        <option value="0" <?= ($_POST['is_active'] ?? 1) == 0 ? 'selected' : '' ?>>Pasif</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Limits and License -->
        <div class="bg-white border border-gray-200 rounded-xl p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4">Limitler ve Lisans</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Günlük Sorgu Limiti</label>
                    <input type="number" name="query_limit_daily" value="<?= e($_POST['query_limit_daily'] ?? '100') ?>" min="-1" class="w-full px-3 py-2 rounded-md border border-gray-300">
                    <p class="text-xs text-gray-500 mt-1">-1 = Sınırsız (Admin için otomatik)</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Lisans Bitiş Tarihi</label>
                    <input type="datetime-local" name="license_expires_at" value="<?= e($_POST['license_expires_at'] ?? '') ?>" class="w-full px-3 py-2 rounded-md border border-gray-300">
                    <p class="text-xs text-gray-500 mt-1">Boş bırakın = Sınırsız</p>
                </div>
            </div>
        </div>

        <!-- Notes -->
        <div class="bg-white border border-gray-200 rounded-xl p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4">Admin Notları</h2>
            <textarea name="notes" rows="4" placeholder="Bu kullanıcı hakkında notlar..." class="w-full px-3 py-2 rounded-md border border-gray-300"><?= e($_POST['notes'] ?? '') ?></textarea>
        </div>

        <!-- Actions -->
        <div class="flex gap-3">
            <button type="submit" class="px-6 py-2 rounded-md border border-indigo-300 bg-indigo-600 text-white hover:bg-indigo-500">
                Kullanıcı Oluştur
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
