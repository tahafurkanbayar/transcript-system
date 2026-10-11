<?php
/**
 * =====================================================================
 *  GirisSiniri – sağlayıcı başına en büyük istek (yaklaşık giriş token'ı)
 * ---------------------------------------------------------------------
 *  Uzun videonun birleştirme isteği Groq'a sığmıyordu (ücretsiz katman:
 *  dakikada 8000 token); Groq her seferinde 413 verdi, zincir "meşgul"
 *  sanıp aynı isteği 1–2 dakikada bir yeniden gönderdi (analiz 3910,
 *  cowork testi 2026-10-11). Artık:
 *
 *    · İsteğin boyutu tahmin edilir (karakter / parca.karakter_basina_token).
 *    · Sağlayıcının sınırı: .env <AD>_MAX_INPUT_TOKENS, yoksa config
 *      ai.giris_siniri.<ad>; 0 = bilinmiyor / sınır yok.
 *    · ÖĞRENİLEN SINIR: sağlayıcı 413 ya da "context length" hatası verirse
 *      o isteğin boyutunun %80'i (GUVENLIK_PAYI) bu sağlayıcı + model için
 *      üst sınır olur (7 gün; "Dinlenmeleri sıfırla" siler). Aynı boyut o
 *      sağlayıcıya yeniden gitmez: birleştirme önce küçük gruplara böler
 *      (cowork 2. tur: Groq'a art arda üç 413 gitmişti). Sağlayıcı
 *      dinlenmez, öteki işler için "Hazır" kalır.
 *    · DAKİKALIK SINIR: Groq ücretsiz katmanın 413'ü "tokens per minute
 *      (TPM): Limit 8000, Requested N" der. "Requested" isteğin kendisinden
 *      ~8.400 fazla çıkıyor (canlı, 2026-10-11: 2.050 → 10.438, 6.325 →
 *      14.724); dakika boşken aynı boyut geçebiliyor. Sınır yine %80
 *      öğrenilir ama 1 saatlik (kalıcı bir bağlam sınırı değil), ve
 *      sağlayıcı 60 sn dinlenir: sıradaki küçük istek dolu dakikaya gitmez.
 *
 *  Kayıt DinlenmeDeposu dosyasında "sinir:<ad>" anahtarıyla durur (bütün
 *  işçiler görür). Yedek zinciri sığmayan halkayı atlar (YedekliSaglayici);
 *  birleştirme sığmayan girdiyi kademeli birleştirir (AnalizBirlestir).
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Ai;

use App\Core\Env;
use Modules\Transkript\Ayarlar;

final class GirisSiniri
{
    /** Öğrenilen sınırın ömrü (sn): katman yükseltilirse kendiliğinden düşsün. */
    public const OGRENME_SN = 7 * 86400;

    /** Dakikalık (TPM) 413'ten öğrenilen sınırın ömrü ve sağlayıcının dinlenmesi (sn). */
    public const DAKIKALIK_OGRENME_SN = 3600;
    public const DAKIKALIK_DINLENME_SN = 60;

    /** Reddedilen isteğin bu oranı sınır olur (tahmin payı: Türkçe metinde token sayımı şaşabiliyor). */
    public const GUVENLIK_PAYI = 0.8;

    private const ONEK = 'sinir:';

    /**
     * İsteğin yaklaşık token sayısı (sistem + bütün mesajlar).
     *
     * @param list<array{role?:string, content?:string}> $mesajlar
     */
    public static function tahmin(string $sistem, array $mesajlar): int
    {
        $karakter = mb_strlen($sistem);

        foreach ($mesajlar as $mesaj) {
            $karakter += mb_strlen((string) ($mesaj['content'] ?? ''));
        }

        return self::metinBoyutu($karakter);
    }

    /** Karakter sayısından (ya da metinden) token tahmini. */
    public static function metinBoyutu(int|string $metin): int
    {
        $karakter = is_int($metin) ? $metin : mb_strlen($metin);
        $oran     = max(1.0, (float) Ayarlar::get('parca.karakter_basina_token', 3.5));

        return (int) ceil($karakter / $oran);
    }

    /** Yapılandırılan sınır: .env <AD>_MAX_INPUT_TOKENS, yoksa config; 0 = sınır yok. */
    public static function yapilandirilan(string $ad): int
    {
        $ortam = trim(Env::get(strtoupper(str_replace('-', '_', $ad)) . '_MAX_INPUT_TOKENS', ''));

        if ($ortam !== '' && ctype_digit($ortam)) {
            return (int) $ortam;
        }

        return max(0, (int) (((array) Ayarlar::get('ai.giris_siniri', []))[$ad] ?? 0));
    }

    /**
     * Bu sağlayıcı + model için öğrenilen sınır (bu boyuta kadar gönderilir);
     * yoksa, süresi geçtiyse ya da model değiştiyse null.
     */
    public static function ogrenilen(string $ad, string $model): ?int
    {
        $kayit = DinlenmeDeposu::al(self::ONEK . $ad);

        if ($kayit === null || (string) ($kayit['model'] ?? '') !== $model || (int) ($kayit['sakla'] ?? 0) <= time()) {
            return null;
        }

        // 0.9.1 kaydı yalnız reddedilen boyutu tutuyordu.
        return max(1, isset($kayit['sinir']) ? (int) $kayit['sinir'] : (int) ($kayit['boyut'] ?? 0) - 1);
    }

    /**
     * Geçerli sınır: yapılandırılan, modelin bağlamı (EVREN: "auto" 32K,
     * EvrenProvider::girisSiniri) ve öğrenilenin küçüğü; 0 = sınır yok.
     */
    public static function sinir(string $ad, string $model): int
    {
        $modelSiniri = $ad === 'evren' ? EvrenProvider::girisSiniri($model) : 0;
        $sinirlar    = array_filter([self::yapilandirilan($ad), $modelSiniri, self::ogrenilen($ad, $model) ?? 0], static fn (int $s): bool => $s > 0);

        return $sinirlar === [] ? 0 : min($sinirlar);
    }

    /** İstek bu sağlayıcıya sığar mı? */
    public static function sigar(string $ad, string $model, int $boyut): bool
    {
        $sinir = self::sinir($ad, $model);

        return $sinir === 0 || $boyut <= $sinir;
    }

    /**
     * Reddedilen isteğin %80'ini sınır olarak kaydeder (eskisi daha küçükse
     * eskisi kalır). Dakikalık (TPM) 413'te kayıt 1 saat yaşar.
     *
     * @return int geçerli öğrenilen sınır
     */
    public static function ogren(string $ad, string $model, int $boyut, ?int $simdi = null, bool $dakikalik = false): int
    {
        $eski  = self::ogrenilen($ad, $model);
        $sinir = max(1, (int) floor($boyut * self::GUVENLIK_PAYI));

        if ($boyut <= 0 || ($eski !== null && $eski <= $sinir)) {
            return $eski ?? 0;
        }

        DinlenmeDeposu::yaz(self::ONEK . $ad, [
            'sinir' => $sinir,
            'istek' => $boyut,
            'model' => $model,
            'zaman' => $simdi ?? time(),
            'sakla' => time() + ($dakikalik ? self::DAKIKALIK_OGRENME_SN : self::OGRENME_SN),
        ]);

        return $sinir;
    }

    public static function unut(string $ad): void
    {
        DinlenmeDeposu::sil(self::ONEK . $ad);
    }

    /** Hata "istek bu sağlayıcıya büyük" mü (413 ya da bağlam aşımı)? */
    public static function buyukIstekHatasi(AiHatasi $e): bool
    {
        return $e->kod === 'cok_buyuk';
    }

    /** 413 dakikalık token kotasından mı (Groq: "tokens per minute (TPM)")? */
    public static function dakikalik(string $mesaj): bool
    {
        return (bool) preg_match('/per\s+minute|\bTPM\b/i', $mesaj);
    }
}
