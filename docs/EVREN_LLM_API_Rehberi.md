# EVREN LLM API rehberi

SSYZ'nin yurt içi yapay zekâ geçidi (H200 kümesi). Bu rehber üç kaynaktan
derlendi: EVREN'in VS Code REST Client istek dosyası (20 örnek istek), canlı
`GET /v1/models` ve `GET /v1/quota` yanıtları (11 Ekim 2026) ve Transkript
modülündeki ölçümler (`docs/evren/`). Fiyatlar ve sınırlar değişebilir;
güncelini `/v1/models` ve `/v1/quota` söyler.

## 1. Bağlantı

| | |
|---|---|
| Taban adres | `https://evren-llmapi.ssyz.org.tr/v1` |
| Kimlik | `Authorization: Bearer <anahtar>` ya da `X-API-Key: <anahtar>` (ikisi de geçer) |
| Anahtar | `evren_llm_` ile başlar; evren.ssyz.org.tr → API anahtarları. `evren_` ile başlayan platform anahtarı LLM uçlarında çalışmaz |
| Uyumluluk | OpenAI uyumlu: `chat/completions` (akış dahil), `responses`, `embeddings`, `audio/transcriptions`, `models` |
| EVREN'e özgü | `ocr`, `rerank`, `systemone`, `systemone/batch`, `quota`, `terms/status`, `terms/text`, `terms/accept` |

Anahtar yalnız `.env`'de durur (`EVREN_API_KEY`); koda, günlüğe, rapora
girmez. REST Client dosyası anahtarı `{{$dotenv EVREN_API_KEY}}` ile okur.

## 2. Kullanım şartları (zorunlu kapı)

1. `GET /v1/terms/status` → `{"current_version": 1, "is_material": true, "accepted": false}`
2. `GET /v1/terms/text` → metin (Markdown)
3. `POST /v1/terms/accept` gövde `{"version": 1}` (2. adımdaki sürüm)

Onaylanmamışsa her çağrı **403** `terms_not_accepted` döner; gövdede
`error.evren.required_terms_version` gereken sürümü söyler. Yeni **esaslı**
sürüm çıkarsa yeniden onay istenir; kozmetik değişiklik istemez.
Pratikte onay **anahtar başına**dır (11.10.2026: Test Alanı'ndaki onay
başka anahtara geçmedi). Transkript'te onay yalnız yöneticinin
Ayarlar → Durum → "Şartları oku ve onayla" düğmesiyle verilir; kod
kendiliğinden onaylamaz.

## 3. Modeller (`GET /v1/models`, 11.10.2026)

| id | görev | bağlam | düşünme | not |
|---|---|---|---|---|
| `glm-5.3` | chat | 512K | var | amiral, en yetenekli; düşünmesi uzun |
| `deepseek-v4.1-flash` | chat | 1M | var | hızlı; çıktı 64K'ya kadar |
| `mimo-v2.6-pro` | chat | 1M | var | filonun en büyüğü; `reasoning_effort: none` destekler |
| `qwen3.8-flash-next` | chat | 256K | var | en yüksek eşzamanlılık |
| `gemma-4-31b` | chat | 256K | yok | düşük gecikme, düşünmesiz |
| `qwen3-vl-30b` | vision_chat | 256K | yok | görsel model — metin işinde kullanmayın |
| `auto` | chat | **32K** | — | geçit her çağrıda başka model seçer; parametre almaz |
| `deepseek-v4-flash` | chat | 1M | var | **1 Kasım 2026'da kalkıyor** |
| `dots-ocr`, `deepseek-ocr-2` | ocr | | | `/v1/ocr` (çok parçalı dosya) |
| `qwen3-asr-1.7b` | audio_transcription | | | `/v1/audio/transcriptions`, `response_format: srt` |
| `qwen3-embedding-8b` | embedding | 40K | | `/v1/embeddings` |
| `qwen3-reranker-8b` | rerank | 4K | | `/v1/rerank` |
| `laya`, `clef` | decision | 8K / 16K | | `/v1/systemone` (tipli karar) |
| `qwen3-guard-4b` | guard | 32K | | istem / yanıt güvenlik denetimi |

