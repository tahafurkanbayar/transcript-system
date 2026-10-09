<?php
/**
 * =====================================================================
 *  MIGRATION: kişisel kütüphane ve paylaşım (Transkript modülü)
 * ---------------------------------------------------------------------
 *  ÖNBELLEK ORTAK, GÖRÜNÜRLÜK KİŞİSEL (cowork 5. tur, kullanıcı kararı).
 *  YouTube kaynağı ve analizi tek kopya kalır (sahip_id = 0); aynı video
 *  ikinci kez yapay zekâya gitmez. Ama raporu herkes değil, yalnız onu
 *  kütüphanesine ekleyen görür:
 *
 *  transkript_kutuphane
 *      kullanıcı ↔ kaynak. ekleme_turu:
 *        baslatti  analizi bu kullanıcı başlattı (ya da yeniden analiz etti)
 *        onbellek  video zaten analiz edilmişti / sürüyordu; AI çağrısı
 *                  yapılmadı, kotadan düşmedi, yalnız kütüphaneye eklendi
 *      "Kütüphanemden çıkar" yalnız bu satırı siler; ortak kayıt kalır.
 *
 *  transkript_kaynaklar.paylasildi
 *      Yöneticinin "Üyelerle paylaş" dediği rapor, kütüphanesinde
 *      olmasa da transkript.view yetkisi olan herkese görünür.
 *
 *  GERİ DOLDURMA: her analiz, başlatanın kütüphanesine "baslatti" olarak
 *  girer. Başlatanı bilinmeyen kaynak oluşturanına, o da yoksa ilk
 *  yöneticiye yazılır. Var olan raporlar PAYLAŞILMAZ (kullanıcı kararı);
 *  yönetici istediğini tek tek paylaşır.
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
            "CREATE TABLE IF NOT EXISTS `transkript_kutuphane` (
                `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `kullanici_id` INT UNSIGNED NOT NULL,
                `kaynak_id`    INT UNSIGNED NOT NULL,
                `ekleme_turu`  ENUM('baslatti', 'onbellek') NOT NULL DEFAULT 'baslatti',
                `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_kutuphane` (`kullanici_id`, `kaynak_id`),
                KEY `idx_kutuphane_kaynak` (`kaynak_id`),
                KEY `idx_kutuphane_tarih` (`kullanici_id`, `created_at`),
                CONSTRAINT `fk_kutuphane_kaynak` FOREIGN KEY (`kaynak_id`)
                    REFERENCES `transkript_kaynaklar` (`id`) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci"
        );

        if (!$this->columnExists('transkript_kaynaklar', 'paylasildi')) {
            $this->execute('ALTER TABLE `transkript_kaynaklar` ADD COLUMN `paylasildi` TINYINT(1) NOT NULL DEFAULT 0 AFTER `durum`');
        }

        // Analizi başlatan → kütüphanesine (ilk analiz tarihiyle).
        $this->execute(
            "INSERT IGNORE INTO `transkript_kutuphane` (`kullanici_id`, `kaynak_id`, `ekleme_turu`, `created_at`)
             SELECT a.`kullanici_id`, a.`kaynak_id`, 'baslatti', MIN(a.`created_at`)
               FROM `transkript_analizler` a
              WHERE a.`kullanici_id` IS NOT NULL AND a.`kullanici_id` > 0
              GROUP BY a.`kullanici_id`, a.`kaynak_id`"
        );

        // Kimsenin kütüphanesinde olmayan YouTube kaynağı: oluşturanı, yoksa ilk yönetici.
        $this->execute(
            "INSERT IGNORE INTO `transkript_kutuphane` (`kullanici_id`, `kaynak_id`, `ekleme_turu`, `created_at`)
             SELECT COALESCE(NULLIF(k.`olusturan_id`, 0),
                             (SELECT MIN(u.`id`) FROM `kullanicilar` u WHERE u.`rol` = 'admin')),
                    k.`id`, 'baslatti', k.`created_at`
               FROM `transkript_kaynaklar` k
              WHERE k.`sahip_id` = 0
                AND NOT EXISTS (SELECT 1 FROM `transkript_kutuphane` ku WHERE ku.`kaynak_id` = k.`id`)
                AND COALESCE(NULLIF(k.`olusturan_id`, 0), (SELECT MIN(u.`id`) FROM `kullanicilar` u WHERE u.`rol` = 'admin')) IS NOT NULL"
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `transkript_kutuphane`');

        if ($this->columnExists('transkript_kaynaklar', 'paylasildi')) {
            $this->execute('ALTER TABLE `transkript_kaynaklar` DROP COLUMN `paylasildi`');
        }
    }
};
