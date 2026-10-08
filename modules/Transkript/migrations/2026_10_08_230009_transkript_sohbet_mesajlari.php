<?php
/**
 * =====================================================================
 *  MIGRATION: transkript_sohbet_mesajlari (Transkript modülü)
 * ---------------------------------------------------------------------
 *  Sohbet mesajları. atif_ms_json: cevabın dayandığı segmentlerin
 *  başlangıç milisaniyeleri; arayüzde tıklanabilir zaman damgası olur.
 *  Token ve maliyet mesaj başına da tutulur (toplamı ai_kullanim_kaydi).
 * =====================================================================
 */

declare(strict_types=1);

return new class extends App\Core\Database\Migration
{
    public function up(): void
    {
        $this->execute(
            "CREATE TABLE IF NOT EXISTS `transkript_sohbet_mesajlari` (
                `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `sohbet_id`    INT UNSIGNED NOT NULL,
                `rol`          ENUM('kullanici','asistan') NOT NULL,
                `icerik`       TEXT NOT NULL,
                `atif_ms_json` JSON NULL,
                `giris_token`  INT UNSIGNED NOT NULL DEFAULT 0,
                `cikis_token`  INT UNSIGNED NOT NULL DEFAULT 0,
                `maliyet_usd`  DECIMAL(12,8) NOT NULL DEFAULT 0,
                `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_mesaj_sohbet` (`sohbet_id`, `id`),
                CONSTRAINT `fk_mesaj_sohbet` FOREIGN KEY (`sohbet_id`)
                    REFERENCES `transkript_sohbetler` (`id`) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci"
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `transkript_sohbet_mesajlari`');
    }
};
