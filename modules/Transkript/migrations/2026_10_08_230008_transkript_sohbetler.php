<?php
/**
 * =====================================================================
 *  MIGRATION: transkript_sohbetler (Transkript modülü)
 * ---------------------------------------------------------------------
 *  "Kayda sor" sohbeti: kaynak başına, kullanıcı başına. Sohbet yalnız
 *  sahibine ve yöneticiye açıktır (TranskriptPolicy).
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
            "CREATE TABLE IF NOT EXISTS `transkript_sohbetler` (
                `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `kaynak_id`    INT UNSIGNED NOT NULL,
                `kullanici_id` INT UNSIGNED NOT NULL,
                `baslik`       VARCHAR(255) NOT NULL DEFAULT '',
                `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`   TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_sohbet_kaynak` (`kaynak_id`, `kullanici_id`),
                KEY `idx_sohbet_kullanici` (`kullanici_id`),
                CONSTRAINT `fk_sohbet_kaynak` FOREIGN KEY (`kaynak_id`)
                    REFERENCES `transkript_kaynaklar` (`id`) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci"
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `transkript_sohbetler`');
    }
};
