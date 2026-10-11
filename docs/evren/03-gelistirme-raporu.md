# EVREN entegrasyonu — 3. Geliştirme raporu

Tarih: 11 Ekim 2026. Dal: `feature/evren-gelistirme` → `main`. Modül 0.10.0.
Kapsam: EVREN geliştirme görevi (FAZ 1–4) ve cowork 3. test turu (kuyruk,
EVREN "auto", log süreleri, zaman damgası, sessiz gönderim). Kullanıcı
bilgisayar başında değildi ve bütün onayları önceden verdi; prompt'taki "onay
bekle" adımlarında kararlar ölçüme dayanarak verildi ve aşağıda gerekçeleriyle
yazıldı.

## 1. Önce / sonra

<!-- ONCE-SONRA -->

## 2. Yapılanlar (öncelik sırasıyla)

### P0 — Güvenilirlik

| # | İş | Durum | Nerede |
|---|---|---|---|
| P0-1 | `auto` analiz başına sabitlenir (görsel / kalkan model hariç) | ✔ canlı doğrulandı (4928: auto → mimo, sonraki 5 çağrı mimo) | `Pipeline::evrenModeliniSabitle`, `transkript_analizler.evren_model` |
| P0-2 | Aşama başına model (bölüm / rapor), etiket = kod varsayılanı | ✔ | `PanelAyarlari` `model_evren_bolum` / `_rapor`, `Ai::evrenModeli` |
| P0-3 | Akış (SSE), bağlantı 15 / boşta 60 / toplam ölçekli; `EVREN_STREAM` | ✔ canlı (ilk token 0,8–0,9 sn) | `SseAkisi`, `HttpProvider` kancaları, `EvrenProvider` |
| P0-4 | Düşünme `EVREN_THINKING` (low); bütçe yetmezse ×2 bir kez; düşünme metni rapora girmez | ✔ | `EvrenProvider::butceyleDene`, `dusunmeParametreleri` |
| P0-5 | EVREN içi hızlı yedek (gemma-4-31b); 503'te 2/4/8 sn × 3; zincir süre sınırı 900 sn | ✔ | `Pipeline::saglayiciUret`, `YedekliSaglayici::MODEL_KAPSAMLI` |
| P0-6 | Devre kesici (429: 60 sn → 5 → 15 → 60 dk); 413'te %80 sınır (0.9.2); model bağlamı (auto 32K) | ✔ | `YedekliSaglayici::ardisik429`, `GirisSiniri` |

### P1 — Ölçüm, maliyet, verim

