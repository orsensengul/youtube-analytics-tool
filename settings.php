<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/AppState.php';

Database::init($config['database']);
Auth::startSession($config['session']);
Auth::requireLogin();

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$currentPage = 'settings';
$error = '';
$success = '';

// Get all analysis prompt templates
$prompts = Database::select("SELECT * FROM analysis_prompts ORDER BY analysis_type");

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_prompt'])) {
    $analysisType = trim($_POST['analysis_type'] ?? '');
    $promptTemplate = trim($_POST['prompt_template'] ?? '');

    if (empty($analysisType) || empty($promptTemplate)) {
        $error = 'Analiz türü ve prompt şablonu gereklidir.';
    } else {
        try {
            // Check if exists
            $existing = Database::select(
                "SELECT id FROM analysis_prompts WHERE analysis_type = ?",
                [$analysisType]
            );

            if (!empty($existing)) {
                // Update
                Database::update(
                    'analysis_prompts',
                    ['prompt_template' => $promptTemplate],
                    'analysis_type = ?',
                    [$analysisType]
                );
            } else {
                // Insert
                Database::insert('analysis_prompts', [
                    'analysis_type' => $analysisType,
                    'prompt_template' => $promptTemplate,
                ]);
            }

            $success = 'Prompt şablonu başarıyla güncellendi.';
            // Refresh prompts
            $prompts = Database::select("SELECT * FROM analysis_prompts ORDER BY analysis_type");
        } catch (Exception $e) {
            $error = 'Güncelleme hatası: ' . $e->getMessage();
        }
    }
}

// Admin-only: offline mode toggle and storage directory
if (Auth::isAdmin() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['offline_toggle'])) {
    $desired = ($_POST['offline'] ?? '') === '1';
    if (AppState::setOffline($config, $desired)) {
        $success = $desired ? 'Offline modu açıldı.' : 'Offline modu kapatıldı.';
    } else {
        $error = 'Offline modu güncellenemedi. Klasör izinlerini kontrol edin.';
    }
}

$storage = AppState::storageStatus($config);
$isOffline = AppState::isOffline($config);

// Default prompts for each analysis type
$defaultPrompts = [
    // Single video analysis types
    'title' => "Başlığı CTR odaklı iyileştir.\n- 10-20 yeni başlık önerisi üret\n- Numara ile listele\n- Türkçe, kısa ve vurucu olsun",
    'description' => "Açıklamayı SEO ve izlenme açısından iyileştir.\n- 2-3 şablon öner\n- İlk 150 karakterde güçlü özet\n- Hashtag ve link yerleşimi öner",
    'thumb-hook' => "Thumbnail metni/hook önerileri üret.\n- 10 kısa ve çarpıcı metin\n- 3-4 kelimeyi geçmesin\n- Türkçe ve vurucu",
    'descriptions' => "JSON içindeki description alanlarını incele.\n- Benzerlikler, farklılıklar, ortak temalar\n- SEO ve YouTube aranma açısından güçlü/zayıf yönler\n- Geliştirme önerileri\n- 2-3 örnek optimize açıklama şablonu\nKısa, maddeli ve somut öneriler ver.",
    'tags' => "JSON içindeki tags alanlarını analiz et.\n- En çok kullanılan etiketler, kümeler\n- Eksik/hatalı etiketler ve öneriler\n- Aranma niyeti (intent) odaklı tag önerileri\n- 10-20 yeni öneri tag listesi (TR odaklı)",
    'titles' => "JSON içindeki title alanlarını analiz et.\n- Öne çıkan kalıplar\n- CTR'ı artırma önerileri\n- 5-10 örnek yeni başlık önerisi",
    'seo' => "Kapsamlı SEO özeti çıkar: title, description, tags, izlenme metriklerine göre genel değerlendirme ve hızlı kazanım önerileri. Maddelerle yaz.",
    'auto-title-generator' => "JSON'daki başarılı başlıkları analiz et ve 20 farklı yeni başlık önerisi üret.\n- 5 clickbait tarzı\n- 5 profesyonel tarzı\n- 5 eğitsel tarzı\n- 5 merak uyandıran tarzı\nHer birini numaralandır ve kategorize et.",
    'performance-prediction' => "İzlenme verilerini analiz et ve performans tahminleri yap.\n- En iyi performans gösteren içerik özellikleri\n- Başarı olasılığı yüksek içerik tipleri\n- Risk faktörleri\n- Gelecek içerikler için öneriler",
    'content-gaps' => "İçerik boşluklarını tespit et.\n- Eksik kalan konu alanları\n- Potansiyel fırsatlar\n- Rakiplerin kullandığı ama burada olmayan konular\n- 10-15 yeni içerik fikri önerisi",
    'trending-topics' => "Yüksek izlenme alan videoların ortak temalarını tespit et.\n- Popüler konular ve trendler\n- Hangi konular daha çok ilgi görüyor\n- Trend takip önerileri\n- Güncel trendlere uyum stratejileri",
    'engagement-rate' => "Etkileşim oranlarını değerlendir.\n- Like/View oranı analizi\n- En çok etkileşim alan içerik özellikleri\n- Etkileşim artırma stratejileri\n- Topluluk oluşturma önerileri",
    'best-performers' => "En yüksek izlenmeye sahip videoları analiz et.\n- Ortak başarı faktörleri\n- Başlık, açıklama, tag paternleri\n- Tekrarlanabilir başarı formülü\n- 5-10 somut uygulama önerisi",
];

