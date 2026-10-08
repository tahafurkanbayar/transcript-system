<?php
/**
 * =====================================================================
 *  TranscriptProviderInterface – transcript sağlayıcısı sözleşmesi
 * ---------------------------------------------------------------------
 *  docs/TEKNIK_RAPOR.md, 5. bölüm. Sağlayıcılar TranscriptZinciri'nde
 *  config.php / .env (TRANSCRIPT_PROVIDERS) sırasıyla denenir.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Transcript;

use Modules\Transkript\Kaynak\Kaynak;

interface TranscriptProviderInterface
{
    /** config'teki adı: youtube · gemini · harici · asr */
    public function name(): string;

    /** Örn. AsrProvider yalnız dosya, YouTube sağlayıcıları yalnız youtube türü. */
    public function supports(Kaynak $kaynak): bool;

    /**
     * @param list<string> $preferredLangs Öncelikli diller ("tr"); sonra orijinal dil, sonra "en"
     *
     * @throws TranscriptNotFound|ProviderUnavailable|RateLimited zincir bir sonrakine geçer
     * @throws \Modules\Transkript\Kaynak\KaynakHatasi            video gizli / silinmiş: zincir durur
     */
    public function fetch(Kaynak $kaynak, array $preferredLangs): TranscriptResult;
}
