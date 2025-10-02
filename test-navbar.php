<!DOCTYPE html>
<html>
<head>
    <title>Navbar Test</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body>
<div class="container mx-auto max-w-6xl p-4">
    <h1 class="text-2xl mb-4">NAVBAR TEST - Aşağıda navbar görünmeli:</h1>
    <hr class="mb-4">

    <?php
    // Simulate Auth class
    class Auth {
        public static function user() {
            return ['username' => 'TestUser'];
        }
    }

    $_SERVER['PHP_SELF'] = 'test-navbar.php';
    include __DIR__ . '/includes/navbar.php';
    ?>

    <hr class="mt-4">
    <p class="mt-4 text-gray-600">Yukarıda "YMT" logosu ve menüler görünüyorsa navbar çalışıyor!</p>
</div>
</body>
</html>
