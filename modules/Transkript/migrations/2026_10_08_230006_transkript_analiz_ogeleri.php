<?php
/**
 * =====================================================================
 *  MIGRATION: transkript_analiz_ogeleri (Transkript modülü)
 * ---------------------------------------------------------------------
 *  Raporun maddeleri: konu, önemli nokta, iddia, fikir, teknik bilgi,
 *  zaman damgası. Hepsi aynı biçimde (metin + zaman + önem) olduğu
 *  için tek tabloda, "tur" sütunuyla tutulur.
 *
 *  FULLTEXT(baslik, aciklama): sohbette ilgili maddeyi bulma ve
 *  analiz edilmiş kayıtlarda konu arama.
 * =====================================================================
 */

declare(strict_types=1);

return new class extends App\Core\Database\Migration
{
    public function up(): void
    {
        $this->execute(
            "CREATE TABLE IF NOT EXISTS `transkript_analiz_ogeleri` (
                `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `analiz_id`    INT UNSIGNED NOT NULL,
                `tur`          ENUM('konu','nokta','iddia','fikir','teknik','zaman') NOT NULL,
                `baslik`       VARCHAR(500) NOT NULL DEFAULT '',
                `aciklama`     TEXT NULL,
                `baslangic_ms` INT UNSIGNED NULL DEFAULT NULL,
                `bitis_ms`     INT UNSIGNED NULL DEFAULT NULL,
                `onem`         ENUM('kritik','onemli','faydali','dusuk') NULL DEFAULT NULL,
                `kategori`     VARCHAR(100) NULL DEFAULT NULL,
                `dogrulama`    VARCHAR(30) NULL DEFAULT NULL,
                `sira`         INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `idx_oge_tur` (`analiz_id`, `tur`, `sira`),
                FULLTEXT KEY `ft_oge_metin` (`baslik`, `aciklama`),
                CONSTRAINT `fk_oge_analiz` FOREIGN KEY (`analiz_id`)
                    REFERENCES `transkript_analizler` (`id`) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci"
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `transkript_analiz_ogeleri`');
    }
};
