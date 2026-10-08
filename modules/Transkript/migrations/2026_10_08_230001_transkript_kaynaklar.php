<?php
/**
 * =====================================================================
 *  MIGRATION: transkript_kaynaklar (Transkript modülü)
 * ---------------------------------------------------------------------
 *  Bir YouTube videosu ya da yüklenen ses / video dosyası tek satırdır.
 *  Diğer tablolar yalnızca kaynak_id bilir; kaynağın TÜRÜNÜ bilmez.
 *  UNIQUE(tur, dis_kimlik, sahip_id) aynı zamanda analiz cache'inin
 *  anahtarıdır: YouTube'da sahip_id = 0 (herkese ortak), dosyada
 *  yükleyen kullanıcı.
 *
 *  dis_kimlik ascii_bin: YouTube video kimliği büyük/küçük harfe
 *  duyarlıdır ("aBc…" ile "AbC…" ayrı videolardır). Tablonun _ci
 *  karşılaştırması ikisini aynı sayar ve UNIQUE yanlış videoyu döndürürdü.
 *
 *  sahip_id / olusturan_id için YABANCI ANAHTAR YOK, bilerek (bkz.
 *  modules/Ornek): kurulum sihirbazı "kullanicilar"a bağlı tabloları
 *  görünce aynı veritabanına yeniden kurulumu reddeder.
 * =====================================================================
 */

declare(strict_types=1);

return new class extends App\Core\Database\Migration
{
    public function up(): void
    {
        $this->execute(
            "CREATE TABLE IF NOT EXISTS `transkript_kaynaklar` (
                `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `tur`          ENUM('youtube','ses_dosyasi','video_dosyasi') NOT NULL,
                `dis_kimlik`   VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                `sahip_id`     INT UNSIGNED NOT NULL DEFAULT 0,
                `baslik`       VARCHAR(255) NOT NULL DEFAULT '',
                `kanal_adi`    VARCHAR(255) NULL DEFAULT NULL,
                `kanal_id`     VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
                `yayin_tarihi` DATETIME NULL DEFAULT NULL,
                `sure_sn`      INT UNSIGNED NULL DEFAULT NULL,
                `kucuk_resim`  VARCHAR(500) NULL DEFAULT NULL,
                `dosya_yolu`   VARCHAR(500) NULL DEFAULT NULL,
                `dosya_boyutu` BIGINT UNSIGNED NULL DEFAULT NULL,
                `mime`         VARCHAR(100) NULL DEFAULT NULL,
                `dil`          VARCHAR(10) NULL DEFAULT NULL,
                `durum`        ENUM('normal','gizli','silinmis') NOT NULL DEFAULT 'normal',
                `olusturan_id` INT UNSIGNED NULL DEFAULT NULL,
                `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`   TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_kaynak` (`tur`, `dis_kimlik`, `sahip_id`),
                KEY `idx_kaynak_olusturan` (`olusturan_id`),
                KEY `idx_kaynak_tarih` (`created_at`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci"
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `transkript_kaynaklar`');
    }
};
