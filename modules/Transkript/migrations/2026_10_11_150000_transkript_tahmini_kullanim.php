<?php
/**
 * =====================================================================
 *  MIGRATION: tahmini kullanım işareti (EVREN geliştirme P1-1, S8)
 * ---------------------------------------------------------------------
 *  ai_kullanim_kaydi.tahmini
 *    1: token sayısı sağlayıcının bildirdiği değil TAHMİN (zaman aşımı ya
 *    da boşta kesilen akışlı çağrı: giriş isteğin boyutundan, çıkış
 *    gelen akış parçalarından). EVREN kesilen istekte de kotadan düşer;
 *    eskiden bu çağrılar 0 token yazılıyordu, kayıt ile kota arasında
 *    fark açılıyordu (11.10.2026: 77.479 kota, 65.103 kayıt).
 *    Mevcut satırlar 0 kalır.
 * =====================================================================
 */

declare(strict_types=1);

return new class extends App\Core\Database\Migration
{
    public function up(): void
    {
        if (!$this->columnExists('ai_kullanim_kaydi', 'tahmini')) {
            $this->execute('ALTER TABLE `ai_kullanim_kaydi` ADD COLUMN `tahmini` TINYINT(1) NOT NULL DEFAULT 0');
        }
    }

    public function down(): void
    {
        if ($this->columnExists('ai_kullanim_kaydi', 'tahmini')) {
            $this->execute('ALTER TABLE `ai_kullanim_kaydi` DROP COLUMN `tahmini`');
        }
    }
};
