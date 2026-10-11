# EVREN entegrasyonu — 2. Ölçüm raporu

Tarih: 11 Ekim 2026. Araç: `modules/Transkript/bin/evren-olcum.php`
(üretim istemcisi `EvrenProvider` + v3 istemleri + `Pipeline` + doğrulayıcı;
ölçüm çağrıları üretim günlüğüne yazılmaz). Ham sonuçlar:
`storage/files/evren-bench/runs/<tarih-saat>-<etiket>/` (results.jsonl,
summary.json, rapor.md).

| Çalıştırma | Ne | Çağrı | Token |
|---|---|---|---|
| `…051038-duman` | smoke + fallback (6 model "Merhaba", 8 sahte senaryo) | 6 | 939 |
| `…051206-once-eski-kod-kismi` | eski kodla, yalnız glm-5.3 (kesildi) | 7 | — |
| `…054011-once` | **önce**: 11.10 sabahki üretim ayarı (akışsız, düşünme varsayılan, json_object, yeniden deneme yok) × 6 model × 6 kısa/orta video | 72 | 384.462 |
| `…054423-deney` | budget, stream, consistency, context | 74 | 507.553 |
| `…-sonra` | **sonra**: akış, düşünme low, json_schema × 5 sabit model × 6 video + 38 dk video × 3 | bkz. 4. bölüm | |

Fixture'lar (herkese açık YouTube transcript'leri, kişisel veri yok):
kısa 2:01 ve 2:33 (TR), orta 3:42 (EN kaynak), 4:19, 5:02, 5:18 (TR altyazı),
uzun 38:41 (TR, 5 bölüm), çok uzun 1:17:08 (TR, 10 bölüm).

**Kalite puanı (0–10, kural tabanlı):** JSON/şema 3 · Türkçe 2 · zorunlu
alanlar 1 · zaman damgası oranı 1,5 · özet uzunluğu 0,5 · düşünme / İngilizce
sızıntısı yok 0,5 · tekrar yok 0,5 · rapordaki sayı ve özel adların
transcript'te geçmesi 1. Hakem model kullanılmadı (puanlar modeller arasında
az ayırt edici çıktı; ayırt eden süre, zaman aşımı ve JSON güvenilirliği).

## 1. Özet

1. **glm-5.3 eski ayarla kullanılamaz:** bölüm analizinde %33, birleştirmede %0
   başarı; 8 çağrının 6'sı 180 / 240 sn'de zaman aşımı. Düşünme çıktının
   %71'i. `reasoning_effort: low` ile aynı iş **16–38 sn**'ye iniyor.
2. **`auto` yavaş ve tutarsız:** her çağrı başka modele (mimo, qwen3-vl görsel
   model, deepseek-v4-flash — kalkacak, qwen3.8-flash-next, glm-5.3, gemma)
   gidiyor; birleştirmenin üçte biri 240 sn'de zaman aşımı, rapor kalitesi
   6,5/10. Aynı istek tekrarlanınca maddelerin örtüşmesi (Jaccard) 0,05–0,53;
   sabit modelde 0,26–0,70.
3. **Düşünme ayarı belirleyici:** qwen3.8-flash-next bölüm analizi 63–75 sn →
   **11–13 sn** (low); glm-5.3 140–165 sn → 24–38 sn. deepseek-v4.1-flash
   "low"u az dinliyor (21–77 sn), mimo-v2.6-pro zaten az düşünüyor (%7–17).
4. **Düşünme bütçesi:** varsayılan düşünmede 4096 token glm-5.3'te çıktının
   %66'sı düşünmeye gidiyor ve 2 / 4 çağrı `length` ile kesildi; qwen 4096'da
   bir kez "düşünme bütçesi yetmedi". `low` ile 2048 bile yeterli.
