<?php
/**
 * =====================================================================
 *  MIGRATION: analizin bekleme süresi (Transkript modülü)
 * ---------------------------------------------------------------------
 *  transkript_analizler.bekleme_sn
 *      İlk adım başladıktan sonra analizin İŞLENMEDEN geçirdiği süre:
 *      yapay zekâ dinlenirken ertelenen süre (TranskriptIsi::erteleyebilir)
 *      ve geçici hatayla durup "kaldığı yerden" sürdürülene kadar geçen
 *      süre (AnalizRepository::devamEttir). Kontrol Paneli "Son analizler"
 *      "X sürdü" yazarken bunu düşer, "Y sırada bekledi" diye ayrı yazar
 *      (cowork 6. tur K6: 6 dakikalık video "49:27 sürdü" görünüyordu).
 *      Eski kayıtlar 0 ile başlar.
 * =====================================================================
 */

declare(strict_types=1);

return new class extends App\Core\Database\Migration
{
    public function up(): void
    {
        if (!$this->columnExists('transkript_analizler', 'bekleme_sn')) {
            $this->execute('ALTER TABLE `transkript_analizler` ADD COLUMN `bekleme_sn` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `bitti_at`');
        }
    }

    public function down(): void
    {
        if ($this->columnExists('transkript_analizler', 'bekleme_sn')) {
            $this->execute('ALTER TABLE `transkript_analizler` DROP COLUMN `bekleme_sn`');
        }
    }
};
