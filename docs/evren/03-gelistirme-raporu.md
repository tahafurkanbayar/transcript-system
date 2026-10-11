# EVREN entegrasyonu — 3. Geliştirme raporu

Tarih: 11 Ekim 2026. Dal: `feature/evren-gelistirme` → `main`. Modül 0.10.0.
Kapsam: EVREN geliştirme görevi (FAZ 1–4) ve cowork 3. test turu (kuyruk,
EVREN "auto", log süreleri, zaman damgası, sessiz gönderim). Kullanıcı
bilgisayar başında değildi ve bütün onayları önceden verdi; prompt'taki "onay
bekle" adımlarında kararlar ölçüme dayanarak verildi ve aşağıda gerekçeleriyle
yazıldı.

## 1. Önce / sonra

### Kabul ölçütleri

"Ölçüm" = `bin/evren-olcum.php` (`02-olcum-raporu.md`); "üretim" = gerçek
kuyruk işçisi ve çağrı günlüğü (`ai_kullanim_kaydi`).

Bu rapordaki numaralar **analiz** numarasıdır (`transkript_analizler.id`).
Panel sayfası **kaynak** (video) numarasıyla açılır ve Durum'daki "Analiz"
sütunu da kaynak numarasını gösterir:

| Analiz | Kaynak | Video | Panel |
|---|---|---|---|
| 4445, 5557, 5561 | 4379 | Satranç tarihi (5:18), `YeB-1F-UKO0` | `panel/transkript/4379` |
| 4446, 5558, 5562 | 4380 | Çikolata tarihi (4:19), `ibjUpk9Iagk` | `panel/transkript/4380` |
| 4447, 5559, 5563 | 4381 | 185 milyon nesil (3:42), `0dSNbPEOPA0` | `panel/transkript/4381` |
| 4448, 5560, 5564 | 4382 | Evrim Ağacı (2:01), `rnByHXewsak` | `panel/transkript/4382` |
| 4928 | 4383 | JEV: A Beyni mi B Beyni mi? (38:41), işçi durdurma denemesi | `panel/transkript/4383` |

Panel her kaynakta en son analizi gösterir; eski analizler veritabanında
değişmeden durur.

| Ölçüt | Hedef | Önce | Sonra | |
|---|---|---|---|---|
| Zaman aşımı | ≤ %2 | ölçüm %11 (8 / 72) | ölçüm %1,2 (1 / 82, düzeltmeden önce başlamış çalıştırmada); üretim 20 / 20 başarılı | ✔ |
| Düşünme bütçesiyle biten analiz | 0 | glm 2 / 4 `length`, qwen "bütçe yetmedi"; üretimde `dusunme_butcesi` hatası | 0 (4 `length`'in 4'ü ×2 bütçeyle düzeldi) | ✔ |
| Kısa video medyanı | ≥ %50 hızlı | ölçüm `auto` 128 sn; üretim AI süresi 142 sn | ölçüm 37 sn (**−%71**); üretim 8 analiz 43,5 sn (**−%69**) | ✔ |
| Analiz başına tek model | evet | 4445'te 4 model (auto, OpenRouter, Groq, görsel qwen3-vl), 4447'de 2 | 8 / 8 tek model | ✔ |
| OpenRouter / Groq boşa çağrı | −%80 | 02:00–06:00, 17 analiz: OpenRouter 33 / 33, Groq 17 / 39 boşa (50) | 06:00 sonrası 9 analiz: OpenRouter / Groq'a **0 çağrı**, EVREN 20 / 20 | ✔ (−%100) |
| Kota sapması | ≤ %3 | ölçülemedi (eşzamanlı ölçümler aynı kotayı kullandı) | ölçüm %2,7 (`/quota`; önbellekten okunan token kotadan düşmüyor); üretim 23 qwen çağrısı **%0,25** | ✔ |
| JSON geçerliliği | ≥ %98 | qwen bölüm %67, auto bölüm %86 | varsayılan model %100; `stop` ile biten 77 / 77 | ✔ |
| Testler | geçer | — | transkript 468, unit 342, yetki 31, smoke 31 | ✔ |

### Cowork 3. tur: aynı 4 kısa video, sabit model (gerçek işçi)

AI süresi = analizin çağrı sürelerinin toplamı. Madde = rapordaki zaman
damgalı madde / bütün madde.

