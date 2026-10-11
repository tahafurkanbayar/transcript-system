# EVREN entegrasyonu — 1. Keşif

Tarih: 11 Ekim 2026. Kod: `feature/evren-gelistirme` dalının başı (`e8af65b`,
modül 0.9.2). Satır numaraları bu sürüme göredir.

> **Proje düzeni (prompt'tan farklı):** proje CodeIgniter 4 değil,
> cy-php-starter (`php cy`, `app/Core`, `modules/<Ad>/`). Çekirdeğin `php cy`
> komut listesi sabit olduğundan modül araçları `modules/Transkript/bin/*.php`,
> testler bağımlılıksız `tests/transkript.php` (PHPUnit yok, Composer yasak:
> CLAUDE.md kural 4), yazılabilir alan `storage/` (`writable/` değil).

## 1. Mimari

```
 Panel / API ──► AnalizBaslatici ──► Queue::push (kuyruk "transkript", tablo isler)
                                          │
   İşçi: kuyruk-isci.bat → php cy schedule:run (dakikada bir)
         → events.php "transkript-kuyrugu" (240 sn bütçe) → Queue::runNext
   Yedek: açık panel sayfası → POST panel/transkript/kuyruk/tik (GET_LOCK, 1 iş)
                                          │
  KaynakBilgisiAl → TranskriptAl → ParcaAnalizEt × N → AnalizBirlestir → RaporuTamamla
        (oEmbed)     (YouTube altyazı)   │ map            │ reduce (kademeli)
                                         ▼                ▼
                         Pipeline::jsonIste(asama, istem, şema, doğrulayıcı)
                                         │
                     Pipeline::saglayiciUret(asama) ─► YedekliSaglayici (zincir)
                        halkalar: evren → gemini → openrouter → groq
                        dinlenme: DinlenmeDeposu (storage/files/transkript-dinlenme.json)
                        giriş sınırı: GirisSiniri (config ai.giris_siniri + öğrenilen)
                                         │
                     EvrenProvider ⊂ OpenAiCompatibleProvider ⊂ HttpProvider (cURL)
                                         │
                     ResultValidator (JSON ayıkla, şema, zaman damgası) → DB
                     AiKullanimRepository::kaydet → ai_kullanim_kaydi (her çağrı)
```

## 2. Dosya / metot haritası

| Konu | Yer |
|---|---|
| Ortak arayüz | `src/Ai/Provider.php` (`send`, `sendJson`, `model`) |
| HTTP + yeniden deneme | `src/Ai/HttpProvider.php`: `request()` (yeniden deneme, Retry-After), `curl()` (CURLOPT_TIMEOUT = `$timeout`, bağlantı `BAGLANTI_ZAMAN_ASIMI`) |
| OpenAI uyumlu | `src/Ai/OpenAiCompatibleProvider.php`: `payload()` 81, `jsonPayload()` 108 (`response_format: json_object`), `normalize()` 123, `errorCode()` 191 |
| EVREN | `src/Ai/EvrenProvider.php`: sabitler 54–87 (`VARSAYILAN_MODEL` glm-5.3, `MODELLER` 6 sabit model, `EN_AZ_TOKEN` 2048, `EN_AZ_SURE` 180, bağlantı 10 sn), `sendJson()` 111 (400 → `response_format`'sız bir kez), `normalize()` 128, `errorCode()` 176, `describeError()` 197, kota 226–300 |
| Fabrika, model çözümü | `src/Ai/Ai.php`: `make()` 245, `varsayilanModel()` 346 (panel → `<AD>_MODEL` → `AI_MODEL` → varsayılan), `zincir()` 410, `sabitFiyat()` 513 |
| Zincir | `src/Ai/YedekliSaglayici.php`: `dene()` 281, `sonuc()` 348, `DINLENME_SN` 64, `DEVRE_DISI` 76, `dinlendir()` 225 |
| Dinlenme deposu | `src/Ai/DinlenmeDeposu.php` → `storage/files/transkript-dinlenme.json` (dosya kilidiyle, bütün işçiler görür) |
| Giriş sınırı | `src/Ai/GirisSiniri.php` (sağlayıcı başına; model başına değil) |
| Aşama sağlayıcısı | `src/Services/Pipeline.php`: `saglayiciUret()` 652, `jsonIste()` 518, `kaydet()` 599, `denemeleriKaydet()` 584, `maliyet()` 619 |
| Bölümleme | `src/Services/Chunker.php` `parcala()` 73: **token** tahmini (karakter / 3,5), hedef `parca.hedef_token` 4000, örtüşme 30 sn |
| Şart onayı | `src/Ai/EvrenHesap.php`, `src/Controllers/AyarController.php` `evrenSartlar()` 156 / `evrenSartOnayla()` 192, kayıt `ayarlar.transkript_evren_sart` |
| Kota | `EvrenProvider::basliklariIsle()` 160 → Cache `transkript:evren_kota`; Durum'da `EvrenProvider::sonKota()` (AyarController 113) |
| Çağrı günlüğü | `src/Repositories/AiKullanimRepository.php` `kaydet()` 33 → `ai_kullanim_kaydi` (saglayici, model = yanıttaki, giris/cikis_token, maliyet_usd, sure_ms, durum, hata_kodu, hata_ayrinti, created_at) |
| Kuyruk | çekirdek `app/Core/Queue/Queue.php` (`runNext`, `releaseStuck`: `ayrildi_at` > `QUEUE_TIMEOUT` 300 sn → geri), modül `src/Services/KuyrukDurumu.php`, `src/Controllers/KaynakController.php` `kuyrukTik()` 445 |
| Testler | `tests/transkript.php` (453), `tests/transkript-yetki.php`, `tests/unit.php`, `tests/smoke.php`; PHPUnit yok |

## 3. Aşama parametreleri (config.php → `ai.*`)

| Aşama | İstem | max_tokens | Zaman aşımı | Diğer |
|---|---|---|---|---|
| Bölüm (map) | `parca_analizi.v3` | 8192 | 120 sn → EVREN'de en az 180 | `response_format: json_object`, sıcaklık gönderilmez, akış YOK |
| Birleştirme (reduce) | `birlestirme.v3` | 24000 | 240 sn | aynı |
| Ara birleştirme | `ara_birlestirme.v2` | 24000 | 240 sn | kademeli |

EVREN çağrısı: `POST {taban}/chat/completions`, `Authorization: Bearer`, gövde
`{model, messages[system, user], max_tokens, response_format}`; yanıttan yalnız
`message.content` (düşünme alanları okunmaz, rapora girmez). Hata eşlemesi:
403 + `terms_not_accepted` → `sartlar_onaylanmadi` (1 sa devre dışı), 401 →
`yetkisiz`, 429 + günlük kalan 0 → `kota_doldu` (03:00'e kadar), öteki 429 →
`rate_limit`, 503 → `sunucu`, 400 → `istek_hatasi` (bağlam aşımıysa `cok_buyuk`),
cURL 28 → `zaman_asimi`. "Düşünme bütçesi yetmedi" (`dusunme_butcesi`):
`normalize()` 147 — içerik boş VE `finish_reason = length`.

## 4. Maliyet

`Pipeline::maliyet()` önce `Ai::sabitFiyat()`'a bakar: EVREN için `0/0`
(USD). Panel kartı "Toplam token / Tahmini maliyet" **bütün zamanların**
toplamıdır (`KaynakRepository::kullanim()`); `$0,2201`'in tamamı Gemini'den
(177.148 token). EVREN token'ı ücretli sayılmıyor — doğrulandı.

## 5. S1–S12 doğrulaması

| # | Sonuç | Kanıt (kod / veri) |
|---|---|---|
| S1 auto her çağrıda başka model | **Doğrulandı** | `Ai::varsayilanModel('evren')` panelden `auto`; analiz boyunca sabitleme yok. #4383: 1 dk'da mimo → qwen3-vl → gemma |
| S2 metin işi görsel modele | **Doğrulandı** | `auto → qwen/qwen3-vl-30b-a3b` (#4383, #4447); `auto` ayrıca kaldırılacak `deepseek/deepseek-v4-flash`'a da gidiyor (#4608) |
| S3 180 sn sabit zaman aşımı | **Doğrulandı** | `EvrenProvider::__construct` `max(EN_AZ_SURE, $timeout)`; `HttpProvider::curl()` tek `CURLOPT_TIMEOUT`, akış yok → keep-alive işe yaramıyor. Bugün EVREN'de 4 zaman aşımı / 27 çağrı |
| S4 düşünme bütçesi | **Kısmen** | max_tokens map 8192 / reduce 24000 (yeterli); #4449'daki 95 sn boş yanıt `auto`'nun düşünen modele düşüp bütçeyi düşünmeye harcaması; yeniden deneme yok, usage atılıyor |
| S5 zincir yavaş | **Doğrulandı** | `dene()`: EVREN 180 sn → OpenRouter 429 (1 sn) → Groq 104–187 sn; EVREN içinde başka model denenmez |
| S6 OpenRouter boşa | **Doğrulandı** | bugün 26/26 çağrı 429; `DINLENME_SN['rate_limit']` sabit kısa, art arda 429'da artmıyor |
| S7 Groq 413 | **Doğrulandı, 0.9.2'de düzeltildi** | 0.9.1'de TPM 413 "hız sınırı" sayılıp aynı istek yeniden gidiyordu; 0.9.2: %80 sınır öğrenme + 60 sn dinlenme (`GirisSiniri`) |
| S8 kota / kayıt farkı | **Doğrulandı (nedeni karışık)** | zaman aşımında ve `dusunme_butcesi`'nde usage kaydedilmiyor (EVREN üretmeye devam edip kotadan düşüyor); ayrıca aynı hesabı başka süreçler de kullanıyor (bugün `efor.php`), günlük kota hesap toplamı → fark tek başına bizim kayıp değil |
| S9 %80'de takılma | **Doğrulandı, nedeni kuyruk** | #4446–4448 işleri 04:17'den 04:30'a kadar `bekliyor`: kalıcı işçi kurulu değil (Görev Zamanlayıcı'da görev yok), tarayıcıdan işleme 04:20'de durdu (sekme gizli / analiz bitince yoklama duruyor). Bkz. 3. tur raporu |
| S10 etiket ↔ kayıt | **Kodda hata yok** | "Varsayılan (glm-5.3)" boş değerin etiketi, `Ai::ortamModeli()` ile aynı kaynak; kayıt `auto` ve `auto` seçeneği seçili. Karışıklık liste metninden; ekranda "şu an kullanılan" yok |
| S11 log zaman damgası | **Doğrulandı** | `denemeleriKaydet()` başarısız denemeleri zincir bittikten sonra yazar (created_at = yazma anı); başarılı satırın `sure_ms`'i zincirin başından ölçülür (`jsonIste` `$baslangic`) → Groq 188 sn = EVREN 180 + OR 1 + Groq 7 |
| S12 sabit model listesi | **Doğrulandı** | `EvrenProvider::MODELLER` sabit; `/v1/models` yalnız teşhiste okunuyor |

## 6. Canlı `/v1/models` (11.10.2026)

| id | görev | bağlam | düşünme | `reasoning_effort` |
|---|---|---|---|---|
| glm-5.3 | chat | 524.288 | var | var (`medium→high`, `minimal→low`) |
| deepseek-v4.1-flash | chat | 1.048.576 | var | var (`medium→high`, `minimal→low`); çıktı 65.536 |
| mimo-v2.6-pro | chat | 1.048.576 | var | none / minimal / low / medium / high / xhigh |
| qwen3.8-flash-next | chat | 262.144 | var | var (`high→xhigh`) |
| gemma-4-31b | chat | 262.144 | yok | yok |
| qwen3-vl-30b | vision_chat | 262.144 | yok | yok |
| auto | chat | **32.768** | — | parametre almaz (`response_format` dahil) |
| deepseek-v4-flash | chat | 1.048.576 | var | var — 1 Kasım'da kalkıyor |

Kota: 5 dakikalık pencere 2.500.000 token + günlük 15.000.000 (00:00 UTC =
03:00 TR sıfırlanır). Bütün fiyatlar 0 CR, `free_until: 2026-11-01`.
