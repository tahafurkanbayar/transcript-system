<?php
/**
 * =====================================================================
 *  MIGRATION: transkript_segmentler (Transkript modülü)
 * ---------------------------------------------------------------------
 *  Zaman damgalı transcript satırları. Rapordaki her zaman damgası bu
 *  satırlardan birine denk gelmek zorundadır (ResultValidator).
 *
 *  UNIQUE(metin_id, sira): segment yazan iş iki kez çalışırsa aynı
 *  satırı ikinci kez eklemez.
 *  FULLTEXT(metin): transcript içi arama ve sohbette ilgili bölümü bulma.
 * =====================================================================
 */

declare(strict_types=1);

return new class extends App\Core\Database\Migration
{
    public function up(): void
    {
        $this->execute(
            "CREATE TABLE IF NOT EXISTS `transkript_segmentler` (
                `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `metin_id`     INT UNSIGNED NOT NULL,
                `sira`         INT UNSIGNED NOT NULL,
                `baslangic_ms` INT UNSIGNED NOT NULL,
                `bitis_ms`     INT UNSIGNED NOT NULL,
                `konusmaci`    VARCHAR(50) NULL DEFAULT NULL,
                `metin`        TEXT NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_segment_sira` (`metin_id`, `sira`),
                KEY `idx_segment_zaman` (`metin_id`, `baslangic_ms`),
                FULLTEXT KEY `ft_segment_metin` (`metin`),
                CONSTRAINT `fk_segment_metin` FOREIGN KEY (`metin_id`)
                    REFERENCES `transkript_metinler` (`id`) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci"
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `transkript_segmentler`');
    }
};
