<?php
/**
 * =====================================================================
 *  AnalizReddedildi – analiz başlatma isteği kabul edilmedi
 * ---------------------------------------------------------------------
 *  Panel ve API aynı istisnayı yakalar: mesaj kullanıcıya gösterilir
 *  (Türkçe, teknik ayrıntısız), kod API'de error.code olur, http yanıt
 *  durumudur. Hız sınırında beklemeSn Retry-After başlığına gider.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Services;

use RuntimeException;

final class AnalizReddedildi extends RuntimeException
{
    public function __construct(
        public readonly string $kod,
        string $mesaj,
        public readonly int $http = 422,
        public readonly int $beklemeSn = 0,
    ) {
        parent::__construct($mesaj);
    }
}