| Video | Sabah, `auto` (4445–4448) | 1. tur (5557–5560) | 2. tur (5561–5564) |
|---|---|---|---|
| Satranç 5:18 (4379) | 380 sn: EVREN 180 sn zaman aşımı, OpenRouter 429, Groq 187 sn, rapor görsel qwen3-vl; madde 11 / 19 | 91 sn; 23 / 23 | 53 sn; 26 / 26 |
| Çikolata 4:19 (4380) | 117 sn (mimo); 4 / 18 | 71 sn; 19 / 19 | 34 sn; 19 / 19 |
| 185 milyon nesil 3:42 (4381) | 167 sn (qwen3-vl + qwen); 14 / 14 | 119 sn; 24 / 24 | 31 sn; 16 / 16 |
| Evrim Ağacı 2:01 (4382) | 108 sn (qwen); 12 / 12 | 32 sn; 9 / 9 | 23 sn; 8 / 8 |
| **AI süresi medyanı** | **142 sn** | **81 sn** | **32,5 sn** |
| Dört analizin bitişi | işçi durdu: 4445 tek başına 365 sn, ötekiler 21 dk | 7 dk 18 sn | 3 dk 9 sn |

İki turda da her analiz 2 çağrı (bölüm + rapor), hepsi qwen3.8-flash-next,
hata ve yedek yok. 1. turda EVREN yavaştı (23–82 tok/sn; ölçümde 88–95),
2. turda 62–126 tok/sn: aynı model saate göre 2–3 kat oynuyor. Kısa
videolarda zaman damgası artık her maddede (sabah 4446'da 4 / 18).

Tek işçi işleri sırayla (FIFO) aldığı için dört analizin raporu aynı anda
bitti (1. tur: 13:10:34). Başlamış analizin işini öne almak ilk raporu ~2 dk
erkene çeker (öneri, 2. bölüm P2).

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
| 1 | Kuyruk işçisi durdu | Görev Zamanlayıcı'da işçi **kayıtlı değildi**; önceki "Görüldü" elle çalıştırılan komutlardandı. Tarayıcıdan işleme yalnız ilerleme yoklamasıyla tetikleniyordu: sayfanın analizi bitince ya da sekme gizlenince durdu (erişim günlüğü: 04:20:12'den 04:38'e hiç istek yok). PHP sınırları (1200 sn, 2 GB) ve yakalanmayan istisna sebep değil. | İş nabzı + 5 dk eşik + sahipsiz parça onarımı; tik döngüsü bütün sayfalarda; `kuyruk-isci-kur.ps1` 5 dk'da bir tetik (ölen işçi yeniden başlar); Durum'da son görülme, takılı sayısı, kopyalanabilir komut; Görev Zamanlayıcı'ya kuruldu ("Transkript kuyruk iscisi", 3. bölüm K7) |
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
- **Öneri (yapılmadı, ayrı adım):** kuyrukta başlamış analizin
  birleştirme / rapor işini yeni bölümlerden önce almak (öncelik sütunu ya
  da ayrı kuyruk; çekirdek `Queue` değişebilir, önce sorulmalı); bölüm
  boyutunu büyütmek (02, 3.4); anlamsal arama (`qwen3-embedding-8b` +
  `qwen3-reranker-8b`), otomatik etiket (`/v1/systemone` `laya`), içerik
  güvenliği (`qwen3-guard-4b`), yüklenen dosya için ASR (`qwen3-asr-1.7b`;
  5b adımı, YouTube'dan ses indirilmez). Ayrıntı:
  `docs/EVREN_LLM_API_Rehberi.md` 6. bölüm.

## 3. Kararlar (iki seçenek, ölçümle)

Prompt'taki "onay bekle" adımlarında kullanıcı yoktu (önceden bütün onayları
verdi). Kararlar ölçümle verildi; her biri panelden ya da `.env`'den geri
alınabilir.

**K1. Varsayılan EVREN modeli → `qwen3.8-flash-next`** (`EvrenProvider::VARSAYILAN_MODEL`)

| | A: qwen3.8-flash-next (seçildi) | B: deepseek-v4.1-flash (cowork önerisi) |
|---|---|---|
| Kısa video medyanı | **37 sn** | 115 sn |
| 38:41 video | **222 sn** | 515 sn |
| Başarı / JSON | 18 / 18, %100 | 21 / 21, %85–88 (3 `length`, ×2 ile düzeldi) |
| Düşünme | low'a uyuyor (0,27–0,36) | low'u yok sayıyor (0,47–0,57) |
| Kalite | 9,82 | 9,79 |
| Artı | en hızlı, en yüksek eşzamanlılık | 1M bağlam |
| Eksi | 256K bağlam (bölüm ≈2.000 token, sorun değil) | yavaş, 2 kat token: ücretli dönemde pahalı |

gemma-4-31b de iyi (43 sn) ama yedek halkadadır. Asıl ile yedeğin farklı
aileden olması, birinin takılması durumunda ötekinin çalışma şansını artırır.

**K2. Aşama başına model → hayır, tek model**

| | A: bölüm ve rapor aynı (seçildi) | B: bölüm qwen, rapor glm-5.3 |
|---|---|---|
| Rapor süresi | 31 sn | 73 sn (p95 111) |
| Rapor kalitesi | 9,78 | 9,80 |
| Analiz başına tek model (kabul ölçütü) | ✔ | ✖ |

Panel alanları ("EVREN · bölüm / rapor modeli") duruyor, boş = varsayılan.

**K3. Panelde kayıtlı `auto` → boşaltıldı** (`transkript_model_evren`:
`auto` → boş)

| | A: boşalt (seçildi) | B: panele `qwen3.8-flash-next` yaz |
|---|---|---|
| Artı | kod varsayılanı ileride değişirse kendiliğinden uygulanır | seçim ekranda açıkça görünür |
| Eksi | panelde "Varsayılan (qwen3.8-flash-next)" yazar, değer boş | ileride elle güncellemek gerekir |

Geri almak için Transkript ayarları → Yapay zekâ → "EVREN modeli" → auto.
Biten raporlara dokunulmadı; değişiklik yeni analizlere uygulanır.

**K4. `auto` → listede kalır, analiz başına sabitlenir.** Kaldırmak
(B) kullanıcı seçimini daraltırdı. Seçen olursa ilk çağrının modeli
analizin geri kalanına kilitlenir (P0-1); açıklamada "yavaş / tutarsız
olabilir" yazıyor.

**K5. Düşünme → `low`** (`EVREN_THINKING`). `off` (B) yalnız deepseek'te
düşünmeyi tamamen kapatıyor (`chat_template_kwargs.thinking=false`); glm,
qwen ve mimo'da güvenli kapatma yok (keşif: glm düşünmesini yanıta
yazdı, qwen ve mimo yanlış yanıt verdi). Bu yüzden `off` ötekilerde `low`
gönderir.

