<?php
/**
 * =====================================================================
 *  MIGRATION: kademeli birleştirme ve hata metni (Transkript modülü)
 * ---------------------------------------------------------------------
 *  transkript_analizler.ara_sonuclar
 *      Uzun videoda birleştirme girdisi açık sağlayıcıların sınırını
 *      aşınca bölümler gruplar hâlinde ara özete çevrilir (AnalizBirlestir).
 *      Ara özetler burada JSON olarak durur ({"s1:00:00:00-00:26:30": {…}});
 *      iş ertelenip yeniden çalışınca biten grup AI'a yeniden gitmez.
 *      Rapor bitince boşaltılır.
 *
 *  ai_kullanim_kaydi.hata_ayrinti
 *      Başarısız çağrıda sağlayıcının mesajı, kısaltılmış ve anahtarlar
 *      maskeli (en çok 500 karakter). Yalnız Transkript ayarlarında
 *      (yönetici) "Son hata"nın üzerine gelince görünür.
 * =====================================================================
 */

declare(strict_types=1);

return new class extends App\Core\Database\Migration
{
    public function up(): void
    {
        if (!$this->columnExists('transkript_analizler', 'ara_sonuclar')) {
            $this->execute('ALTER TABLE `transkript_analizler` ADD COLUMN `ara_sonuclar` MEDIUMTEXT NULL DEFAULT NULL AFTER `sonuc_json`');
        }

        if (!$this->columnExists('ai_kullanim_kaydi', 'hata_ayrinti')) {
            $this->execute('ALTER TABLE `ai_kullanim_kaydi` ADD COLUMN `hata_ayrinti` VARCHAR(500) NULL DEFAULT NULL AFTER `hata_kodu`');
        }
    }

    public function down(): void
    {
        if ($this->columnExists('ai_kullanim_kaydi', 'hata_ayrinti')) {
            $this->execute('ALTER TABLE `ai_kullanim_kaydi` DROP COLUMN `hata_ayrinti`');
        }

        if ($this->columnExists('transkript_analizler', 'ara_sonuclar')) {
            $this->execute('ALTER TABLE `transkript_analizler` DROP COLUMN `ara_sonuclar`');
        }
    }
};
