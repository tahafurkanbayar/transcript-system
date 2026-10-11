<?php
/**
 * =====================================================================
 *  EvrenModelleri – EVREN model listesi ve fiyatı (GET /v1/models, günde bir)
 * ---------------------------------------------------------------------
 *  Panel listesi sabit kodluydu (EvrenProvider::MODELLER, S12). Artık:
 *    · Zamanlanmış görev (events.php, günde bir) ve teşhis / ölçüm araçları
 *      GET /v1/models'i okuyup önbelleğe yazar (yenile). Panel isteği
 *      EVREN'e GİTMEZ (CLAUDE.md kural 6); yalnız önbelleği okur.
 *    · Panelde yalnız METİN sohbet modelleri seçilir (task = chat):
 *      görsel (qwen3-vl), OCR, ses, gömme, karar, güvenlik modelleri ve
 *      1 Kasım 2026'da kalkan deepseek-v4-flash listede yoktur.
 *    · Önbellek yoksa ya da API'ye ulaşılamadıysa sabit listeye düşülür.
 *    · Fiyat: pricing.prompt_token_price / completion_token_price (CR /
 *      1M token, "currency": "CR") ve free_until. Ücretsiz dönemde maliyet
 *      0; sonrasında CR fiyatı panelde gösterilir (CR → USD çevrimi
 *      bilinmediği için maliyet_usd'ye yazılmaz).
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Ai;

use App\Core\Cache\Cache;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class EvrenModelleri
{
    public const ONBELLEK = 'transkript:evren_modeller';

    /** Önbellek ömrü (sn): günlük yenileme kaçarsa liste 2 gün daha geçerli. */
    private const OMUR = 3 * 86400;

    /** /v1/models okunamazsa ücretsiz dönemin sonu (EVREN duyurusu). */
    public const UCRETSIZ_SON = '2026-11-01';

    /**
     * Panelin seçenekleri: id => "id — açıklama". Bilinen sıra korunur,
     * yeni modeller sona (auto'dan önce) eklenir.
     *
     * @return array<string,string>
     */
    public static function secenekler(): array
    {
        $liste = [];

        foreach ((array) (self::kayit()['modeller'] ?? []) as $m) {
            $id = is_array($m) ? (string) ($m['id'] ?? '') : '';

            if ($id === '' || ($m['task'] ?? '') !== 'chat' || in_array($id, EvrenProvider::METIN_DISI, true)) {
                continue;
            }

            $liste[$id] = EvrenProvider::MODELLER[$id] ?? self::aciklama($m);
        }

        if ($liste === []) {
            $liste = EvrenProvider::MODELLER;
        }

        $sira = array_flip(array_keys(EvrenProvider::MODELLER));
        uksort($liste, static fn (string $a, string $b): int
            => [$a === 'auto', $sira[$a] ?? 50, $a] <=> [$b === 'auto', $sira[$b] ?? 50, $b]);

        $sonuc = [];

        foreach ($liste as $id => $aciklama) {
            $sonuc[$id] = $id . ' — ' . $aciklama;
        }

        return $sonuc;
    }

    /** @return array{zaman?:int, modeller?:list<array<string,mixed>>} */
    public static function kayit(): array
    {
        try {
            $kayit = Cache::get(self::ONBELLEK);
        } catch (Throwable) {
            return [];
        }

        return is_array($kayit) ? $kayit : [];
    }

    /**
     * GET /v1/models → önbellek. Yalnız komut satırı (zamanlanmış görev,
     * teşhis, ölçüm) çağırır. Başarılıysa model sayısı, değilse null.
     */
    public static function yenile(?EvrenHesap $hesap = null): ?int
    {
        $hesap ??= EvrenHesap::ortamdan();

        if ($hesap === null) {
            return null;
        }

        $yanit = $hesap->modeller();
        $veri  = $yanit->basarili() ? ($yanit->json()['data'] ?? null) : null;

        if (!is_array($veri)) {
            return null;
        }

        $modeller = [];

        foreach ($veri as $m) {
            if (!is_array($m) || !is_string($m['id'] ?? null)) {
                continue;
            }

            $modeller[] = [
                'id'             => $m['id'],
                'task'           => (string) ($m['task'] ?? ''),
                'context_length' => (int) ($m['context_length'] ?? 0),
                'pricing'        => array_intersect_key((array) ($m['pricing'] ?? []), array_flip(['prompt_token_price', 'completion_token_price', 'currency', 'free_until'])),
            ];
        }

        try {
            Cache::put(self::ONBELLEK, ['zaman' => time(), 'modeller' => $modeller], self::OMUR);
        } catch (Throwable) {
            return null;
        }

        return count($modeller);
    }

    /** Ücretsiz dönemin son günü (Y-m-d): /v1/models'te en erken free_until, yoksa 2026-11-01. */
    public static function ucretsizSon(): string
    {
        $tarihler = [];

        foreach ((array) (self::kayit()['modeller'] ?? []) as $m) {
            $tarih = (string) ($m['pricing']['free_until'] ?? '');

            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $tarih)) {
                $tarihler[] = substr($tarih, 0, 10);
            }
        }

        return $tarihler === [] ? self::UCRETSIZ_SON : min($tarihler);
    }

    /** EVREN ücretli döneme geçti mi (free_until gününün başı, İstanbul saati)? */
    public static function ucretliMi(?int $simdi = null): bool
    {
        $sinir = new DateTimeImmutable(self::ucretsizSon() . ' 00:00:00', new DateTimeZone('Europe/Istanbul'));

        return ($simdi ?? time()) >= $sinir->getTimestamp();
    }

    /**
     * Modelin fiyatı (CR / 1M token); bilinmiyorsa null.
     *
     * @return array{giris: float, cikis: float, birim: string}|null
     */
    public static function fiyat(string $model): ?array
    {
        $kisa = EvrenProvider::kisaAd($model);

        foreach ((array) (self::kayit()['modeller'] ?? []) as $m) {
            if (($m['id'] ?? '') === $kisa && isset($m['pricing']['prompt_token_price'])) {
                return [
                    'giris' => (float) $m['pricing']['prompt_token_price'],
                    'cikis' => (float) ($m['pricing']['completion_token_price'] ?? 0),
                    'birim' => (string) ($m['pricing']['currency'] ?? 'CR'),
                ];
            }
        }

        return null;
    }

    /** @param array<string,mixed> $m */
    private static function aciklama(array $m): string
    {
        $baglam = (int) ($m['context_length'] ?? 0);

        return $baglam > 0 ? 'yeni model, ' . (int) round($baglam / 1024) . 'K bağlam' : 'yeni model';
    }
}
