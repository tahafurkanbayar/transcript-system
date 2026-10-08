<?php
/**
 * =====================================================================
 *  HttpYanit – bir HTTP isteğinin sonucu (değer nesnesi)
 * ---------------------------------------------------------------------
 *  Ağ hatasında durum 0 olur ve $hata doludur; HTTP hatası (404, 429 …)
 *  istisna DEĞİLDİR, çağıran duruma bakıp kendisi karar verir. Hangi
 *  durumun ne anlama geldiği servise göre değişir (oEmbed'de 401 "gizli
 *  video" demektir, Data API'de "anahtar geçersiz").
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Http;

final class HttpYanit
{
    /**
     * @param array<string,string> $basliklar küçük harfli adlarla
     */
    public function __construct(
        public readonly int $durum,
        public readonly string $govde,
        public readonly array $basliklar = [],
        public readonly string $hata = '',
        public readonly int $hataNo = 0,
    ) {
    }

    public function basarili(): bool
    {
        return $this->durum >= 200 && $this->durum < 300;
    }

    /** Ağ ya da bağlantı hatası (yanıt hiç gelmedi). */
    public function agHatasi(): bool
    {
        return $this->hata !== '';
    }

    /** curl 28 = CURLE_OPERATION_TIMEDOUT */
    public function zamanAsimi(): bool
    {
        return $this->hataNo === 28;
    }

    /** @return array<mixed>|null Gövde JSON değilse null */
    public function json(): ?array
    {
        $veri = json_decode($this->govde, true);

        return is_array($veri) ? $veri : null;
    }
}
