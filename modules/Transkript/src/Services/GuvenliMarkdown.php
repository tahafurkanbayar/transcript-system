<?php
/**
 * =====================================================================
 *  GuvenliMarkdown – dış metni (EVREN kullanım şartları) güvenle biçimler
 * ---------------------------------------------------------------------
 *  Şart sayfası Markdown'ı ham gösteriyordu ("## 1. Kapsam", "**…**",
 *  shields.io rozet satırı; cowork 2. test turu). Bağımlılık eklenmez
 *  (CLAUDE.md kural 4); CommonMark'ın bu metinlerde geçen alt kümesi:
 *
 *    başlık (#…######), paragraf, alıntı (>), madde ve numaralı liste
 *    (devam satırlarıyla), yatay çizgi (---), kod bloğu (```), satır içi
 *    kod, kalın, eğik, üstü çizili, bağlantı, <https://…> bağlantısı.
 *
 *  GÜVENLİK
 *    · Ham HTML KAPALI: bütün metin htmlspecialchars'tan geçer; etiket
 *      düz metin olarak görünür. Yalnız bu sınıfın ürettiği etiketler var.
 *    · Bağlantı yalnız http / https / mailto; target="_blank",
 *      rel="noopener nofollow noreferrer". Öteki şema (javascript:, data:)
 *      bağlantı olmaz, yalnız metni kalır.
 *    · Resim YÜKLENMEZ (dış istek, izleme): yerine alt metni yazılır.
 *    · Başlıklar sayfanın başlığının altına iner (# → h4).
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Services;

final class GuvenliMarkdown
{
    /** "# Başlık" → <h4>: sayfada kartın başlığı h3. */
    private const BASLIK_KAYMASI = 3;

    /** @var list<string> satır içi yer tutucuların HTML'i */
    private array $saklanan = [];

    public static function html(string $metin): string
    {
        // \x01 \x02 satır içi yer tutucunun işaretleri; metinde bulunmasın.
        $metin = str_replace(["\r\n", "\r", "\0", "\x01", "\x02"], ["\n", "\n", '', '', ''], $metin);

        return '<div class="tk-md">' . (new self())->bloklar(explode("\n", $metin)) . '</div>';
    }

    /* =================================================================
     *  BLOKLAR
     * ============================================================== */

    /** @param list<string> $satirlar */
    private function bloklar(array $satirlar): string
    {
        $html = '';
        $n    = count($satirlar);

        for ($i = 0; $i < $n;) {
            $satir = $satirlar[$i];

            if (trim($satir) === '') {
                $i++;

                continue;
            }

            // Kod bloğu: ``` … ``` (içi olduğu gibi, kaçışlı)
            if (preg_match('/^\s{0,3}(```|~~~)/', $satir, $m)) {
                $kod = [];

                for ($i++; $i < $n && !str_starts_with(ltrim($satirlar[$i]), $m[1]); $i++) {
                    $kod[] = $satirlar[$i];
                }

                $i++;
                $html .= '<pre><code>' . self::kacis(implode("\n", $kod)) . '</code></pre>';

                continue;
            }

            if (preg_match('/^\s{0,3}(#{1,6})\s+(.*?)\s*#*\s*$/u', $satir, $m)) {
                $duzey = min(6, strlen($m[1]) + self::BASLIK_KAYMASI);
                $html .= '<h' . $duzey . '>' . $this->satirIci($m[2]) . '</h' . $duzey . '>';
                $i++;

                continue;
            }

            if (preg_match('/^\s{0,3}([-*_])(\s*\1){2,}\s*$/', $satir)) {
                $html .= '<hr>';
                $i++;

                continue;
            }

            if (preg_match('/^\s{0,3}>/', $satir)) {
                $alinti = [];

                for (; $i < $n && preg_match('/^\s{0,3}>\s?(.*)$/u', $satirlar[$i], $m); $i++) {
                    $alinti[] = $m[1];
                }

                $html .= '<blockquote>' . $this->bloklar($alinti) . '</blockquote>';

                continue;
            }

            if (self::listeMaddesi($satir) !== null) {
                [$parca, $i] = $this->liste($satirlar, $i);
                $html .= $parca;

                continue;
            }

            // Paragraf: boş satıra ya da başka bir blok başına kadar; satırlar birleşir.
            $paragraf = [];

            for (; $i < $n && trim($satirlar[$i]) !== '' && ($paragraf === [] || !self::blokBasi($satirlar[$i])); $i++) {
                $paragraf[] = trim($satirlar[$i]);
            }

            $html .= '<p>' . $this->satirIci(implode(' ', $paragraf)) . '</p>';
        }

        return $html;
    }

    /**
     * Madde ya da numaralı liste; girintili devam satırları maddeye eklenir.
     *
     * @param  list<string> $satirlar
     * @return array{0: string, 1: int} HTML ve sonraki satır
     */
    private function liste(array $satirlar, int $i): array
    {
        $numarali = self::listeMaddesi($satirlar[$i])['numarali'];
        $maddeler = [];
        $n        = count($satirlar);

        while ($i < $n) {
            $madde = self::listeMaddesi($satirlar[$i]);

            if ($madde !== null) {
                if ($madde['numarali'] !== $numarali) {
                    break;
                }

                $maddeler[] = [$madde['metin']];
                $i++;

                continue;
            }

            // Devam satırı: girintili ya da (boşluksuz) düz metin; boş satırdan sonra liste biter.
            if (trim($satirlar[$i]) !== '' && $maddeler !== [] && !self::blokBasi($satirlar[$i])) {
                $maddeler[count($maddeler) - 1][] = trim($satirlar[$i]);
                $i++;

                continue;
            }

            break;
        }

        $etiket = $numarali ? 'ol' : 'ul';
        $html   = '<' . $etiket . '>';

        foreach ($maddeler as $madde) {
            $html .= '<li>' . $this->satirIci(implode(' ', $madde)) . '</li>';
        }

        return [$html . '</' . $etiket . '>', $i];
    }

    /** @return array{numarali: bool, metin: string}|null */
    private static function listeMaddesi(string $satir): ?array
    {
        if (preg_match('/^\s{0,3}[-*+]\s+(.*)$/u', $satir, $m) && !preg_match('/^\s{0,3}([-*_])(\s*\1){2,}\s*$/', $satir)) {
            return ['numarali' => false, 'metin' => $m[1]];
        }

        if (preg_match('/^\s{0,3}\d{1,9}[.)]\s+(.*)$/u', $satir, $m)) {
            return ['numarali' => true, 'metin' => $m[1]];
        }

        return null;
    }

    /** Satır yeni bir blok başlatıyor mu (paragrafı ya da maddeyi keser)? */
    private static function blokBasi(string $satir): bool
    {
        return (bool) preg_match('/^\s{0,3}(#{1,6}\s|>|```|~~~|([-*_])(\s*\2){2,}\s*$)/', $satir)
            || self::listeMaddesi($satir) !== null;
    }

    /* =================================================================
     *  SATIR İÇİ
     * ============================================================== */

    /**
     * Kod, resim ve bağlantı önce yer tutucuya alınır (içleri biçimlenmez),
     * kalan metin kaçışlanır, sonra kalın / eğik / üstü çizili.
     */
    private function satirIci(string $metin, bool $baglanti = true): string
    {
        $metin = (string) preg_replace_callback('/(`+)(.+?)\1/u', fn (array $m): string => $this->sakla('<code>' . self::kacis(trim($m[2])) . '</code>'), $metin);

        // URL'de bir kat parantez olabilir: …/badge/durum-esaslı%20(v1%2C%20ilk%20yayın)-orange
        $url = '((?:[^()\s]|\([^()\s]*\))+)(?:\s+"[^"]*")?';

        // Resim: dış kaynak yüklenmez; alt metni yazılır.
        $metin = (string) preg_replace_callback('/!\[([^\]]*)\]\(' . $url . '\)/u', function (array $m): string {
            $alt = trim($m[1]);

            return $alt === '' ? '' : $this->sakla('<span class="tk-md-resim" title="Dış resim gösterilmez">' . self::kacis($alt) . '</span>');
        }, $metin);

        if ($baglanti) {
            $metin = (string) preg_replace_callback('/\[([^\]]+)\]\(' . $url . '\)/u',
                fn (array $m): string => $this->sakla(self::baglanti($m[2], $this->satirIci($m[1], false))), $metin);
            $metin = (string) preg_replace_callback('/<((?:https?:\/\/|mailto:)[^\s<>]+)>/u',
                fn (array $m): string => $this->sakla(self::baglanti($m[1], self::kacis($m[1]))), $metin);
        }

        $metin = self::kacis($metin);
        $metin = (string) preg_replace('/(\*\*|__)(?=\S)(.+?)(?<=\S)\1/u', '<strong>$2</strong>', $metin);
        $metin = (string) preg_replace('/(?<![\p{L}\p{N}*])\*(?=\S)(.+?)(?<=\S)\*(?!\*)/u', '<em>$1</em>', $metin);
        $metin = (string) preg_replace('/(?<![\p{L}\p{N}_])_(?=\S)(.+?)(?<=\S)_(?![\p{L}\p{N}_])/u', '<em>$1</em>', $metin);
        $metin = (string) preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/u', '<del>$1</del>', $metin);

        // Yer tutucular geri (iç içe olabilir: bağlantı metnindeki kod).
        while (preg_match('/\x01(\d+)\x02/', $metin)) {
            $metin = (string) preg_replace_callback('/\x01(\d+)\x02/', fn (array $m): string => $this->saklanan[(int) $m[1]] ?? '', $metin);
        }

        return $metin;
    }

    private function sakla(string $html): string
    {
        $this->saklanan[] = $html;

        return "\x01" . (count($this->saklanan) - 1) . "\x02";
    }

    /** Yalnız http / https / mailto bağlantı olur; öteki şemada yalnız metin. */
    private static function baglanti(string $adres, string $metinHtml): string
    {
        $adres = trim($adres);

        if (!preg_match('/^(https?:\/\/[^\s"<>]+|mailto:[^\s"<>]+)$/iu', $adres)) {
            return $metinHtml;
        }

        return '<a href="' . self::kacis($adres) . '" target="_blank" rel="noopener nofollow noreferrer">' . $metinHtml . '</a>';
    }

    private static function kacis(string $metin): string
    {
        return htmlspecialchars($metin, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
