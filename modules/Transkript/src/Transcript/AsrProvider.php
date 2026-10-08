<?php
/**
 * =====================================================================
 *  AsrProvider – yüklenen ses / video dosyası için konuşma tanıma (5b)
 * ---------------------------------------------------------------------
 *  OpenAI uyumlu tek istemci: Groq Whisper, OpenAI ya da kendi
 *  sunucumuzdaki speaches. YALNIZCA kullanıcının yüklediği dosyada
 *  çalışır; YouTube sesi indirilip buraya verilmez (YouTube şartları,
 *  CLAUDE.md kural 8) — bu yüzden supports() yalnız dosya türlerini
 *  kabul eder. Bu sürümde iskelet. Anahtar: .env → ASR_API_KEY.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Transcript;

use Modules\Transkript\Kaynak\Kaynak;

final class AsrProvider implements TranscriptProviderInterface
{
    public function name(): string
    {
        return 'asr';
    }

    public function supports(Kaynak $kaynak): bool
    {
        return $kaynak->dosyaMi();
    }

    public function fetch(Kaynak $kaynak, array $preferredLangs): TranscriptResult
    {
        throw new ProviderUnavailable('saglayici_kapali', 'ASR (ses / video dosyası) 5b. aşamada açılacak.');
    }
}