$analysisLabels = [
    'title' => '📌 Tekli Video — Başlık',
    'description' => '📝 Tekli Video — Açıklama',
    'thumb-hook' => '🖼️ Tekli Video — Thumbnail/Hook',
    'descriptions' => '📝 Açıklamalar Analizi',
    'tags' => '🏷️ Etiketler Analizi',
    'titles' => '📌 Başlıklar Analizi',
    'seo' => '🔍 SEO Analizi',
    'auto-title-generator' => '✨ Otomatik Başlık Üretici',
    'performance-prediction' => '📈 Performans Tahmini',
    'content-gaps' => '🔎 İçerik Boşlukları',
    'trending-topics' => '🔥 Trend Konular',
    'engagement-rate' => '💬 Etkileşim Oranı',
    'best-performers' => '⭐ En İyi Performans',
];

// Convert prompts array to keyed array
$promptsByType = [];
foreach ($prompts as $p) {
    $promptsByType[$p['analysis_type']] = $p['prompt_template'];
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ayarlar - YMAT</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body class="bg-gray-50">
<div class="container mx-auto max-w-6xl px-4 py-6">
    <?php include __DIR__ . '/includes/navbar.php'; ?>

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">⚙️ Ayarlar</h1>
        <p class="text-sm text-gray-600 mt-1">Analiz prompt şablonlarını görüntüleyin ve düzenleyin</p>
    </div>

    <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg p-4 mb-4">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg p-4 mb-4">
            <?= e($success) ?>
        </div>
    <?php endif; ?>

    <!-- Analysis Prompts Section -->
    <?php if (Auth::isAdmin()): ?>
    <!-- Admin: Storage + Offline Mode -->
    <div class="bg-white border border-gray-200 rounded-xl p-6 mb-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">🗃️ Depolama ve Offline Mod</h2>
        <div class="text-sm text-gray-700 mb-3">Depolama klasörü: <code><?= e($storage['dir']) ?></code>
            <span class="ml-2 inline-block px-2 py-0.5 rounded-full border <?= $storage['writable'] ? 'border-green-300 bg-green-50 text-green-700' : 'border-red-300 bg-red-50 text-red-700' ?>">
                <?= $storage['writable'] ? 'Yazılabilir' : 'Yazılamaz' ?>
            </span>
        </div>
        <form method="post" class="flex items-center gap-3">
            <input type="hidden" name="offline_toggle" value="1">
            <label class="flex items-center gap-2 text-sm text-gray-800">
                <input type="checkbox" name="offline" value="1" <?= $isOffline ? 'checked' : '' ?>> Offline modu etkin
            </label>
            <button class="px-3 py-1.5 rounded-md border border-gray-300 bg-gray-100 hover:bg-gray-200" type="submit">Kaydet</button>
        </form>
        <p class="text-xs text-gray-500 mt-2">Offline modda ağ istekleri yapılmaz; yalnızca veritabanı/dosyalar kullanılır.</p>
    </div>
    <?php endif; ?>

    <div class="bg-white border border-gray-200 rounded-xl p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">📝 Analiz Prompt Şablonları</h2>
        <p class="text-sm text-gray-600 mb-6">
            Her analiz türü için kullanılan prompt şablonlarını buradan düzenleyebilirsiniz.
            Şablonlar AI'a gönderilen talimatları belirler.
        </p>

        <div class="space-y-6">
            <?php foreach ($analysisLabels as $type => $label): ?>
                <div class="border border-gray-200 rounded-lg p-4 bg-gray-50">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="font-semibold text-gray-900"><?= e($label) ?></h3>
                        <button
                            onclick="toggleEdit('<?= e($type) ?>')"
                            class="text-sm px-3 py-1 bg-blue-600 text-white rounded-md hover:bg-blue-700"
                            id="edit-btn-<?= e($type) ?>">
                            ✏️ Düzenle
                        </button>
                    </div>

                    <!-- View Mode -->
                    <div id="view-<?= e($type) ?>" class="bg-white border border-gray-300 rounded-md p-3">
                        <pre class="text-sm text-gray-700 whitespace-pre-wrap"><?= e($promptsByType[$type] ?? $defaultPrompts[$type] ?? 'Prompt tanımlanmamış.') ?></pre>
                    </div>

                    <!-- Edit Mode (Hidden by default) -->
                    <div id="edit-<?= e($type) ?>" class="hidden">
                        <form method="post">
                            <input type="hidden" name="analysis_type" value="<?= e($type) ?>">
                            <textarea
                                name="prompt_template"
                                rows="8"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                required><?= e($promptsByType[$type] ?? $defaultPrompts[$type] ?? '') ?></textarea>

                            <div class="flex gap-2 mt-3">
                                <button
                                    type="submit"
                                    name="update_prompt"
                                    class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                                    ✅ Kaydet
                                </button>
                                <button
                                    type="button"
                                    onclick="toggleEdit('<?= e($type) ?>')"
                                    class="px-4 py-2 bg-gray-300 text-gray-800 rounded-md hover:bg-gray-400">
                                    ❌ İptal
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
function toggleEdit(type) {
    const viewDiv = document.getElementById('view-' + type);
    const editDiv = document.getElementById('edit-' + type);
    const editBtn = document.getElementById('edit-btn-' + type);

    if (editDiv.classList.contains('hidden')) {
        // Show edit mode
        viewDiv.classList.add('hidden');
        editDiv.classList.remove('hidden');
        editBtn.classList.add('hidden');
    } else {
        // Show view mode
        viewDiv.classList.remove('hidden');
        editDiv.classList.add('hidden');
        editBtn.classList.remove('hidden');
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
