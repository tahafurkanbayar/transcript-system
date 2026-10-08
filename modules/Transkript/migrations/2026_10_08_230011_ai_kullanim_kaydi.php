<?php
/**
 * =====================================================================
 *  MIGRATION: ai_kullanim_kaydi (Transkript modülü açar, ad GENEL)
 * ---------------------------------------------------------------------
 *  Her AI / ASR / harici transcript çağrısı bir satır yazar: model,
 *  token, maliyet, süre, sonuç. Fiyat sağlayıcının döndürdüğü gerçek
 *  token sayılarından hesaplanır (Ai/ PRICING + priceFor()).
 *
 *  Adı "transkript_" ile başlamaz: ileride başka modüller de yazsın
 *  diye. Bu yüzden down() tabloyu yalnızca içinde başka modülün satırı
 *  yoksa siler; varsa yalnız bu modülün satırlarını temizler.
 * =====================================================================
 */

declare(strict_types=1);

return new class extends App\Core\Database\Migration
{
    public function up(): void
    {
        $this->execute(
            "CREATE TABLE IF NOT EXISTS `ai_kullanim_kaydi` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `modul`       VARCHAR(50) NOT NULL,
                `ref_tur`     VARCHAR(50) NULL DEFAULT NULL,
                `ref_id`      INT UNSIGNED NULL DEFAULT NULL,
                `saglayici`   VARCHAR(50) NOT NULL,
                `model`       VARCHAR(100) NOT NULL DEFAULT '',
                `giris_token` INT UNSIGNED NOT NULL DEFAULT 0,
                `cikis_token` INT UNSIGNED NOT NULL DEFAULT 0,
                `maliyet_usd` DECIMAL(12,8) NOT NULL DEFAULT 0,
                `sure_ms`     INT UNSIGNED NOT NULL DEFAULT 0,
                `durum`       VARCHAR(20) NOT NULL,
                `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_ai_modul_tarih` (`modul`, `created_at`),
                KEY `idx_ai_ref` (`ref_tur`, `ref_id`),
                KEY `idx_ai_tarih` (`created_at`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci"
        );
    }

    public function down(): void
    {
        if (!$this->tableExists('ai_kullanim_kaydi')) {
            return;
        }

        $baska = $this->db->prepare("SELECT COUNT(*) FROM `ai_kullanim_kaydi` WHERE `modul` <> 'transkript'");
        $baska->execute();

        if ((int) $baska->fetchColumn() > 0) {
            $this->execute("DELETE FROM `ai_kullanim_kaydi` WHERE `modul` = 'transkript'");

            return;
        }

        $this->execute('DROP TABLE IF EXISTS `ai_kullanim_kaydi`');
    }
};
