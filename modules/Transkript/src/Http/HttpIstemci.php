<?php
/**
 * =====================================================================
 *  HttpIstemci – dış servislere (YouTube, transcript API'leri) istek
 * ---------------------------------------------------------------------
 *  Arayüz olmasının tek sebebi test: YoutubeMetadata ve transcript
 *  sağlayıcıları bu arayüzü alır, birim testleri sahte bir istemciyle
 *  ağa gitmeden çalışır (CLAUDE.md, kural 10). Gerçek uygulaması
 *  CurlHttpIstemci'dir; Composer paketi yoktur.
 *
 *  AI sağlayıcıları (src/Ai) kendi HTTP katmanlarını taşır; bu istemci
 *  onların yerine geçmez.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Http;

interface HttpIstemci
{
    /**
     * @param array<int,string> $basliklar "Ad: değer" biçiminde
     */
    public function get(string $adres, array $basliklar = []): HttpYanit;

    /**
     * @param array<int,string> $basliklar "Ad: değer" biçiminde
     */
    public function post(string $adres, string $govde, array $basliklar = []): HttpYanit;
}
