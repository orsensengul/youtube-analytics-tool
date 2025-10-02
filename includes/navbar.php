<?php
/**
 * Standard Navigation Bar Component
 * Usage: include __DIR__ . '/includes/navbar.php';
 */

if (!function_exists('e')) {
    function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$username = 'User';
if (class_exists('Auth')) {
    $user = Auth::user();
    $username = $user['username'] ?? 'User';
}

function navIsActive(string $page): string {
    global $currentPage;
    return $currentPage === $page ? 'bg-indigo-100 border-indigo-400 text-indigo-900 font-semibold' : 'bg-gray-100 border-gray-300 text-gray-800 hover:bg-gray-200';
}
?>
<nav class="flex items-center justify-between mb-6 pb-4 border-b border-gray-200">
    <!-- Logo/Title -->
    <div class="flex items-center gap-4">
        <a href="index.php" class="text-2xl font-bold text-indigo-600 hover:text-indigo-700">YMAT</a>
        <div class="text-sm text-gray-500">YouTube Managing & Analyzing Tool</div>
    </div>

    <!-- Navigation Links -->
    <div class="flex items-center gap-2">
        <!-- Main Pages -->
        <a class="text-sm px-4 py-2 rounded-md border <?= navIsActive('index') ?>" href="index.php">
            🔍 Arama
        </a>
        <a class="text-sm px-4 py-2 rounded-md border <?= navIsActive('channel') ?>" href="channel.php">
            📺 Kanal Analizi
        </a>
        <a class="text-sm px-4 py-2 rounded-md border <?= navIsActive('analyze') ?>" href="analyze.php">
            📊 Analiz
        </a>
        <a class="text-sm px-4 py-2 rounded-md border <?= navIsActive('history') ?>" href="history.php">
            📜 Geçmiş
        </a>

        <!-- Divider -->
        <div class="border-l border-gray-300 h-8 mx-2"></div>

        <!-- User Menu -->
        <span class="text-sm text-gray-600 px-2">👤 <?= e($username) ?></span>
        <a class="text-sm px-4 py-2 rounded-md border border-red-300 bg-red-50 text-red-700 hover:bg-red-100" href="logout.php">
            Çıkış
        </a>
    </div>
</nav>
