<?php
/**
 * =====================================================================
 *  MIGRATION: yapay zekâ çağrı ölçümleri ve analiz başına EVREN modeli
 * ---------------------------------------------------------------------
 *  EVREN geliştirme (docs/evren, P0-1 ve P1-1). Mevcut satırlara dokunmaz;
 *  yeni sütunlar eski kayıtlarda NULL kalır.
 *
 *  ai_kullanim_kaydi
 *    created_at artık çağrının KENDİ başlangıcıdır (zincirde düşen
 *    denemeler de kendi saatiyle; eskiden hepsi zincirin bitişini
 *    alıyordu). Ek sütunlar:
 *      bitis_at        çağrının bitişi
 *      asama           map / reduce
 *      istenen_model   ayardaki model ("auto"); model sütunu yanıttaki
 *      akis            akışlı (SSE) mı
 *      max_token       gönderilen bütçe
 *      bitis_nedeni    finish_reason (stop / length …)
 *      dusunme_token   completion_tokens_details.reasoning_tokens
 *      onbellek_token  prompt_tokens_details.cached_tokens (%3 ağırlık)
 *      ilk_token_ms    akışta ilk veri (TTFT)
 *      http_durum      HTTP durumu
 *      kalan_kota      x-evren-daily-remaining-tokens (çağrıdan sonra)
 *
 *  transkript_analizler.evren_model
 *    Ayar "auto" ise analizin ilk başarılı EVREN yanıtındaki metin modeli
 *    ("glm-5.3"); sonraki bölümler ve birleştirme bu modelle çağrılır.
 * =====================================================================
 */

declare(strict_types=1);

return new class extends App\Core\Database\Migration
{
    /** @var array<string,string> sütun => tanım */
    private const KAYIT_SUTUNLARI = [
        'bitis_at'       => 'DATETIME NULL DEFAULT NULL',
        'asama'          => 'VARCHAR(20) NULL DEFAULT NULL',
        'istenen_model'  => 'VARCHAR(100) NULL DEFAULT NULL',
        'akis'           => 'TINYINT(1) NULL DEFAULT NULL',
        'max_token'      => 'INT UNSIGNED NULL DEFAULT NULL',
        'bitis_nedeni'   => 'VARCHAR(30) NULL DEFAULT NULL',
        'dusunme_token'  => 'INT UNSIGNED NULL DEFAULT NULL',
        'onbellek_token' => 'INT UNSIGNED NULL DEFAULT NULL',
        'ilk_token_ms'   => 'INT UNSIGNED NULL DEFAULT NULL',
        'http_durum'     => 'SMALLINT UNSIGNED NULL DEFAULT NULL',
        'kalan_kota'     => 'BIGINT UNSIGNED NULL DEFAULT NULL',
    ];

    public function up(): void
    {
        foreach (self::KAYIT_SUTUNLARI as $sutun => $tanim) {
            if (!$this->columnExists('ai_kullanim_kaydi', $sutun)) {
                $this->execute('ALTER TABLE `ai_kullanim_kaydi` ADD COLUMN `' . $sutun . '` ' . $tanim);
            }
        }

        if (!$this->columnExists('transkript_analizler', 'evren_model')) {
            $this->execute('ALTER TABLE `transkript_analizler` ADD COLUMN `evren_model` VARCHAR(100) NULL DEFAULT NULL AFTER `modeller`');
        }
    }

    public function down(): void
    {
        if ($this->columnExists('transkript_analizler', 'evren_model')) {
            $this->execute('ALTER TABLE `transkript_analizler` DROP COLUMN `evren_model`');
        }

        foreach (array_reverse(array_keys(self::KAYIT_SUTUNLARI)) as $sutun) {
            if ($this->columnExists('ai_kullanim_kaydi', $sutun)) {
                $this->execute('ALTER TABLE `ai_kullanim_kaydi` DROP COLUMN `' . $sutun . '`');
            }
        }
    }
};
