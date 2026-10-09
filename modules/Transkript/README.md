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
├─ config.php         davranış ayarları (Ayarlar::get); anahtar yok
├─ routes.php         panel/transkript/… ve api/v1/transkript/… rotaları
├─ events.php         "transkript-kuyrugu" zamanlanmış görevi (yalnız CLI)
├─ migrations/        11 tablo (aşağıda)
├─ prompts/           parca_analizi.vN.txt, birlestirme.vN.txt; sürüm analizle kaydedilir
├─ assets/            css/transkript.css, js/transkript.js (Varliklar rotasıyla sunulur)
├─ src/
│  ├─ Controllers/    KaynakController, SohbetController, Api/TranskriptApiController
│  ├─ Repositories/   SQL'in tek yeri, her sorgu hazırlıklı
│  ├─ Kaynak/         UrlParser, YoutubeMetadata, DosyaYukleme
│  ├─ Services/       AnalizBaslatici, KaynakDurumu, RaporVerisi, Cleaner, Chunker, Pipeline, ResultValidator, Istem, Search
│  ├─ Transcript/     sağlayıcı zinciri (YouTube → Gemini → harici → ASR)
│  ├─ Ai/             AI sağlayıcıları (ai-integration-system'den)
│  ├─ Jobs/           KaynakBilgisiAl → TranskriptAl → ParcaAnalizEt × N → AnalizBirlestir → RaporuTamamla
│  └─ TranskriptPolicy.php   kayıt sahibine bağlı kurallar
└─ views/             index (yeni analiz + liste), goster (rapor), _parcalar/sayfalama
```

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

Eylem bazında (canlı denendi: misafir / üye / editör / yönetici, 46 denetim):

| Eylem | Misafir | Üye | Editör | Yönetici |
|---|:-:|:-:|:-:|:-:|
| Liste, rapor, ilerleme, transcript, indirme (ortak YouTube kaydı) | giriş sayfası | ✓ | ✓ | ✓ |
| Başkasının yüklediği dosya kaydı | giriş sayfası | 404 | 404 (kendi: ✓) | ✓ |
| Analiz başlat (önbellekteyse AI'a gidilmez) | 401 | 403 | ✓ | ✓ |
| Yeniden analiz (`yeniden=1`) | 401 | 403 | 403 | ✓ |
| Süren analizi iptal | 401 | 403 | kendi başlattığı | ✓ |
| Sil | 401 | kendi yüklediği | kendi yüklediği | ✓ (ortak kayıt dahil) |
| "Şimdi işle" (kuyruk) | 401 | 403 | 403 | ✓ |
| Maliyet sütunları, "bütün kullanıcılar" sayaçları | | kendi | kendi | herkesin |

CSRF anahtarı olmayan her POST 403'tür.

**Kotalar** (`config.php` → `analiz`; yalnız yeni AI işi açan istek sayılır,
önbellekten dönen sayılmaz): kullanıcı başına saatte `saatlik_sinir` (20) ve
günde `gunluk_sinir` (10) analiz; bütün kullanıcılar için günlük
`gunluk_token_tavani` (0 = kapalı) ve `gunluk_maliyet_tavani` (5 USD, ücretli
katman fiyatıyla tahmin). Aşılınca 429 + `Retry-After` (gün sonuna kadar) ve
Türkçe mesaj (`gunluk_sinir`, `gunluk_butce`). Yönetici kişisel sınırlardan
muaftır (`yonetici_muaf`); genel bütçe tavanı herkese uygulanır.

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
`panel/transkript/{kaynak_id}/transkript` (okuma + arama JSON'u),
`panel/transkript/varlik/{dosya}` (modülün JS / CSS'i),
`panel/transkript/{kaynak_id}/indir/{bicim}` (rapor.md, transkript.txt / .srt / .vtt),
`panel/transkript/{kaynak_id}/sil` ve `…/iptal` (POST, kayıt yönetimi),
`panel/transkript/kuyruk/isle` (POST, yönetici; "Şimdi işle"),
`panel/transkript/{kaynak_id}/sohbet` (POST, sonraki aşama).

API (`Authorization: Bearer cy_…`): hazır olanlar `analiz`, `kaynaklar`
(sayfalı; `tur`, `durum` = hazir / suruyor / hata, `q`), `kaynaklar/{kaynak_id}`,
`…/ozet` (rapor; maliyet yalnız `transkript.admin`'e), `…/transkript`
(`bas` / `bit` saniye, `q` arama). `dosya`, `…/sohbet`, `istatistik` sonraki
aşamalarda (501). Ayrıntı ve erişim matrisi: `tests/transkript.php`.

## Ekranlar

**Liste (`panel/transkript`):** URL kutusu ve "Analiz Et" (AJAX). Analiz
edilmiş video girilirse sayfa değişmeden "Bu video daha önce analiz edildi
[Sonuçları Gör] [Yeniden Analiz Et]" uyarısı (yeniden analiz yalnız
`transkript.admin`); yeni analizde rapor sayfasına geçilir. Özet kutucukları
raporları sayar, denemeleri değil: raporu hazır video (altında "2 sürüyor ·
1 hata"), bugün tamamlanan analiz, token, tahmini maliyet (yönetici herkesinkini,
diğerleri kendininkini görür; silinen kayıtların harcaması token ve maliyette
kalır). Sunucu taraflı sayfalı tablo: Kaynak · Süre · Durum (rozet + ilerleme
ya da "Rapor hazır") · Tarih · İşlemler; arama başlık, kanal ve video
kimliğinin bir parçasında ("jNQX"), durum filtresi; telefonda tablo karta
döner. Süren analizin satırı 3 sn'de bir güncellenir.

**Kayıt yönetimi** (`Services/KayitYonetimi`, onaylar panelin pencere
biçimiyle, tarayıcının `confirm()` kutusu yok):

| Eylem | Kim | Ne olur |
|---|---|---|
| Yeniden dene | analiz yetkisi (`transkript.analyze`), son analiz hatalıysa | yeni analiz açılır; transcript yeniden kullanılır |
| İptal | yönetici ya da analizi başlatan | analiz `iptal_edildi` koduyla biter, bekleyen işleri silinir; çalışan iş sonucunu yazamaz |
| Sil | ortak YouTube kaydında yalnız yönetici; yüklenen dosyada sahibi | kaynak, analizler, transcript, sohbetler (CASCADE) ve bekleyen işler tek işlemde; AI kullanım kayıtları kalır |

**Rapor (`panel/transkript/{kaynak_id}`):** kaynak kartı; analiz sürerken 4
aşamalı canlı ilerleme (bitince sayfa kendini yeniler); kısa özet; açılır
bölümler: detaylı özet, konular, önemli noktalar (Kritik / Önemli / Faydalı
rozeti, "yalnız kritik ve önemli" filtresi; açıkken rozet "12 / 14", tercih
tarayıcıda hatırlanır), zaman çizelgesi, kavramlar ve teknik bilgiler
(kategoriye göre), fikirler, iddialar (Kesin ifade / Görüş / Tahmin), öneriler,
transcript (açılınca yüklenir; yüklenemezse "Tekrar dene"; arama en az 2 harf,
debounce + `seq` yarış koruması, Türkçe katlamalı vurgu). Analiz edilemeyen
bölümler varsa aralıkları uyarıda. Konular videoyu kesintisiz böler: ilk konu
00:00'da başlar, her konu bir sonrakinin başında biter, son konu video sonunda
(`ResultValidator::konulariDuzenle`; eski raporlara da gösterirken uygulanır).
"Bu rapor" panelinde model, istem ve transcript dili; rapor güncel istemden
eski bir sürümle üretildiyse not düşülür (yönetici yeniden analiz edebilir).

**Dışa aktarma ve kısayollar** (rakip analizinden; `docs/RAKIP_ANALIZI.md`):
rapor Markdown (zaman damgaları `youtu.be?t=` bağlantısı, AI metni
kaçışlanır), transcript TXT, altyazı SRT / VTT (`Services/DisaAktarim`);
YouTube açıklamasına yapıştırılacak bölüm listesi (ilk 0:00, en az 3, 10 sn
kuralı); kısa özeti kopyala; "~N dk okuma"; `panel/transkript?url=…` ile dolu
açılış ve masaüstü yer imi. Hepsi kayıtlı rapordan üretilir, AI çağrısı yok.

**Oynatıcı:** sağda yapışkan `youtube-nocookie` gömülü oynatıcı. Zaman
damgasına tıklayınca IFrame Player API'nin `postMessage` komutlarıyla
(`seekTo`, `playVideo`) o saniyeye atlar; dış betik (`iframe_api`)
yüklenmez. Oynatıcının `infoDelivery` iletileri (anlık zaman) transcript'te o
an konuşulan satırı vurgular ve listeyi kaydırır. Telefonda oynatıcı gizlenir, damga `https://youtu.be/ID?t=…`
bağlantısıdır ve YouTube uygulamasını o saniyeden açar.

**Güvenlik:** AI ve transcript metni PHP'de `e()`, JS'de `textContent` ile
basılır. Çekirdeğin CSP'si yalnız kendi alan adına izin verir; Transkript
sayfaları `Varliklar::youtubeIzinleri()` ile yalnız `img-src https://i.ytimg.com`
ve `frame-src` YouTube kaynaklarını ekler (`script-src` açılmaz). Modülün JS /
CSS'i `modules/Transkript/assets/` altında durur; `modules/` klasörü nginx
örneğinde ve geliştirme sunucusunda tarayıcıya kapalı olduğundan beyaz
listeyle `panel/transkript/varlik/{dosya}` rotasından, sürüm damgası ve ETag
ile sunulur. Çekirdeğe dosya eklenmez.

## Kaynak bilgisi (YouTube)

- `Kaynak/UrlParser`: watch, youtu.be, shorts, embed, live, mobil, music ve
  nocookie biçimlerinden 11 karakterlik kimlik; alan adı beyaz listede.
  Kullanıcının yazdığı adrese hiç istek atılmaz, adres kimlikten yeniden kurulur.
- `Kaynak/YoutubeMetadata`: `YOUTUBE_API_KEY` varsa Data API v3 (süre, tarih,
  dil, gizlilik), yoksa ya da hata verirse oEmbed. Anahtar URL'de değil
  `X-Goog-Api-Key` başlığında gider. Gizli / bulunamadı / silinmiş ayrı
  `KaynakHatasi` kodlarıdır; ağ ve 5xx hatası `metadata_hatasi` (yeniden denenir).
  Anahtarsız kurulumda gömmeye kapalı herkese açık video "gizli" görünür.
  Anahtarı Google Cloud'da **web sitesine (HTTP yönlendiren) kısıtlamayın**:
  sunucu isteğinde referer yoktur, Google 403 `API_KEY_HTTP_REFERRER_BLOCKED`
  döner ve her video oEmbed'e (süresiz) düşer. Uygulama kısıtı "Yok" ya da
  sunucunun IP adresi, API kısıtı "YouTube Data API v3" olmalı.
- **Ön kontrol ("Analiz Et" isteğinde):** video doğrulanmamışsa (kaynak yok
  ya da başlıksız) kayıt açılmadan önce yalnız oEmbed'e gidilir (en çok 4 sn).
  400 / 404 → `video_bulunamadi` 422; 401 → `gizli_video` 422 (Data API
  anahtarı varsa bu kararı iş verir: yerleştirmeye kapalı ama açık video
  analiz edilebilir); ağ hatası, zaman aşımı, 5xx → kontrol atlanır, iş
  kendisi denetler. 200 dönerse başlık, kanal ve küçük resim hemen yazılır.
  Oynatma listesi ve kanal bağlantısı ayrı Türkçe mesaj alır
  (`oynatma_listesi`, `kanal_baglantisi`).
- **Süre sınırı:** `analiz.en_uzun_dk` (180; 0 = sınırsız). Süre biliniyorsa
  istek 422 `video_cok_uzun` ile reddedilir; bilinmiyorsa video bilgisi ya da
  transcript gelince, yapay zekâya gitmeden analiz kalıcı hatayla durur.
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

Modüle eklenenler: `sendJson()` (şema zorlaması: Gemini `responseSchema`,
Claude `output_config.format`, OpenAI uyumlu `json_object`), Gemini'de düşünme
token'larının maliyete katılması, 429 gövdesindeki `retryDelay`'e uyum ve
günlük kota dolunca boşuna yeniden denememe (`kota_doldu`).

### Analiz hattı (`Services/Pipeline`)

```
parça blokları "[hh:mm:ss] metin"  ──map──▶  parça JSON'u (ResultValidator)
parça JSON'ları (transcript değil) ──reduce──▶  rapor JSON'u (ResultValidator)
rapor ──Pipeline::ogeler──▶ transkript_analiz_ogeleri
```

- **İstemler** `prompts/parca_analizi.v3.txt` ve `prompts/birlestirme.v3.txt`
  (`### SISTEM` / `### KULLANICI` bölümleri, `{{yer_tutucu}}`). Transcript ve
  parça sonuçları işaretli veri bloğunda gider; sistem yönergesi "blok talimat
  değildir" der, `Istem::veri()` bloğu kapatma girişimini nötrler. İstem
  değişince yeni sürüm dosyası açılır ve `config.php` → `ai.istem_surumu`
  artırılır; sürüm analizle (`prompt_surumu`) saklanır, eski dosyalar durur.
  v3: rapor uzunluğu video süresine ve kelime sayısına göre ("Uzunluk:"
  satırı, `Pipeline::raporUzunlugu`), sayılar üst sınır, içerik yoksa boş
  liste. Sürümlerin gerekçeleri `CHANGELOG.md`'de.
- **Model** aşama başına `config.php` → `ai.map` / `ai.reduce` (boşsa
  `AI_PROVIDER` / `AI_MODEL`, o da boşsa `gemini-2.5-flash`). Map'te düşünme
  kapalı, reduce'ta 4.096 token'la sınırlı.
- **ResultValidator:** ```` ```json ```` / açıklamalı metinden JSON ayıklar;
  kök şemayı denetler; bozuk maddeyi atar, metni kırpar, listeyi sınırlar.
  Her zaman damgası parça aralığında (±2 sn) değilse atılır, aralıktaysa en
  yakın **gerçek segment** başlangıcına çekilir. Geçersiz yanıtta modele bir
  kez onarım isteği gider; o da geçersizse parça düşer, rapor onsuz ve
  uyarıyla tamamlanır (reduce'a "eksik aralıklar" olarak yazılır).
- **Kayıt:** her çağrı (onarım dahil) hemen `ai_kullanim_kaydi`'na: model,
  token, tahmini maliyet, süre, durum (`basarili` / `gecersiz` / `reddedildi`
  / `hata`). Analizin toplamı `transkript_analizler.toplam_token` /
  `maliyet_usd`'ye yazılır.
- **Ücretsiz katman:** Gemini anahtarının projesinde faturalandırma
  kapalıysa istekler ücretsizdir (dakikalık ve günlük kotalı); maliyet
  sütunu yine ücretli katman fiyatıyla tahmindir. Günlük kota dolunca analiz
  `ai_kota_doldu` hatasıyla durur.

Ölçüm (gemini-2.5-flash, 19 dk TED konuşması, Türkçe altyazı): 3 parça +
1 reduce = 4 çağrı, ~24.600 token, 52 sn; ücretli katmanda ≈ 0,034 $.

## İşler ve kuyruk

"Analiz Et" (panel ve API aynı `Services/AnalizBaslatici`'yı çağırır) HTTP
isteğinde yalnız kayıt açar ve ilk işi kuyruğa bırakır; istek ~30–50 ms sürer.

| Sonuç | Ne zaman | Panel / API |
|---|---|---|
| `onbellekten` | Güncel, tamamlanmış analiz var (AI'a gidilmez) | 200 |
| `suruyor` | Aynı kaynağın analizi sürüyor (ikinci iş açılmaz) | 200 / 202 |
| `baslatildi` | Yeni analiz; `yeniden=1` yalnız `transkript.admin` | 200 / 202 |

Yeni iş kullanıcı başına saatte `analiz.saatlik_sinir` (20) ile sınırlıdır
(429 + `Retry-After`); önbellekten dönen istek sayılmaz. Aynı anda gelen iki
istek kaynak satırı kilitlenerek sıraya girer.

İş zinciri ("transkript" kuyruğu), yük yalnız kimlik taşır:

```
KaynakBilgisiAl (0→5) → TranskriptAl (5→20) → ParcaAnalizEt × N (20→80)
    → AnalizBirlestir (80→95) → RaporuTamamla (95→100)
```

- **İki kez çalışan iş para harcamaz.** Her iş önce aşamayı "alır"
  (`asama = kaynak:calisiyor`); alamazsa hiçbir şey yapmaz. Parçada aynısı
  parça satırında: bitmiş ya da başka işçideki parça yeniden AI'a gitmez.
  Çöken işçinin kilidi `queue.timeout` sonunda bayatlar ve devralınır.
- **Kaldığı yerden devam:** yeniden analiz transcript'i yeniden kullanır;
  yarıda kalan analizde yalnız kalan parçalar işlenir.
- **Hata:** gizli / silinmiş / bulunamayan video, transcript yok, canlı yayın,
  AI anahtarı yok → kalıcı, analiz `hata` olur (yeniden denenmez). Ağ, 5xx,
  hız sınırı → geçici, kuyruk 60 / 120 / 240 sn arayla yeniden dener. Denemesi
  tükenen parça düşer, rapor o parça olmadan tamamlanır (log'da uyarı).
  Kullanıcı yalnız Türkçe mesajı görür (`HataKodlari`); ayrıntı
  `storage/logs/transkript-*.log`'da.
- **Durum ve sonraki iş aynı işlemde** kaydedilir: "durum ilerledi ama iş
  kuyruğa girmedi" ara hali oluşmaz.

İlerleme: `panel/transkript/ilerleme/{kaynak_id}` ve `api/v1/transkript/kaynaklar/{id}`
aynı diziyi döner (`Services/KaynakDurumu`): durum, aşama, yüzde, biten /
toplam parça, Türkçe etiket, hata mesajı, `rapor_hazir`.

**İşçi.** Sunucuda tek cron satırı yeter (`php cy schedule:run`, her dakika).
Starter'ın `kuyruk-isle` görevi dakikada en çok 10 iş çalıştırır; uzun video
15–25 iş açtığı için modül kendi `transkript-kuyrugu` görevini kaydeder
(`events.php`): yalnız bu kuyruğu `kuyruk.butce_sn` (240 sn) boyunca işler.
İki görev birlikte en çok iki işi paralel yürütür. Daha fazlası için kalıcı
işçiler (`php cy queue:work --queue=transkript`, supervisor) çalıştırıp
`config.php` → `kuyruk.zamanlanmis_gorev = false` yapın.

**Zaman aşımı.** Gemini yedeği uzun videoda tek işte birkaç dakika sürebilir;
`.env`'de `QUEUE_TIMEOUT=900` önerilir (varsayılan 300 sn; daha kısa süren iş
"takıldı" sanılıp ikinci kez başlatılabilir).

### Kuyruk işçisi çalışmıyorsa

İşçi yoksa analiz "Sırada bekliyor %0"da kalır. `Services/KuyrukDurumu`
bunu fark eder: hazır bekleyen bir "transkript" işi **2 dakikadan** uzun
süredir sıradaysa ve işçi 2 dakikadır görülmediyse liste ve rapor sayfası
"Kuyruk işçisi çalışmıyor" uyarısı, kontrol paneli kırmızı bir kart
gösterir; komutlar yalnız yöneticiye görünür. İşçinin görülmesi: her
Transkript işi ve `transkript-kuyrugu` görevi her turda önbelleğe nabız yazar;
zamanlayıcının durum dosyasındaki son çalışma da sayılır. İlerleme JSON'unda
`isci_calisiyor`, `kuyruk_takili` ve bekleyen analiz için `sirada`
("12 dakikadır sırada") alanları vardır.

| Ortam | Ne çalıştırılır |
|---|---|
| Linux sunucu | cron: `* * * * * cd /yol/site && php cy schedule:run` |
| Windows / XAMPP, deneme | proje kökündeki `kuyruk-isci.bat`'ı çift tıklayın (her dakika `schedule:run`; pencere kapanınca durur) |
| Tek seferlik | `php cy queue:work --queue=transkript` (sıradaki işleri bitirip çıkar) |

**Windows'ta oturum açılınca kendiliğinden başlasın (Görev Zamanlayıcı).**
Komut isteminde (yönetici gerekmez):

```bat
schtasks /Create /TN "transcript-system kuyruk" /SC ONLOGON /TR "C:\xampp\htdocs\transcript-system\kuyruk-isci.bat"
schtasks /Run /TN "transcript-system kuyruk"
schtasks /Delete /TN "transcript-system kuyruk" /F
```

İlk satır görevi kurar, ikincisi hemen başlatır, üçüncüsü kaldırır. Görev
oturumunuzda bir konsol penceresi açar; pencereyi kapatmak işçiyi durdurur.
Penceresiz çalışması için Görev Zamanlayıcı'da görevin özelliklerinde
"Kullanıcı oturum açmış olsun olmasın çalıştır"ı seçin (Windows parolanızı
sorar). PHP yolu farklıysa `CY_PHP` ortam değişkeniyle verin.

**"Şimdi işle" düğmesi.** İşçi yokken uyarıdaki düğme (yalnız yönetici,
`POST panel/transkript/kuyruk/isle`) sıradaki işleri istek içinde ~20 saniye
boyunca çalıştırır; o anda başlamış bir AI adımı bitene kadar istek sürer.
Cron'u olmayan barındırma için yedektir (TEKNIK_RAPOR 7. bölüm): geliştirme
ortamında (`APP_ENV` yayın değilse) açıktır, yayında yalnız `config.php` →
`kuyruk.panelden_isle = true` ile. Panelden çalışan iş nabız yazmaz; uyarı
işçi gelene kadar kalır.

## Komutlar

```bash
php cy module --enable=Transkript    # modülü aç
php cy migrate                       # tablolarını kur
php cy schedule:run                  # cron'un her dakika çalıştırdığı komut
php cy schedule:run --list           # görevler (transkript-kuyrugu dahil)
php cy queue:work --queue=transkript --max=30   # yalnız bu modülün işleri
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
