<?php
/**
 * Responsive Navigation Bar Component - ClarityStat
 * Usage: include __DIR__ . '/includes/navbar.php';
 */

if (!function_exists('e')) {
    function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$navUsername = 'User';
$isAdmin = false;
$inAdminFolder = strpos($_SERVER['PHP_SELF'], '/admin/') !== false;
$baseUrl = $inAdminFolder ? '../' : '';

if (class_exists('Auth')) {
    $navUser = Auth::user();
    $navUsername = $navUser['username'] ?? 'User';
    $isAdmin = Auth::isAdmin();
}

function navIsActive(string $page): string {
    global $currentPage;
    return $currentPage === $page ? 'bg-indigo-100 border-indigo-400 text-indigo-900 font-semibold' : 'bg-gray-100 border-gray-300 text-gray-800 hover:bg-gray-200';
}

function navIsActiveAny(array $pages): string {
    global $currentPage;
    return in_array($currentPage, $pages, true) ? 'bg-indigo-100 border-indigo-400 text-indigo-900 font-semibold' : 'bg-gray-100 border-gray-300 text-gray-800 hover:bg-gray-200';
}
?>
<nav class="mb-6 pb-4 border-b border-gray-200">
    <div class="flex items-center justify-between">
        <!-- Logo -->
        <div class="flex items-center">
            <a href="<?= $baseUrl ?>index.php" class="text-xl md:text-2xl font-bold text-indigo-600 hover:text-indigo-700">
                ClarityStat
            </a>
        </div>

        <!-- Mobile Menu Button -->
        <button id="mobile-menu-btn" class="lg:hidden p-2 rounded-md border border-gray-300 bg-gray-100 hover:bg-gray-200">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
            </svg>
        </button>

        <!-- Desktop Navigation Links -->
        <div class="hidden lg:flex items-center gap-2">
            <div class="relative group">
                <a class="text-sm px-3 py-2 rounded-md border inline-flex items-center gap-1 <?= navIsActiveAny(['index','channel','video']) ?>" href="<?= $baseUrl ?>index.php">
                    🧭 Keşfet
                    <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.24a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd"/></svg>
                </a>
                <div class="absolute right-0 top-full w-48 bg-white border border-gray-200 rounded-md shadow-lg hidden group-hover:block group-focus-within:block z-50">
                    <a class="block px-3 py-2 text-sm text-gray-700 hover:bg-gray-100" href="<?= $baseUrl ?>index.php">🔍 Arama</a>
                    <a class="block px-3 py-2 text-sm text-gray-700 hover:bg-gray-100" href="<?= $baseUrl ?>channel.php">📺 Kanal</a>
                    <a class="block px-3 py-2 text-sm text-gray-700 hover:bg-gray-100" href="<?= $baseUrl ?>video.php">🎬 Tekli Video</a>
                </div>
            </div>
            <div class="relative group">
                <a class="text-sm px-3 py-2 rounded-md border inline-flex items-center gap-1 <?= navIsActiveAny(['analyze','history']) ?>" href="<?= $baseUrl ?>analyze.php">
                    📊 Analiz
                    <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.24a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd"/></svg>
                </a>
                <div class="absolute right-0 top-full w-44 bg-white border border-gray-200 rounded-md shadow-lg hidden group-hover:block group-focus-within:block z-50">
                    <a class="block px-3 py-2 text-sm text-gray-700 hover:bg-gray-100" href="<?= $baseUrl ?>analyze.php">📊 Analiz</a>
                    <a class="block px-3 py-2 text-sm text-gray-700 hover:bg-gray-100" href="<?= $baseUrl ?>history.php">📜 Geçmiş</a>
                </div>
            </div>

            <?php if ($isAdmin): ?>
                <a class="text-sm px-3 py-2 rounded-md border <?= navIsActive('settings') ?>" href="<?= $baseUrl ?>settings.php">
                    ⚙️ Ayarlar
                </a>
                <a class="text-sm px-3 py-2 rounded-md border <?= navIsActive('users') ?>" href="<?= $inAdminFolder ? 'users.php' : 'admin/users.php' ?>">
                    👥 Kullanıcılar
                </a>
            <?php endif; ?>

            <div class="border-l border-gray-300 h-8 mx-2"></div>

            <a class="text-sm px-3 py-2 rounded-md border <?= navIsActive('profile') ?>" href="<?= $baseUrl ?>profile.php" title="Profilim">
                👤 <?= e($navUsername) ?>
            </a>
            <a class="text-sm px-3 py-2 rounded-md border border-red-300 bg-red-50 text-red-700 hover:bg-red-100" href="<?= $baseUrl ?>logout.php">
                Çıkış
            </a>
        </div>
    </div>

    <!-- Mobile Navigation Menu -->
    <div id="mobile-menu" class="hidden lg:hidden mt-4 space-y-2">
        <div class="space-y-1">
            <a class="block text-sm px-4 py-2 rounded-md border <?= navIsActiveAny(['index','channel','video']) ?>" href="<?= $baseUrl ?>index.php">
                🧭 Keşfet
            </a>
            <a class="block text-sm px-6 py-2 rounded-md border <?= navIsActive('index') ?>" href="<?= $baseUrl ?>index.php">
                ↳ 🔍 Arama
            </a>
            <a class="block text-sm px-6 py-2 rounded-md border <?= navIsActive('channel') ?>" href="<?= $baseUrl ?>channel.php">
                ↳ 📺 Kanal
            </a>
            <a class="block text-sm px-6 py-2 rounded-md border <?= navIsActive('video') ?>" href="<?= $baseUrl ?>video.php">
                ↳ 🎬 Tekli Video
            </a>
        </div>
        <div class="space-y-1">
            <a class="block text-sm px-4 py-2 rounded-md border <?= navIsActive('analyze') ?>" href="<?= $baseUrl ?>analyze.php">
                📊 Analiz
            </a>
            <a class="block text-sm px-6 py-2 rounded-md border <?= navIsActive('history') ?>" href="<?= $baseUrl ?>history.php">
                ↳ 📜 Geçmiş
            </a>
        </div>

        <?php if ($isAdmin): ?>
            <a class="block text-sm px-4 py-2 rounded-md border <?= navIsActive('settings') ?>" href="<?= $baseUrl ?>settings.php">
                ⚙️ Ayarlar
            </a>
            <a class="block text-sm px-4 py-2 rounded-md border <?= navIsActive('users') ?>" href="<?= $inAdminFolder ? 'users.php' : 'admin/users.php' ?>">
                👥 Kullanıcılar
            </a>
        <?php endif; ?>

        <div class="pt-2 border-t border-gray-200 mt-2">
            <a class="block text-sm px-4 py-2 rounded-md border <?= navIsActive('profile') ?>" href="<?= $baseUrl ?>profile.php">
                👤 <?= e($navUsername) ?>
            </a>
            <a class="block text-sm px-4 py-2 rounded-md border border-red-300 bg-red-50 text-red-700 hover:bg-red-100 mt-2" href="<?= $baseUrl ?>logout.php">
                Çıkış
            </a>
        </div>
    </div>
</nav>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const menuBtn = document.getElementById('mobile-menu-btn');
    const mobileMenu = document.getElementById('mobile-menu');
    if (menuBtn && mobileMenu) {
        menuBtn.addEventListener('click', function() {
            mobileMenu.classList.toggle('hidden');
        });
    }
});
</script>
