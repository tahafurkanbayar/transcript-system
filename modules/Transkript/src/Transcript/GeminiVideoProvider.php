<?php
/**
 * =====================================================================
 *  GeminiVideoProvider – Gemini'ye YouTube URL'si, zaman damgalı transcript
 * ---------------------------------------------------------------------
 *  YouTube altyazısı alınamazsa (altyazı kapalı, IP engeli, PoToken)
 *  zincirin yedeğidir. Google'ın kendi yolu olduğu için şartlar
 *  açısından en düşük risk; yalnız herkese açık videolarda çalışır.
 *  ÜCRETLİDİR: her çağrının token ve maliyeti TranscriptResult'ın
 *  aiKullanimi alanında döner, iş ai_kullanim_kaydi'na yazar.
 *
 *  UZUN VİDEO: süre biliniyorsa video pencerelere bölünür
 *  (videoMetadata startOffset / endOffset, varsayılan 20 dk); her
 *  pencerenin çıktısı max token sınırına sığar. Süre bilinmiyorsa
 *  (YOUTUBE_API_KEY yok) tek çağrı yapılır.
 *
 *  UCUZ İSTEK: mediaResolution LOW (görüntü değil konuşma lazım) ve
 *  thinkingBudget 0 (yazıya dökmek düşünme gerektirmez); ikisi de
 *  config.php'de.
 *
 *  ZAMAN DAMGASI DOĞRULAMA (docs/TEKNIK_RAPOR.md, 5. bölüm)
 *    · "hh:mm:ss", "mm:ss" ya da saniye; okunamayan satır atılır
 *    · pencerede istenen mutlak zamanı Gemini pencereye göre GÖRELİ
 *      verirse (hepsi pencere başlangıcından küçük) kaydırılır
 *    · pencere ve video süresi dışındakiler atılır
 *    · bitiş = sonraki satırın başlangıcı (en çok 30 sn)
 *  Çıktı max token'da kesilirse tamamlanmış satırlar kurtarılır.
 *
 *  Bir pencere başarısız olursa iş baştan tekrarlanır; biten
 *  pencereler yeniden ödenir. Bu yol yalnızca yedek olduğu için MVP'de
 *  kabul edilebilir.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Transcript;

use Closure;
use Modules\Transkript\Ai\Ai;
use Modules\Transkript\Ai\AiHatasi;
use Modules\Transkript\Ai\GeminiProvider;
use Modules\Transkript\Ayarlar;
use Modules\Transkript\Kaynak\Kaynak;
use Modules\Transkript\Kaynak\KaynakHatasi;
use Modules\Transkript\Kaynak\UrlParser;

final class GeminiVideoProvider implements TranscriptProviderInterface
{
    /** Gemini responseSchema (OpenAPI alt kümesi). */
    public const SEMA = [
        'type'       => 'OBJECT',
        'properties' => [
            'dil'      => ['type' => 'STRING'],
            'satirlar' => [
                'type'  => 'ARRAY',
                'items' => [
                    'type'       => 'OBJECT',
                    'properties' => ['bas' => ['type' => 'STRING'], 'metin' => ['type' => 'STRING']],
                    'required'   => ['bas', 'metin'],
                ],
            ],
        ],
        'required' => ['dil', 'satirlar'],
    ];

    private const EN_UZUN_SEGMENT_MS = 30000;
    private const TOLERANS_MS        = 2000;

    /**
     * @param Closure(array<int,array<string,mixed>>, string, array<string,mixed>): array<string,mixed> $cagri
     *        Gemini'ye parçaları gönderip normalleştirilmiş yanıtı döner (testte sahte).
     * @param array<string,mixed> $ek generationConfig eki (mediaResolution, thinkingConfig)
     */
    public function __construct(
        private readonly Closure $cagri,
        private readonly int $pencereSn = 1200,
        private readonly array $ek = [],
    ) {
    }

    public static function fromAyarlar(): self
    {
        $model    = (string) Ayarlar::get('transcript.gemini.model', 'gemini-2.5-flash');
        $maxToken = (int) Ayarlar::get('transcript.gemini.max_token', 16000);

        return new self(
            // Anahtar ancak çağrı anında aranır: yoksa AiHatasi(anahtar_yok)
            // fetch()'te ProviderUnavailable'a çevrilir, zincir sürer.
            static function (array $parcalar, string $sistem, array $ek) use ($model, $maxToken): array {
                $gemini = Ai::make('gemini', $model, $maxToken, 600);

                if (!$gemini instanceof GeminiProvider) {
                    throw new AiHatasi('Gemini sağlayıcısı üretilemedi.', 'ayar_eksik');
                }

                return $gemini->sendParts($parcalar, $sistem, $ek);
            },
            max(60, (int) Ayarlar::get('transcript.gemini.pencere_dakika', 20) * 60),
            (array) Ayarlar::get('transcript.gemini.ek', []),
        );
    }

    public function name(): string
    {
        return 'gemini';
    }

    public function supports(Kaynak $kaynak): bool
    {
        return $kaynak->youtubeMu();
    }

    public function fetch(Kaynak $kaynak, array $preferredLangs): TranscriptResult
    {
        if (!UrlParser::gecerliKimlik($kaynak->disKimlik)) {
            throw new KaynakHatasi('gecersiz_url', 'Geçersiz video kimliği: ' . $kaynak->disKimlik);
        }

        $segmentler = $kullanim = [];
        $dil = '';

        foreach (self::pencereler($kaynak->sureSn, $this->pencereSn) as [$bas, $bit]) {
            $parca = ['fileData' => ['fileUri' => UrlParser::izlemeAdresi($kaynak->disKimlik)]];

            if ($bas !== null) {
                $parca['videoMetadata'] = ['startOffset' => $bas . 's', 'endOffset' => $bit . 's'];
            }

            $basla  = microtime(true);
            $sonuc  = $this->cagir([$parca, ['text' => self::istem($bas, $bit)]]);
            $kullanim[] = [
                'saglayici'   => 'gemini',
                'model'       => (string) ($sonuc['model'] ?? ''),
                'giris_token' => (int) ($sonuc['usage']['input_tokens'] ?? 0),
                'cikis_token' => (int) ($sonuc['usage']['output_tokens'] ?? 0),
                'maliyet_usd' => (float) ($sonuc['cost'] ?? 0.0),
                'sure_ms'     => (int) round((microtime(true) - $basla) * 1000),
            ];

            [$pencereDili, $satirlar] = self::cozumle($sonuc);
            $dil = $dil !== '' ? $dil : $pencereDili;
            array_push($segmentler, ...self::segmentlere($satirlar, $bas, $bit, $kaynak->sureSn));
        }

        $segmentler = self::bitisleriKur($segmentler, $kaynak->sureSn);

        if ($segmentler === []) {
            throw new TranscriptNotFound('Gemini konuşma bulamadı ya da geçerli zaman damgası dönmedi: ' . $kaynak->disKimlik);
        }

        return new TranscriptResult($segmentler, $dil, 'gemini', true, $kullanim);
    }

    /* =================================================================
     *  ÇAĞRI VE HATA EŞLEME
     * ============================================================== */

    /**
     * @param  array<int,array<string,mixed>> $parcalar
     * @return array<string,mixed>
     */
    private function cagir(array $parcalar): array
    {
        $ek = ['responseMimeType' => 'application/json', 'responseSchema' => self::SEMA] + $this->ek;

        try {
            return ($this->cagri)($parcalar, '', $ek);
        } catch (AiHatasi $e) {
            throw match ($e->kod) {
                'rate_limit'                                => new RateLimited('Gemini: ' . $e->getMessage(), $e),
                'sunucu', 'baglanti', 'zaman_asimi',
                'yanit_cozulemedi'                          => new ProviderUnavailable($e->kod, 'Gemini: ' . $e->getMessage(), true, $e),
                default                                     => new ProviderUnavailable($e->kod, 'Gemini: ' . $e->getMessage(), false, $e),
            };
        }
    }

    /* =================================================================
     *  PENCERE VE İSTEM
     * ============================================================== */

    /**
     * Süre biliniyorsa [başlangıç sn, bitiş sn] pencereleri; değilse tek
     * pencere [null, null] (videonun tamamı).
     *
     * @return list<array{0:?int,1:?int}>
     */
    public static function pencereler(?int $sureSn, int $pencereSn): array
    {
        if ($sureSn === null || $sureSn <= 0 || $sureSn <= $pencereSn) {
            return [[null, null]];
        }

        $liste = [];

        for ($bas = 0; $bas < $sureSn; $bas += $pencereSn) {
            $liste[] = [$bas, min($bas + $pencereSn, $sureSn)];
        }

        return $liste;
    }

    /** Model'e giden talimat (İngilizce: yazıya dökme talimatında en tutarlı sonuç). */
    public static function istem(?int $bas, ?int $bit): string
    {
        $aralik = $bas === null
            ? ''
            : sprintf(' Only transcribe the part of the video between %s and %s.', self::zaman($bas * 1000), self::zaman((int) $bit * 1000));

        return 'Transcribe the speech in this video verbatim, in the original spoken language. '
            . 'Do not translate, summarize, correct or add anything.' . $aralik
            . ' Split the speech into short lines of one sentence or phrase (about 5-15 seconds each). '
            . 'For each line give "bas": its start time as HH:MM:SS measured from the beginning of the FULL video, '
            . 'and "metin": the spoken words. Set "dil" to the ISO 639-1 code of the spoken language. '
            . 'If there is no speech, return an empty "satirlar" list.';
    }

    /* =================================================================
     *  ÇIKTIYI OKUMA VE DOĞRULAMA
     * ============================================================== */

    /**
     * @param  array<string,mixed> $sonuc normalleştirilmiş Gemini yanıtı
     * @return array{0:string,1:list<array{bas:string,metin:string}>}
     */
    public static function cozumle(array $sonuc): array
    {
        if (($sonuc['refusal'] ?? null) !== null) {
            throw new TranscriptNotFound('Gemini yanıt vermedi: ' . (string) $sonuc['refusal'], 'gemini_reddetti');
        }

        $metin = trim((string) ($sonuc['text'] ?? ''));
        $metin = (string) preg_replace('/^```(?:json)?\s*|\s*```$/', '', $metin);
        $json  = json_decode($metin, true);

        if (is_array($json) && is_array($json['satirlar'] ?? null)) {
            $satirlar = array_values(array_filter(
                $json['satirlar'],
                static fn (mixed $s): bool => is_array($s) && is_string($s['bas'] ?? null) && is_string($s['metin'] ?? null)
            ));

            return [self::dilKodu($json['dil'] ?? ''), $satirlar];
        }

        // max token'da kesilmiş JSON: tamamlanmış {"bas": …, "metin": …} nesnelerini kurtar.
        preg_match_all('/\{\s*"bas"\s*:\s*"([^"]*)"\s*,\s*"metin"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"\s*\}/u', $metin, $eslesmeler, PREG_SET_ORDER);

        if ($eslesmeler === []) {
            throw new ProviderUnavailable('yanit_cozulemedi', 'Gemini çıktısı JSON değil (stop_reason=' . (string) ($sonuc['stop_reason'] ?? '') . ').');
        }

        $satirlar = [];

        foreach ($eslesmeler as $m) {
            $cozulmus = json_decode('"' . $m[2] . '"');
            $satirlar[] = ['bas' => $m[1], 'metin' => is_string($cozulmus) ? $cozulmus : $m[2]];
        }

        preg_match('/"dil"\s*:\s*"([^"]*)"/', $metin, $dilEslesme);

        return [self::dilKodu($dilEslesme[1] ?? ''), $satirlar];
    }

    /**
     * @param  list<array{bas:string,metin:string}> $satirlar
     * @return list<Segment> bitişleri henüz kurulmamış
     */
    public static function segmentlere(array $satirlar, ?int $basSn, ?int $bitSn, ?int $sureSn): array
    {
        $okunan = [];

        foreach ($satirlar as $satir) {
            $ms    = self::zamaniOku($satir['bas']);
            $metin = trim((string) preg_replace('/\s+/u', ' ', $satir['metin']));

            if ($ms !== null && $metin !== '') {
                $okunan[] = [$ms, mb_substr($metin, 0, 2000)];
            }
        }

        if ($okunan === []) {
            return [];
        }

        $altMs = $basSn !== null ? $basSn * 1000 : 0;
        $ustMs = $bitSn !== null ? $bitSn * 1000 : ($sureSn !== null ? $sureSn * 1000 : PHP_INT_MAX);

        // Göreli zaman: pencere 20. dakikada başladığı halde hepsi 20 dakikadan küçükse kaydır.
        if ($altMs > 0 && max(array_column($okunan, 0)) < $altMs) {
            $okunan = array_map(static fn (array $o): array => [$o[0] + $altMs, $o[1]], $okunan);
        }

        $segmentler = [];

        foreach ($okunan as [$ms, $metin]) {
            if ($ms < $altMs - self::TOLERANS_MS || $ms > $ustMs + self::TOLERANS_MS) {
                continue;
            }

            $segmentler[] = new Segment($ms, $ms, $metin);
        }

        return $segmentler;
    }

    /**
     * Sıralar, yinelenenleri atar, bitiş = sonraki başlangıç (en çok 30 sn).
     *
     * @param  list<Segment> $segmentler
     * @return list<Segment>
     */
    public static function bitisleriKur(array $segmentler, ?int $sureSn): array
    {
        usort($segmentler, static fn (Segment $a, Segment $b): int => $a->baslangicMs <=> $b->baslangicMs);

        $tekil = [];

        foreach ($segmentler as $s) {
            $son = end($tekil);

            if ($son instanceof Segment && $son->baslangicMs === $s->baslangicMs && $son->metin === $s->metin) {
                continue;   // pencere sınırında iki kez yazılan satır
            }

            $tekil[] = $s;
        }

        $sonMs = $sureSn !== null ? $sureSn * 1000 : null;
        $sonuc = [];

        foreach ($tekil as $i => $s) {
            $sonraki = $tekil[$i + 1] ?? null;
            $bitis   = $sonraki !== null && $sonraki->baslangicMs > $s->baslangicMs
                ? min($sonraki->baslangicMs, $s->baslangicMs + self::EN_UZUN_SEGMENT_MS)
                : $s->baslangicMs + 5000;

            if ($sonMs !== null) {
                $bitis = min($bitis, max($sonMs, $s->baslangicMs));
            }

            $sonuc[] = new Segment($s->baslangicMs, max($bitis, $s->baslangicMs), $s->metin);
        }

        return $sonuc;
    }

    /** "01:02:03", "02:03", "1:02:03.5", "75" (saniye) → milisaniye; okunamazsa null. */
    public static function zamaniOku(string $zaman): ?int
    {
        $zaman = trim($zaman);

        if (preg_match('/^(?:(\d{1,2}):)?(\d{1,2}):(\d{2})(?:[.,](\d{1,3}))?$/', $zaman, $m)) {
            if ((int) $m[3] >= 60 || ($m[1] !== '' && (int) $m[2] >= 60)) {
                return null;
            }

            $kesir = isset($m[4]) ? (int) str_pad($m[4], 3, '0') : 0;

            return (((int) ($m[1] ?: 0) * 3600) + ((int) $m[2] * 60) + (int) $m[3]) * 1000 + $kesir;
        }

        if (preg_match('/^\d+(?:\.\d+)?$/', $zaman)) {
            return (int) round((float) $zaman * 1000);
        }

        return null;
    }

    private static function zaman(int $ms): string
    {
        $sn = intdiv($ms, 1000);

        return sprintf('%02d:%02d:%02d', intdiv($sn, 3600), intdiv($sn % 3600, 60), $sn % 60);
    }

    private static function dilKodu(mixed $dil): string
    {
        $dil = is_string($dil) ? strtolower(trim($dil)) : '';

        return preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $dil) ? $dil : '';
    }
}
