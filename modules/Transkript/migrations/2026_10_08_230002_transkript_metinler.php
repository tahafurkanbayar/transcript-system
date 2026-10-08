<?php
/**
 * =====================================================================
 *  MIGRATION: transkript_metinler (Transkript modülü)
 * ---------------------------------------------------------------------
 *  Bir kaynağın transcript sürümü: hangi sağlayıcıdan, hangi dilde,
 *  otomatik altyazı mı. Segmentler transkript_segmentler'dedir.
 *
 *  saglayici VARCHAR (ENUM değil): youtube_elle, youtube_otomatik,
 *  gemini, harici, asr. Yeni sağlayıcı eklemek şema değişikliği
 *  gerektirmesin; geçerli değerler kodda (TranscriptProviderInterface).
 * =====================================================================
 */

declare(strict_types=1);

return new class extends App\Core\Database\Migration
{
    public function up(): void
    {
        $this->execute(
            "CREATE TABLE IF NOT EXISTS `transkript_metinler` (
                `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `kaynak_id`       INT UNSIGNED NOT NULL,
                `saglayici`       VARCHAR(30) NOT NULL,
                `dil`             VARCHAR(10) NOT NULL DEFAULT '',
                `otomatik_mi`     TINYINT(1) NOT NULL DEFAULT 0,
                `segment_sayisi`  INT UNSIGNED NOT NULL DEFAULT 0,
                `karakter_sayisi` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_metin_kaynak` (`kaynak_id`),
                CONSTRAINT `fk_metin_kaynak` FOREIGN KEY (`kaynak_id`)
                    REFERENCES `transkript_kaynaklar` (`id`) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci"
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `transkript_metinler`');
    }
};
