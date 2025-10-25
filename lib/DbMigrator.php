<?php

class DbMigrator
{
    public static function run(): void
    {
        try {
            self::ensureVideoMetadataLocalAssets();
        } catch (Exception $e) {}
        try {
            self::ensureSearchHistoryVideoType();
        } catch (Exception $e) {}
        try {
            self::ensureAnalysisResultsVideoMode();
        } catch (Exception $e) {}
        try {
            self::ensureVideoTranscriptsTable();
        } catch (Exception $e) {}
        try {
            self::ensureAnalysisPromptTemplates();
        } catch (Exception $e) {}
    }

    private static function tableExists(string $table): bool
    {
        $row = Database::selectOne("SHOW TABLES LIKE ?", [$table]);
        return (bool)$row;
    }

    private static function columnExists(string $table, string $column): bool
    {
        if (!self::tableExists($table)) return false;
        $row = Database::selectOne("SHOW COLUMNS FROM `$table` LIKE ?", [$column]);
        return (bool)$row;
    }

    private static function ensureVideoMetadataLocalAssets(): void
    {
        if (!self::tableExists('video_metadata')) return;
        if (!self::columnExists('video_metadata', 'local_assets')) {
            Database::query("ALTER TABLE `video_metadata` ADD COLUMN `local_assets` JSON NULL");
        }
    }

    private static function ensureSearchHistoryVideoType(): void
    {
        if (!self::tableExists('search_history')) return;
        $col = Database::selectOne("SHOW COLUMNS FROM `search_history` LIKE 'search_type'");
        if (!$col) return;
        $type = strtolower((string)($col['Type'] ?? ''));
        if (strpos($type, "'video'") === false) {
            Database::query("ALTER TABLE `search_history` MODIFY COLUMN `search_type` ENUM('keyword','channel','video') NOT NULL");
        }
    }

    private static function ensureAnalysisResultsVideoMode(): void
    {
        if (!self::tableExists('analysis_results')) return;
        $col = Database::selectOne("SHOW COLUMNS FROM `analysis_results` LIKE 'mode'");
        if (!$col) return;
        $type = strtolower((string)($col['Type'] ?? ''));
        if (strpos($type, "'video'") === false) {
            Database::query("ALTER TABLE `analysis_results` MODIFY COLUMN `mode` ENUM('search','channel','video') NOT NULL");
        }
    }

    private static function ensureVideoTranscriptsTable(): void
    {
        if (self::tableExists('video_transcripts')) return;
        $sql = "CREATE TABLE IF NOT EXISTS `video_transcripts` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `video_id` VARCHAR(20) NOT NULL,
  `lang` VARCHAR(10) NOT NULL,
  `provider` VARCHAR(50) NULL,
  `file_json_path` VARCHAR(500) NULL,
  `file_txt_path` VARCHAR(500) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_video_lang (`video_id`, `lang`),
  INDEX idx_video_id (`video_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        Database::query($sql);
        // Add FK if video_metadata exists (best-effort)
        if (self::tableExists('video_metadata')) {
            try {
                Database::query("ALTER TABLE `video_transcripts` ADD CONSTRAINT fk_vt_vid FOREIGN KEY (`video_id`) REFERENCES `video_metadata`(`video_id`) ON DELETE CASCADE");
            } catch (Exception $e) {}
        }
    }

    private static function ensureAnalysisPromptTemplates(): void
    {
        if (!self::tableExists('analysis_prompts')) return;
        $defaults = [
            'title' => "Başlığı CTR odaklı iyileştir.\n- 10-20 yeni başlık önerisi üret\n- Numara ile listele\n- Türkçe, kısa ve vurucu olsun",
            'description' => "Açıklamayı SEO ve izlenme açısından iyileştir.\n- 2-3 şablon öner\n- İlk 150 karakterde güçlü özet\n- Hashtag ve link yerleşimi öner",
            'tags' => "Videoya uygun 15-25 Türkçe etiket öner.\n- Aranma niyeti odaklı\n- Kümeler halinde ver\n- Gereksiz tekrar yok",
            'seo' => "Tek video için SEO özeti çıkar.\n- Başlık, açıklama, etiketler için güçlü/zayıf yönler\n- 5 hızlı kazanım önerisi",
            'thumb-hook' => "Thumbnail metni/hook önerileri üret.\n- 10 kısa ve çarpıcı metin\n- 3-4 kelimeyi geçmesin\n- Türkçe ve vurucu",
        ];
        foreach ($defaults as $type => $tpl) {
            $row = Database::selectOne("SELECT id FROM analysis_prompts WHERE analysis_type = ?", [$type]);
            if (!$row) {
                Database::insert('analysis_prompts', [
                    'analysis_type' => $type,
                    'prompt_template' => $tpl,
                ]);
            }
        }
    }
}