**K6. İlk token beklemesi → 120 sn, sessiz bağlantı 30 sn.** 60 sn (B)
glm'nin 50 sn'lik, mimo'nun ≈90 sn'lik kuyruk beklemesini kesiyordu (ölçümde
1 rapor). Keep-alive gelmiyorsa 30 sn'de kesilir, ölü bağlantı beklenmez.

**K7. Kuyruk işçisi → Görev Zamanlayıcı (5 dk'da bir tetik) + tarayıcı tik.**
Yalnız tarayıcı (B) sayfa kapanınca durduğu için cowork 3. turdaki
duruşun sebebiydi. Görev bu bilgisayarda kuruldu (`kuyruk-isci-kur.ps1`,
"Transkript kuyruk iscisi"), kaldırmak için `kuyruk-isci-kur.bat kaldir`.

## 4. Yeni ayarlar

Varsayılan EVREN modeli `qwen3.8-flash-next` (`EVREN_MODEL` boşsa; panelden
değişir). `.env.example` (hepsi isteğe bağlı): `EVREN_STREAM` (true),
`EVREN_ILK_TOKEN_SN` (120), `EVREN_BOSTA_SN` (60), `EVREN_TOPLAM_SN` (boş: 120 sn + giriş/40, ≤900), `EVREN_THINKING`
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

1. **Kota ortak:** EVREN anahtarı başka süreçlerde de (ölçüm, başka
   oturum) kullanılırsa günlük 15M token paylaşılır. Durum'daki kalan kota
   EVREN'in kendi başlığından gelir, kesindir; bizim token toplamımız
   yalnız bu sistemin payıdır.
2. **Önbellek ve kota:** ölçüm, önbellekten okunan girişin kotadan
   düşmediğini gösteriyor (sapma %2,7). Tek ölçüm; ücretli dönemde
   faturayla karşılaştırın. Önbelleksiz qwen'de günlüğümüz EVREN'le %0,25
   içinde (23 çağrı); aylık tahmin günlükten hesaplandığı için şişkin
   değil. Durum'daki "kalan günlük kota" son yanıtın başlığından gelir,
   o çağrının kendi token'ı henüz düşülmemiştir (bir çağrı geriden gelir;
   karşılaştırma yöntemi 02, bulgu 8).
