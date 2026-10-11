<?php
/**
 * =====================================================================
 *  RaporPdf – doğrulanmış raporun PDF çıktısı
 * ---------------------------------------------------------------------
 *  DisaAktarim::markdown() ile aynı veri ve sıra: başlık ve künye (model,
 *  istem; yapay zekâ uyarısı her sayfanın alt bilgisinde), kısa
 *  özet (kutu), detaylı özet, konular, önemli noktalar (Kritik / Önemli
 *  / Faydalı rozeti), zaman çizelgesi, kavramlar ve teknik bilgiler,
 *  fikirler, iddialar, öneriler. Zaman damgaları videoyu o saniyede açan
 *  bağlantıdır; bölümler görüntüleyicinin içindekiler çubuğunda.
 *
 *  AI çağrısı yok; kaydedilmiş rapordan üretilir (HTTP isteğinde kısa
 *  sürer). AI metni yalnız metin olarak yazılır (Akis / PdfBelgesi).
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Pdf;

use Modules\Transkript\Services\ModelOzeti;
use Modules\Transkript\Services\RaporVerisi;

final class RaporPdf
{
    private const METIN   = [17, 24, 39];
    private const IKINCIL = [75, 85, 99];
    private const SOLUK   = [120, 128, 140];
    private const VURGU   = [29, 78, 216];
    private const KOYU    = [15, 23, 42];
    private const CIZGI   = [226, 232, 240];
    private const ZEMIN   = [239, 246, 255];

    /** Önem → [etiket, zemin, yazı]. */
    private const ONEM = [
        'critical'  => ['Kritik', [254, 226, 226], [185, 28, 28]],
        'important' => ['Önemli', [254, 243, 199], [146, 64, 14]],
        'useful'    => ['Faydalı', [219, 234, 254], [29, 78, 216]],
    ];

    private const GOVDE = 10.0;

    private Akis $akis;

    private string $video;

    /**
     * @param array<string,mixed>      $kaynak     transkript_kaynaklar satırı
     * @param array<string,mixed>      $rapor      doğrulanmış rapor JSON'u
     * @param array<string,mixed>|null $analiz     RaporVerisi::getir()['analiz']
     * @param array<string,mixed>|null $transcript RaporVerisi::getir()['transcript']
     * @param array{site?: string, zaman?: int} $secenek
     */
    public static function olustur(array $kaynak, array $rapor, ?array $analiz = null, ?array $transcript = null, array $secenek = []): string
    {
        return (new self())->ciz($kaynak, $rapor, $analiz, $transcript, $secenek);
    }

    /**
     * @param array<string,mixed>      $kaynak
     * @param array<string,mixed>      $rapor
     * @param array<string,mixed>|null $analiz
     * @param array<string,mixed>|null $transcript
     * @param array{site?: string, zaman?: int} $secenek
     */
    private function ciz(array $kaynak, array $rapor, ?array $analiz, ?array $transcript, array $secenek): string
    {
        $baslik      = trim((string) ($kaynak['baslik'] ?? '')) !== '' ? trim((string) $kaynak['baslik']) : (string) ($kaynak['dis_kimlik'] ?? 'Video');
        $site        = trim((string) ($secenek['site'] ?? ''));
        $zaman       = (int) ($secenek['zaman'] ?? time());
        $this->video = ($kaynak['tur'] ?? '') === 'youtube' ? (string) ($kaynak['dis_kimlik'] ?? '') : '';

        $pdf = new PdfBelgesi($baslik, 'Video analiz raporu', $site !== '' ? $site . ' · Transkript' : 'Transkript', $zaman);

        // 2. sayfadan itibaren üst bilgi: video adı ve ince çizgi.
        $this->akis = new Akis($pdf, 50.0, 50.0, 56.0, 64.0, static function (Akis $a) use ($baslik): void {
            if ($a->pdf->etkinSayfa() === 0) {
                return;
            }

            $a->tekSatir($baslik, $a->sol, 34.0, Yazitipi::NORMAL, 8.0, self::SOLUK, $a->genislik() - 110);
            $a->tekSatir('Video analiz raporu', PdfBelgesi::GENISLIK - $a->sag, 34.0, Yazitipi::NORMAL, 8.0, self::SOLUK, 110, 'sag');
            $a->pdf->cizgi($a->sol, 41.0, PdfBelgesi::GENISLIK - $a->sag, 41.0, self::CIZGI, 0.6);
        });

        $this->kapak($kaynak, $rapor, $analiz, $transcript, $baslik, $zaman);
        $this->govde($rapor);

        // Alt bilgi: toplam sayfa sayısı ancak sonda bilinir.
        $toplam = $pdf->sayfaSayisi();

        for ($i = 0; $i < $toplam; $i++) {
            $pdf->sayfaSec($i);
            $y = PdfBelgesi::YUKSEKLIK - 32.0;
            $pdf->cizgi($this->akis->sol, $y - 12, PdfBelgesi::GENISLIK - $this->akis->sag, $y - 12, self::CIZGI, 0.6);
            $this->akis->tekSatir(($site !== '' ? $site : 'Transkript') . ' · Yapay zekâ ile üretildi; hata içerebilir, iddialar doğrulanmamıştır.', $this->akis->sol, $y, Yazitipi::NORMAL, 7.5, self::SOLUK, 380);
            $this->akis->tekSatir('Sayfa ' . ($i + 1) . ' / ' . $toplam, PdfBelgesi::GENISLIK - $this->akis->sag, $y, Yazitipi::NORMAL, 7.5, self::SOLUK, 120, 'sag');
        }

        return $pdf->cikti();
    }

    /* =================================================================
     *  BÖLÜMLER
     * ============================================================== */

    /**
     * @param array<string,mixed>      $kaynak
     * @param array<string,mixed>      $rapor
     * @param array<string,mixed>|null $analiz
     * @param array<string,mixed>|null $transcript
     */
    private function kapak(array $kaynak, array $rapor, ?array $analiz, ?array $transcript, string $baslik, int $zaman): void
    {
        $a = $this->akis;
        $a->yeniSayfa();

        // İlk sayfada üst bilgi yerine renkli şerit ve küçük etiket.
        $a->pdf->dikdortgen(0, 0, PdfBelgesi::GENISLIK, 5.0, self::VURGU);
        $a->y = 44.0;
        $a->paragraf([['metin' => 'VİDEO ANALİZ RAPORU', 'yazitipi' => Yazitipi::KALIN, 'punto' => 8.0, 'renk' => self::VURGU]]);
        $a->bosluk(2);
        $a->yerAc(30);
        $a->pdf->yerImi($baslik, $a->y);
        $a->paragraf([['metin' => $baslik, 'yazitipi' => Yazitipi::KALIN, 'punto' => 19.0, 'renk' => self::KOYU]], ['satir' => 1.25]);
        $a->bosluk(4);

        $meta = array_values(array_filter([
            trim((string) ($kaynak['kanal_adi'] ?? '')),
            ($kaynak['sure_sn'] ?? null) !== null ? 'Süre ' . RaporVerisi::sure((int) $kaynak['sure_sn']) : '',
            $transcript !== null ? RaporVerisi::dilAdi((string) $transcript['dil']) . (!empty($transcript['otomatik_mi']) ? ' otomatik altyazı' : ' altyazı') : '',
            ($analiz['bitti_at'] ?? null) !== null ? 'Hazırlandı ' . date('d.m.Y H:i', (int) strtotime((string) $analiz['bitti_at'])) : '',
            ($okuma = RaporVerisi::okumaDakikasi($rapor)) > 0 ? '~' . $okuma . ' dk okuma' : '',
        ], static fn (string $s): bool => $s !== ''));

        if ($meta !== []) {
            $a->paragraf([['metin' => implode('  ·  ', $meta), 'punto' => 9.0, 'renk' => self::IKINCIL]]);
        }

        $kunye = array_values(array_filter([
            ($model = ModelOzeti::satirMetni($analiz['modeller'] ?? ModelOzeti::satirlar(null, (string) ($analiz['model'] ?? '')))) !== '' ? 'Model: ' . $model : '',
            ($analiz['prompt_surumu'] ?? '') !== '' ? 'İstem: ' . $analiz['prompt_surumu'] : '',
            'PDF: ' . date('d.m.Y H:i', $zaman),
        ], static fn (string $s): bool => $s !== ''));

        $a->paragraf([['metin' => implode('  ·  ', $kunye), 'punto' => 8.0, 'renk' => self::SOLUK]], ['satir' => 1.45]);

        if ($this->video !== '') {
            $adres = RaporVerisi::youtubeBaglantisi($this->video, 0);
            $a->bosluk(2);
            $a->paragraf([
                ['metin' => 'Videoyu izle: ', 'punto' => 9.0, 'renk' => self::IKINCIL],
                ['metin' => $adres, 'punto' => 9.0, 'renk' => self::VURGU, 'url' => $adres],
            ]);
        }

        $a->bosluk(14);

        $ozet = trim((string) ($rapor['summary'] ?? ''));

        if ($ozet !== '') {
            $this->baslik('Kısa özet');
            $a->paragraf([['metin' => $ozet, 'punto' => 10.5, 'renk' => self::METIN]],
                ['zemin' => self::ZEMIN, 'serit' => self::VURGU, 'ic' => 11.0, 'satir' => 1.5]);
            $a->bosluk(10);
        }
    }

    /** @param array<string,mixed> $rapor */
    private function govde(array $rapor): void
    {
        $a = $this->akis;

        $detay = trim((string) ($rapor['detailed_summary'] ?? ''));

        if ($detay !== '') {
            $this->baslik('Detaylı özet');

            foreach (preg_split('/\n\s*\n/', str_replace("\r\n", "\n", $detay)) ?: [] as $paragraf) {
                if (trim($paragraf) !== '') {
                    $a->paragraf([['metin' => trim($paragraf), 'punto' => self::GOVDE, 'renk' => self::METIN]], ['satir' => 1.5]);
                    $a->bosluk(6);
                }
            }

            $a->bosluk(6);
        }

        $konular = self::liste($rapor['topics'] ?? null);

        if ($konular !== []) {
            $this->baslik('Konular', count($konular));

            foreach ($konular as $m) {
                $ust = [array_merge($this->aralik($m['start'] ?? null, $m['end'] ?? null), [
                    ['sekme' => 92.0],
                    ['metin' => (string) ($m['title'] ?? ''), 'yazitipi' => Yazitipi::KALIN, 'punto' => 10.5, 'renk' => self::KOYU],
                ]), ['asilma' => 92.0]];
                $alt = trim((string) ($m['summary'] ?? '')) !== ''
                    ? [[['metin' => (string) $m['summary'], 'punto' => 9.5, 'renk' => self::IKINCIL]], ['girinti' => 92.0, 'satir' => 1.45]]
                    : null;

                $a->birlikteTut(array_values(array_filter([$ust, $alt])));
                $a->paragraf(...$ust);

                if ($alt !== null) {
                    $a->paragraf(...$alt);
                }

                $a->bosluk(6);
            }

            $a->bosluk(6);
        }

        $noktalar = self::liste($rapor['important_points'] ?? null);

        if ($noktalar !== []) {
            $this->baslik('Önemli noktalar', count($noktalar));

            foreach ($noktalar as $m) {
                [$etiket, $zemin, $renk] = self::ONEM[$m['importance'] ?? ''] ?? self::ONEM['useful'];
                $this->madde(array_merge([
                    ['metin' => $etiket, 'yazitipi' => Yazitipi::KALIN, 'punto' => 7.5, 'renk' => $renk, 'rozet' => $zemin],
                    ['sekme' => 54.0],
                    ['metin' => (string) ($m['text'] ?? ''), 'punto' => self::GOVDE, 'renk' => self::METIN],
                ], $this->zaman($m['at'] ?? null)), ['asilma' => 54.0, 'satir' => 1.5]);
                $a->bosluk(4);
            }

            $a->bosluk(8);
        }

        $zamanlar = self::liste($rapor['timestamps'] ?? null);

        if ($zamanlar !== []) {
            $this->baslik('Zaman çizelgesi', count($zamanlar));

            foreach ($zamanlar as $m) {
                $a->paragraf(array_merge($this->zaman($m['at'] ?? null, false), [
                    ['sekme' => 56.0],
                    ['metin' => (string) ($m['label'] ?? ''), 'punto' => self::GOVDE, 'renk' => self::METIN],
                ]), ['asilma' => 56.0, 'satir' => 1.45]);
                $a->bosluk(2);
            }

            $a->bosluk(10);
        }

        $teknik = self::liste($rapor['technical_information'] ?? null);

        if ($teknik !== []) {
            $this->baslik(RaporVerisi::TEKNIK_BASLIK, count($teknik));

            foreach (RaporVerisi::kategoriyeGore($teknik) as $kategori => $maddeler) {
                $a->yerAc(40);
                $a->paragraf([['metin' => $kategori, 'yazitipi' => Yazitipi::KALIN, 'punto' => 9.5, 'renk' => self::IKINCIL]]);
                $a->bosluk(2);

                foreach ($maddeler as $m) {
                    $parcalar = [
                        ['metin' => '•', 'punto' => self::GOVDE, 'renk' => self::VURGU],
                        ['sekme' => 12.0],
                        ['metin' => (string) ($m['name'] ?? ''), 'yazitipi' => Yazitipi::KALIN, 'punto' => self::GOVDE, 'renk' => self::KOYU],
                    ];

                    if (trim((string) ($m['description'] ?? '')) !== '') {
                        $parcalar[] = ['metin' => ' — ' . $m['description'], 'punto' => self::GOVDE, 'renk' => self::METIN];
                    }

                    $this->madde(array_merge($parcalar, $this->zaman($m['at'] ?? null)), ['asilma' => 12.0, 'satir' => 1.45]);
                    $a->bosluk(3);
                }

                $a->bosluk(6);
            }

            $a->bosluk(4);
        }

        $this->maddeListesi('Fikirler', self::liste($rapor['ideas'] ?? null), static fn (array $m): ?string => trim((string) ($m['type'] ?? '')) ?: null);
        $this->maddeListesi('İddialar', self::liste($rapor['claims'] ?? null),
            static fn (array $m): ?string => isset(RaporVerisi::GUVEN_ETIKETI[$m['speaker_confidence'] ?? '']) ? mb_strtolower(RaporVerisi::GUVEN_ETIKETI[$m['speaker_confidence']]) : null,
            'İddialar videodaki ifadelerdir, doğrulanmamıştır.');
        $this->maddeListesi('Öneriler', self::liste($rapor['recommendations'] ?? null), static fn (array $m): ?string => null);
    }

    /* =================================================================
     *  YARDIMCILAR
     * ============================================================== */

    /**
     * Tek madde: sayfa sonunda bölünmez (kısa olduğu sürece).
     *
     * @param list<array<string,mixed>> $parcalar
     * @param array<string,mixed>       $secenek
     */
    private function madde(array $parcalar, array $secenek): void
    {
        $this->akis->birlikteTut([[$parcalar, $secenek]]);
        $this->akis->paragraf($parcalar, $secenek);
    }

    /** Bölüm başlığı (+ sayı), altında ince çizgi; yer imi. */
    private function baslik(string $metin, ?int $sayi = null): void
    {
        $a = $this->akis;
        $a->yerAc(64);
        $a->pdf->yerImi($metin, $a->y, 1);

        $parcalar = [['metin' => $metin, 'yazitipi' => Yazitipi::KALIN, 'punto' => 13.0, 'renk' => self::KOYU]];

        if ($sayi !== null) {
            $parcalar[] = ['metin' => '  ' . $sayi, 'yazitipi' => Yazitipi::KALIN, 'punto' => 10.0, 'renk' => self::VURGU];
        }

        $a->paragraf($parcalar, ['satir' => 1.3]);
        $a->pdf->cizgi($a->sol, $a->y + 1, PdfBelgesi::GENISLIK - $a->sag, $a->y + 1, self::CIZGI, 0.8);
        $a->bosluk(9);
    }

    /**
     * Madde işaretli liste; tür / güven eğik ve soluk, sonda zaman.
     *
     * @param list<array<string,mixed>>            $maddeler
     * @param callable(array<string,mixed>): ?string $ek
     */
    private function maddeListesi(string $baslik, array $maddeler, callable $ek, ?string $not = null): void
    {
        if ($maddeler === []) {
            return;
        }

        $a = $this->akis;
        $this->baslik($baslik, count($maddeler));

        if ($not !== null) {
            $a->paragraf([['metin' => $not, 'yazitipi' => Yazitipi::EGIK, 'punto' => 8.5, 'renk' => self::SOLUK]]);
            $a->bosluk(4);
        }

        foreach ($maddeler as $m) {
            $metin = trim((string) ($m['text'] ?? ''));

            if ($metin === '') {
                continue;
            }

            $parcalar = [
                ['metin' => '•', 'punto' => self::GOVDE, 'renk' => self::VURGU],
                ['sekme' => 12.0],
                ['metin' => $metin, 'punto' => self::GOVDE, 'renk' => self::METIN],
            ];

            if (($tur = $ek($m)) !== null) {
                $parcalar[] = ['metin' => ' (' . $tur . ')', 'yazitipi' => Yazitipi::EGIK, 'punto' => 9.0, 'renk' => self::SOLUK];
            }

            $this->madde(array_merge($parcalar, $this->zaman($m['at'] ?? null)), ['asilma' => 12.0, 'satir' => 1.45]);
            $a->bosluk(3);
        }

        $a->bosluk(10);
    }

    /**
     * Zaman damgası parçası: YouTube'da o saniyeye giden bağlantı.
     *
     * @return list<array<string,mixed>>
     */
    private function zaman(mixed $at, bool $boslukla = true): array
    {
        $sn = RaporVerisi::saniye($at);

        if ($sn === null) {
            return [];
        }

        $parca = ['metin' => ($boslukla ? ' ' : '') . (string) $at, 'yazitipi' => Yazitipi::KALIN, 'punto' => 8.5, 'renk' => self::VURGU];

        if ($this->video !== '') {
            $parca['url'] = RaporVerisi::youtubeBaglantisi($this->video, $sn);
        }

        // Boşluk bağlantıya dahil olmasın.
        return $boslukla ? [['metin' => ' ', 'punto' => 8.5], ['metin' => (string) $at] + $parca] : [$parca];
    }

    /** @return list<array<string,mixed>> konu aralığı "00:00:00–00:01:20" (başlangıç bağlantılı) */
    private function aralik(mixed $bas, mixed $son): array
    {
        $parcalar = $this->zaman($bas, false);

        if ($parcalar !== [] && RaporVerisi::saniye($son) !== null) {
            $parcalar[] = ['metin' => '–' . $son, 'yazitipi' => Yazitipi::NORMAL, 'punto' => 8.5, 'renk' => self::SOLUK];
        }

        return $parcalar;
    }

    /** @return list<array<string,mixed>> yalnız dizi maddeler */
    private static function liste(mixed $liste): array
    {
        return is_array($liste) ? array_values(array_filter($liste, 'is_array')) : [];
    }
}
