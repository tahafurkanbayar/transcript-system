<?php
/**
 * =====================================================================
 *  MIGRATION: transkript_segmentler → arama_metni (Transkript modülü)
 * ---------------------------------------------------------------------
 *  ÖLÇÜLEN SORUN: "ai" araması "AI ajanları" satırını bulmuyordu.
 *  utf8mb4_turkish_ci'de "I"nın küçüğü "ı"dır; AI, API, CI, UI gibi
 *  kısaltmalar küçük harfle aranınca eşleşmez. utf8mb4_unicode_ci bunu
 *  çözer ama bu kez "IŞIK" ile "ışık" eşleşmez. İkisi de yetmiyor.
 *
 *  Çözüm: aranacak metnin Search::trFold() ile katlanmış kopyası
 *  (ı/I/İ → i, ş → s, ğ → g, ü → u, ö → o, ç → c, küçük harf). Sorgu da
 *  aynı katlamadan geçer; vurgulama zaten bu kuralla çalışıyor, arama
 *  ile vurgulama artık aynı şeyi "eşleşme" sayar. FULLTEXT bu sütuna
 *  taşınır.
 *
 *  CHECK (metin = '' OR arama_metni <> ''): segment yazan kod sütunu
 *  doldurmayı unutursa ekleme hata verir. NOT NULL tek başına
 *  yetmiyordu — ÖLÇÜLEN: XAMPP'ın MariaDB'si strict modda değil
 *  (sql_mode'da STRICT_* yok) ve eksik TEXT sütununu sessizce '' ile
 *  dolduruyordu. CHECK'i MariaDB 10.2+ ve MySQL 8.0.16+ uygular.
 *
 *  Var olan satırlar burada doldurulur. Katlama tablosu bilerek
 *  KOPYALANDI: migration o günkü davranışın anlık görüntüsüdür, sonra
 *  Search değişse de bu dosya aynı sonucu üretmeli.
 * =====================================================================
 */

declare(strict_types=1);

return new class extends App\Core\Database\Migration
{
    private const KATLAMA = [
        'İ' => 'i', 'I' => 'i', 'ı' => 'i',
        'Ş' => 's', 'ş' => 's',
        'Ğ' => 'g', 'ğ' => 'g',
        'Ü' => 'u', 'ü' => 'u',
        'Ö' => 'o', 'ö' => 'o',
        'Ç' => 'c', 'ç' => 'c',
    ];

    public function up(): void
    {
        if (!$this->columnExists('transkript_segmentler', 'arama_metni')) {
            $this->execute('ALTER TABLE `transkript_segmentler` ADD COLUMN `arama_metni` TEXT NOT NULL AFTER `metin`');
        }

        $this->doldur();

        if (!$this->constraintExists('chk_segment_arama')) {
            $this->execute(
                "ALTER TABLE `transkript_segmentler`
                    ADD CONSTRAINT `chk_segment_arama` CHECK (`metin` = '' OR `arama_metni` <> '')"
            );
        }

        if ($this->indexExists('transkript_segmentler', 'ft_segment_metin')) {
            $this->execute('ALTER TABLE `transkript_segmentler` DROP INDEX `ft_segment_metin`');
        }

        if (!$this->indexExists('transkript_segmentler', 'ft_segment_arama')) {
            $this->execute('ALTER TABLE `transkript_segmentler` ADD FULLTEXT KEY `ft_segment_arama` (`arama_metni`)');
        }
    }

    public function down(): void
    {
        if ($this->indexExists('transkript_segmentler', 'ft_segment_arama')) {
            $this->execute('ALTER TABLE `transkript_segmentler` DROP INDEX `ft_segment_arama`');
        }

        // Sütunu kullanan kısıt sütundan ÖNCE kaldırılır.
        if ($this->constraintExists('chk_segment_arama')) {
            $this->execute('ALTER TABLE `transkript_segmentler` DROP CONSTRAINT `chk_segment_arama`');
        }

        if ($this->columnExists('transkript_segmentler', 'arama_metni')) {
            $this->execute('ALTER TABLE `transkript_segmentler` DROP COLUMN `arama_metni`');
        }

        if (!$this->indexExists('transkript_segmentler', 'ft_segment_metin')) {
            $this->execute('ALTER TABLE `transkript_segmentler` ADD FULLTEXT KEY `ft_segment_metin` (`metin`)');
        }
    }

    /** CHECK kısıtı var mı? (Migration sınıfında yardımcısı yok.) */
    private function constraintExists(string $ad): bool
    {
        $statement = $this->db->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'transkript_segmentler'
                AND CONSTRAINT_NAME = :kisit"
        );
        $statement->execute([':kisit' => $ad]);

        return (int) $statement->fetchColumn() > 0;
    }

    /** Boş kalan satırları 500'erli doldurur. */
    private function doldur(): void
    {
        $oku = $this->db->prepare(
            "SELECT id, metin FROM `transkript_segmentler`
              WHERE arama_metni = '' AND metin <> '' AND id > :son
              ORDER BY id LIMIT 500"
        );
        $yaz = $this->db->prepare('UPDATE `transkript_segmentler` SET arama_metni = :arama WHERE id = :id');
        $son = 0;

        while (true) {
            $oku->execute([':son' => $son]);
            $satirlar = $oku->fetchAll(PDO::FETCH_ASSOC);

            if ($satirlar === []) {
                return;
            }

            foreach ($satirlar as $satir) {
                $son = (int) $satir['id'];
                $yaz->execute([
                    ':arama' => mb_strtolower(strtr((string) $satir['metin'], self::KATLAMA), 'UTF-8'),
                    ':id'    => $son,
                ]);
            }
        }
    }
};
