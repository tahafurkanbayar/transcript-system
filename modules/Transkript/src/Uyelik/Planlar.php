<?php
/**
 * =====================================================================
 *  Planlar – kullanıcının üyelik planı ve o planın hakları
 * ---------------------------------------------------------------------
 *  Kullanıcı isteği (2026-10-10): "Üye ücretsiz ve kısıtlı işlem yapar,
 *  editör ücretli üyeliktir; limitleri daha yüksektir."
 *
 *  PLAN = ROL. Ayrı bir plan sütunu yoktur; çekirdeğin rolü okunur:
 *      uye     → Ücretsiz plan
 *      editor  → Pro plan (ücretli; süresi Uyelik\UyelikYonetimi'nde)
 *      admin   → plan yok; analiz.yonetici_muaf açıksa kişisel sınır yok
 *
 *  Sınırlar ve özellikler config.php → uyelik.planlar'dan gelir, yönetici
 *  panelden değiştirir (PanelAyarlari). Bu sınıf yalnız OKUR; sayaçlar
 *  AnalizBaslatici'de, yetki kararları TranskriptPolicy'dedir. Hepsi aynı
 *  haklar() dizisine bakar: liste, Üyeliğim sayfası, ana sayfadaki plan
 *  tablosu ve API aynı sayıyı söyler.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Uyelik;

use App\Models\Role;
use App\Models\User;
use Modules\Transkript\Ayarlar;

final class Planlar
{
    public const UCRETSIZ = Role::MEMBER;
    public const PRO      = Role::EDITOR;

    /** Plan anahtarı → ekranda görünen ad (sıra: plan tablosunun sütunları). */
    public const ADLAR = [self::UCRETSIZ => 'Ücretsiz', self::PRO => 'Pro'];

    /** Para birimi → simge. */
    public const PARA_BIRIMLERI = ['TL' => '₺', 'USD' => '$', 'EUR' => '€'];

    /** Kullanıcının planı; yönetici, misafir ve bilinmeyen rol null. */
    public static function plan(?User $user): ?string
    {
        return match ($user?->rol) {
            Role::MEMBER => self::UCRETSIZ,
            Role::EDITOR => self::PRO,
            default      => null,
        };
    }

    public static function ad(?string $plan): string
    {
        return $plan === null ? 'Yönetici' : (self::ADLAR[$plan] ?? $plan);
    }

    /**
     * Planın ayarları (config.php + panel). Pro her zaman analiz başlatır
     * (rolün transkript.analyze yetkisi vardır; module.json).
     *
     * @return array{analiz: bool, gunluk_sinir: int, saatlik_sinir: int, en_uzun_dk: int, yeniden_analiz: bool, altyazi_indir: bool, fiyat_aylik: float, fiyat_yillik: float}
     */
    public static function tanim(string $plan): array
    {
        $yol = 'uyelik.planlar.' . $plan . '.';

        return [
            'analiz'         => $plan === self::PRO || (bool) Ayarlar::get($yol . 'analiz', false),
            'gunluk_sinir'   => max(0, (int) Ayarlar::get($yol . 'gunluk_sinir', 0)),
            'saatlik_sinir'  => max(0, (int) Ayarlar::get($yol . 'saatlik_sinir', 0)),
            'en_uzun_dk'     => max(0, (int) Ayarlar::get($yol . 'en_uzun_dk', 0)),
            'yeniden_analiz' => (bool) Ayarlar::get($yol . 'yeniden_analiz', false),
            'altyazi_indir'  => (bool) Ayarlar::get($yol . 'altyazi_indir', false),
            'fiyat_aylik'    => max(0.0, (float) Ayarlar::get($yol . 'fiyat_aylik', 0)),
            'fiyat_yillik'   => max(0.0, (float) Ayarlar::get($yol . 'fiyat_yillik', 0)),
        ];
    }

    /**
     * Kullanıcının hakları: planın sınırları, yönetici muafiyeti ve
     * sistemin en uzun video tavanı birlikte. Yönetici muaf değilse
     * (analiz.yonetici_muaf = false) Pro sınırlarına tabidir.
     *
     * @return array{plan: ?string, ad: string, muaf: bool, analiz: bool, gunluk_sinir: int, saatlik_sinir: int, en_uzun_dk: int, yeniden_analiz: bool, altyazi_indir: bool}
     */
    public static function haklar(?User $user): array
    {
        $plan     = self::plan($user);
        $yonetici = $user !== null && $plan === null && $user->can('transkript.admin');
        $sistem   = max(0, (int) Ayarlar::get('analiz.en_uzun_dk', 180));

        if ($plan === null && !$yonetici) {
            return ['plan' => null, 'ad' => '', 'muaf' => false, 'analiz' => false, 'gunluk_sinir' => 0, 'saatlik_sinir' => 0,
                'en_uzun_dk' => $sistem, 'yeniden_analiz' => false, 'altyazi_indir' => false];
        }

        $muaf  = $yonetici && (bool) Ayarlar::get('analiz.yonetici_muaf', true);
        $tanim = self::tanim($plan ?? self::PRO);

        return [
            'plan'           => $plan,
            'ad'             => self::ad($plan),
            'muaf'           => $muaf,
            'analiz'         => $yonetici || $tanim['analiz'],
            'gunluk_sinir'   => $muaf ? 0 : $tanim['gunluk_sinir'],
            'saatlik_sinir'  => $muaf ? 0 : $tanim['saatlik_sinir'],
            'en_uzun_dk'     => $muaf ? $sistem : self::etkinEnUzunDk($tanim['en_uzun_dk'], $sistem),
            'yeniden_analiz' => $yonetici || $tanim['yeniden_analiz'],
            'altyazi_indir'  => $yonetici || $tanim['altyazi_indir'],
        ];
    }

    /**
     * Planın süre sınırı sistemin tavanını aşamaz. 0 = sınır yok; ikisi
     * de 0 ise sınırsız. Saf (test edilir).
     */
    public static function etkinEnUzunDk(int $plan, int $sistem): int
    {
        $plan   = max(0, $plan);
        $sistem = max(0, $sistem);

        return match (true) {
            $plan === 0   => $sistem,
            $sistem === 0 => $plan,
            default       => min($plan, $sistem),
        };
    }

    public static function paraBirimi(): string
    {
        $kod = strtoupper((string) Ayarlar::get('uyelik.para_birimi', 'TL'));

        return isset(self::PARA_BIRIMLERI[$kod]) ? $kod : 'TL';
    }

    /**
     * "149 ₺ / ay", "1.490 ₺ / yıl"; fiyat girilmemişse boş metin.
     *
     * @param 'aylik'|'yillik' $donem
     */
    public static function fiyatMetni(string $plan, string $donem = 'aylik'): string
    {
        $tutar = self::tanim($plan)['fiyat_' . $donem] ?? 0.0;

        return $tutar > 0 ? self::tutar($tutar) . ' / ' . ($donem === 'yillik' ? 'yıl' : 'ay') : '';
    }

    /** 149 → "149 ₺", 149.9 → "149,90 ₺" (para birimi panelden). */
    public static function tutar(float $tutar, ?string $paraBirimi = null): string
    {
        $kod   = $paraBirimi !== null && isset(self::PARA_BIRIMLERI[$paraBirimi]) ? $paraBirimi : self::paraBirimi();
        $metin = number_format($tutar, fmod($tutar, 1.0) === 0.0 ? 0 : 2, ',', '.');

        return $kod === 'TL' ? $metin . ' ₺' : self::PARA_BIRIMLERI[$kod] . $metin;
    }

    /** 30 → "30 dk", 180 → "3 saat", 90 → "1 sa 30 dk", 0 → "Sınırsız". */
    public static function sureMetni(int $dakika): string
    {
        return match (true) {
            $dakika <= 0       => 'Sınırsız',
            $dakika < 60       => $dakika . ' dk',
            $dakika % 60 === 0 => intdiv($dakika, 60) . ' saat',
            default            => intdiv($dakika, 60) . ' sa ' . ($dakika % 60) . ' dk',
        };
    }

    /**
     * Plan karşılaştırma tablosu: satır başına bir özellik, her plan için
     * metin ya da evet / hayır. Ana sayfa, Üyeliğim ve yönetici ekranı
     * aynı tabloyu çizer (views/_parcalar/plan-tablosu.php).
     *
     * @return list<array{etiket: string, aciklama: string, degerler: array<string, string|bool>}>
     */
    public static function karsilastirma(): array
    {
        $sistem = max(0, (int) Ayarlar::get('analiz.en_uzun_dk', 180));
        $t      = [];

        foreach (array_keys(self::ADLAR) as $plan) {
            $t[$plan] = self::tanim($plan);
        }

        $sinir = static fn (array $p, string $alan): string|bool => !$p['analiz'] ? false
            : ($p[$alan] === 0 ? 'Sınırsız' : (string) $p[$alan]);
        $satir = static fn (string $etiket, string $aciklama, callable $deger): array => [
            'etiket' => $etiket, 'aciklama' => $aciklama, 'degerler' => array_map($deger, $t),
        ];

        // API: çekirdeğin rol yetkisi (profile.api), plan ayarı değil.
        $api = [];

        foreach (array_keys(self::ADLAR) as $plan) {
            $api[$plan] = Role::can($plan, 'profile.api');
        }

        return [
            $satir('Raporları okuma', 'Kütüphanenizdeki ve sizinle paylaşılan raporlar', static fn (): bool => true),
            $satir('Önceden analiz edilmiş videolar', 'Başkası analiz ettiyse rapor hemen açılır; hakkınızdan düşmez', static fn (): bool => true),
            $satir('Günlük yeni analiz', 'Gün içinde yapay zekâya gönderebileceğiniz video', static fn (array $p): string|bool => $sinir($p, 'gunluk_sinir')),
            $satir('Saatlik yeni analiz', 'Kısa sürede art arda gönderim sınırı', static fn (array $p): string|bool => $sinir($p, 'saatlik_sinir')),
            $satir('En uzun video', 'Bundan uzun videolar analiz edilmez', static fn (array $p): string|bool => !$p['analiz'] ? false
                : self::sureMetni(self::etkinEnUzunDk($p['en_uzun_dk'], $sistem))),
            $satir('Yeniden analiz', 'Kendi raporunuzu güncel modelle baştan üretmek', static fn (array $p): bool => $p['analiz'] && $p['yeniden_analiz']),
            $satir('Rapor ve metin indirme', 'PDF ve Markdown rapor, düz metin transcript', static fn (): bool => true),
            $satir('Altyazı indirme', 'SRT ve VTT (video düzenleyiciler için)', static fn (array $p): bool => $p['altyazi_indir']),
            ['etiket' => 'API erişimi', 'aciklama' => 'Kendi uygulamanızdan analiz başlatmak (Bearer anahtar)', 'degerler' => $api],
        ];
    }
}
