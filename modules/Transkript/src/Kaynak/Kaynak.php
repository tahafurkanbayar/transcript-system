<?php
/**
 * =====================================================================
 *  Kaynak – transkript_kaynaklar satırının değer nesnesi
 * ---------------------------------------------------------------------
 *  Transcript sağlayıcıları kaynağın türüne bakar (supports) ve
 *  kimliğiyle çalışır; veritabanı dizisinin anahtarlarını bilmezler.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Kaynak;

final class Kaynak
{
    public const TURLER = ['youtube', 'ses_dosyasi', 'video_dosyasi'];

    public function __construct(
        public readonly int $id,
        public readonly string $tur,
        /** YouTube'da 11 karakterlik video kimliği, dosyada SHA-256 özeti */
        public readonly string $disKimlik,
        public readonly int $sahipId = 0,
        public readonly ?int $sureSn = null,
        public readonly ?string $dil = null,
        public readonly ?string $dosyaYolu = null,
        public readonly ?string $mime = null,
    ) {
    }

    /** @param array<string,mixed> $satir */
    public static function satirdan(array $satir): self
    {
        return new self(
            id:        (int) ($satir['id'] ?? 0),
            tur:       (string) ($satir['tur'] ?? ''),
            disKimlik: (string) ($satir['dis_kimlik'] ?? ''),
            sahipId:   (int) ($satir['sahip_id'] ?? 0),
            sureSn:    isset($satir['sure_sn']) ? (int) $satir['sure_sn'] : null,
            dil:       isset($satir['dil']) && $satir['dil'] !== '' ? (string) $satir['dil'] : null,
            dosyaYolu: isset($satir['dosya_yolu']) ? (string) $satir['dosya_yolu'] : null,
            mime:      isset($satir['mime']) ? (string) $satir['mime'] : null,
        );
    }

    public function youtubeMu(): bool
    {
        return $this->tur === 'youtube';
    }

    public function dosyaMi(): bool
    {
        return $this->tur === 'ses_dosyasi' || $this->tur === 'video_dosyasi';
    }
}
