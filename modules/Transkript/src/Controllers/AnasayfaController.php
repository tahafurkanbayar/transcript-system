<?php
/**
 * =====================================================================
 *  AnasayfaController – sitenin ana sayfası: Transkript tanıtımı
 * ---------------------------------------------------------------------
 *  Adres: "" (çekirdeğin rotası). Çekirdek HomeController, events.php'de
 *  kaydedilen çiziciyi App\Core\HomePage ile sorar; ayar kapalıysa
 *  (anasayfa.tanitim) genel vitrin görünür.
 *
 *  Sayfa yalnız ÇALIŞAN özellikleri anlatır (sohbet, kişisel özet ve
 *  dosya yükleme sonraki aşamada; burada yok). Sayılar ayarlardan gelir:
 *  süre sınırı ve günlük kota değişince sayfa da değişir.
 *
 *  VAAT YETKİYE BAĞLI (rol testi): "bağlantıyı yapıştırın" yalnız analiz
 *  başlatabilene, API kartının anahtar bağlantısı yalnız profile.api
 *  yetkisi olana. Misafir için bakılan, kayıt olunca açılacak ÜYE
 *  hesabıdır (yapabilir()). Tanım tek yerde: rol yetkileri (Role +
 *  module.json "yetkiler"); Profilim'in API bölümü de profile.api'ye bakar.
 *
 *  Hareket assets/js/anasayfa.js'te; JavaScript kapalıyken ya da
 *  "hareketi azalt" tercihinde sayfa durağan ama eksiksizdir.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Controllers;

use App\Core\Auth;
use App\Core\Exceptions\HttpException;
use App\Core\Registration;
use App\Core\Request;
use App\Http\Controller;
use App\Http\Controllers\Site\HomeController;
use App\Models\Role;
use App\Models\User;
use Modules\Transkript\Ayarlar;
use Modules\Transkript\TranskriptPolicy;
use Modules\Transkript\Varliklar;

final class AnasayfaController extends Controller
{
    public const BASLIK = 'YouTube videolarını Türkçe rapora dönüştürün';

    /** Ana sayfa (HomePage çizicisinden). */
    public function goster(Request $request): void
    {
        $kullanici = Auth::user();
        $analiz    = self::analizEdebilir($kullanici);
        $enUzunDk  = max(0, (int) Ayarlar::get('analiz.en_uzun_dk', 180));
        $sss       = self::sss(
            $enUzunDk,
            max(0, (int) Ayarlar::get('analiz.gunluk_sinir', 10)),
            in_array('tr', (array) Ayarlar::get('transcript.tercih_diller', ['tr']), true),
            $analiz,
        );

        $this->view('Transkript::anasayfa', [
            // Sekme başlığı: "<bu> · <site adı>" (Ayarlar → SEO şablonu); 60 karakteri aşmasın.
            'title'          => self::BASLIK,
            'paylasimGorseli' => Varliklar::adres('paylasim.png'),
            'girisli'        => $kullanici !== null,
            'analizEdebilir' => $kullanici !== null && $analiz,
            'analizVaadi'    => $analiz,
            'apiAcik'        => self::yapabilir($kullanici, 'profile.api'),
            'kayitAcik'      => Registration::isOpen(),
            'sure'           => self::sureMetni($enUzunDk),
            'sss'            => $sss,
            'jsonLd'         => array_merge(HomeController::structuredData(), [self::sssVerisi($sss)]),
        ], 'layouts/site');
    }

    /** GET transkript/varlik/{dosya} — yalnız ana sayfanın CSS / JS'i, girişsiz. */
    public function varlik(Request $request, string $dosya): void
    {
        $yol = Varliklar::herkeseAcik($dosya) ? Varliklar::yol($dosya) : null;

        if ($yol === null) {
            throw HttpException::notFound();
        }

        $etiket = '"' . substr(sha1($dosya . filemtime($yol) . filesize($yol)), 0, 20) . '"';

        if (!headers_sent()) {
            header_remove('Pragma');
            header('Content-Type: ' . Varliklar::tur($dosya));
            header('Cache-Control: ' . ($request->input('v') !== '' ? 'public, max-age=31536000, immutable' : 'public, no-cache'));
            header('ETag: ' . $etiket);
        }

        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etiket) {
            http_response_code(304);
            exit;
        }

        readfile($yol);
        exit;
    }

    /**
     * Bu ziyaretçi şu işi yapabilir mi? Girişsizse kayıt formunun açtığı
     * üye hesabına bakılır: sayfa, hesap açınca yapılamayacak bir şeyi
     * vaat etmesin.
     */
    public static function yapabilir(?User $kullanici, string $yetki): bool
    {
        return $kullanici !== null ? $kullanici->can($yetki) : Role::can(Role::MEMBER, $yetki);
    }

    /**
     * Analiz başlatabilir mi? Rol yetkisi ya da "Üyeler analiz
     * başlatabilir" ayarı (TranskriptPolicy::canAnalyze); girişsizse
     * yeni üye hesabının hakkı.
     */
    public static function analizEdebilir(?User $kullanici): bool
    {
        return $kullanici !== null
            ? (new TranskriptPolicy($kullanici))->canAnalyze()
            : Role::can(Role::MEMBER, 'transkript.analyze') || TranskriptPolicy::uyelerBaslatabilir();
    }

    /**
     * Süre sınırı, sayaç için sayı + birim: 180 → [3, 'saat'], 90 → [90, 'dakika'].
     * 0 (sınır yok) → null; sayfa o kutuyu başka bir bilgiyle doldurur.
     *
     * @return array{sayi:int,birim:string}|null
     */
    public static function sureMetni(int $dakika): ?array
    {
        if ($dakika <= 0) {
            return null;
        }

        return $dakika >= 120 && $dakika % 60 === 0
            ? ['sayi' => intdiv($dakika, 60), 'birim' => 'saat']
            : ['sayi' => $dakika, 'birim' => 'dakika'];
    }

    /**
     * Sık sorulan sorular: sayfa ve yapısal veri (FAQPage) aynı listeden.
     * $analiz false ise (analiz başlatamayan) video girmeyle ilgili soru
     * ve günlük analiz sınırı çıkmaz.
     *
     * @return list<array{soru:string,cevap:string}>
     */
    public static function sss(int $enUzunDk, int $gunlukSinir, bool $turkceAltyazi = true, bool $analiz = true): array
    {
        $sinir = [];

        if ($enUzunDk > 0) {
            $sure    = self::sureMetni($enUzunDk);
            $sinir[] = sprintf('En uzun video %d %s.', $sure['sayi'], $sure['birim']);
        }

        if ($gunlukSinir > 0 && $analiz) {
            $sinir[] = sprintf('Kişi başına günde %d yeni analiz yapılabilir; daha önce analiz edilmiş bir videoyu açmak bu sayıya girmez.', $gunlukSinir);
        }

        $liste = [
            [
                'soru'  => 'Hangi videolar analiz edilebilir?',
                'cevap' => 'Herkese açık ve altyazısı olan YouTube videoları. Altyazı videonun sahibinin girdiği altyazı da olabilir, YouTube\'un otomatik altyazısı da. Gizli, silinmiş ya da altyazısı olmayan bir videoda nedenini söyleyen bir mesaj görürsünüz.',
            ],
            [
                'soru'  => 'Rapor hangi dilde hazırlanır?',
                // Altyazı tercihi (Transkript ayarları): Türkçe altyazı varsa o, yoksa videonun dili.
                'cevap' => $turkceAltyazi
                    ? 'Rapor her zaman Türkçedir; video İngilizce ya da başka bir dilde olabilir. Videoda elle hazırlanmış Türkçe altyazı varsa transcript de Türkçe olur; yoksa videonun kendi dilinde kalır, böylece her maddeyi asıl cümlesiyle karşılaştırabilirsiniz.'
                    : 'Rapor her zaman Türkçedir. Video İngilizce ya da başka bir dilde olabilir; transcript ise videonun kendi dilinde kalır, böylece her maddeyi asıl cümlesiyle karşılaştırabilirsiniz.',
            ],
            [
                'soru'  => 'Analiz ne kadar sürer?',
                'cevap' => 'Videonun uzunluğuna ve yapay zekâ servisinin yoğunluğuna bağlıdır; çoğu video birkaç dakikada hazır olur. İş arka planda sürer: sayfayı kapatıp daha sonra dönebilirsiniz, ilerleme kaldığı yerden görünür.',
            ],
            [
                'soru'  => 'Video ya da ses indiriliyor mu?',
                'cevap' => 'Hayır. Yalnızca videonun altyazısı okunur; görüntü ya da ses dosyası indirilmez, saklanmaz.',
            ],
            [
                'soru'  => 'Yapay zekâ yanılabilir mi?',
                'cevap' => 'Evet; bu yüzden maddeler videodaki saniyesine bağlıdır. Zaman damgasına tıklayıp cümleyi kaynağından dinleyebilirsiniz. Yapay zekânın verdiği her zaman damgası transcript\'teki en yakın gerçek satıra çekilir; videoda karşılığı olmayan damga rapora girmez. İddialar ayrı bir bölümde "kesin ifade", "görüş" ya da "tahmin" diye işaretlenir ve doğrulanmamış sayılır.',
            ],
        ];

        if ($analiz) {
            $liste[] = [
                'soru'  => 'Aynı videoyu tekrar girersem ne olur?',
                'cevap' => 'Daha önce analiz edilmiş video yeniden yapay zekâya gönderilmez; hazır rapor hemen açılır.',
            ];
        }

        if ($sinir !== []) {
            $liste[] = ['soru' => 'Bir sınır var mı?', 'cevap' => implode(' ', $sinir)];
        }

        return $liste;
    }

    /**
     * @param  list<array{soru:string,cevap:string}> $sss
     * @return array<string,mixed>
     */
    private static function sssVerisi(array $sss): array
    {
        return [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => array_map(static fn (array $s): array => [
                '@type'          => 'Question',
                'name'           => $s['soru'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $s['cevap']],
            ], $sss),
        ];
    }
}
