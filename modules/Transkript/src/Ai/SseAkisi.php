<?php
/**
 * =====================================================================
 *  SseAkisi – OpenAI uyumlu akışı (SSE) tek yanıta çevirir
 * ---------------------------------------------------------------------
 *  EVREN "stream": true ile parça parça yanıt verir:
 *
 *      : keep-alive                       ← yorum satırı (10 sn'de bir)
 *      data: {"choices":[{"delta":{"reasoning_content":"…"}}]}
 *      data: {"choices":[{"delta":{"content":"{\"topic\""}}]}
 *      data: {"choices":[{"delta":{},"finish_reason":"stop"}],"usage":null}
 *      data: {"choices":[],"usage":{"prompt_tokens":…}}   ← include_usage
 *      data: [DONE]
 *
 *  Kurallar (EVREN rehberi):
 *    · ":" ile başlayan satır yorumdur; JSON'a sokulmaz, keep-alive sayılır.
 *    · Bir olay birden çok "data:" satırı taşıyabilir; boş satır olayı bitirir.
 *    · delta.content yanıt metnidir; delta.reasoning_content / delta.reasoning
 *      DÜŞÜNME metnidir: rapora girmez, yalnız uzunluğu sayılır.
 *    · Son parçadaki usage alınır; [DONE] akışı bitirir.
 *    · Akış ortasında {"error": …} gelirse hata olarak döner.
 *
 *  Çıktı akışsız yanıtla aynı biçimdedir (choices[0].message.content,
 *  finish_reason, usage, model): OpenAiCompatibleProvider::normalize
 *  ikisini de aynı okur.
 * =====================================================================
 */

declare(strict_types=1);

namespace Modules\Transkript\Ai;

final class SseAkisi
{
    /**
     * @return array{
     *     yanit: array<string,mixed>|null,
     *     hata: array<string,mixed>|null,
     *     keepalive: int,
     *     dusunme_karakter: int,
     *     parca: int,
     *     bitti: bool
     * } yanit null: hiç veri parçası yok (akış değilmiş ya da boş)
     */
    public static function coz(string $raw): array
    {
        $metin     = '';
        $dusunme   = 0;
        $keepalive = 0;
        $parca     = 0;
        $model     = '';
        $kimlik    = '';
        $bitis     = null;
        $usage     = null;
        $hata      = null;
        $bitti     = false;
        $veri      = [];

        $olayiIsle = static function (array $satirlar) use (&$metin, &$dusunme, &$parca, &$model, &$kimlik, &$bitis, &$usage, &$hata, &$bitti): void {
            $yuk = trim(implode("\n", $satirlar));

            if ($yuk === '') {
                return;
            }

            if ($yuk === '[DONE]') {
                $bitti = true;

                return;
            }

            $json = json_decode($yuk, true);

            if (!is_array($json)) {
                return;
            }

            $parca++;

            if (isset($json['error'])) {
                $hata = is_array($json['error']) ? $json['error'] : ['message' => (string) $json['error']];

                return;
            }

            $model  = $model !== '' ? $model : trim((string) ($json['model'] ?? ''));
            $kimlik = $kimlik !== '' ? $kimlik : (string) ($json['id'] ?? '');

            if (is_array($json['usage'] ?? null)) {
                $usage = $json['usage'];
            }

            $secim = $json['choices'][0] ?? null;

            if (!is_array($secim)) {
                return;
            }

            $delta = is_array($secim['delta'] ?? null) ? $secim['delta'] : (is_array($secim['message'] ?? null) ? $secim['message'] : []);

            if (is_string($delta['content'] ?? null)) {
                $metin .= $delta['content'];
            }

            foreach (['reasoning_content', 'reasoning'] as $alan) {
                if (is_string($delta[$alan] ?? null)) {
                    $dusunme += mb_strlen($delta[$alan]);
                }
            }

            if (is_string($secim['finish_reason'] ?? null) && $secim['finish_reason'] !== '') {
                $bitis = $secim['finish_reason'];
            }
        };

        foreach (preg_split('/\r\n|\n|\r/', $raw) ?: [] as $satir) {
            if ($satir === '') {
                $olayiIsle($veri);
                $veri = [];

                continue;
            }

            if ($satir[0] === ':') {
                $keepalive++;

                continue;
            }

            if (str_starts_with($satir, 'data:')) {
                $veri[] = ltrim(substr($satir, 5), ' ');
            }
            // event:, id:, retry: alanları kullanılmaz.
        }

        $olayiIsle($veri);

        $yanit = $parca === 0 && $hata === null ? null : [
            'id'      => $kimlik,
            'model'   => $model,
            'choices' => [[
                'index'         => 0,
                'message'       => ['role' => 'assistant', 'content' => $metin],
                'finish_reason' => $bitis,
            ]],
            'usage'   => $usage ?? [],
        ];

        return ['yanit' => $yanit, 'hata' => $hata, 'keepalive' => $keepalive, 'dusunme_karakter' => $dusunme, 'parca' => $parca, 'bitti' => $bitti];
    }

    /** Akışın bu satırı gerçek veri mi (token)? Keep-alive, boş ve [DONE] değil. */
    public static function veriSatiriMi(string $satir): bool
    {
        if (!str_starts_with($satir, 'data:')) {
            return false;
        }

        $yuk = trim(substr($satir, 5));

        return $yuk !== '' && $yuk !== '[DONE]';
    }
}
