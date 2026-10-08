<?php
/**
 * =====================================================================
 *  MIGRATION: transkript_ilgi_alanlari (Transkript modülü)
 * ---------------------------------------------------------------------
 *  Kişisel AI filtresinin girdisi. UNIQUE(kullanici_id, ilgi) tablonun
 *  _ci karşılaştırmasıyla çalışır: "PHP" ile "php" aynı ilgi sayılır.
 *
 *  kullanici_id için yabancı anahtar yok (bkz. transkript_kaynaklar).
 * =====================================================================
 */

declare(strict_types=1);

return new class extends App\Core\Database\Migration
{
    public function up(): void
    {
        $this->execute(
            "CREATE TABLE IF NOT EXISTS `transkript_ilgi_alanlari` (
                `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `kullanici_id` INT UNSIGNED NOT NULL,
                `ilgi`         VARCHAR(100) NOT NULL,
                `agirlik`      TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_ilgi` (`kullanici_id`, `ilgi`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci"
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `transkript_ilgi_alanlari`');
    }
};
