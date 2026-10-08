<?php
/**
 * =====================================================================
 *  MIGRATION: transkript_parcalar (Transkript modülü)
 * ---------------------------------------------------------------------
 *  AI'a giden parçalar ve map sonuçları. Kaldığı yerden devam bu
 *  tablodan okunur: durumu "tamamlandi" olan parça yeniden AI'a
 *  gitmez, para iki kez ödenmez.
 *
 *  durum VARCHAR: bekliyor, isleniyor, tamamlandi, hata.
 * =====================================================================
 */

declare(strict_types=1);

return new class extends App\Core\Database\Migration
{
    public function up(): void
    {
        $this->execute(
            "CREATE TABLE IF NOT EXISTS `transkript_parcalar` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `analiz_id`     INT UNSIGNED NOT NULL,
                `sira`          INT UNSIGNED NOT NULL,
                `baslangic_ms`  INT UNSIGNED NOT NULL,
                `bitis_ms`      INT UNSIGNED NOT NULL,
                `tahmini_token` INT UNSIGNED NOT NULL DEFAULT 0,
                `durum`         VARCHAR(20) NOT NULL DEFAULT 'bekliyor',
                `sonuc_json`    JSON NULL,
                `deneme`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `hata`          VARCHAR(255) NULL DEFAULT NULL,
                `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`    TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_parca_sira` (`analiz_id`, `sira`),
                KEY `idx_parca_durum` (`analiz_id`, `durum`),
                CONSTRAINT `fk_parca_analiz` FOREIGN KEY (`analiz_id`)
                    REFERENCES `transkript_analizler` (`id`) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci"
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `transkript_parcalar`');
    }
};
