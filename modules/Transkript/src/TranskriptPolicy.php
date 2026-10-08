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
 *       YouTube kaynağı ve analizi herkes için ortak cache'tir
 *       (sahip_id = 0). Yüklenen dosya, sohbet ve kişisel özet yalnızca
 *       sahibine ve transkript.admin yetkisi olana açıktır.
 *
 *  Görünüm düğmeleri bu sınıfa sorarak gösterir, denetleyici de AYNI
 *  sınıfa sorarak isteği kabul eder. Düğmeyi gizlemek güvenlik değildir.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript;

use App\Models\User;

final class TranskriptPolicy
{
    /** Ekranda gösterilen yetkiler, sırasıyla. */
    public const YETKILER = [
        'transkript.view'    => 'Analiz raporlarını görmek',
        'transkript.analyze' => 'Analiz başlatmak ve dosya yüklemek (maliyet oluşturur)',
        'transkript.chat'    => 'Kayda soru sormak',
        'transkript.admin'   => 'Yeniden analiz, herkesin kaydı ve tüm maliyet',
    ];

    public function __construct(private readonly ?User $user)
    {
    }

    public function can(string $yetki): bool
    {
        return $this->user !== null && $this->user->can($yetki);
    }

    public function isAdmin(): bool
    {
        return $this->can('transkript.admin');
    }

    public function canAnalyze(): bool
    {
        return $this->can('transkript.analyze');
    }

    /** Var olan güncel analizi bırakıp yeniden AI'a göndermek (yeniden=1). */
    public function canReanalyze(): bool
    {
        return $this->isAdmin();
    }

    public function canChat(): bool
    {
        return $this->can('transkript.chat');
    }

    /** Herkesin maliyetini ve istatistiğini görmek. */
    public function canSeeAllCosts(): bool
    {
        return $this->isAdmin();
    }

    /**
     * Kaynağı ve analizini görmek. YouTube kaynağı (sahip_id = 0) ortak;
     * yüklenen dosya yalnızca sahibine ve yöneticiye.
     *
     * @param array<string,mixed> $kaynak
     */
    public function canViewSource(array $kaynak): bool
    {
        if (!$this->can('transkript.view')) {
            return false;
        }

        $sahip = (int) ($kaynak['sahip_id'] ?? 0);

        return $sahip === 0 || $this->isAdmin() || $this->isUser($sahip);
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

    private function isUser(int $id): bool
    {
        return $this->user !== null && $id > 0 && $this->user->id === $id;
    }
}
