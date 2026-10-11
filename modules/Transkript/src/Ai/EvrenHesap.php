<?php
/**
 * =====================================================================
 *  EvrenHesap – EVREN hesabının sohbet dışı uçları
 * ---------------------------------------------------------------------
 *      GET  /v1/terms/status   şartlar onaylı mı, güncel sürüm
 *      GET  /v1/terms/text     şart metni (yöneticiye gösterilir)
 *      POST /v1/terms/accept   {"version": n} — YALNIZ yöneticinin
 *                              "Okudum, onaylıyorum" tıklamasıyla
 *                              (AyarController::evrenSartOnayla). Kod
 *                              kendiliğinden ya da arka planda onaylamaz.
 *      GET  /v1/quota          kalan günlük token
 *      GET  /v1/models         modeller (token harcamaz)
 *
 *  Teşhis (bin/evren-teshis.php, Ayarlar → Durum) ve şart onayı burayı
 *  kullanır. Analiz çağrıları EvrenProvider'dadır. Anahtar yalnız
 *  .env'den gelir; hiçbir çıktıya ham hâliyle girmez (maskele()).
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Ai;

use App\Core\Env;
use Modules\Transkript\Http\CurlHttpIstemci;
use Modules\Transkript\Http\HttpIstemci;
use Modules\Transkript\Http\HttpYanit;

final class EvrenHesap
{
    public function __construct(
        private readonly string $anahtar,
        private readonly string $taban = EvrenProvider::TABAN_ADRES,
        private readonly HttpIstemci $http = new CurlHttpIstemci(30, false),
    ) {
    }

    /** .env'deki anahtarla; anahtar yoksa null. */
    public static function ortamdan(?HttpIstemci $http = null): ?self
    {
        $anahtar = trim(Env::get('EVREN_API_KEY', ''));
        $taban   = trim(Env::get('EVREN_BASE_URL', ''));

        return $anahtar === '' ? null : new self($anahtar, $taban !== '' ? $taban : EvrenProvider::TABAN_ADRES, $http ?? new CurlHttpIstemci(30, false));
    }

    /** Taban adres (sondaki eğik çizgisiz). */
    public function taban(): string
    {
        return rtrim($this->taban, '/');
    }

    /** Anahtarın türü: "evren_llm_" önekli mi, uzunluğu (değer yok). @return array{onek_dogru:bool, uzunluk:int, onek:string} */
    public function anahtarBilgisi(): array
    {
        return [
            'onek_dogru' => str_starts_with($this->anahtar, 'evren_llm_'),
            'uzunluk'    => strlen($this->anahtar),
            'onek'       => str_starts_with($this->anahtar, 'evren_llm_') ? 'evren_llm_' : (str_starts_with($this->anahtar, 'evren_') ? 'evren_ (platform anahtarı?)' : '(bilinmeyen önek)'),
        ];
    }

    /** İsteklerde giden kimlik başlığı, maskeli. */
    public function baslikOrnegi(): string
    {
        return 'Authorization: Bearer ' . $this->maskele($this->anahtar);
    }

    public function sartDurumu(): HttpYanit
    {
        return $this->http->get($this->taban() . '/terms/status', $this->basliklar());
    }

    public function sartMetni(): HttpYanit
    {
        return $this->http->get($this->taban() . '/terms/text', $this->basliklar());
    }

    /** Yalnız yöneticinin onay tıklamasıyla çağrılır (bkz. dosya başı). */
    public function sartlariOnayla(int $surum): HttpYanit
    {
        return $this->http->post($this->taban() . '/terms/accept', (string) json_encode(['version' => $surum]), $this->basliklar(true));
    }

    public function kota(): HttpYanit
    {
        return $this->http->get($this->taban() . '/quota', $this->basliklar());
    }

    public function modeller(): HttpYanit
    {
        return $this->http->get($this->taban() . '/models', $this->basliklar());
    }

    /** Teşhis: küçük bir sohbet isteği (ücretsiz dönemde maliyet 0). */
    public function sohbetDene(string $model = EvrenProvider::VARSAYILAN_MODEL): HttpYanit
    {
        return $this->http->post($this->taban() . '/chat/completions', (string) json_encode([
            'model'      => $model,
            'messages'   => [['role' => 'user', 'content' => 'Merhaba, tek kelimeyle yanıt ver.']],
            'max_tokens' => EvrenProvider::EN_AZ_TOKEN,
        ], JSON_UNESCAPED_UNICODE), $this->basliklar(true));
    }

    /**
     * Şart durumu gövdesinden: onaylı mı, güncel sürüm, onaylanan sürüm.
     * Alan adları sağlayıcıya göre değişebilir; bilinmeyende null.
     *
     * @return array{onayli:?bool, guncel_surum:?int, onaylanan_surum:?int}
     */
    public static function sartBilgisi(?array $veri): array
    {
        $veri ??= [];
        $onayli = $veri['accepted'] ?? $veri['terms_accepted'] ?? null;
        $guncel = $veri['current_version'] ?? $veri['required_version'] ?? $veri['version'] ?? ($veri['error']['evren']['required_terms_version'] ?? null);
        $kabul  = $veri['accepted_version'] ?? null;

        return [
            'onayli'          => is_bool($onayli) ? $onayli : null,
            'guncel_surum'    => is_numeric($guncel) ? (int) $guncel : null,
            'onaylanan_surum' => is_numeric($kabul) ? (int) $kabul : null,
        ];
    }

    /** Anahtarı (ve "evren_llm_" ile başlayan her şeyi) metinden çıkarır. */
    public function maskele(string $metin): string
    {
        if ($this->anahtar !== '') {
            $metin = str_replace($this->anahtar, 'evren_llm_****', $metin);
        }

        return (string) preg_replace('/evren_llm_[A-Za-z0-9_\-]{4,}/', 'evren_llm_****', $metin);
    }

    /** @return list<string> */
    private function basliklar(bool $json = false): array
    {
        return array_merge(
            ['Authorization: Bearer ' . $this->anahtar, 'Accept: application/json'],
            $json ? ['Content-Type: application/json'] : []
        );
    }
}
