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
 *      o isteğin boyutu bu sağlayıcı + model için üst sınır olur (7 gün;
 *      "Dinlenmeleri sıfırla" siler). Daha büyük istek o sağlayıcıya hiç
 *      gitmez; sağlayıcı dinlenmez, öteki işler için "Hazır" kalır.
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

    /** Bu sağlayıcı + model için öğrenilen sınır; yoksa (ya da model değiştiyse) null. */
    public static function ogrenilen(string $ad, string $model): ?int
    {
        $kayit = DinlenmeDeposu::al(self::ONEK . $ad);

        if ($kayit === null || (string) ($kayit['model'] ?? '') !== $model || (int) ($kayit['sakla'] ?? 0) <= time()) {
            return null;
        }

        return max(1, (int) ($kayit['boyut'] ?? 0));
    }

    /**
     * Geçerli sınır (bu boyuta kadar gönderilir): yapılandırılan ve
     * öğrenilenin bir eksiğinin küçüğü (öğrenilen boyut reddedilmişti);
     * 0 = sınır yok.
     */
    public static function sinir(string $ad, string $model): int
    {
        $ogrenilen = self::ogrenilen($ad, $model);
        $sinirlar  = array_filter([self::yapilandirilan($ad), $ogrenilen !== null ? max(1, $ogrenilen - 1) : 0], static fn (int $s): bool => $s > 0);

        return $sinirlar === [] ? 0 : min($sinirlar);
    }

    /** İstek bu sağlayıcıya sığar mı? */
    public static function sigar(string $ad, string $model, int $boyut): bool
    {
        $sinir = self::sinir($ad, $model);

        return $sinir === 0 || $boyut <= $sinir;
    }

    /** Reddedilen isteğin boyutunu sınır olarak kaydeder (eskisinden büyükse eskisi kalır). */
    public static function ogren(string $ad, string $model, int $boyut, ?int $simdi = null): void
    {
        $eski = self::ogrenilen($ad, $model);

        if ($boyut <= 0 || ($eski !== null && $eski <= $boyut)) {
            return;
        }

        DinlenmeDeposu::yaz(self::ONEK . $ad, [
            'boyut' => $boyut,
            'model' => $model,
            'zaman' => $simdi ?? time(),
            'sakla' => time() + self::OGRENME_SN,
        ]);
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

    /**
     * 413 aslında DAKİKALIK kotanın dolması mı? Groq ücretsiz katmanda
     * "tokens per minute (TPM): Limit 8000, Requested 10438" der; Requested o
     * dakikada harcananı da içerir (canlı ölçüm, 2026-10-11: ~2.000 token'lık
     * istek, bir önceki çağrının hemen ardından 10.438 "istendi"). Requested
     * isteğin kendisinden belirgin büyükse (×1,3) ve istek tek başına sınıra
     * sığıyorsa küçültmek işe yaramaz: dakika dolunca geçer. O zaman hız
     * sınırı gibi davranılır, sınır öğrenilmez. Requested yoksa: istek
     * sınırın %75'inin altındaysa.
     */
    public static function dakikalikDoluluk(string $mesaj, int $boyut): bool
    {
        if ($boyut <= 0 || !preg_match('/per minute.{0,40}?Limit\s+(\d+)/is', $mesaj, $m)) {
            return false;
        }

        $sinir = (int) $m[1];

        if (preg_match('/Requested\s+(\d+)/i', $mesaj, $r)) {
            return $boyut <= $sinir && (int) $r[1] > $boyut * 1.3;
        }

        return $boyut <= $sinir * 0.75;
    }
}
