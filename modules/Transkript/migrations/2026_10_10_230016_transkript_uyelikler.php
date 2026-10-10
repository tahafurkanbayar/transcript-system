<?php
/**
 * =====================================================================
 *  MIGRATION: Pro üyelik dönemleri ve "Pro'ya geç" talepleri
 * ---------------------------------------------------------------------
 *  Kullanıcı isteği (2026-10-10): üye ücretsiz ve kısıtlı, editör ücretli
 *  üyelik. Plan ROLDEN gelir (Uyelik\Planlar); bu tablolar yalnız Pro'nun
 *  SÜRESİNİ ve ödeme geçmişini tutar.
 *
 *  transkript_uyelikler — bir satır bir ödeme dönemi
 *      Yönetici "Pro ver" ya da "Uzat" dediğinde açılır. Süren dönemi olan
 *      kullanıcının yeni dönemi eskisinin bitişinde başlar (üst üste
 *      eklenir). bitis NULL: süresiz. Bütün aktif dönemleri biten
 *      kullanıcının rolü "uye"ye döner (UyelikYonetimi::suresiDolanlar).
 *      durum: aktif | bitti (süresi doldu) | iptal (yönetici kaldırdı).
 *      hatirlatildi: "bitmesine N gün kaldı" e-postası gitti mi.
 *
 *  transkript_uyelik_talepleri — üyenin "Pro'ya geç" isteği
 *      Kullanıcı başına en çok bir "bekliyor" talep. Yönetici ödemeyi
 *      görünce onaylar (dönem açılır) ya da gerekçesiyle reddeder.
 *
 *  kullanici_id için yabancı anahtar yok (bkz. transkript_kaynaklar).
 *  Silinen hesabın TALEPLERİ gece silinir (HesapTemizligi); dönemler
 *  ödeme kaydıdır, kişi bilgisi taşımaz ve kalır.
 * =====================================================================
 */

declare(strict_types=1);

return new class extends App\Core\Database\Migration
{
    public function up(): void
    {
        $this->execute(
            "CREATE TABLE IF NOT EXISTS `transkript_uyelikler` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `kullanici_id`  INT UNSIGNED NOT NULL,
                `plan`          VARCHAR(20) NOT NULL DEFAULT 'editor',
                `baslangic`     DATETIME NOT NULL,
                `bitis`         DATETIME NULL DEFAULT NULL,
                `durum`         ENUM('aktif', 'bitti', 'iptal') NOT NULL DEFAULT 'aktif',
                `tutar`         DECIMAL(12,2) NULL DEFAULT NULL,
                `para_birimi`   VARCHAR(3) NOT NULL DEFAULT 'TL',
                `odeme_notu`    VARCHAR(255) NOT NULL DEFAULT '',
                `talep_id`      INT UNSIGNED NULL DEFAULT NULL,
                `olusturan_id`  INT UNSIGNED NULL DEFAULT NULL,
                `hatirlatildi`  TINYINT(1) NOT NULL DEFAULT 0,
                `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_uyelik_kullanici` (`kullanici_id`, `durum`),
                KEY `idx_uyelik_bitis` (`durum`, `bitis`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci"
        );

        $this->execute(
            "CREATE TABLE IF NOT EXISTS `transkript_uyelik_talepleri` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `kullanici_id`  INT UNSIGNED NOT NULL,
                `plan`          VARCHAR(20) NOT NULL DEFAULT 'editor',
                `sure_ay`       TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `mesaj`         VARCHAR(500) NOT NULL DEFAULT '',
                `durum`         ENUM('bekliyor', 'onaylandi', 'reddedildi', 'iptal') NOT NULL DEFAULT 'bekliyor',
                `yanit`         VARCHAR(500) NOT NULL DEFAULT '',
                `isleyen_id`    INT UNSIGNED NULL DEFAULT NULL,
                `islendi_at`    DATETIME NULL DEFAULT NULL,
                `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_talep_durum` (`durum`, `created_at`),
                KEY `idx_talep_kullanici` (`kullanici_id`, `durum`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci"
        );
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `transkript_uyelik_talepleri`');
        $this->execute('DROP TABLE IF EXISTS `transkript_uyelikler`');
    }
};