5. **json_object güvenilmez:** gemma önemli noktayı `"point"`, iddiayı
   `"claim"` yazdı, içerik maddelerine `at` koymadı → doğrulayıcı maddeleri
   atıyordu (cowork 3. tur 4'ün kök nedeni). `json_schema` (strict) gemma ve
   deepseek'te alanları birebir ve her maddede zamanla yazdırdı.
6. **Akış:** süreyi belirgin değiştirmiyor (deepseek 41,7 / 46,5 sn); kazancı
   boşta kalma sınırı (ölü bağlantı 60 sn'de anlaşılır) ve ilk token
   (deepseek ~1 sn, glm ~3–9 sn).
7. **Bölüm boyutu:** 38 dk'lık videoda 8.000 token'lık bölümler 155 sn,
   2.000'lik bölümler 458 sn sürdü; kalite aynı (9,4–9,6). Bugünkü bölüm
   süreye bağlı (8–10 dk ≈ 2.000 token).
8. **Önbellek:** sistem istemi sabit; girişin %25–60'ı önbellekten
   (`cached_tokens` 912–2.048), istem sırasını değiştirmeye gerek yok.
9. **Kota farkı (S8):** ölçüm sırasında hesabın günlük kullanımı, kendi
   kayıtlarımızdan fazla arttı; aynı hesabı başka süreçler de kullanıyor
   (eşzamanlı ölçümler, başka oturumlar), bu yüzden fark tek başına bize ait
   değil. Kesilen çağrılar artık tahminî kullanımla yazılıyor.

## 2. Önce: model karşılaştırması (eski ayar)

Akışsız, düşünme varsayılan, json_object; bölüm 8192 / rapor 24000 token,
süre 180 / 240 sn. 6 kısa / orta video.

| model · aşama | çağrı | başarı | ort. sn | p95 sn | ort. token | JSON | zaman aşımı | düşünme oranı | önbellek |
|---|---|---|---|---|---|---|---|---|---|
| auto · bölüm | 7 | %100 | 26,6 | 42,9 | 4.914 | %86 | 0 | 0,60 | %53 |
| auto · rapor | 6 | **%67** | 117,7 | 240,0 | 4.896 | %100 | **2** | 0,62 | %26 |
| deepseek-v4.1-flash · bölüm | 6 | %100 | 65,3 | 75,7 | 6.552 | %100 | 0 | 0,60 | %30 |
| deepseek-v4.1-flash · rapor | 6 | %100 | 70,7 | 84,9 | 8.080 | %100 | 0 | 0,47 | %35 |
| gemma-4-31b · bölüm | 6 | %100 | 33,6 | 40,7 | 3.743 | %100 | 0 | — | %29 |
| gemma-4-31b · rapor | 6 | %100 | 42,4 | 57,4 | 4.349 | %100 | 0 | — | %34 |
| glm-5.3 · bölüm | 6 | **%33** | 171,8 | 180,0 | 2.606 | %100 | **4** | 0,71 | %23 |
| glm-5.3 · rapor | 2 | **%0** | 240,0 | 240,0 | — | — | **2** | — | — |
| mimo-v2.6-pro · bölüm | 6 | %100 | 29,6 | 42,8 | 3.454 | %100 | 0 | 0,13 | %43 |
| mimo-v2.6-pro · rapor | 6 | %100 | 59,9 | 97,6 | 5.426 | %100 | 0 | 0,23 | %46 |
| qwen3.8-flash-next · bölüm | 9 | %100 | 42,5 | 67,4 | 7.001 | **%67** | 0 | 0,85 | %4 |
| qwen3.8-flash-next · rapor | 6 | %100 | 57,9 | 74,4 | 8.730 | %100 | 0 | 0,72 | %0 |

Uçtan uca (bölüm + rapor, video başına) ve kalite:

| model | medyan sn | ort. sn | kalite | başarısız rapor |
|---|---|---|---|---|
| gemma-4-31b | 88 | 76 | 9,77 | 0 / 6 |
| mimo-v2.6-pro | 111 | 89 | 9,69 | 0 / 6 |
| qwen3.8-flash-next | 133 | 122 | 9,69 | 0 / 6 |
| deepseek-v4.1-flash | 130 | 136 | 9,71 | 0 / 6 |
| auto | 160 | 149 | 6,51 | 2 / 6 |
| glm-5.3 | 399 | 395 | 0 | 6 / 6 (yalnız 2'si birleştirmeye ulaştı) |

## 3. Deneyler

### 3.1 Düşünme bütçesi (`budget`, 185 milyon nesil, 3:42 EN kaynak, bölüm analizi)

| model | düşünme | max_tokens → ort. sn · düşünme oranı · sonuç |
|---|---|---|
| glm-5.3 | default | 4096: 140 sn · 0,66 · 2/4 `length`; 16384: 165 sn · 0,71; 1 zaman aşımı |
| glm-5.3 | **low** | 2048: 31 sn · 0; 4096: 38 sn · 0; 8192: 24 sn · 0; 1 boşta kesilme (60 sn) |
| deepseek-v4.1-flash | default | 2048: 27 sn · 0,28; 4096: 26 sn · 0,33; 8192: 83 sn · 0,71; 16384: 80 sn · 0,73 |
| deepseek-v4.1-flash | low | 2048: 21 sn · 0,50; 4096: 58 sn · 0,57; 8192: 50 sn · 0,59; 16384: 77 sn · 0,62 |
| mimo-v2.6-pro | default | 33–45 sn · 0,07–0,14 |
| mimo-v2.6-pro | low | 23–36 sn · 0,01–0,17 |
| qwen3.8-flash-next | default | 4096: **düşünme bütçesi yetmedi**; 8192: 76 sn · 0,72; 16384: 67 sn · 0,77 |
| qwen3.8-flash-next | **low** | 2048–16384: **11–13 sn** · 0,17–0,40 |

Önerilen: düşünme `low`; bölüm 8192 / rapor 24000 kalır (low'da fazlası
kullanılmıyor, ama uzun raporda pay). En az 2048 kuralı korunur.

### 3.2 Akış (`stream`, 4:19 video, bölüm analizi, ×2)

| model | akış | ort. sn | ilk token | 
|---|---|---|---|
| deepseek-v4.1-flash | akışlı | 41,7 | 1,0 sn |
| deepseek-v4.1-flash | akışsız | 46,5 | — |
| glm-5.3 | akışlı | 102,1 (biri yavaş) | 9,0 sn |
| glm-5.3 | akışsız | 49,9 | — |

Akış toplam süreyi değiştirmiyor; değeri "ölü" bağlantıyı 180 sn yerine 60
sn'de anlamak ve ilk token ile kuyrukta bekleyişi görmek. Varsayılan açık,
`EVREN_STREAM=false` ile kapanır.

### 3.3 Tutarlılık ve `auto` dağılımı (`consistency`, 2 kısa video × 3)

| model · video | çözülen modeller | Jaccard | hata |
|---|---|---|---|
| auto · 2:01 | qwen3.8-flash-next, qwen3-vl-30b (görsel), glm-5.3 | 0,05 | — |
| auto · 2:33 | glm-5.3, qwen3.8-flash-next | 0,53 | 1 zaman aşımı |
| glm-5.3 (low) · 2:01 | glm-5.3 ×3 | 0,26 | — |
| glm-5.3 (low) · 2:33 | glm-5.3 ×3 | 0,70 | — |

"Önce" çalıştırmasında `auto` 13 çağrıda 7 farklı modele gitti (mimo ×3,
qwen3-vl ×3, deepseek-v4-flash ×1, deepseek-v4.1-flash ×2, gemma ×1,
qwen3.8-flash-next ×1).

### 3.4 Bölüm boyutu (`context`, 38:41 video, deepseek-v4.1-flash, akış, low)

| hedef token / bölüm | bölüm | uçtan uca sn | kalite (rapor) |
|---|---|---|---|
| 2.000 | 6 | 458 | 9,53 |
| 4.000 | 3 | 465 | 9,62 |
| 8.000 | 2 | **155** | 9,55 |
| 16.000 | 1 | 173 | 9,43 |

### 3.5 Yedek senaryoları (sahte HTTP, ağ yok)

| senaryo | zincir | sonuç | EVREN dinlenmesi |
|---|---|---|---|
| 403 terms_not_accepted | evren → groq | groq yanıtladı | 3600 sn (devre dışı) |
| 429 günlük kota | evren → groq | groq | 03:00'e kadar |
| 429 dakikalık | evren → groq | groq | Retry-After 20 sn |
| 503 doluluk | evren (3× 2/4/8 sn) → groq | groq | 120 sn (modele bağlı) |
| 400 endpoint_mismatch | evren (düşünmesiz, json_object, biçimsiz) → groq | groq | yok |
| boşta zaman aşımı | evren → groq | groq | 120 sn (modele bağlı) |
| boş içerik + düşünme ×2 | evren (×2 bütçe) → groq | groq | yok |
| length, ×2 bütçeyle düzelir | evren (×2 bütçe) | evren | yok |

## 4. Sonra (yeni ayar)

Akışlı, düşünme `low`, json_schema; 5 sabit model × 6 kısa / orta video +
38:41 video (qwen3.8-flash-next, gemma-4-31b, deepseek-v4.1-flash).

<!-- SONRA -->

## 5. Öneri tablosu

<!-- ONERI -->
