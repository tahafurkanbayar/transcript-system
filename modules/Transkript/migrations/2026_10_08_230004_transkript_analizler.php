<?php
/**
 * =====================================================================
 *  MIGRATION: transkript_analizler (Transkript modülü)
 * ---------------------------------------------------------------------
 *  Bir analiz çalışması ve onun raporu. İşin kendisi starter'ın
 *  "isler" tablosundadır; burada durum / aşama / ilerleme tutulur.
 *
 *  durum VARCHAR: bekliyor, transkript_aliniyor, transkript_hazir,
 *  analiz_ediliyor, tamamlandi, hata (geçerli değerler kodda).
 *
 *  Yeniden analizde eski satır silinmez, guncel_mi = 0 olur; prompt
 *  sürümleri karşılaştırılabilir. Cache sorgusu
 *  (kaynak_id, guncel_mi, durum) indeksini kullanır.
 *
 *  metin_id ON DELETE SET NULL: kaynak silinince metin ve analiz ayrı
 *  zincirlerden silinir; RESTRICT olsaydı silme sırası kaynağa göre
 *  değişip hata verebilirdi.
 * =====================================================================
 */

declare(strict_types=1);

return new class extends App\Core\Database\Migration
{
    public function up(): void
    {
        $this->execute(
            "CREATE TABLE IF NOT EXISTS `transkript_analizler` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `kaynak_id`     INT UNSIGNED NOT NULL,
                `metin_id`      INT UNSIGNED NULL DEFAULT NULL,
                `kullanici_id`  INT UNSIGNED NULL DEFAULT NULL,
                `durum`         VARCHAR(30) NOT NULL DEFAULT 'bekliyor',
                `asama`         VARCHAR(30) NULL DEFAULT NULL,
                `ilerleme`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `kisa_ozet`     TEXT NULL,
                `detayli_ozet`  MEDIUMTEXT NULL,
                `sonuc_json`    JSON NULL,
                `prompt_surumu` VARCHAR(20) NULL DEFAULT NULL,
                `model`         VARCHAR(150) NULL DEFAULT NULL,
                `guncel_mi`     TINYINT(1) NOT NULL DEFAULT 1,
                `toplam_token`  INT UNSIGNED NOT NULL DEFAULT 0,
                `maliyet_usd`   DECIMAL(12,8) NOT NULL DEFAULT 0,
                `basladi_at`    DATETIME NULL DEFAULT NULL,
                `bitti_at`      DATETIME NULL DEFAULT NULL,
                `hata_kodu`     VARCHAR(50) NULL DEFAULT NULL,
                `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`    TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_analiz_kaynak` (`kaynak_id`, `guncel_mi`, `durum`),
                KEY `idx_analiz_metin` (`metin_id`),
                KEY `idx_analiz_kullanici` (`kullanici_id`),
                KEY `idx_analiz_durum` (`durum`),
                KEY `idx_analiz_tarih` (`created_at`),
                CONSTRAINT `fk_analiz_kaynak` FOREIGN KEY (`kaynak_id`)
                    REFERENCES `transkript_kaynaklar` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_analiz_metin` FOREIGN KEY (`metin_id`)
                    REFERENCES `transkript_metinler` (`id`) ON DELETE SET NULL
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci"
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `transkript_analizler`');
    }
};
