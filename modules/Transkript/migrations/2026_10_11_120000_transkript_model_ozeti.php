<?php
/**
 * =====================================================================
 *  MIGRATION: raporun bütün modelleri (Transkript modülü)
 * ---------------------------------------------------------------------
 *  Rapor kutusunda yalnız birleştirme modeli görünüyordu; bölümleri başka
 *  model yazmış olabilir (EVREN "auto", yedek zinciri). Cowork 2. test
 *  turu, 2026-10-11.
 *
 *  transkript_parcalar.saglayici, .model
 *      Bölümü analiz eden sağlayıcı ("evren") ve yanıttaki model
 *      ("auto → qwen/qwen3.8-flash-next"). Eski parçalarda NULL.
 *
 *  transkript_analizler.modeller
 *      Aşama başına modeller, JSON: {"bolumler": [{"saglayici", "model",
 *      "adet"}], "ara": [...], "birlestirme": [...]} (Services\ModelOzeti).
 *      Eski raporlarda NULL: rapor "model" sütununu gösterir.
 * =====================================================================
 */

declare(strict_types=1);

return new class extends App\Core\Database\Migration
{
    public function up(): void
    {
        if (!$this->columnExists('transkript_parcalar', 'saglayici')) {
            $this->execute('ALTER TABLE `transkript_parcalar` ADD COLUMN `saglayici` VARCHAR(50) NULL DEFAULT NULL AFTER `sonuc_json`');
        }

        if (!$this->columnExists('transkript_parcalar', 'model')) {
            $this->execute('ALTER TABLE `transkript_parcalar` ADD COLUMN `model` VARCHAR(150) NULL DEFAULT NULL AFTER `saglayici`');
        }

        if (!$this->columnExists('transkript_analizler', 'modeller')) {
            $this->execute('ALTER TABLE `transkript_analizler` ADD COLUMN `modeller` TEXT NULL DEFAULT NULL AFTER `model`');
        }
    }

    public function down(): void
    {
        if ($this->columnExists('transkript_analizler', 'modeller')) {
            $this->execute('ALTER TABLE `transkript_analizler` DROP COLUMN `modeller`');
        }

        if ($this->columnExists('transkript_parcalar', 'model')) {
            $this->execute('ALTER TABLE `transkript_parcalar` DROP COLUMN `model`');
        }

        if ($this->columnExists('transkript_parcalar', 'saglayici')) {
            $this->execute('ALTER TABLE `transkript_parcalar` DROP COLUMN `saglayici`');
        }
    }
};
