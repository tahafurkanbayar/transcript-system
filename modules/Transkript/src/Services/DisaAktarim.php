<?php
/**
 * =====================================================================
 *  DisaAktarim – raporu ve transcript'i dosya / metin olarak verir
 * ---------------------------------------------------------------------
 *  Rakiplerde ortak beklenti (Glasp: Markdown / HTML / CSV / JSON,
 *  transcript kopyalama; docs/RAKIP_ANALIZI.md):
 *
 *    rapor.pdf        rapor PDF (Pdf\RaporPdf); zaman damgası bağlantı, içindekiler
 *    rapor.md         rapor Markdown; zaman damgaları youtu.be?t= bağlantısı
 *    transkript.txt   okunur bloklar, "[hh:mm:ss] metin"
 *    transkript.srt   altyazı (video düzenleyiciler, oynatıcılar)
 *    transkript.vtt   WebVTT (tarayıcı <track>)
 *    bölüm listesi    YouTube açıklamasına yapıştırılacak "0:00 Başlık"
 *
 *  AI metni Markdown'da kaçışlanır: "[tıkla](javascript:…)" gibi bir
 *  metin bağlantıya dönüşmez, "#" satırı başlık olmaz.
 *  AI çağrısı yok; hepsi kaydedilmiş rapordan ve segmentlerden üretilir.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Services;

use Modules\Transkript\Kategori\Kategoriler;
use Modules\Transkript\Transcript\Segment;
use Modules\Transkript\Zaman;

final class DisaAktarim
{
    /** İndirilebilir biçimler: ad → [içerik türü, dosya eki] */
    public const BICIMLER = [
        'rapor.pdf'      => ['application/pdf', 'rapor.pdf'],
        'rapor.md'       => ['text/markdown; charset=utf-8', 'rapor.md'],
        'transkript.txt' => ['text/plain; charset=utf-8', 'transkript.txt'],
        'transkript.srt' => ['application/x-subrip; charset=utf-8', 'srt'],
        'transkript.vtt' => ['text/vtt; charset=utf-8', 'vtt'],
    ];

    /** YouTube bölüm kuralı: ilk bölüm 0:00, en az 3 bölüm, her biri en az 10 sn. */
    private const BOLUM_EN_AZ_SN = 10;

    private const ONEM = ['critical' => 'Kritik', 'important' => 'Önemli', 'useful' => 'Faydalı'];
    private const GUVEN = ['stated_as_fact' => 'kesin ifade', 'opinion' => 'görüş', 'speculation' => 'tahmin'];

    /* =================================================================
     *  RAPOR → MARKDOWN
     * ============================================================== */

    /**
     * @param array<string,mixed>      $kaynak transkript_kaynaklar satırı
     * @param array<string,mixed>      $rapor  doğrulanmış rapor JSON'u
     * @param array<string,mixed>|null $analiz   RaporVerisi::getir()['analiz']
     * @param array<string,mixed>|null $kategori RaporVerisi::getir()['kategori'] (Kategoriler::disaVer)
     */
    public static function markdown(array $kaynak, array $rapor, ?array $analiz, ?array $transcript = null, ?array $kategori = null): string
    {
        $video = $kaynak['tur'] === 'youtube' ? (string) $kaynak['dis_kimlik'] : '';
        $zaman = static fn (mixed $at): string => self::zamanBaglantisi($at, $video);
        $satir = [];

        $satir[] = '# ' . self::md((string) ($kaynak['baslik'] ?: $kaynak['dis_kimlik']));
        $satir[] = '';

        $meta = array_filter([
            ($kaynak['kanal_adi'] ?? '') !== '' ? 'Kanal: ' . self::md((string) $kaynak['kanal_adi']) : null,
            $kaynak['sure_sn'] !== null ? 'Süre: ' . RaporVerisi::sure((int) $kaynak['sure_sn']) : null,
            $video !== '' ? 'Video: <https://youtu.be/' . rawurlencode($video) . '>' : null,
        ]);

        if ($meta !== []) {
            $satir[] = implode(' · ', $meta);
            $satir[] = '';
        }

        if ($kategori !== null) {
            $satir[] = 'Kategori: ' . self::md(Kategoriler::ozet($kategori))
                . ($kategori['ana_konu'] !== '' ? ' · Konu: ' . self::md($kategori['ana_konu']) : '')
                . ($kategori['etiketler'] !== [] ? ' · Etiketler: ' . implode(', ', array_map(self::md(...), $kategori['etiketler'])) : '');
            $satir[] = '';
        }

        // Raporun künyesi: ne zaman, hangi model ve istemle, hangi transcript'ten.
        $kunye = array_filter([
            ($analiz['bitti_at'] ?? null) !== null ? 'Hazırlandı: ' . date('d.m.Y H:i', (int) strtotime((string) $analiz['bitti_at'])) : null,
            // Aşama başına modeller (RaporVerisi); yoksa yalnız "model".
            ($modelMetni = ModelOzeti::satirMetni($analiz['modeller'] ?? ModelOzeti::satirlar(null, (string) ($analiz['model'] ?? '')))) !== ''
                ? 'Model: ' . self::md($modelMetni) : null,
            ($analiz['prompt_surumu'] ?? '') !== '' ? 'İstem: ' . self::md((string) $analiz['prompt_surumu']) : null,
            $transcript !== null ? 'Transcript: ' . RaporVerisi::dilAdi((string) $transcript['dil']) . (!empty($transcript['otomatik_mi']) ? ' (otomatik altyazı)' : ' (altyazı)') : null,
        ]);

        if ($kunye !== []) {
            $satir[] = '_' . implode(' · ', $kunye) . '_';
            $satir[] = '';
        }

        $satir[] = '## Kısa özet';
        $satir[] = '';
        $satir[] = self::paragraf((string) ($rapor['summary'] ?? ''));
        $satir[] = '';

        if (trim((string) ($rapor['detailed_summary'] ?? '')) !== '') {
            $satir[] = '## Detaylı özet';
            $satir[] = '';
            $satir[] = self::paragraf((string) $rapor['detailed_summary']);
            $satir[] = '';
        }

        $bolum = static function (string $baslik, array $maddeler, callable $bicim) use (&$satir): void {
            if ($maddeler === []) {
                return;
            }

            $satir[] = '## ' . $baslik;
            $satir[] = '';

            foreach ($maddeler as $m) {
                if (is_array($m)) {
                    $satir[] = '- ' . trim($bicim($m));
                }
            }

            $satir[] = '';
        };

        $bolum('Konular', (array) ($rapor['topics'] ?? []), static fn (array $m): string =>
            trim($zaman($m['start'] ?? null) . ' **' . self::md((string) $m['title']) . '**')
            . (trim((string) ($m['summary'] ?? '')) !== '' ? ' — ' . self::md((string) $m['summary']) : ''));

        $bolum('Önemli noktalar', (array) ($rapor['important_points'] ?? []), static fn (array $m): string =>
            '**' . (self::ONEM[$m['importance'] ?? ''] ?? 'Faydalı') . ':** ' . self::md((string) $m['text']) . ' ' . $zaman($m['at'] ?? null));

        $bolum('Zaman çizelgesi', (array) ($rapor['timestamps'] ?? []), static fn (array $m): string =>
            $zaman($m['at'] ?? null) . ' ' . self::md((string) $m['label']));

        if (($rapor['technical_information'] ?? []) !== []) {
            $satir[] = '## ' . RaporVerisi::TEKNIK_BASLIK;
            $satir[] = '';

            foreach (RaporVerisi::kategoriyeGore((array) $rapor['technical_information']) as $kategori => $maddeler) {
                $satir[] = '### ' . self::md($kategori);
                $satir[] = '';

                foreach ($maddeler as $m) {
                    $satir[] = '- **' . self::md((string) $m['name']) . '**'
                        . (($m['description'] ?? null) !== null ? ' — ' . self::md((string) $m['description']) : '')
                        . ' ' . $zaman($m['at'] ?? null);
                }

                $satir[] = '';
            }
        }

        $bolum('Fikirler', (array) ($rapor['ideas'] ?? []), static fn (array $m): string =>
            self::md((string) $m['text']) . (($m['type'] ?? null) !== null ? ' _(' . self::md((string) $m['type']) . ')_' : '') . ' ' . $zaman($m['at'] ?? null));

        $bolum('İddialar', (array) ($rapor['claims'] ?? []), static fn (array $m): string =>
            self::md((string) $m['text']) . (isset(self::GUVEN[$m['speaker_confidence'] ?? '']) ? ' _(' . self::GUVEN[$m['speaker_confidence']] . ')_' : '')
            . ' ' . $zaman($m['at'] ?? null));

        $bolum('Öneriler', (array) ($rapor['recommendations'] ?? []), static fn (array $m): string => self::md((string) ($m['text'] ?? '')));

        $satir[] = '---';
        $satir[] = '';
        $satir[] = '_Transkript ile üretildi. İddialar doğrulanmamıştır._';

        return (string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $satir)) . "\n";
    }

    /* =================================================================
     *  YOUTUBE BÖLÜM LİSTESİ
     * ============================================================== */

    /**
     * Açıklamaya yapıştırılacak "0:00 Başlık" satırları. Konulardan
     * (yoksa zaman çizelgesinden) üretilir; ilk bölüm 0:00'a çekilir,
     * 10 sn'den yakın bölümler atlanır. 3'ten az bölüm çıkarsa boş:
     * YouTube 3'ten az bölümü göstermez.
     *
     * @param array<string,mixed> $rapor
     */
    public static function bolumListesi(array $rapor, ?int $sureSn = null): string
    {
        $kaynak = [];

        foreach ((array) ($rapor['topics'] ?? []) as $m) {
            $kaynak[] = [RaporVerisi::saniye($m['start'] ?? null), (string) ($m['title'] ?? '')];
        }

        if (count(array_filter($kaynak, static fn (array $k): bool => $k[0] !== null)) < 3) {
            $kaynak = [];

            foreach ((array) ($rapor['timestamps'] ?? []) as $m) {
                $kaynak[] = [RaporVerisi::saniye($m['at'] ?? null), (string) ($m['label'] ?? '')];
            }
        }

        $kaynak = array_values(array_filter($kaynak, static fn (array $k): bool => $k[0] !== null && trim($k[1]) !== ''));
        usort($kaynak, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $uzun   = max($sureSn ?? 0, $kaynak === [] ? 0 : (int) end($kaynak)[0]) >= 3600;
        $satir  = [];
        $onceki = null;

        foreach ($kaynak as $i => [$sn, $baslik]) {
            $sn = $i === 0 ? 0 : (int) $sn;

            if ($onceki !== null && $sn - $onceki < self::BOLUM_EN_AZ_SN) {
                continue;
            }

            $satir[] = self::bolumZamani($sn, $uzun) . ' ' . ResultValidator::metin($baslik, 100);
            $onceki  = $sn;
        }

        return count($satir) >= 3 ? implode("\n", $satir) : '';
    }

    /* =================================================================
     *  TRANSCRIPT
     * ============================================================== */

    /** @param list<Segment> $bloklar Cleaner::hazirla() çıktısı */
    public static function metin(array $bloklar): string
    {
        return implode("\n", array_map(static fn (Segment $b): string => '[' . Zaman::yaz($b->baslangicMs) . '] ' . $b->metin, $bloklar)) . "\n";
    }

    /**
     * SRT / VTT. Otomatik altyazıda satırlar örtüşür; her satırın sonu
     * bir sonrakinin başına kırpılır, boş satır atlanır.
     *
     * @param list<Segment> $segmentler
     */
    public static function altyazi(array $segmentler, string $bicim): string
    {
        $vtt    = $bicim === 'vtt';
        $cikti  = $vtt ? ["WEBVTT\n"] : [];
        $no     = 0;
        $adet   = count($segmentler);

        foreach ($segmentler as $i => $s) {
            $metin = trim(str_replace(["\r", "-->"], ['', '→'], $s->metin));

            if ($metin === '') {
                continue;
            }

            $bas = $s->baslangicMs;
            $bit = $i + 1 < $adet ? min($s->bitisMs, $segmentler[$i + 1]->baslangicMs) : $s->bitisMs;
            $bit = max($bit, $bas + 500);

            $cikti[] = ($vtt ? '' : ++$no . "\n")
                . self::altyaziZamani($bas, $vtt) . ' --> ' . self::altyaziZamani($bit, $vtt) . "\n"
                . $metin . "\n";
        }

        return implode("\n", $cikti);
    }

    /** "Do schools kill creativity? | TED" → "do-schools-kill-creativity-ted" */
    public static function dosyaAdi(array $kaynak, string $bicim): string
    {
        $ad = Search::trFold((string) ($kaynak['baslik'] ?: $kaynak['dis_kimlik']));
        $ad = trim((string) preg_replace('/[^a-z0-9]+/', '-', $ad), '-');
        $ad = $ad !== '' ? mb_substr($ad, 0, 60) : 'transkript';

        return rtrim($ad, '-') . '.' . (self::BICIMLER[$bicim][1] ?? 'txt');
    }

    /* =================================================================
     *  YARDIMCILAR
     * ============================================================== */

    /** Markdown'da anlam taşıyan karakterler kaçışlanır. */
    public static function md(string $metin): string
    {
        $metin = (string) preg_replace('/\s+/u', ' ', trim($metin));

        return (string) preg_replace('/([\\\\`*_\[\]<>#|~])/', '\\\\$1', $metin);
    }

    private static function paragraf(string $metin): string
    {
        $paragraflar = array_filter(array_map('trim', preg_split('/\n{2,}/', trim($metin)) ?: []));

        return implode("\n\n", array_map(static fn (string $p): string => self::md($p), $paragraflar));
    }

    private static function zamanBaglantisi(mixed $at, string $video): string
    {
        $sn = RaporVerisi::saniye($at);

        if ($sn === null) {
            return '';
        }

        return $video === '' ? '[' . $at . ']' : '[' . $at . '](' . RaporVerisi::youtubeBaglantisi($video, $sn) . ')';
    }

    private static function bolumZamani(int $sn, bool $uzun): string
    {
        return $uzun
            ? sprintf('%d:%02d:%02d', intdiv($sn, 3600), intdiv($sn % 3600, 60), $sn % 60)
            : sprintf('%d:%02d', intdiv($sn, 60), $sn % 60);
    }

    private static function altyaziZamani(int $ms, bool $vtt): string
    {
        $ms = max(0, $ms);

        return sprintf('%02d:%02d:%02d%s%03d', intdiv($ms, 3600000), intdiv($ms % 3600000, 60000), intdiv($ms % 60000, 1000), $vtt ? '.' : ',', $ms % 1000);
    }
}
