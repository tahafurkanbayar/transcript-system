<?php
/**
 * =====================================================================
 *  MIGRATION: transkript_kisisel_ozetler (Transkript modülü)
 * ---------------------------------------------------------------------
 *  "Sana özel" bölümü. Analiz herkes için ortak cache'tir, kişisel özet
 *  ise kullanıcıya özeldir; bu yüzden ayrı tabloda. İlgi alanları
 *  değişince yalnız bu satır yeniden üretilir.
 *
 *  kullanici_id için yabancı anahtar yok (bkz. transkript_kaynaklar).
 * =====================================================================
 */

declare(strict_types=1);

return new class extends App\Core\Database\Migration
{
    public function up(): void
    {
        $this->execute(
            "CREATE TABLE IF NOT EXISTS `transkript_kisisel_ozetler` (
                `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `analiz_id`            INT UNSIGNED NOT NULL,
                `kullanici_id`         INT UNSIGNED NOT NULL,
                `ozet`                 TEXT NULL,
                `ilgili_bolumler_json` JSON NULL,
                `created_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`           TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_kisisel_ozet` (`analiz_id`, `kullanici_id`),
                KEY `idx_kisisel_kullanici` (`kullanici_id`),
                CONSTRAINT `fk_kisisel_analiz` FOREIGN KEY (`analiz_id`)
                    REFERENCES `transkript_analizler` (`id`) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci"
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `transkript_kisisel_ozetler`');
    }
};