3. **Sunucu yükü:** aynı model farklı saatte 2–3 kat yavaş olabiliyor
   (mimo 42–157 sn, glm ilk token 3–52 sn). İlk token 120 sn ve yedek halka
   (gemma) bunu karşılıyor. Durum → "EVREN model sağlığı" izlenmeli.
4. **deepseek-v4.1-flash low'u dinlemiyor:** seçilirse uzun videoda 2 kat
   yavaş ve token'lı. `EVREN_THINKING=off` onda düşünmeyi kapatır.
5. **gemma düşünmesi kısılamıyor** (reasoning_effort yok; akışta %40 düşünme
   token'ı); bir bölümde çıktı döngüye girdi, ×2 bütçe düzeltti.
6. **json_schema desteği değişebilir:** EVREN 400 dönerse sıra json_object →
   biçimsiz; doğrulayıcı alternatif alan adlarını okur.
7. **İşçi bu bilgisayara bağlı:** Görev Zamanlayıcı oturum açıkken çalışır;
   bilgisayar kapalıysa analizler sırada bekler (kaybolmaz, nabız 5 dk
   sonra geri alır). Sunucuya taşınırsa cron `php cy schedule:run`.
8. **Uzun videoda bölümler sırayla:** tek işçi bölümleri ard arda işler
   (38 dk ≈ 5 çağrı). Bölüm boyutunu büyütmek (ölçümde 8.000 token: 155 sn
   vs 458 sn) ayrı adım olarak önerilir; zaman damgası ve örtüşme davranışı
   değişeceği için ölçülerek yapılmalı.
9. **Şartlar:** sürüm 1 onaylı (anahtar başına). Yeni sürüm gelirse EVREN
   403 döner, Durum uyarır, zincir Gemini'ye geçer. Kod onaylamaz;
   yönetici "Şartları oku ve onayla" ile onaylar.
10. **1 Kasım sonrası fiyat bilinmiyor** (8. bölüm).
11. **Kalite puanı kural tabanlıdır** (şema, Türkçe, zaman damgası, kaynakta
    geçen ad / sayı); modeller arasında az ayırt edici. Hakem modelle ya da
    insan gözüyle örnek rapor karşılaştırması ayrı yapılmalı.

## 8. 1 Kasım 2026 sonrası

- **Fiyat:** `/v1/models` bugün bütün modellerde 0 CR, `free_until:
  2026-11-01`; sonraki fiyat açıklanmadı. Model listesi ve fiyatlar her gün
  06:10'da yenilenir (`transkript-evren-modeller` görevi). Durum sayfası
  ücretli döneme geçince "ücretli" der ve son 7 günün token'ından aylık tahmin
  gösterir (`AiKullanimRepository::evrenTahmini`).
- **İlk hafta yapılacak:** Durum → "EVREN model sağlığı" ve aylık tahmine
  bakın. Bölüm analizi token'ın çoğunu harcar (her bölüm ayrı çağrı); fiyat
  modeller arasında ayrışırsa önce **bölüm modelini** ucuz / hızlı olana
  çekin (panel: Yapay zekâ → "EVREN · bölüm analizi modeli"), rapor modelini
  kalitede bırakın. EVREN Gemini'den pahalı kalırsa `.env` `AI_PROVIDERS`
  sırasında EVREN'i ikinciye alın; kod değişikliği gerekmez.
- **Kalkan model:** `deepseek-v4-flash` 1 Kasım'da kalkıyor; panelde ya da
  `.env`'de kayıtlıysa kod `deepseek-v4.1-flash` kullanır ve günlüğe bir kez
  yazar (`Ai::evrenTasi`).
- **Kesilen istek de kotadan düşer:** zaman aşımında kullanım tahmin edilip
  "≈" ile yazılıyor; ücretli dönemde bu satırlar da faturaya girer, Durum'da
  zaman aşımı oranını izleyin.
