<?php
/**
 * =====================================================================
 *  MIGRATION: hata ayrıntısı (Transkript modülü)
 * ---------------------------------------------------------------------
 *  transkript_analizler.hata_ozeti
 *      Yapay zekâ yüzünden duran analizde yedek zincirinin özeti:
 *      "Gemini: günlük kota doldu (açılış yarın 10:01) · OpenRouter:
 *      model yok (404) · Mistral: anahtar yok". Yalnız yöneticiye
 *      gösterilir; sağlayıcının kendi mesajını taşımaz.
 *
 *  ai_kullanim_kaydi.hata_kodu
 *      Başarısız çağrının kodu (kota_doldu, rate_limit, model_bulunamadi…).
 *      Transkript ayarlarındaki çağrı günlüğü ve sağlayıcının "son hatası"
 *      buradan okunur. Başarılı çağrıda NULL.
 * =====================================================================
 */

declare(strict_types=1);

return new class extends App\Core\Database\Migration
{
    public function up(): void
    {
        if (!$this->columnExists('transkript_analizler', 'hata_ozeti')) {
            $this->execute('ALTER TABLE `transkript_analizler` ADD COLUMN `hata_ozeti` VARCHAR(500) NULL DEFAULT NULL AFTER `hata_kodu`');
        }

        if (!$this->columnExists('ai_kullanim_kaydi', 'hata_kodu')) {
            $this->execute('ALTER TABLE `ai_kullanim_kaydi` ADD COLUMN `hata_kodu` VARCHAR(40) NULL DEFAULT NULL AFTER `durum`');
        }
    }

    public function down(): void
    {
        if ($this->columnExists('ai_kullanim_kaydi', 'hata_kodu')) {
            $this->execute('ALTER TABLE `ai_kullanim_kaydi` DROP COLUMN `hata_kodu`');
        }

        if ($this->columnExists('transkript_analizler', 'hata_ozeti')) {
            $this->execute('ALTER TABLE `transkript_analizler` DROP COLUMN `hata_ozeti`');
        }
    }
};