| # | İş | Durum |
|---|---|---|
| P1-1 | Çağrı günlüğü: kendi başlangıç / bitiş, aşama, istenen → yanıttaki model, akış, bütçe, bitiş nedeni, düşünme / önbellek token'ı, TTFT, HTTP, kalan kota; kesilen çağrıda tahmini kullanım (`tahmini`) | ✔ migration `…140000`, `…150000` |
| P1-2 | Kalan kota her yanıtta; %10 altında Durum uyarısı | ✔ (EVREN'i ikinci sıraya alma ayarı eklenmedi: günlük kota 429'unda zincir zaten sıradakine geçiyor ve EVREN 03:00'e kadar dinleniyor) |
| P1-3 | Fiyat `/v1/models`'ten günde bir; ücretsiz dönem sonu; 7 günlük token ve aylık tahmin; $0,2201'in tamamı Gemini (EVREN 0) | ✔ |
| P1-4 | Önbellek dostu istem | ✔ ölçüldü: sistem istemi sabit, girişin %25–60'ı önbellekten; istem değişmedi (v4 gerekmedi) |
| P1-5 | Şart kapısı: gereken sürüm saklanır, Durum uyarır; süre `config ai.yedek.dinlenme_sn.sartlar_onaylanmadi`; kod onaylamaz | ✔ |
| P1-6 | Dinamik model listesi (`EvrenModelleri`), görsel / OCR / ses / kalkan yok; `deepseek-v4-flash` → `v4.1` | ✔ |
| P1-7 | `json_schema` (strict) → reddedilirse `json_object` → biçimsiz; doğrulayıcı alternatif adları okur; onarım isteği (zaten vardı) | ✔ |
| P1-8 | İş nabzı, bayat iş 5 dk'da geri, sahipsiz parça onarımı; ilerlemede "— EVREN · glm-5.3, 2. deneme" | ✔ canlı doğrulandı |

### Cowork 3. tur

| # | Bulgu | Neden | Çözüm |
|---|---|---|---|
| 1 | Kuyruk işçisi durdu | Görev Zamanlayıcı'da işçi **kayıtlı değildi**; önceki "Görüldü" elle çalıştırılan komutlardandı. Tarayıcıdan işleme yalnız ilerleme yoklamasıyla tetikleniyordu: sayfanın analizi bitince ya da sekme gizlenince durdu (erişim günlüğü: 04:20:12'den 04:38'e hiç istek yok). PHP sınırları (1200 sn, 2 GB) ve yakalanmayan istisna sebep değil. | İş nabzı + 5 dk eşik + sahipsiz parça onarımı; tik döngüsü bütün sayfalarda; `kuyruk-isci-kur.ps1` 5 dk'da bir tetik (ölen işçi yeniden başlar); Durum'da son görülme, takılı sayısı, kopyalanabilir komut; Görev Zamanlayıcı'ya kurulum yapıldı (bkz. 5. bölüm) |
| 1 | Takılı 4380, 4381, 4382 | — | İşçi başlayınca 04:31'de tamamlandı; 4380 ve 4381 EVREN'e yeniden gitmedi (yalnız RaporuTamamla), 4382'nin birleştirmesi hiç yapılmamıştı |
| 1 | İşçi durdur → 6 dk → başlat | — | Canlı (4928, 38:41, 5 bölüm): 2 bölüm bitti, 3. bölüm sürerken işçi öldürüldü; açılan panel sayfasının tik'i işi geri aldı, **burada bir hata bulundu ve düzeltildi** (nabız kilidi almadan tazeliyordu → parça sahipsiz kaldı); onarım parçayı kuyruğa aldı, işçi yeniden başlayınca yalnız kalan iş yapıldı: 5 bölüm = 5 çağrı, tekrar yok |
| 1 | Çelişkili mesaj | "kapatabilirsiniz" ve "kapatırsanız bekler" birlikte | İşçi durumuna göre yalnız doğrusu (JS ilerlemeyle günceller) |
| 2 | EVREN auto yavaş / tutarsız | auto 32K, her çağrı başka model, düşünme sınırsız | Varsayılan sabit model, auto notu ve sabitleme, akış + boşta sınırı, düşünme low, ölçüm aracı |
| 3 | Log saati / süresi | Başarısız denemeler zincirin bitiş saatiyle, başarılının süresi zincirin başından | Her çağrı kendi başlangıcı ve süresi (canlı: 4928) |
| 4 | Kısa videoda zaman damgası yok | json_object'te "point" / "claim", `at` yok → maddeler atılıyordu | json_schema, doğrulayıcı toleransı, transcript satırından yedek |
| 5 | Gönderim sessizce başarısız | 419 (CSRF) → 1,5 sn bildirim + yenileme, bağlantı kayboluyordu | Sebep kutuda kalır, sayfa bağlantı kutuya yazılmış açılır; ağ / ayrıştırma hatası da söylenir |

### P2 (yeni yetenekler)

- **Yapıldı:** Durum'da "EVREN model sağlığı (son 7 gün)" — model başına
  başarı, ort. / p95 süre, zaman aşımı, ilk token, token, önbellek oranı.
- **Öneri (yapılmadı, ayrı adım):** anlamsal arama (`qwen3-embedding-8b` +
  `qwen3-reranker-8b`), otomatik etiket (`/v1/systemone` `laya`), içerik
  güvenliği (`qwen3-guard-4b`), yüklenen dosya için ASR (`qwen3-asr-1.7b`;
  5b adımı, YouTube'dan ses indirilmez). Ayrıntı:
  `docs/EVREN_LLM_API_Rehberi.md` 6. bölüm.

## 3. Kararlar (iki seçenek, ölçümle)

<!-- KARARLAR -->

## 4. Yeni ayarlar

`.env.example` (hepsi isteğe bağlı): `EVREN_STREAM` (true), `EVREN_BOSTA_SN`
(60), `EVREN_TOPLAM_SN` (boş: 120 sn + giriş/40, ≤900), `EVREN_THINKING`
(low), `EVREN_JSON_SCHEMA` (true), `EVREN_YEDEK_MODEL` (gemma-4-31b; "yok"
kapatır), `EVREN_LIVE_TESTS` (0). config: `ai.evren.bolum_modeli`,
`ai.evren.rapor_modeli`, `ai.evren.yedek_model`, `ai.yedek.zincir_en_cok_sn`
(900), `kuyruk.nabiz_esik_sn` (300). Panel: Yapay zekâ → "EVREN · bölüm
analizi modeli", "EVREN · rapor birleştirme modeli".

## 5. Migration'lar

`php cy migrate` (projede `php spark` yok; çekirdek komutu `php cy`):

- `2026_10_11_120000_transkript_model_ozeti` — parça ve analiz modelleri (0.9.2)
- `2026_10_11_140000_transkript_cagri_olcumleri` — çağrı ölçüm sütunları, `evren_model`
- `2026_10_11_150000_transkript_tahmini_kullanim` — `tahmini`

Hepsi yalnız sütun ekler (`down()` siler); mevcut satır ve raporlara dokunmaz.

## 6. Testler

`tests/transkript.php` (PHPUnit yok, bağımlılık yasak; aynı desen) — SSE
ayrıştırıcı, hata eşlemesi, düşünme bütçesi ×2, 400 sırası, json_schema, auto
sabitleme, aşama modelleri, yedek halka, devre kesici, giriş sınırı, fiyat /
ücretsiz dönem, model listesi süzgeci ve taşıma, iş nabzı ve sahipsiz parça,
tik döngüsü, zaman yedeği, doğrulayıcı toleransı, sağlık özeti, öteki
sağlayıcılarda gerileme yok. Canlı: `EVREN_LIVE_TESTS=1 php tests/evren-canli.php`
(gemma ve deepseek 6/6).

## 7. Açık konular ve riskler

<!-- ACIK -->

## 8. 1 Kasım 2026 sonrası

<!-- KASIM -->
