<?php
/**
 * =====================================================================
 *  Bildirim – üyelik e-postaları (çekirdeğin Notifier düzeninde)
 * ---------------------------------------------------------------------
 *  Hepsi SESSİZDİR: gönderilemezse istisna fırlatmaz, false döner ve
 *  çekirdeğin e-posta günlüğüne düşer. Üyelik işlemi e-posta yüzünden
 *  geri alınmaz. Şablon: views/eposta/uyelik.php (çekirdeğin e-posta
 *  çerçevesiyle).
 *
 *      talepAlindi      yöneticiye: "X Pro'ya geçmek istiyor"
 *      proAcildi        üyeye: Pro açıldı / uzatıldı, bitiş tarihi
 *      bitiyor          üyeye: bitmesine N gün kaldı
 *      proBitti         üyeye: süre doldu ya da yönetici kaldırdı
 *      talepReddedildi  üyeye: yöneticinin gerekçesiyle
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Uyelik;

use App\Core\Mail\Mailable;
use App\Core\Mail\Mailer;
use App\Core\Setting;
use App\Models\Role;
use App\Models\User;
use Throwable;

final class Bildirim
{
    public static function talepAlindi(User $kullanici, int $ay, string $mesaj): bool
    {
        $admin = Mailer::adminAddress();

        if ($admin === '') {
            return false;
        }

        $uzatma = $kullanici->rol === Role::EDITOR;

        return self::gonder($admin, Setting::get('site_adi', 'Yönetici'), ($uzatma ? 'Pro uzatma talebi: ' : 'Pro üyelik talebi: ') . $kullanici->fullName(), [
            'baslik'     => $uzatma ? 'Pro üyeliğini uzatma talebi' : 'Yeni Pro üyelik talebi',
            'paragraflar' => [
                $kullanici->fullName() . ' (@' . $kullanici->kullaniciAdi . ') ' . $ay . ($uzatma ? ' ay uzatma istiyor.' : ' aylık Pro üyelik istiyor.'),
                'Ödemeyi aldığınızda Transkript → Üyelikler sayfasından talebi onaylayın; üyelik hemen açılır.',
            ],
            'bilgi'      => array_filter(['E-posta' => $kullanici->eposta, 'Süre' => $ay . ' ay', 'Not' => $mesaj]),
            'dugme'      => ['panel/transkript/uyelikler', 'Talebi aç'],
        ], null);
    }

    public static function proAcildi(User $kullanici, ?string $bitis, bool $yeni): bool
    {
        return self::gonder($kullanici->eposta, $kullanici->fullName(), $yeni ? 'Pro üyeliğiniz açıldı' : 'Pro üyeliğiniz uzatıldı', [
            'baslik'      => $yeni ? 'Pro üyeliğiniz açıldı' : 'Pro üyeliğiniz uzatıldı',
            'paragraflar' => [
                'Merhaba ' . $kullanici->ad . ', ' . ($bitis === null
                    ? 'Pro üyeliğiniz süresiz olarak açıldı.'
                    : 'Pro üyeliğiniz ' . self::tarih($bitis) . ' tarihine kadar geçerli.'),
                'Pro planında günlük analiz hakkınız ve video süre sınırınız daha yüksek; yeniden analiz ve altyazı indirme açık.',
            ],
            'dugme'       => ['panel/transkript/uyelik', 'Üyeliğimi gör'],
        ], $kullanici->id);
    }

    public static function bitiyor(User $kullanici, string $bitis): bool
    {
        return self::gonder($kullanici->eposta, $kullanici->fullName(), 'Pro üyeliğinizin süresi bitiyor', [
            'baslik'      => 'Pro üyeliğinizin süresi bitiyor',
            'paragraflar' => [
                'Merhaba ' . $kullanici->ad . ', Pro üyeliğiniz ' . self::tarih($bitis) . ' tarihinde sona erecek.',
                'Süre dolunca hesabınız Ücretsiz plana döner; raporlarınız ve kütüphaneniz silinmez. Uzatmak için Üyeliğim sayfasından talep gönderebilirsiniz.',
            ],
            'dugme'       => ['panel/transkript/uyelik', 'Üyeliğimi uzat'],
        ], $kullanici->id);
    }

    public static function proBitti(User $kullanici, bool $kaldirildi): bool
    {
        return self::gonder($kullanici->eposta, $kullanici->fullName(), 'Pro üyeliğiniz sona erdi', [
            'baslik'      => 'Pro üyeliğiniz sona erdi',
            'paragraflar' => [
                'Merhaba ' . $kullanici->ad . ', ' . ($kaldirildi ? 'Pro üyeliğiniz yönetici tarafından kapatıldı.' : 'Pro üyeliğinizin süresi doldu.')
                    . ' Hesabınız Ücretsiz plana döndü.',
                'Raporlarınız ve kütüphaneniz duruyor. API anahtarlarınız kapatıldı. Yeniden Pro olmak için Üyeliğim sayfasından talep gönderebilirsiniz.',
            ],
            'dugme'       => ['panel/transkript/uyelik', 'Üyeliğim'],
        ], $kullanici->id);
    }

    public static function talepReddedildi(User $kullanici, string $yanit): bool
    {
        return self::gonder($kullanici->eposta, $kullanici->fullName(), 'Pro üyelik talebiniz hakkında', [
            'baslik'      => 'Pro üyelik talebiniz onaylanmadı',
            'paragraflar' => array_values(array_filter([
                'Merhaba ' . $kullanici->ad . ', Pro üyelik talebiniz şu an onaylanmadı.',
                $yanit !== '' ? 'Yöneticinin notu: ' . $yanit : '',
                'Sorunuz varsa sitenin iletişim sayfasından yazabilir ya da yeni bir talep gönderebilirsiniz.',
            ])),
            'dugme'       => ['panel/transkript/uyelik', 'Üyeliğim'],
        ], $kullanici->id);
    }

    /**
     * @param array{baslik: string, paragraflar: list<string>, bilgi?: array<string,string>, dugme?: array{0: string, 1: string}} $veri
     */
    private static function gonder(string $adres, string $ad, string $konu, array $veri, ?int $kullaniciId): bool
    {
        if ($adres === '') {
            return false;
        }

        try {
            if (isset($veri['dugme'])) {
                $veri['dugme'][0] = Mailer::absolute(url($veri['dugme'][0]));
            }

            return Mailer::send(Mailable::make()
                ->to($adres, $ad)
                ->subject($konu)
                ->type('bildirim')
                ->forUser($kullaniciId)
                ->view('Transkript::eposta/uyelik', $veri));
        } catch (Throwable) {
            return false;
        }
    }

    private static function tarih(string $tarih): string
    {
        return date('d.m.Y H:i', (int) strtotime($tarih));
    }
}
