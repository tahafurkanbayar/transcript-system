# Transkript Modülü

YouTube videolarından (sonra yüklenen ses / video dosyalarından da) zaman
damgalı transcript çıkarır ve AI ile analiz eder: kısa ve detaylı özet, önemli
noktalar, zaman çizelgesi, teknik bilgiler, iddialar, fikirler. Onaylı plan:
[docs/TEKNIK_RAPOR.md](../../docs/TEKNIK_RAPOR.md).

Projeye ait kodun tamamı bu klasördedir; çekirdeğe (`app/`, `config/`,
çekirdek `routes/` ve `views/`) dokunulmaz.

---

## Dosya yapısı

```
modules/Transkript/
├─ module.json        künye, menü, rollere dağıtılan yetkiler, kurulumda açık
├─ routes.php         panel/transkript/… ve api/v1/transkript/… rotaları
├─ migrations/        11 tablo (aşağıda)
├─ prompts/           *.v1.txt; sürüm analizle birlikte kaydedilir
├─ src/
│  ├─ Controllers/    KaynakController, SohbetController, Api/TranskriptApiController
│  ├─ Repositories/   SQL'in tek yeri, her sorgu hazırlıklı
│  ├─ Kaynak/         UrlParser, YoutubeMetadata, DosyaYukleme
│  ├─ Services/       Cleaner, Chunker, Pipeline, ResultValidator, Chat, Search, CostTracker
│  ├─ Transcript/     sağlayıcı zinciri (YouTube → Gemini → harici → ASR)
│  ├─ Ai/             AI sağlayıcıları (ai-integration-system'den)
│  ├─ Jobs/           KaynakBilgisiAl → TranskriptAl → ParcaAnalizEt × N → AnalizBirlestir → RaporuTamamla
│  └─ TranskriptPolicy.php   kayıt sahibine bağlı kurallar
└─ views/             index (yeni analiz + liste), goster (rapor), _parcalar/
```

Boş klasörler (`.gitkeep`) sonraki MVP adımlarında dolar.

## Roller ve yetkiler

| Yetki | Ne sağlar | Yönetici | Editör | Üye |
|---|---|:-:|:-:|:-:|
| `transkript.view` | Raporları görmek | ✓ | ✓ | ✓ |
| `transkript.analyze` | Analiz başlatmak, dosya yüklemek (maliyet oluşturur) | ✓ | ✓ | |
| `transkript.chat` | Kayda soru sormak | ✓ | ✓ | ✓ |
| `transkript.admin` | Yeniden analiz, herkesin kaydı, tüm maliyet | ✓ | | |

Satır düzeyi (`TranskriptPolicy`): YouTube kaynağı ve analizi herkes için
ortak cache'tir (`sahip_id = 0`). Yüklenen dosya, sohbet ve kişisel özet
yalnızca sahibine ve `transkript.admin` yetkisi olana açıktır.

## Tablolar

| Tablo | İçerik |
|---|---|
| `transkript_kaynaklar` | YouTube videosu ya da yüklenen dosya; UNIQUE(`tur`, `dis_kimlik`, `sahip_id`) cache anahtarı |
| `transkript_metinler` | Bir kaynağın transcript sürümü (sağlayıcı, dil) |
| `transkript_segmentler` | Zaman damgalı satırlar; `arama_metni` = `Search::trFold(metin)` üzerinde FULLTEXT |
| `transkript_analizler` | Analiz çalışması + rapor, durum / aşama / ilerleme, token ve maliyet |
| `transkript_parcalar` | AI'a giden parçalar ve map sonuçları (kaldığı yerden devam) |
| `transkript_analiz_ogeleri` | Konu, nokta, iddia, fikir, teknik, zaman maddeleri |
| `transkript_kisisel_ozetler` | "Sana özel" bölümü, kullanıcı başına |
| `transkript_sohbetler` / `transkript_sohbet_mesajlari` | Kayda sor |
| `transkript_ilgi_alanlari` | Kişisel filtrenin ilgi alanları |
| `ai_kullanim_kaydi` | Her AI / ASR çağrısının token ve maliyeti (adı genel, başka modüller de yazabilir) |

**Arama neden katlanmış sütunda?** `utf8mb4_turkish_ci`'de "I"nın küçüğü
"ı"dır: "ai" araması "AI"yı, "api" "API"yi bulmaz. `unicode_ci` ise "IŞIK" ile
"ışık"ı eşleştirmez. Segment yazan kod `arama_metni`'ni `Search::trFold($metin)`
ile doldurur (CHECK kısıtı boş bırakılmasına izin vermez); sorgu da aynı
katlamadan geçer. Böylece "ai" → "AI", "isik" → "IŞIK / ışık" bulunur.

Kullanıcı sütunlarına (`sahip_id`, `kullanici_id` …) bilerek yabancı anahtar
verilmez: kurulum sihirbazı `kullanicilar`'a bağlı tabloları görünce aynı
veritabanına yeniden kurulumu reddeder. Modül tabloları arası bağlar yabancı
anahtarlıdır; kaynak silinince ona bağlı her şey silinir.

## Rotalar

Panel: `panel/transkript` (liste), `panel/transkript/{kaynak_id}` (rapor),
`panel/transkript/analiz` (POST), `panel/transkript/ilerleme/{kaynak_id}`,
`panel/transkript/{kaynak_id}/sohbet` (POST).

