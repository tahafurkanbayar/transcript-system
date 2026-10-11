<?php
/**
 * =====================================================================
 *  ModelOzeti – raporu hangi modeller yazdı (bölümler, ara özetler, birleştirme)
 * ---------------------------------------------------------------------
 *  "Bu rapor" kutusunda yalnız birleştirme modeli görünüyordu (analiz
 *  4378: bölümleri qwen3.8-flash-next, birleştirmeyi gemma-4-31b yazdı;
 *  kutuda yalnız "auto → google/gemma-4-31b"). Cowork 2. test turu.
 *
 *  Kayıt (transkript_analizler.modeller, AnalizBirlestir yazar):
 *    {"bolumler":    [{"saglayici": "evren", "model": "auto → qwen/…", "adet": 5}],
 *     "ara":         [...],   // yalnız kademeli birleştirmede
 *     "birlestirme": [{"saglayici": "evren", "model": "auto → google/…", "adet": 1}]}
 *
 *  Ekranda: hepsi aynı modelse tek satır ("EVREN · glm-5.3"); değilse
 *  aşama başına ("Bölümler: EVREN · qwen3.8-flash-next"). Eski raporda
 *  kayıt yok: "model" sütunu olduğu gibi gösterilir.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Services;

final class ModelOzeti
{
    public const ASAMALAR = ['bolumler' => 'Bölümler', 'ara' => 'Ara özetler', 'birlestirme' => 'Birleştirme'];

    private const KISA_ADLAR = [
        'evren' => 'EVREN', 'gemini' => 'Gemini', 'openrouter' => 'OpenRouter', 'groq' => 'Groq',
        'mistral' => 'Mistral', 'cerebras' => 'Cerebras', 'claude' => 'Claude', 'openai-compatible' => 'OpenAI uyumlu',
    ];

    /**
     * @param  list<array{saglayici?: ?string, model?: ?string}> $bolumler parça başına
     * @param  list<array{saglayici?: ?string, model?: ?string}> $ara      ara özet başına
     * @return array<string, list<array{saglayici: string, model: string, adet: int}>>
     */
    public static function olustur(array $bolumler, array $ara, string $saglayici, string $model): array
    {
        return [
            'bolumler'    => self::say($bolumler),
            'ara'         => self::say($ara),
            'birlestirme' => self::say([['saglayici' => $saglayici, 'model' => $model]]),
        ];
    }

    /**
     * Aynı sağlayıcı + modeli sayar; sağlayıcısı bilinmeyen (eski parça) atlanır.
     *
     * @param  list<array{saglayici?: ?string, model?: ?string}> $kullanimlar
     * @return list<array{saglayici: string, model: string, adet: int}>
     */
    public static function say(array $kullanimlar): array
    {
        $sayac = [];

        foreach ($kullanimlar as $k) {
            $saglayici = trim((string) ($k['saglayici'] ?? ''));

            if ($saglayici === '') {
                continue;
            }

            $model   = trim((string) ($k['model'] ?? ''));
            $anahtar = $saglayici . "\0" . $model;
            $sayac[$anahtar] ??= ['saglayici' => $saglayici, 'model' => $model, 'adet' => 0];
            $sayac[$anahtar]['adet']++;
        }

        return array_values($sayac);
    }

    /**
     * Ekran satırları. Kayıt yoksa (eski rapor) $yedek tek satır.
     *
     * @param  mixed $modeller transkript_analizler.modeller (JSON metni ya da dizi)
     * @return list<array{etiket: string, deger: string}> etiket '' = tek satır
     */
    public static function satirlar(mixed $modeller, string $yedek = ''): array
    {
        $modeller = is_string($modeller) ? json_decode($modeller, true) : $modeller;
        $asamalar = [];
        $ciftler  = [];

        foreach (self::ASAMALAR as $asama => $etiket) {
            $liste = is_array($modeller) ? array_values(array_filter((array) ($modeller[$asama] ?? []), 'is_array')) : [];

            if ($liste === []) {
                continue;
            }

            // Ekranda aynı görünenler birleşir ("auto → xiaomi/mimo-v2.6-pro" ve sabitlenen "mimo-v2.6-pro").
            $adetler = [];

            foreach ($liste as $m) {
                $gorunen = self::etiket((string) ($m['saglayici'] ?? ''), (string) ($m['model'] ?? ''));
                $adetler[$gorunen] = ($adetler[$gorunen] ?? 0) + (int) ($m['adet'] ?? 1);
                $ciftler[$gorunen] = true;
            }

            $tekModel = count($adetler) === 1;
            $asamalar[] = ['etiket' => $etiket, 'deger' => implode(', ', array_map(
                static fn (string $gorunen, int $adet): string => $gorunen . ($tekModel ? '' : ' (' . $adet . ')'),
                array_keys($adetler),
                $adetler
            ))];
        }

        if ($asamalar === []) {
            return trim($yedek) !== '' ? [['etiket' => '', 'deger' => trim($yedek)]] : [];
        }

        // Bütün aşamaları aynı model yazdıysa tek satır.
        return count($ciftler) === 1 ? [['etiket' => '', 'deger' => array_key_first($ciftler)]] : $asamalar;
    }

    /** Tek satır: "Bölümler: EVREN · qwen3.8-flash-next — Birleştirme: EVREN · gemma-4-31b". */
    public static function metin(mixed $modeller, string $yedek = ''): string
    {
        return self::satirMetni(self::satirlar($modeller, $yedek));
    }

    /** @param list<array{etiket: string, deger: string}> $satirlar satirlar() çıktısı */
    public static function satirMetni(array $satirlar): string
    {
        return implode(' — ', array_map(
            static fn (array $s): string => ($s['etiket'] !== '' ? $s['etiket'] . ': ' : '') . $s['deger'],
            $satirlar
        ));
    }

    /**
     * "EVREN · gemma-4-31b": sağlayıcının kısa adı + modelin kısa adı.
     * EVREN "auto" yanıttaki gerçek modeli "auto → google/gemma-4-31b"
     * diye yazar; ok ve üretici öneki atılır.
     */
    public static function etiket(string $saglayici, string $model): string
    {
        $ad    = self::KISA_ADLAR[$saglayici] ?? ucfirst($saglayici);

        // EVREN: panelle aynı kısa ad ("auto → zai/glm-5.3-fp8" → glm-5.3).
        if ($saglayici === 'evren' && trim($model) !== '') {
            return $ad . ' · ' . \Modules\Transkript\Ai\EvrenProvider::kisaAd($model);
        }

        $model = trim((string) preg_replace('/^auto\s*→\s*/u', '', trim($model)));
        $kisa  = str_contains($model, '/') ? substr($model, strrpos($model, '/') + 1) : $model;

        return $kisa !== '' ? $ad . ' · ' . $kisa : $ad;
    }
}
