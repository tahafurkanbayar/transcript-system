<?php
/**
 * =====================================================================
 *  AnalizBaslatici – "Analiz Et" isteğinin TEK yolu (panel + API)
 * ---------------------------------------------------------------------
 *  HTTP isteğinde uzun iş yapılmaz: yalnız kayıt açılır ve ilk iş
 *  (KaynakBilgisiAl) kuyruğa bırakılır. İstek milisaniyeler sürer.
 *
 *  SIRA
 *    1) URL → 11 karakterlik video kimliği (UrlParser); geçersizse ret
 *    2) yetki: transkript.analyze; yeniden=1 yalnız transkript.admin
 *    3) kaynak varsa
 *         analizi sürüyorsa          → SURUYOR (ikinci iş açılmaz)
 *         güncel tamamlanmış analizi → ONBELLEKTEN (AI'a gidilmez)
 *    4) yeni AI işi maliyetlidir: kullanıcı başına saatlik sınır
 *       (Throttle; önbellekten dönen istek sayılmaz)
 *    5) kaynak satırı kilitlenir, sürmekte olan analiz yeniden
 *       kontrol edilir; analiz kaydı ve ilk iş aynı işlemde açılır
 *       → BASLATILDI
 *
 *  Yeni analiz güncel DEĞİLDİR; tamamlanınca güncel olur ve eskisini
 *  güncellikten düşürür (AnalizRepository::raporuYaz). Yeniden analiz
 *  sürerken eski rapor görünmeye devam eder.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Services;

use App\Core\Queue\Queue;
use App\Core\Throttle;
use App\Models\User;
use Modules\Transkript\Ayarlar;
use Modules\Transkript\Jobs\KaynakBilgisiAl;
use Modules\Transkript\Kaynak\KaynakHatasi;
use Modules\Transkript\Kaynak\UrlParser;
use Modules\Transkript\Repositories\AnalizRepository;
use Modules\Transkript\Repositories\KaynakRepository;
use Modules\Transkript\TranskriptPolicy;
use PDO;
use Throwable;

final class AnalizBaslatici
{
    public const BASLATILDI  = 'baslatildi';
    public const SURUYOR     = 'suruyor';
    public const ONBELLEKTEN = 'onbellekten';

    public function __construct(
        private readonly PDO $db,
        private readonly ?User $user,
    ) {
    }

    /**
     * @return array{sonuc: string, kaynak: array<string,mixed>, analiz: array<string,mixed>}
     *
     * @throws AnalizReddedildi
     */
    public function youtube(string $url, bool $yeniden = false): array
    {
        try {
            $videoId = UrlParser::cozumle($url);
        } catch (KaynakHatasi $e) {
            throw new AnalizReddedildi($e->kod, $e->kullaniciMesaji(), 422);
        }

        $policy = new TranskriptPolicy($this->user);

        if (!$policy->canAnalyze()) {
            throw new AnalizReddedildi('yetkisiz', 'Analiz başlatma yetkiniz yok.', 403);
        }

        if ($yeniden && !$policy->canReanalyze()) {
            throw new AnalizReddedildi('yetkisiz', 'Yeniden analiz yalnızca yöneticilere açıktır.', 403);
        }

        $kaynaklar = new KaynakRepository($this->db);
        $kaynak    = $kaynaklar->youtubeBul($videoId);

        if ($kaynak !== null && ($hazir = $this->hazirAnaliz($kaynak, $yeniden)) !== null) {
            return $hazir;
        }

        $this->sinirKontrolu();

        $kaynak ??= $kaynaklar->youtubeBulVeyaOlustur($videoId, $this->user?->id);

        return $this->ac($kaynak, $yeniden);
    }

    /**
     * Sürmekte olan ya da (yeniden istenmediyse) güncel tamamlanmış analiz.
     *
     * @param  array<string,mixed> $kaynak
     * @return array{sonuc: string, kaynak: array<string,mixed>, analiz: array<string,mixed>}|null
     */
    private function hazirAnaliz(array $kaynak, bool $yeniden): ?array
    {
        $kaynaklar = new KaynakRepository($this->db);
        $id        = (int) $kaynak['id'];

        if (($suren = $kaynaklar->suregelenAnaliz($id)) !== null) {
            return ['sonuc' => self::SURUYOR, 'kaynak' => $kaynak, 'analiz' => $suren];
        }

        if (!$yeniden && ($guncel = $kaynaklar->guncelTamamlanmisAnaliz($id)) !== null) {
            return ['sonuc' => self::ONBELLEKTEN, 'kaynak' => $kaynak, 'analiz' => $guncel];
        }

        return null;
    }

    private function sinirKontrolu(): void
    {
        $sinir = (int) Ayarlar::get('analiz.saatlik_sinir', 20);
        $bekle = Throttle::attempt('transkript-analiz:' . ($this->user?->id ?? 0), $sinir, 3600);

        if ($bekle > 0) {
            throw new AnalizReddedildi(
                'rate_limit',
                sprintf('Saatlik analiz sınırına ulaştınız. %d dakika sonra yeniden deneyin.', (int) ceil($bekle / 60)),
                429,
                $bekle,
            );
        }
    }

    /**
     * @param  array<string,mixed> $kaynak
     * @return array{sonuc: string, kaynak: array<string,mixed>, analiz: array<string,mixed>}
     */
    private function ac(array $kaynak, bool $yeniden): array
    {
        $kaynaklar = new KaynakRepository($this->db);
        $analizler = new AnalizRepository($this->db);

        $this->db->beginTransaction();

        try {
            $kaynaklar->kilitle((int) $kaynak['id']);

            // Kilit beklenirken başka bir istek analizi açmış olabilir.
            $hazir = $this->hazirAnaliz($kaynak, $yeniden);

            if ($hazir === null) {
                $analizId = $analizler->olustur((int) $kaynak['id'], $this->user?->id);
                Queue::push(new KaynakBilgisiAl($analizId));
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }

        return $hazir ?? [
            'sonuc'  => self::BASLATILDI,
            'kaynak' => $kaynak,
            'analiz' => $analizler->bul($analizId) ?? ['id' => $analizId, 'durum' => 'bekliyor', 'ilerleme' => 0],
        ];
    }
}