API (`Authorization: Bearer cy_…`): `api/v1/transkript/analiz`, `dosya`,
`kaynaklar`, `kaynaklar/{kaynak_id}`, `…/ozet`, `…/transkript`, `…/sohbet`,
`istatistik`. Ayrıntı ve erişim matrisi: `tests/transkript.php`.

## Kaynak bilgisi (YouTube)

- `Kaynak/UrlParser`: watch, youtu.be, shorts, embed, live, mobil, music ve
  nocookie biçimlerinden 11 karakterlik kimlik; alan adı beyaz listede.
  Kullanıcının yazdığı adrese hiç istek atılmaz, adres kimlikten yeniden kurulur.
- `Kaynak/YoutubeMetadata`: `YOUTUBE_API_KEY` varsa Data API v3 (süre, tarih,
  dil, gizlilik), yoksa ya da hata verirse oEmbed. Anahtar URL'de değil
  `X-Goog-Api-Key` başlığında gider. Gizli / bulunamadı / silinmiş ayrı
  `KaynakHatasi` kodlarıdır; ağ ve 5xx hatası `metadata_hatasi` (yeniden denenir).
  Anahtarsız kurulumda gömmeye kapalı herkese açık video "gizli" görünür.
- `Repositories/KaynakRepository`: `INSERT … ON DUPLICATE KEY UPDATE` ile
  yarışsız bul-ya-da-oluştur; `guncelTamamlanmisAnaliz()` (cache) ve
  `suregelenAnaliz()` (ikinci kez "Analiz Et").
- `Http/`: `HttpIstemci` arayüzü ve curl uygulaması; testler sahte istemciyle
  ağa gitmeden çalışır.

## Transcript

`Transcript/TranscriptZinciri` sağlayıcıları `config.php` → `transcript.saglayicilar`
sırasıyla dener (`.env`: `TRANSCRIPT_PROVIDERS`, varsayılan `youtube,gemini`):

| Sağlayıcı | Nasıl | Not |
|---|---|---|
| `youtube` | İzleme sayfası → InnerTube `player` (ANDROID istemcisi) → altyazı XML'i | Ücretsiz. MrMySQL/youtube-transcript'ten (WTFPL) taşındı. Dil: tercih (elle) → orijinal ses dili → en |
| `gemini` | Gemini'ye YouTube URL'si, JSON şemalı zaman damgalı transcript | Ücretli, `GEMINI_API_KEY`. Uzun video 20 dk pencerelere bölünür, düşük çözünürlük, düşünme kapalı |
| `harici` | Ücretli transcript API | 5c. aşama, iskelet |
| `asr` | Yüklenen dosyada Whisper benzeri | 5b. aşama, iskelet; YouTube sesi ASR'a verilmez |

Gizli / silinmiş video zinciri durdurur (`KaynakHatasi`); diğer hatalarda sıradaki
sağlayıcı denenir, hepsi başarısızsa tek sonuç hatası döner (hız sınırı ve geçici
hatalar yeniden denenir). Her deneme `storage/logs/transkript-*.log`'a yazılır.

`Repositories/SegmentRepository::kaydet()` transcript'i tek transaction'da, 500'erli
toplu INSERT ile yazar (2 saatlik video ~3.000 segment, ~0,3 sn).

`Services/Cleaner`: varlık / etiket / [Müzik] temizliği, örtüşen otomatik altyazı
satırlarının düzeltilmesi, 15–30 sn cümle blokları. `Services/Chunker`: 8–10 dk ya
da ~4.000 token parçalar, 30 sn örtüşme; kesim cümle sonunda (9 dk'ya en yakın),
noktalama yoksa en uzun sessizlikte. Ölçüm: 2 saatlik video 14 parça, 9–10 dk.

## Yapay zekâ

`src/Ai/` CilginYazilim/ai-integration-system'den (MIT) taşındı: Gemini, Claude
ve OpenAI uyumlu (Groq, xAI, OpenRouter, Ollama) sağlayıcılar, tek
normalleştirilmiş yanıt, `Retry-After` uyumlu yeniden deneme, `PRICING` +
`priceFor()` maliyet hesabı. Aşama başı model `Ai::make($saglayici, $model)`
ile seçilir. Hatalar `AiHatasi` olarak gelir; `kod` alanı yeniden denenip
denenmeyeceğini söyler (`rate_limit`, `sunucu`, `baglanti`, `zaman_asimi`
geçici). Anahtarlar yalnızca `.env`'de (`GEMINI_API_KEY`, `CLAUDE_API_KEY`,
`GROQ_API_KEY`, `AI_API_KEY`).

Fiyat tabloları kaynaktaki anlık görüntüdür; kullanmadan önce sağlayıcının
güncel fiyatıyla karşılaştırın.

## Komutlar

```bash
php cy module --enable=Transkript    # modülü aç
php cy migrate                       # tablolarını kur
php tests/transkript.php             # modül birim testleri (veritabanı gerekmez)
```

## Kaldırmak

```bash
php cy migrate:status                     # modül migration'larının parti numarasına bak
php cy migrate:rollback --step=N --force  # o partiye kadar geri al (her migration'ın down()'u var)
php cy module --disable=Transkript
```

`ai_kullanim_kaydi` yalnızca içinde başka modülün satırı yoksa silinir;
varsa yalnız bu modülün satırları temizlenir.
