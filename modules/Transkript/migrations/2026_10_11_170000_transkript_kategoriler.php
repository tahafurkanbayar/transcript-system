<?php
/**
 * =====================================================================
 *  MIGRATION: video kategorisi (Transkript modülü, 0.11.0)
 * ---------------------------------------------------------------------
 *  Kullanıcı isteği (11.10.2026): videonun kategorisi ve konusu
 *  veritabanında dursun; ileride türe göre başka içerikler (ders
 *  videosundan soru / test, tanıtımdan makale …) üretilecek.
 *
 *  transkript_kategoriler — KAYNAK (video) başına bir satır
 *      icerik_turu   biçim: ders_anlatimi, vlog, haber … (Kategori\Kategoriler::TURLER)
 *      konu_alani    ana konu: teknoloji, spor, siyaset … (ALANLAR)
 *      ikincil_alan  varsa ikinci alan
 *      seviye        genel / baslangic / orta / ileri
 *      ana_konu      tek satır konu
 *      etiketler     JSON dizi, en çok 8 anahtar kelime
 *      belirleyen    ai (Jobs\KategoriBelirle) | elle (yönetici düzeltti;
 *                    yeniden analizde AI üzerine yazmaz)
 *      analiz_id     hangi analizin raporundan çıkarıldı
 *      istem_surumu, model: hangi istem ve modelle
 *
 *  Yalnız yeni tablo; mevcut kaynak / analiz / rapor satırlarına dokunmaz.
 *  Var olan raporların kategorisi `bin/kategori-doldur.php` ile doldurulur.
 *  down(): tabloyu siler.
 * =====================================================================
 */

declare(strict_types=1);

return new class extends App\Core\Database\Migration
{
    public function up(): void
    {
        $this->execute(
            "CREATE TABLE IF NOT EXISTS `transkript_kategoriler` (
                `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `kaynak_id`    INT UNSIGNED NOT NULL,
                `analiz_id`    INT UNSIGNED NULL,
                `icerik_turu`  VARCHAR(40) NOT NULL,
                `konu_alani`   VARCHAR(40) NOT NULL,
                `ikincil_alan` VARCHAR(40) NULL,
                `seviye`       VARCHAR(20) NOT NULL DEFAULT 'genel',
                `ana_konu`     VARCHAR(200) NOT NULL DEFAULT '',
                `etiketler`    VARCHAR(1000) NOT NULL DEFAULT '[]',
                `belirleyen`   ENUM('ai', 'elle') NOT NULL DEFAULT 'ai',
                `istem_surumu` VARCHAR(20) NULL,
                `model`        VARCHAR(120) NULL,
                `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_kategori_kaynak` (`kaynak_id`),
                KEY `idx_kategori_tur` (`icerik_turu`),
                KEY `idx_kategori_alan` (`konu_alani`),
                CONSTRAINT `fk_kategori_kaynak` FOREIGN KEY (`kaynak_id`)
                    REFERENCES `transkript_kaynaklar` (`id`) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci"
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `transkript_kategoriler`');
    }
};