Model alanı kısa ad (`glm-5.3`), repo adı (`zai/glm-5.3-fp8`) ya da `auto`
olabilir. Yanıtın `model` alanı gerçekten yanıt vereni söyler
(`auto` → `zai/glm-5.3-fp8`). Yanlış uca gönderilen model 400
`endpoint_mismatch` alır (ör. sohbet modeli `/v1/ocr`'ye).

## 4. Sohbet (`POST /v1/chat/completions`)

```json
{
  "model": "glm-5.3",
  "messages": [{"role": "system", "content": "Kısa ve Türkçe cevap ver."},
               {"role": "user", "content": "Merhaba!"}],
  "max_tokens": 4096,
  "reasoning_effort": "low",
  "response_format": {"type": "json_schema", "json_schema": {"name": "cikti", "strict": true, "schema": {…}}},
  "stream": true,
  "stream_options": {"include_usage": true}
}
```

- **Düşünme bütçesi:** düşünen modellerde düşünme ve yanıt AYNI
  `max_tokens` havuzundan harcanır. En az 2048, önerilen 4096; uzun
  rapor daha fazlasını ister. İçerik boş ve `finish_reason: "length"`
  ise bütçe düşünmeye gitmiştir. Ölçüm: deepseek-v4.1-flash
  `reasoning_effort: low` ile bile 2 dakikalık bir transcript'te 4096'nın
  tamamını düşünmeye harcadı (docs/evren/02).
- **`reasoning_effort`:** `/v1/models` → `capabilities.reasoning_effort`.
  glm-5.3 ve deepseek-v4.1-flash'ta `medium → high`, `minimal → low`
  takma adları var; mimo-v2.6-pro `none … xhigh` alır. Düşünme metni
  `delta.reasoning_content` / `delta.reasoning` (akışta) ya da
  `message.reasoning_content` (akışsız) alanındadır; rapora girmemeli.
- **JSON:** `response_format` `json_object` ya da `json_schema` (strict).
  `json_object` alan adlarını zorlamaz (gemma önemli noktayı `"point"`
  yazdı); `json_schema` strict'te model şemadaki adları ve zorunlu
  alanları birebir yazar. Strict şemada her nesnede
  `"additionalProperties": false` olmalı. `auto` `response_format`'ı
  desteklemez.
- **Akış (SSE):** sunucu ~10 sn'de bir `: keep-alive` yorum satırı yollar;
  `:` ile başlayan satırı JSON'a sokmayın. Akış `data: [DONE]` ile biter;
  `include_usage` açıksa son parçada `usage` gelir. Akışta boşta kalma
  süresi ölçülebilir: veri gelmeden geçen 60 sn, bağlantının öldüğünü
  gösterir (keep-alive veri değildir).
- **Araç çağırma:** `chat/completions`'ta iç içe (`tools[].function.name`),
  `/v1/responses`'ta düz (`tools[].name`, Codex CLI biçimi).
- **Görsel:** `image_url` yalnız base64 `data:` adresi (http bağlantısı 400).

## 5. Kota ve hatalar

`GET /v1/quota`:

```json
{"used_tokens": 24815, "cap": 2500000, "window_minutes": 5,
 "daily_used_tokens": 77479, "daily_limit_tokens": 15000000,
 "daily_remaining_tokens": 14922521, "daily_reset_at": "2026-10-12T00:00:00Z"}
```

- Hesap başına **günlük 15.000.000 token** (bütün modeller ve anahtarlar
  toplamı), 00:00 UTC'de (03:00 TR) sıfırlanır; ayrıca **5 dakikalık**
  pencere 2.500.000 token. Her yanıtta `x-evren-daily-remaining-tokens`,
  `x-evren-daily-limit-tokens`; kredi için `X-Evren-Credits-*`.
- **429:** kota doldu (günlük kalan 0 ise yenilenmeye kadar bekleyin;
  dakikalık sınırda bekleme ≤ 60 sn, hesap kilitlenmez).
- **503:** anlık sunucu doluluğu — kısa bekleyip (2, 4, 8 sn) yeniden.
- **403 `terms_not_accepted`:** şartlar (2. bölüm).
- **400 `endpoint_mismatch`:** model yanlış uçta.
- Hata gövdesindeki `error.evren` ek bilgi taşır (kalan kota, alternatif
  model, gereken şart sürümü).

**Önbellek indirimi:** yeniden gönderilen bağlam sunucu önbelleğinden
okunur ve kotaya %3 ağırlıkla yazılır (`usage.prompt_tokens_details.cached_tokens`).
İsteğin başını (sistem istemi, kurallar, şema) sabit tutun, değişken kısmı
sona koyun.

**Fiyat:** `/v1/models` → `pricing.prompt_token_price`,
`completion_token_price` (CR / 1M token), `free_until: "2026-11-01"`.
1 Kasım 2026'ya kadar bütün modeller 0 CR.

## 6. Öteki uçlar (Transkript'te henüz kullanılmıyor)

| Uç | Model | Ne işe yarar | Transkript'te olası kullanım |
|---|---|---|---|
| `/v1/audio/transcriptions` | `qwen3-asr-1.7b` | sesi metne (`srt` dahil); ~30 sn pencereler | yalnız kullanıcının YÜKLEDİĞİ ses / video (5b). YouTube'dan ses indirilmez |
| `/v1/embeddings` | `qwen3-embedding-8b` | metni vektöre | kütüphanede anlamsal arama, "benzer videolar" |
| `/v1/rerank` | `qwen3-reranker-8b` | belgeleri soruya göre sıralar | transkripte soru sorma (RAG) |
| `/v1/systemone` | `laya` (hızlı, toplu ≤64), `clef` (isabetli) | tipli karar: `choice`, `score`, `noul` | otomatik kategori / etiket, ilgi alanına göre puan |
| chat | `qwen3-guard-4b` | istem / yanıt güvenlik denetimi | kullanıcının serbest metni (soru, not) |
| `/v1/ocr` | `dots-ocr`, `deepseek-ocr-2` | görselden metin | — |

## 7. Transkript modülünde nasıl kullanılıyor

`modules/Transkript/src/Ai/EvrenProvider.php`, `EvrenHesap.php`,
`EvrenModelleri.php`; ölçüm `modules/Transkript/bin/evren-olcum.php`,
canlı deneme `tests/evren-canli.php`, teşhis
`modules/Transkript/bin/evren-teshis.php`. Ayarlar: `.env.example`
(EVREN_*), Transkript ayarları → Yapay zekâ (EVREN modeli, bölüm / rapor
modeli). Ölçüm ve kararlar: `docs/evren/01-kesif.md`,
`02-olcum-raporu.md`, `03-gelistirme-raporu.md`.
