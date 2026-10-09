<?php
/**
 * =====================================================================
 *  TranskriptPolicy – "Bu kullanıcı bu kayda ne yapabilir?" sorusunun
 *  TEK cevap yeri
 * ---------------------------------------------------------------------
 *  RBAC iki katmandır (bkz. modules/Ornek/src/OrnekPolicy.php):
 *
 *    1) ROL → YETKİ (kaba): rotadaki can:… ara katmanı cevaplar.
 *       Yetkiler module.json → "yetkiler" bloğunda rollere dağıtılır;
 *       yönetici her yetkiye sahiptir.
 *
 *         transkript.view     raporları görür
 *         transkript.analyze  analiz başlatır, dosya yükler (maliyet oluşturur)
 *         transkript.chat     kayda soru sorar
 *         transkript.admin    yeniden analiz, herkesin kaydı, tüm maliyet
 *
 *    2) SATIR DÜZEYİ (ince): bu sınıf.
 *       ÖNBELLEK ORTAK, GÖRÜNÜRLÜK KİŞİSEL. YouTube kaynağı ve analizi
 *       tek kopyadır (sahip_id = 0; aynı video ikinci kez yapay zekâya
 *       gitmez) ama raporu yalnız onu KÜTÜPHANESİNE ekleyen
 *       (transkript_kutuphane) ve yöneticinin PAYLAŞTIĞI raporu
 *       (paylasildi = 1) herkes görür. Yönetici her şeyi görür.
 *       Yüklenen dosya, sohbet ve kişisel özet yalnızca sahibine ve
 *       yöneticiye açıktır.
 *
 *  ROL MATRİSİ (kendi = analizi başlatan / kütüphanesindeki)
 *
 *    eylem                     yönetici   analist (editor)   üye
 *    rapor görmek              hepsi      kütüphane + paylaşılan
 *    analiz başlatmak          kotasız    kotayla            ayar açıksa, kotayla
 *    duran analizi sürdürmek   hepsi      yalnız kendi       (ayarla) yalnız kendi
 *    yeniden analiz            hepsi      yalnız kendi raporu, kotadan düşer
 *    süren analizi iptal       hepsi      yalnız kendi
 *    kütüphaneden çıkarmak     kendi kütüphanesinden (ortak kayıt kalır)
 *    tamamen silmek            YouTube: yalnız yönetici · dosya: sahibi de
 *    paylaşmak                 yalnız yönetici
 *    token / maliyet           yalnız yönetici
 *
 *  KONTROL SIRASI (her uç): giriş → yetki ve sahiplik (görmüyorsa 404,
 *  göremiyorsa varlığı da sızmaz; görüyor ama yapamıyorsa 403) → en son
 *  durum (409 / 422).
 *
 *  Görünüm düğmeleri bu sınıfa sorarak gösterir, denetleyici de AYNI
 *  sınıfa sorarak isteği kabul eder. Düğmeyi gizlemek güvenlik değildir.
 *  Liste ve sayaçlar aynı kuralı SQL'de KaynakRepository::gorunurluk()
 *  ile uygular; tests/transkript.php ikisini aynı örneklerle sınar.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript;

use App\Core\Database;
use App\Models\Role;
use App\Models\User;
use Modules\Transkript\Repositories\KutuphaneRepository;

final class TranskriptPolicy
{
    /** Ekranda gösterilen yetkiler, sırasıyla. */
    public const YETKILER = [
        'transkript.view'    => 'Kütüphanesindeki ve paylaşılan raporları görmek',
        'transkript.analyze' => 'Analiz başlatmak ve dosya yüklemek (maliyet oluşturur)',
        'transkript.chat'    => 'Kayda soru sormak',
        'transkript.admin'   => 'Herkesin kaydı, paylaşım, tamamen silme ve tüm maliyet',
    ];

    public function __construct(
        private readonly ?User $user,
        private readonly ?KutuphaneRepository $kutuphane = null,
    ) {
    }

    public function can(string $yetki): bool
    {
        return $this->user !== null && $this->user->can($yetki);
    }

    public function isAdmin(): bool
    {
        return $this->can('transkript.admin');
    }

    /**
     * Analiz başlatmak: transkript.analyze yetkisi (yönetici, analist) ya
     * da üye + Transkript ayarlarındaki "Üyeler analiz başlatabilir".
     */
    public function canAnalyze(): bool
    {
        return $this->can('transkript.analyze') || $this->uyeBaslatabilir();
    }

    /** Ayar açıksa üye de analiz başlatır (varsayılan kapalı). */
    public static function uyelerBaslatabilir(): bool
    {
        return (bool) Ayarlar::get('analiz.uye_baslatabilir', false);
    }

    /**
     * Güncel raporu bırakıp videoyu yeniden yapay zekâya göndermek
     * (yeniden=1): yönetici her raporu, analiz başlatabilen yalnız KENDİ
     * başlattığı raporu (kotadan düşer).
     *
     * @param array<string,mixed>|null $rapor güncel tamamlanmış analiz (kullanici_id); yoksa yalnız yönetici
     */
    public function canReanalyze(?array $rapor = null): bool
    {
        return $this->isAdmin() || ($rapor !== null && $this->canAnalyze() && $this->isUser((int) ($rapor['kullanici_id'] ?? 0)));
    }

    /**
     * Geçici sebeple (kota, servis) duran analizi kaldığı yerden sürdürmek
     * ("Yeniden dene"): yönetici ya da analizi başlatan. Başkasının duran
     * işine dokunulmaz; o kişi aynı videoyu gönderirse kendi analizi açılır.
     *
     * @param array<string,mixed> $analiz kullanici_id taşıyan analiz satırı
     */
    public function canRetry(array $analiz): bool
    {
        return $this->isAdmin() || ($this->canAnalyze() && $this->isUser((int) ($analiz['kullanici_id'] ?? 0)));
    }

    public function canChat(): bool
    {
        return $this->can('transkript.chat');
    }

    /**
     * Kaynağı TAMAMEN silmek (analizleri, transcript'i, sohbetleri ve
     * herkesin kütüphanesindeki satırıyla). YouTube kaynağı ortak
     * önbellektir: yalnız yönetici siler. Yüklenen dosyayı sahibi de
     * silebilir. Kullanıcı kendi listesini "Kütüphanemden çıkar" ile temizler.
     *
     * @param array<string,mixed> $kaynak
     */
    public function canDeleteSource(array $kaynak): bool
    {
        $sahip = (int) ($kaynak['sahip_id'] ?? 0);

        return $this->canViewSource($kaynak) && ($this->isAdmin() || ($sahip > 0 && $this->isUser($sahip)));
    }

    /**
     * "Kütüphanemden çıkar": YouTube kaynağı kullanıcının kütüphanesindeyse.
     * Ortak kayıt ve rapor silinmez; paylaşılan rapor listede kalır.
     *
     * @param array<string,mixed> $kaynak
     */
    public function canRemoveFromLibrary(array $kaynak): bool
    {
        return $this->can('transkript.view') && (int) ($kaynak['sahip_id'] ?? 0) === 0 && $this->kutuphanede($kaynak);
    }

    /** Raporu "Üyelerle paylaş" olarak işaretlemek ya da paylaşımı kaldırmak. */
    public function canShare(): bool
    {
        return $this->isAdmin();
    }

    /**
     * API anahtarı üretebilir mi? Çekirdeğin profile.api yetkisi
     * (yönetici ve analist; üye üretemez). Ana sayfadaki API kartı da
     * buna göre konuşur.
     */
    public function canCreateApiKey(): bool
    {
        return $this->can('profile.api');
    }

    /**
     * Süren analizi iptal etmek: yönetici ya da analizi başlatan (analiz
     * yetkisi hâlâ varsa).
     *
     * @param array<string,mixed> $analiz transkript_analizler satırı (kullanici_id)
     */
    public function canCancelAnalysis(array $analiz): bool
    {
        return $this->isAdmin() || ($this->canAnalyze() && $this->isUser((int) ($analiz['kullanici_id'] ?? 0)));
    }

    /**
     * Başarısız analizin iç nedenini (kota, servis, ayar) görmek: yönetici
     * ya da analizi başlatan. Ötekiler HataKodlari::GENEL metni okur;
     * videonun kendisinden gelen neden (altyazı yok…) herkese açıktır.
     *
     * @param array<string,mixed> $analiz kullanici_id taşıyan analiz satırı
     */
    public function canSeeErrorDetail(array $analiz): bool
    {
        return $this->isAdmin() || $this->isUser((int) ($analiz['kullanici_id'] ?? 0));
    }

    /** Herkesin maliyetini ve istatistiğini görmek. */
    public function canSeeAllCosts(): bool
    {
        return $this->isAdmin();
    }

    /**
     * Kaynağı ve analizini görmek: yönetici hepsini; yüklenen dosyayı
     * sahibi; YouTube kaynağını (sahip_id = 0) kütüphanesine ekleyen ve
     * yönetici paylaştıysa herkes. SQL karşılığı KaynakRepository::gorunurluk().
     *
     * @param array<string,mixed> $kaynak "kutuphanede" anahtarı varsa veritabanına sorulmaz
     */
    public function canViewSource(array $kaynak): bool
    {
        if (!$this->can('transkript.view')) {
            return false;
        }

        if ($this->isAdmin()) {
            return true;
        }

        $sahip = (int) ($kaynak['sahip_id'] ?? 0);

        if ($sahip > 0) {
            return $this->isUser($sahip);
        }

        return (int) ($kaynak['paylasildi'] ?? 0) === 1 || $this->kutuphanede($kaynak);
    }

    /**
     * Kaynağa soru sormak: kaynağı görebilmeli ve sohbet yetkisi olmalı.
     *
     * @param array<string,mixed> $kaynak
     */
    public function canChatAbout(array $kaynak): bool
    {
        return $this->canChat() && $this->canViewSource($kaynak);
    }

    /**
     * Bir sohbeti okumak ve sürdürmek: yalnızca sahibi ve yönetici.
     *
     * @param array<string,mixed> $sohbet
     */
    public function canAccessChat(array $sohbet): bool
    {
        return $this->can('transkript.view')
            && ($this->isAdmin() || $this->isUser((int) ($sohbet['kullanici_id'] ?? 0)));
    }

    /**
     * Kişisel özeti görmek: yalnızca sahibi ve yönetici.
     *
     * @param array<string,mixed> $ozet
     */
    public function canViewPersonalSummary(array $ozet): bool
    {
        return $this->can('transkript.view')
            && ($this->isAdmin() || $this->isUser((int) ($ozet['kullanici_id'] ?? 0)));
    }

    /**
     * Kaynak bu kullanıcının kütüphanesinde mi? Satır "kutuphanede"
     * taşıyorsa (liste sorgusu, testler) ona bakılır; yoksa tek sorgu.
     *
     * @param array<string,mixed> $kaynak
     */
    private function kutuphanede(array $kaynak): bool
    {
        if (array_key_exists('kutuphanede', $kaynak)) {
            return (bool) $kaynak['kutuphanede'];
        }

        if ($this->user === null || (int) ($kaynak['id'] ?? 0) < 1) {
            return false;
        }

        return ($this->kutuphane ?? new KutuphaneRepository(Database::connection()))->iceriyor($this->user->id, (int) $kaynak['id']);
    }

    private function uyeBaslatabilir(): bool
    {
        return $this->user !== null && $this->user->rol === Role::MEMBER
            && $this->can('transkript.view') && self::uyelerBaslatabilir();
    }

    private function isUser(int $id): bool
    {
        return $this->user !== null && $id > 0 && $this->user->id === $id;
    }
}
