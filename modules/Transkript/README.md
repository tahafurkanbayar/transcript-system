# Transkript Modülü

YouTube videolarından (sonra yüklenen ses / video dosyalarından da) zaman
damgalı transcript çıkarır ve AI ile analiz eder: kısa ve detaylı özet, önemli
noktalar, zaman çizelgesi, teknik bilgiler, iddialar, fikirler. Onaylı plan:
[docs/TEKNIK_RAPOR.md](../../docs/TEKNIK_RAPOR.md).

Projeye ait kodun tamamı bu klasördedir; çekirdeğe (`app/`, `config/`,
çekirdek `routes/` ve `views/`) dokunulmaz. İstisnalar, hepsi kullanıcının
isteğiyle:

- Modülün ana sayfayı çizebilmesi için genel kanca: `app/Core/HomePage.php`
  ve `HomeController`'da üç satır ("Ekranlar → Ana sayfa"). Kanca "şu an
  etkin mi" bilgisini de alır; Site Ayarları → Ana Sayfa bunu şeritle söyler.
- **Site Ayarları doğrulamaları** (`SettingsController`, `views/settings/group.php`,
  2026-10-09, cowork 5. tur): karakter sınırları, SMTP zorunlu alanları, aydınlatma
  metni sayfası listeden, sosyal alan adları, robots "Disallow: /" onayı.
- **Editör'ün kapsamı daraltıldı** (`app/Models/Role.php`, 2026-10-09).
  Editör artık sayfalara, iletişim mesajlarına ve e-posta geçmişine
  giremez; bunlar kişisel veri ve KVKK metni taşıdığı için yalnız
  yöneticidedir. Editör analiz başlatır ve API anahtarı alır. Starter'da
  editör sayfa ve mesaj yönetiyordu; geri açmak için `Role::ABILITIES`'e
  `pages.*`, `messages.*` ve `mail.view` eklenir.
- **Kontrol Paneli bölüm kancası** (`app/Core/DashboardPanels.php`, 2026-10-09):
  modüllerin panele liste bölümü eklediği genel kanca; Transkript yöneticiye
  "Son analizler"i bununla gösterir. `DashboardController` ve
  `views/dashboard/index.php`'de birer satır.
- **Editör ekranda "Analist"** (`Role::LABELS`, 2026-10-09): rol artık içerik
  düzenlemiyor, analiz yapıyor. Rol anahtarı `editor` kaldı; veritabanı ve
  `module.json` değişmedi.
- **Kullanıcı olarak oturum açma** (`Auth`, `Middleware`, `Session`, 2026-10-09):
  yönetici Kullanıcılar ekranından parola sormadan bir üyenin ya da analistin
  gözünden gezer, alttaki banttan döner. Ayrıntı: CHANGELOG 0.6.4.
- **Cowork 6. tur** (2026-10-10): `/api/v1` 404/405 API zarfıyla (`ErrorHandler`),
  girişte kademeli gecikme 1-2-4-8 sn (`RateLimiter`, `Auth` mesajı), iletişim
  sayfası özeti tanımlı kanallardan (`ContactController`). Ayrıntı: CHANGELOG 0.7.0.

---

## Dosya yapısı

```
modules/Transkript/
├─ module.json        künye, menü, rollere dağıtılan yetkiler, kurulumda açık
├─ config.php         davranış ayarları (Ayarlar::get); anahtar yok
├─ routes.php         panel/transkript/… ve api/v1/transkript/… rotaları
├─ events.php         "transkript-kuyrugu" zamanlanmış görevi (yalnız CLI)
├─ migrations/        12 tablo (aşağıda)
├─ prompts/           parca_analizi.vN.txt, birlestirme.vN.txt; sürüm analizle kaydedilir
├─ assets/            css/transkript.css, js/transkript.js (Varliklar rotasıyla sunulur)
├─ src/
│  ├─ Controllers/    KaynakController, AyarController, AnasayfaController, SohbetController, Api/TranskriptApiController
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

Rol aynı zamanda **üyelik planıdır** (aşağıda "Üyelik planları"): `uye` = Ücretsiz,
`editor` = Pro (ekranda "Pro Üye"; önceki adı "Analist").

| Yetki | Ne sağlar | Yönetici | Pro (`editor`) | Ücretsiz (`uye`) |
|---|---|:-:|:-:|:-:|
| `transkript.view` | Kütüphanesindeki ve paylaşılan raporları görmek | ✓ | ✓ | ✓ |
| `transkript.analyze` | Analiz başlatmak, dosya yüklemek (maliyet oluşturur) | ✓ | ✓ | plan ayarıyla (varsayılan açık) |
| `transkript.chat` | Kayda soru sormak | ✓ | ✓ | ✓ |
| `transkript.admin` | Herkesin kaydı, paylaşım, tamamen silme, tüm maliyet | ✓ | | |

**Önbellek ortak, görünürlük kişisel** (cowork 5. tur, 2026-10-09). YouTube
kaynağı ve analizi tek kopyadır (`sahip_id = 0`); aynı video ikinci kez
yapay zekâya gitmez. Ama raporu herkes değil, yalnız onu **kütüphanesine**
ekleyen (`transkript_kutuphane`) ve yöneticinin **paylaştığı** raporu
(`paylasildi = 1`) panele giren herkes görür. Daha önce analiz edilmiş video
gönderen kullanıcının kütüphanesine "onbellek" olarak eklenir: yapay zekâ
çağrısı yok, hakkından düşmez. Yüklenen dosya, sohbet ve kişisel özet
yalnızca sahibine ve yöneticiye açıktır.

Kural TEK yerdedir: `TranskriptPolicy::canViewSource` (PHP) ve onun SQL
karşılığı `KaynakRepository::gorunurluk()`. Liste, sayaçlar, Kontrol Paneli
kartı, rapor sayfası, indirme, ilerleme JSON'u ve API ikisinden birine sorar.

**Liste kimin kaydını gösterir** (kullanıcı kararı, 2026-10-09): yönetici
herkesinkini görür; her satırda "Kim" (son analizi başlatan · rolü ve raporu
kütüphanesine ekleyenler) ve kişiye göre süzgeç vardır (API'de
`?kullanici=`, yanıtta `baslatan` ve `kutuphanede`). Üye ve analist
varsayılan olarak yalnız **kendi** kütüphanesini görür
(`KaynakRepository::kendi()`); yöneticinin paylaştıkları ayrı
"Paylaşılanlar" seçeneğindedir (API'de `?kapsam=paylasilan`). Sayaçlar ve
Kontrol Paneli kartı varsayılan kapsamı sayar (`kendiIstatistik`).

**Ücretsiz planın "Analiz başlatabilir"** ayarı (Transkript ayarları →
Üyelik planları, varsayılan açık) rol yetkisi değil ayardır. Bu yüzden analiz
başlatan panel uçları rotada yalnız giriş ister, kararı
`TranskriptPolicy::canAnalyze` verir.

Eylem bazında (HTTP kodları `tests/transkript-yetki.php`'de gerçek rota ve
ara katmanlarla, kararlar `tests/transkript.php`'deki matriste sınanır;
"kendi" = analizi başlatan ya da kütüphanesindeki):

| Eylem | Misafir | Üye | Analist | Yönetici |
|---|:-:|:-:|:-:|:-:|
| Rapor, ilerleme, transcript, indirme: kütüphanesindeki ya da paylaşılan | giriş | ✓ | ✓ | ✓ |
| … başkasının, paylaşılmamış | giriş | 404 | 404 | ✓ |
| Analiz başlat (önbellekteyse AI'a gidilmez, haktan düşmez) | 401 | ✓ Ücretsiz sınırlarıyla (plan ayarı kapalıysa 403) | ✓ Pro sınırlarıyla | ✓ |
| Duran analizi sürdür ("Yeniden dene") | 401 | kendi | kendi; başkasının videosunu gönderirse kendi analizi açılır | ✓ |
| Yeniden analiz (`yeniden=1`, haktan düşer) | 401 | 403 (planda açılırsa kendi raporu) | kendi raporu (başkasınınki 403) | ✓ |
| Altyazı indirme (SRT / VTT; rapor ve TXT herkese) | 401 | 403 (planda açılabilir) | ✓ | ✓ |
| Süren analizi iptal | 401 | kendi | kendi (başkasınınki 403; süren yoksa 409) | ✓ |
| Üyeliğim, "Pro'ya geç" talebi | 401 | ✓ | ✓ (uzatma) | sayfa: plan yok |
| Üyelikler: Pro ver / uzat / kaldır, talebi onayla / reddet | 401 | 403 | 403 | ✓ |
| Kütüphanemden çıkar (ortak kayıt kalır) | 401 | kendi kütüphanesi | kendi kütüphanesi | ✓ |
| Tamamen sil | 401 | kendi yüklediği | kendi yüklediği (YouTube 403) | ✓ (kaç kullanıcıda olduğunu söyler) |
| Üyelerle paylaş / paylaşımı kaldır | 401 | 403 | 403 | ✓ |
| "Şimdi işle" (kuyruk), Transkript ayarları | 401 | 403 | 403 | ✓ |
| Hatanın iç nedeni (kota, servis, ayar) | | genel metin | kendi başlattığı | ✓ |
| "Raporlarınız" / "Bugün eklediğiniz" | | kütüphane + paylaşılan | kütüphane + paylaşılan | herkesin |
| Bugünkü analiz hakkı ("1 / 2 · saatlik 0 / 1") | | ✓ | ✓ | muaf |
| Süren / hatalı sayısı (liste, kontrol paneli) | | | ✓ | ✓ |
| Token / maliyet (liste kartları, rapor kartı, API özeti) | | | | ✓ |
| API anahtarı (çekirdek `profile.api`; Pro düşünce anahtarları silinir) | | | ✓ | ✓ |
| Sayfalar, iletişim mesajları, e-posta geçmişi (çekirdek) | | 403 | 403 | ✓ |

Kontrol sırası her uçta aynıdır: giriş → yetki ve sahiplik (görmüyorsa 404,
görüyor ama yapamıyorsa 403) → en son durum (409 / 422). CSRF anahtarı
olmayan her POST 403'tür.

**Hata metni:** videonun kendisinden gelen neden (altyazı yok, video gizli…)
herkese söylenir. Kota, servis ve ayar hataları iç işletme bilgisidir:
yalnız yönetici ve analizi başlatan adıyla görür, ötekiler "Bu video için
rapor hazırlanamadı." okur; API ve ilerleme JSON'unda kod da
`rapor_hazirlanamadi` olur (`HataKodlari::gorunenMesaj` / `gorunenKod`).

**Ana sayfa vaadi** yetkiye bağlıdır (`AnasayfaController::analizEdebilir`, `::yapabilir`):
"bağlantıyı yapıştırın" yalnız analiz başlatabilene, API kartının anahtar
bağlantısı yalnız `profile.api` yetkisi olana. Misafir için kayıt formunun
açtığı üye hesabının yetkisine bakılır; varsayılan kurulumda misafir ve üye
rapor okumayı anlatan metni ve "API erişimi için bize yazın" bağlantısını görür.

**Kotalar** (yalnız yeni AI işi açan istek sayılır, önbellekten dönen sayılmaz):
kişi başına saatlik ve günlük sınır **planındadır** (aşağıda); hiç yapay zekâ
çağrısı yapılmadan iptal edilen ya da kalıcı hatayla düşen analiz günlük haktan
düşmez (`AnalizBaslatici::BUGUN_SAYILAN`). Bütün kullanıcılar için günlük
`gunluk_token_tavani` (0 = kapalı) ve `gunluk_maliyet_tavani` (5 USD, ücretli
katman fiyatıyla tahmin) `config.php` → `analiz`'tedir. Aşılınca 429 +
`Retry-After` ve Türkçe mesaj (`gunluk_sinir`, `gunluk_butce`, `rate_limit`).
Yönetici plan sınırlarından muaftır (`yonetici_muaf`; kapatılırsa Pro sınırları);
genel bütçe tavanı herkese uygulanır.

## Üyelik planları

Kullanıcı isteği (2026-10-10): "Üye ücretsiz ve kısıtlı işlem yapar, editör
ücretli üyeliktir; limitleri daha yüksektir." Plan rolden gelir, ayrı sütun
yoktur (`Uyelik\Planlar`):

| | Ücretsiz (`uye`) | Pro (`editor`, "Pro Üye") |
|---|:-:|:-:|
| Analiz başlatabilir | ✓ (ayarla kapatılır) | ✓ her zaman |
| Günlük / saatlik yeni analiz | 2 / 1 | 20 / 5 |
| En uzun video | 30 dk | 180 dk |
| Yeniden analiz | | ✓ |
| Altyazı indirme (SRT / VTT) | | ✓ |
| API anahtarı (çekirdek `profile.api`) | | ✓ |
| Fiyat | 0 | panelden (boş: "iletişime geçin") |

Varsayılanlar `config.php` → `uyelik`; hepsi Transkript ayarları → **Üyelik
planları** sekmesinden değişir (iki sütunlu tablo). Planın süre sınırı sistemin
`analiz.en_uzun_dk` tavanını aşamaz. Süre çoğu zaman ancak iş sırasında
öğrenilir: sistem tavanı aşılırsa `video_cok_uzun` (herkese söylenir), yalnız
planınki aşılırsa `plan_video_uzun` (kalıcı hata, yalnız başlatana; AI'a
gidilmediği için haktan düşmez).

**Pro nasıl verilir?** Çevrim içi ödeme yok (ödeme altyapısı kullanıcı kararı
bekliyor). Akış (`Uyelik\UyelikYonetimi`):
1. Üye **Üyeliğim → Pro'ya geç** ile 1 / 3 / 6 / 12 aylık talep gönderir
   (`transkript_uyelik_talepleri`; kişi başına bir bekleyen talep, günde 5 deneme).
   Yöneticiye e-posta gider; üye panelde yazılan **ödeme talimatını** görür.
2. Yönetici **Üyelikler**'de talebi onaylar (süre, alınan tutar, not) ya da
   talepsiz "Pro ver" der. Dönem açılır (`transkript_uyelikler`), rol `editor`
   olur, üyeye e-posta gider. Süren Pro'ya eklenen dönem eskisinin bitişinde başlar.
3. Bitime `uyelik.hatirlatma_gun` (3) kala e-posta; süre dolunca rol `uye`ye
   döner, API anahtarları silinir, e-posta gider. Bu kontrol zamanlanmış görevle
   saatte bir (`transkript-uyelik-suresi`) ve görev zamanlayıcısı yoksa panel
   istekleri arasında en çok 10 dakikada bir çalışır (`UyelikYonetimi::araSira`).

Kullanıcılar ekranından elle "Pro Üye" yapılan hesabın dönemi yoktur:
süresizdir. Yönetici hesabına plan verilmez; rol değişikliği yalnız `uye` ↔
`editor` arasındadır (her UPDATE "AND rol = …" koşullu). E-posta ayarı yoksa
işlem yine yapılır, yönetici "e-posta gönderilemedi" uyarısı görür.

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
| `transkript_kutuphane` | Kişisel kütüphane: kullanıcı × kaynak, `ekleme_turu` = baslatti / onbellek; UNIQUE(`kullanici_id`, `kaynak_id`) |
| `transkript_uyelikler` | Pro dönemleri (ödeme kaydı): başlangıç, bitiş (NULL süresiz), durum aktif / bitti / iptal, tutar |
| `transkript_uyelik_talepleri` | "Pro'ya geç" talepleri: süre, not, durum bekliyor / onaylandi / reddedildi / iptal, yönetici yanıtı |
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
`panel/transkript/{kaynak_id}/indir/{bicim}` (rapor.pdf, rapor.md, transkript.txt / .srt / .vtt),
`panel/transkript/{kaynak_id}/sil`, `…/iptal`, `…/kutuphaneden-cikar` ve
`…/paylas` (POST, kayıt yönetimi; paylaşım yalnız yönetici), `…/kategori`
(POST, video kategorisini düzeltme; yalnız yönetici),
`panel/transkript/kuyruk/isle` (POST, yönetici; "Şimdi işle"),
`panel/transkript/uyelik` (Üyeliğim) + `…/uyelik/talep` ve `…/uyelik/talebi-geri-cek` (POST),
`panel/transkript/uyelikler` (yönetici) + `…/uyelikler/ver`, `…/kaldir`, `…/reddet` (POST),
`panel/transkript/{kaynak_id}/sohbet` (POST, sonraki aşama).

API (`Authorization: Bearer cy_…`): hazır olanlar `analiz`, `kaynaklar`
(sayfalı; `tur`, `durum` = hazir / suruyor / hata, `q`), `kaynaklar/{kaynak_id}`,
`…/ozet` (rapor; maliyet yalnız `transkript.admin`'e), `…/transkript`
(`bas` / `bit` saniye, `q` arama). `dosya`, `…/sohbet`, `istatistik` sonraki
aşamalarda (501). Ayrıntı ve erişim matrisi: `tests/transkript.php`.

## Ekranlar

**Ana sayfa (site kökü, herkese açık):** sitenin vitrini modülün tanıtım sayfasıdır
(`views/anasayfa.php`, `Controllers/AnasayfaController`). Çekirdeğin `""`
rotası modüle bırakılamadığı için (Router: ilk kayıt kazanır) çekirdeğe tek,
genel bir kanca eklendi: `App\Core\HomePage`. Modül `events.php`'de çizici
kaydeder; `HomeController` önce ona sorar, çizici `false` dönerse ya da hata
verirse çekirdeğin Panel → Ayarlar → Ana Sayfa'dan beslenen genel vitrini
çizilir. Seçim panelde: Transkript ayarları → Ana sayfa (config:
`anasayfa.tanitim`). Bölümler: karşılama (kendi kendine oynayan ürün
önizlemesi), sayılar, nasıl çalışır, raporda neler var (ARIA sekmeli vitrin),
neden güvenilir, kimler için, SSS (FAQPage JSON-LD), son çağrı. Yalnız çalışan
özellikleri anlatır; süre sınırı ve günlük kota ayarlardan yazılır. Misafire
"Hesap oluşturun / Giriş yapın", analiz yetkisi olana URL kutusu
(`panel/transkript?url=…` kutuyu doldurur, analizi başlatmaz), diğer
girişlilere "Raporlara git". Hareket `assets/js/anasayfa.js`'te; ekran dışında
ve arka plan sekmesinde durur, "hareketi azalt" tercihinde hiç başlamaz,
JavaScript kapalıyken sayfa durağan ama eksiksizdir. CSS / JS girişsiz
`transkript/varlik/{dosya}` rotasından sunulur (`Varliklar::HERKESE_ACIK`;
panel dosyaları bu rotadan verilmez).

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
| Kütüphanemden çıkar | kütüphanesinde olan | yalnız o kullanıcının `transkript_kutuphane` satırı; ortak kayıt ve rapor kalır |
| Paylaş | yalnız yönetici | `paylasildi` 1 / 0; paylaşılan rapor herkesin listesinde "Paylaşılan" rozetiyle |
| Tamamen sil | ortak YouTube kaydında yalnız yönetici; yüklenen dosyada sahibi | kaynak, analizler, transcript, sohbetler, herkesin kütüphane satırı (CASCADE) ve bekleyen işler tek işlemde; AI kullanım kayıtları kalır |

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

**Video kategorisi** (`Kategori\Kategoriler`, tablo `transkript_kategoriler`):
rapor bitince `Jobs\KategoriBelirle` küçük bir AI çağrısıyla (girdi raporun
özeti; `prompts/kategori.v1.txt`, json_schema enum) içerik türünü (ders
anlatımı, eğitim / nasıl yapılır, konferans, belgesel, haber, röportaj,
vlog, inceleme …), konu alanını (teknoloji, bilim, sağlık, siyaset, spor …),
ikincil alanı, seviyeyi, ana konuyu ve en çok 8 etiketi yazar. Rapor
sayfasında rozet ve etiket olarak görünür (rozete tıklayınca liste o
süzgeçle açılır), listede "İçerik türü" / "Konu alanı" süzgeçleri, API
`kaynaklar/{id}/ozet` → `kategori`, PDF ve Markdown'da. Yönetici "Kategoriyi
düzelt" ile değiştirir (`elle`; yeniden analizde AI üzerine yazmaz). Var olan
raporlar için: `php modules/Transkript/bin/kategori-doldur.php --dry-run`, sonra
`--hemen` (sırayla) ya da seçeneksiz (kuyruğa). Kapatmak: config
`kategori.otomatik = false`. İleride türe göre üretim (ders → soru / test,
teknoloji tanıtımı → makale) bu tablonun üstüne kurulur.

**Dışa aktarma ve kısayollar** (rakip analizinden; `docs/RAKIP_ANALIZI.md`):
rapor PDF (`Pdf\RaporPdf`: A4, kısa özet kutusu, önem rozetleri, zaman
damgası videoyu o saniyede açan bağlantı, görüntüleyicide içindekiler, her
sayfada "Sayfa i / N" ve yapay zekâ uyarısı; bağımlılıksız küçük PDF
yazıcı `Pdf\PdfBelgesi`, yazı tipi gömülmez, Helvetica + CP1254 Türkçe,
desteklenmeyen karakter / emoji atlanır), rapor Markdown (zaman damgaları
`youtu.be?t=` bağlantısı, AI metni kaçışlanır), transcript TXT, altyazı SRT / VTT (`Services/DisaAktarim`);
YouTube açıklamasına yapıştırılacak bölüm listesi (ilk 0:00, en az 3, 10 sn
kuralı); kısa özeti kopyala; "~N dk okuma"; `panel/transkript?url=…` ile dolu
açılış ve masaüstü yer imi. Hepsi kayıtlı rapordan üretilir, AI çağrısı yok.

**Sol menü** (çekirdeğin `SidebarMenu` kancası, `events.php`): "Transkript"
bölümünde Yeni analiz (liste sayfasındaki kutuya odaklanır), Analizler (süren
analiz sayacı), Üyeliğim (plan etiketi) ya da yöneticiye Üyelikler (bekleyen
talep sayacı) ve Transkript ayarları.

**Bölüm menüsü:** Transkript sayfalarının üstünde sekmeler: Analizler · Üyeliğim
(yönetici olmayan; yanında plan çipi "Ücretsiz plan · Pro'ya geç →") ·
Üyelikler (yönetici; bekleyen talep rozeti) · Ayarlar (yönetici). Telefonda yatay kayar.

**Üyeliğim (`panel/transkript/uyelik`):** planınız, bugünkü kullanım ölçerleri
(liste ve Kontrol Paneli ile aynı sayaç), plan karşılaştırma kartları, "Pro'ya
geç" / "Süremi uzat" talebi ve talepten sonra ödeme talimatı, Pro dönemleri.
**Üyelikler (`panel/transkript/uyelikler`, yönetici):** bekleyen talepler
(onayla: süre + tutar + not; reddet: üyeye not), "Pro üyelik ver" (kullanıcı
adı / e-posta), Pro üyeler (bitiş, kalan gün, uzat, kaldır), son 30 dönem ve
girilen ödeme toplamı.

**Ayarlar (`panel/transkript/ayarlar`, yalnız yönetici):** beş sekme; her
sekmenin başında ne işe yaradığı yazar, seçili sekme adreste (`#uyelik`) durur
ve kayıttan sonra korunur. **Üyelik planları** (Ücretsiz | Pro iki sütunlu
tablo, fiyat, para birimi, ödeme talimatı), **Yapay zekâ** (asıl ve yedek
sağlayıcıların modeli (Gemini'de yalnız Flash ailesi: map'te düşünme kapalı,
`thinkingBudget 0` Pro'da geçersiz), tercih edilen altyazı (Türkçe varsa o /
videonun kendi dili), sistemin en uzun videosu), **Bütçe ve maliyet** (günlük
token / maliyet tavanı, 1M token giriş / çıkış fiyatı), **Site ve kuyruk**,
**Durum** (salt okunur). Değerler çekirdeğin `ayarlar`
tablosunda gizli `transkript` grubunda (`transkript_<alan>`, düzenlenemez
işaretli; çekirdeğin Ayarlar ekranı göstermez, ezemez) durur ve
`config.php`'nin üstüne yazılır (`PanelAyarlari`, `Ayarlar::get`). Boş alan
varsayılana döner. API anahtarları yalnız "tanımlı / tanımsız" görünür; değeri
`.env`'den çıkmaz. Yanında sağlayıcı, transcript sırası, istem sürümü ve kuyruk
işçisinin durumu.

**Kontrol Paneli** (çekirdeğin `DashboardCards` kancası): "Transkript raporu"
(raporu hazır video; sürüyor / hata sayısı), yönetici olmayana "Planınız:
Ücretsiz · 1 / 2 analiz" (Pro'da bitiş tarihi), yöneticiye "Pro üye" (onay
bekleyen talep sayısı), "Bugünkü yapay zekâ
kullanımı" (token + tahmini $) ve işçi çalışmıyorsa kırmızı kart. Son analizler
listesi ve panelde hızlı analiz kutusu için çekirdekte bir bileşen kancası yok
(kartlar yalnız sayı gösterir).

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

### Yedek zinciri (`Ai\YedekliSaglayici`)

Ücretsiz katmanların günlük kotası çabuk dolar; uzun bir video 10–20 AI
çağrısı açar. `.env`'de sıra verilince sağlayıcılar sırayla denenir, biri
kota ya da hata verirse sıradakine geçilir:

```
AI_PROVIDERS=evren,gemini,openrouter,mistral,groq
EVREN_API_KEY=…
GEMINI_API_KEY=…
OPENROUTER_API_KEY=…
MISTRAL_API_KEY=…
GROQ_API_KEY=…
# isteğe bağlı: EVREN_MODEL=…, OPENROUTER_MODEL=…, GROQ_MODEL=… (boşsa varsayılan)
```

`AI_PROVIDERS` ve `AI_PROVIDER` ikisi de boşsa sıra `evren,gemini,openrouter,groq`
(`Ai::VARSAYILAN_ZINCIR`).

| Sağlayıcı | Varsayılan model | Anahtar |
|---|---|---|
| `evren` | `qwen3.8-flash-next` (ölçümle; panelde listeden: glm-5.3, deepseek-v4.1-flash, mimo-v2.6-pro, gemma-4-31b, auto) | evren.ssyz.org.tr (`evren_llm_…`) |
| `gemini` | `gemini-2.5-flash` (panel "Google Gemini modeli" / `GEMINI_MODEL`) | aistudio.google.com/apikey |
| `openrouter` | `google/gemma-4-31b-it:free` | openrouter.ai/keys |
| `mistral` | `mistral-small-latest` | console.mistral.ai/api-keys |
| `groq` | `openai/gpt-oss-120b` | console.groq.com/keys |
| `cerebras` | `llama-3.3-70b` (denenmedi) | cloud.cerebras.ai |

Ücretsiz model listeleri sık değişir: 2026-10'da OpenRouter'ın Llama 3.3
`:free`'si ve Groq'un `llama-3.3-70b-versatile`'ı kaldırıldı, zincir "model
yok (404)" verip duruyordu. Geçerli modeller sağlayıcının `GET /models`
ucundan görülür (token harcamaz). Her sağlayıcının modeli **Transkript
ayarlarından** seçilir ("OpenRouter modeli", "Groq modeli"…; ekranda yalnız
zincirdekiler); boşsa `.env`'deki `<AD>_MODEL`, o da boşsa yukarıdaki
varsayılan (`Ai::varsayilanModel`).

- Anahtarı tanımlı olmayan sağlayıcı zincire hiç girmez (ayarlarda "Atlandı
  (anahtar yok)"); tek sağlayıcı kalırsa davranış eskisiyle aynıdır. Ortak
  `AI_API_KEY` yalnız ilk sağlayıcıya gider (bir servisin anahtarı başka
  servise gönderilmez). Anahtarlar veritabanında değil, yalnız `.env`'de.
- Her sağlayıcı kendi modelini kullanır, asıl olan dahil (panel →
  `<AD>_MODEL` → varsayılan). config'teki aşama modeli (`ai.map.model`) yalnız
  `AI_PROVIDER`'a uygulanır; `AI_MODEL` `AI_PROVIDER`'ındır (yazılmamışsa eski
  varsayılan Gemini'nin: EVREN'e Gemini modeli gitmez). Gemini'nin
  `thinkingConfig` eki yalnız Gemini'ye gider.
- **Dinlenme** (kalıcı dosya `storage/files/transkript-dinlenme.json`, `DinlenmeDeposu`;
  bütün işçiler görür, önbellek temizlenince kaybolmaz; dinlenen sağlayıcı denenmez.
  "Dinlenmeleri sıfırla" günlük kotası dolanları kutu işaretlenmedikçe korur):

  | Hata | Süre |
  |---|---|
  | Günlük kota (Gemini `…PerDay`) | bir sonraki sıfırlamaya: Pasifik gece yarısı (`ai.yedek.kota_saat_dilimi`) |
  | Günlük kota (EVREN, `x-evren-daily-remaining-tokens: 0`) | bir sonraki 03:00'e (Europe/Istanbul; `x-evren-daily-reset` okunabilirse o) |
  | 429 hız sınırı | sağlayıcının `Retry-After` / `X-RateLimit-Reset` süresi, yoksa 60 sn |
  | 401/403 anahtar, 404 model | **devre dışı** 6 saat ya da "Dinlenmeleri sıfırla"; model hatası yalnız o modele bağlı |
  | EVREN 403 `terms_not_accepted` | **devre dışı** 1 saat (`sartlar_onaylanmadi`); yönetici Durum → "Şartları oku ve onayla" ile onaylar; kod kendiliğinden onaylamaz |
  | 5xx, ağ, zaman aşımı | 2 dk |
  | 413 (çok büyük), 400, EVREN boş yanıt (`dusunme_butcesi`) | dinletmez, sıradakine geçilir (Groq dakikalık 413: 60 sn) |

- **Giriş sınırı (`Ai\GirisSiniri`):** her sağlayıcının en büyük isteği
  (yaklaşık giriş token'ı, karakter / 3,5) config `ai.giris_siniri`'nde
  (groq 7000, evren 200000, gemini 900000 …); `.env` `<AD>_MAX_INPUT_TOKENS`
  ezer. Sığmayan istek o sağlayıcıya hiç gitmez; sağlayıcı dinlenmez, "Hazır"
  kalır. 413 / bağlam aşımı gelirse isteğin %80'i 7 gün sınır olur
  ("Dinlenmeleri sıfırla" siler): aynı boyut o sağlayıcıya yeniden gitmez,
  birleştirme önce küçük gruplara böler. Groq'un "tokens per minute (TPM)"
  413'ünde sınır 1 saatlik, sağlayıcı 60 sn dinlenir ("Requested", isteğin
  kendisinden ~8.400 fazla; dakika boşken aynı boyut geçebiliyor).
- **Kademeli birleştirme (`Pipeline::birlestirKademeli`, `AnalizBirlestir`):**
  birleştirme girdisi o an açık sağlayıcıların sınırını aşınca ardışık
  bölümler en çok 4'lük gruplarda ara özete çevrilir (`prompts/ara_birlestirme.v2.txt`,
  bölüm analiziyle aynı biçim, her maddede `at` zorunlu), sonra ara özetler birleştirilir; gerekirse
  birkaç seviye. Ara özetler `transkript_analizler.ara_sonuclar`'da; iş
  ertelenip dönerse biten grup AI'a yeniden gitmez. Ekran gerçek sebebi
  söyler ("Rapor girdisi uygun servislerin sınırını aşıyor; bölümler gruplar
  hâlinde birleştiriliyor (2 / 4)" ya da "büyük bağlamlı servis açılınca
  sürecek"); "meşgul" yalnız sağlayıcılar gerçekten dinlenirken. Canlı:
  1:17'lik video (10 bölüm) Groq'la tamamlandı (cowork testi 2026-10-11).
- **Zaman yedeği (`Services\ZamanYedegi`):** ara ve son birleştirmede model
  bir maddenin `at` alanını boş bırakır (ya da aralık dışı yazar) ise madde
  metni girdideki maddelerle eşleştirilir (Türkçe harf katlanmış sözcük
  kökleri). En çok örtüşen kaynak maddenin zamanı, yoksa maddenin geldiği
  bölümün başlangıcı kullanılır; hiçbiri tutmazsa zaman uydurulmaz.
- **Rapordaki modeller (`Services\ModelOzeti`):** her parçanın sağlayıcı ve
  modeli `transkript_parcalar.saglayici` / `.model`'e, raporun aşama başına
  modelleri `transkript_analizler.modeller`'e yazılır. "Bu rapor" kutusu:
  "Bölümler: EVREN · qwen3.8-flash-next — Birleştirme: EVREN · gemma-4-31b"
  (hepsi aynıysa tek satır; eski raporda yalnız `model`).
- **Son hata ayrıntısı:** başarısız çağrıda sağlayıcının mesajı (anahtarlar
  maskeli, en çok 500 karakter) `ai_kullanim_kaydi.hata_ayrinti`'ye yazılır;
  Durum'da "Son hata"nın ve günlükteki sonucun üzerine gelince görünür.
  Günlüğün "Analiz" sütunu analiz sayfasının numarasını (kaynak) gösterir.
- **EVREN teşhisi:** `php modules/Transkript/bin/evren-teshis.php` (anahtarın
  öneki ve uzunluğu, maskeli başlık, `/terms/status`, `/quota`, `/models`,
  küçük bir sohbet isteği). Çekirdeğin `php cy` komut listesi sabit olduğundan
  betik modülde. **Şart onayı:** Durum → EVREN satırı → "Şartları oku ve
  onayla" (metin EVREN'den, `Services\GuvenliMarkdown` ile: ham HTML kapalı,
  bağlantı yalnız http(s) / mailto + `rel="noopener nofollow"`, dış resim
  yüklenmez, yerine alt metni; "Okudum" kutusu + güncel sürüm denetimi;
  CSRF, yalnız yönetici). Kod kendiliğinden onaylamaz.
- **EVREN (`Ai\EvrenProvider`, `OpenAiCompatibleProvider`'dan):** düşünme ve
  yanıt aynı `max_tokens` havuzundan harcanır; bütçe en az 2048 (map 8192,
  reduce 24000), istek süresi en az 180 sn, bağlantı 10 sn. İçerik boş ve
  `finish_reason=length` ise `dusunme_butcesi`; `reasoning` alanları
  kullanılmaz. JSON modu 400 alırsa bir kez parametresiz denenir. Maliyet 0
  (1 Kasım 2026'ya kadar ücretsiz; panelde girilen USD fiyatı EVREN'e
  uygulanmaz, `Ai::sabitFiyat`). Sonrası CR cinsinden, `GET /v1/models`
  `pricing` alanından. Kalan günlük token her yanıtın başlığından okunup Durum
  ekranında gösterilir (panel isteğinde API'ye gidilmez). Anahtar hata
  mesajında geçerse `evren_llm_****` olarak maskelenir.

- **Hepsi düşerse HATA DEĞİL:** geçici olarak dinlenen biri varsa iş en erken
  açılışa ertelenir (`zincir_dinleniyor` → `Queue::later`, deneme hakkı yeni,
  biten parçalar korunur). Ekran "Yapay zekâ servisleri şu an meşgul; analiz
  saat 10:01 civarında kendiliğinden sürecek" der. Tek sağlayıcıda günlük kota
  da böyle ertelenir. En çok `ai.yedek.erteleme_gun` (2) gün; sonra
  `ai_kota_doldu`. Hepsi devre dışıysa (anahtar / model) analiz durur.
- Arkasında yedek olan sağlayıcı 429 / 5xx'te istek içinde beklemez
  (`HttpProvider::yenidenDenemeSiniri(0)`); son sağlayıcı `Retry-After`'a uyar
  (2 dakikadan uzunsa beklemez, iş ertelenir).
- **Yönetici görünürlüğü** (Transkript ayarları): sağlayıcı tablosu (hazır /
  dinleniyor / devre dışı / atlandı, açılış saati ve kalan dk, son başarı, son
  hata kodu, bugünkü çağrı ve token), "Dinlenmeleri sıfırla" ve son 50
  çağrının günlüğü (`ai_kullanim_kaydi.hata_kodu`). Duran analizde yöneticiye
  zincirin özeti (`transkript_analizler.hata_ozeti`): "Google Gemini: günlük
  kota doldu (açılış yarın 10:01) · OpenRouter: model yok (404) (devre dışı,
  açılış 03:05) · Mistral: anahtar yok". Sağlayıcının kendi mesajı yalnız
  log'da.
- **Kaldığı yerden devam:** kota / servis / ayar yüzünden duran son analiz
  "Yeniden dene" ya da yöneticinin "Bekleyenleri yeniden dene" düğmesiyle
  sürer; tamamlanan parçalar AI'a yeniden gitmez, kotaya sayılmaz
  (`AnalizBaslatici::devamEt`).
- Yapılmadı: 413'te parçayı bölmek ve parça boyutunu sağlayıcının token
  sınırına göre ayarlamak; 413'te sıradaki (bağlamı büyük) sağlayıcıya geçilir.

Ücretsiz modellerin bir kısmı (ör. OpenRouter `:free`) gönderilen metni
eğitimde kullanabilir; KVKK metninde yurt dışına aktarılan sağlayıcılar
listelenmeli.

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

Yeni iş kullanıcı başına saatte `analiz.saatlik_sinir` (5) ile sınırlıdır
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

**Windows'ta (XAMPP, Laragon) kendiliğinden başlasın ve ölürse yeniden
başlasın.** Proje kökündeki `kuyruk-isci-kur.bat`'a bir kez çift tıklayın:
işçi Görev Zamanlayıcı'ya "oturum açılınca ve 5 dakikada bir, gizli
pencerede, aynı anda tek örnek" olarak kaydedilir ve hemen başlar (yönetici
izni gerekmez). İşçi çalışırken 5 dakikalık tetik bir şey yapmaz; pencere
kapandıysa ya da PHP çöktüyse en geç 5 dakikada yeniden başlar. PowerShell'den
denetim: `Get-ScheduledTask 'Transkript kuyruk iscisi'`, durdurmak:
`Stop-ScheduledTask 'Transkript kuyruk iscisi'`, kaldırmak:
`kuyruk-isci-kur.bat kaldir`. PHP yolu farklıysa (Laragon:
`C:\laragon\bin\php\php-8.x\php.exe`) `CY_PHP` ortam değişkeniyle verin:
`setx CY_PHP "C:\laragon\bin\php\php-8.3.0-Win32-vs16-x64\php.exe"`.

**Takılan iş kurtarılır (iş nabzı, 0.10.0).** Çalışan iş, uzun yapay zekâ
çağrısı sürerken bile 30 sn'de bir nabız atar (`Jobs\IsNabzi`): kuyruk
satırının `ayrildi_at`'i, analizin aşama kilidi ve parça kilidi tazelenir.
Süreç ölürse (pencere kapandı, PHP çöktü) nabız durur; 5 dakika sonra
(`QUEUE_TIMEOUT`) çekirdeğin `Queue::releaseStuck`'ı işi kuyruğa geri
bırakır ve hem işçi hem tarayıcı onu alabilir. Biten parçalar ve kayıtlı
birleştirme yeniden AI'a gitmez; iş kaldığı yerden sürer. Durum sekmesi
işçinin son görülmesini, bekleyen / çalışan / takılı iş sayısını ve
kopyalanabilir başlatma komutlarını gösterir.

**11.10.2026 olayı (cowork 3. tur):** dört analiz %80–95'te 13 dakika
bekledi. Sebep: Görev Zamanlayıcı'da işçi kayıtlı değildi; tarayıcıdan
işleme ise yalnız açık sayfanın ilerleme yoklamasıyla tetikleniyordu ve
sayfanın kendi analizi bitince / sekme gizlenince duruyordu. İşçi
başlayınca üç analiz de EVREN'e yeniden gitmeden tamamlandı.

**Komut satırı hiç açmadan: tarayıcı üzerinden.** Transkript ayarları →
"Kuyruğu tarayıcı üzerinden işle" açıkken (geliştirme ortamında varsayılan
açık, yayında kapalı; `kuyruk.tarayicidan_isle`) işçi görülmüyor ve iş
bekliyorsa, açık herhangi bir Transkript sayfası (liste, rapor, ayarlar,
üyelik) arka planda `POST panel/transkript/kuyruk/tik` atar. Yanıt
`bekleyen` iş söyledikçe sayfa bir sonrakini hemen atar (TİK DÖNGÜSÜ,
0.10.0): sayfanın kendi analizi bitse ya da sekme arka plana geçse de kuyruk
boşalana kadar sürer (CSRF'li, analiz yetkisi olan). Sunucu
veritabanı kilidiyle (`GET_LOCK`) aynı anda tek işleyici bırakır, kilit
doluysa hemen 202 döner; kilidi alan TEK bir işi (bir adım ya da bir parça)
çalıştırır, sekme kapansa da onu bitirir (`ignore_user_abort`). Oturum kilidi
hemen bırakılır, yoklama beklemez. PHP'den işletim sistemi komutu ya da süreç
başlatılmaz. Uyarı kutusu bu modda "Kuyruk tarayıcı üzerinden işleniyor" der.
Sayfa kapalıyken iş ilerlemez; kalıcı çözüm işçidir.

**"Şimdi işle" düğmesi.** İşçi yokken uyarıdaki düğme (yalnız yönetici,
`POST panel/transkript/kuyruk/isle`) sıradaki işleri istek içinde ~20 saniye
boyunca çalıştırır; o anda başlamış bir AI adımı bitene kadar istek sürer.
Cron'u olmayan barındırma için yedektir (TEKNIK_RAPOR 7. bölüm): geliştirme
ortamında (`APP_ENV` yayın değilse) açıktır, yayında yalnız `config.php` →
`kuyruk.panelden_isle = true` ile. Panelden çalışan iş nabız yazmaz; uyarı
işçi gelene kadar kalır.

### EVREN (`Ai\EvrenProvider`, 0.10.0)

API ayrıntısı: [`docs/EVREN_LLM_API_Rehberi.md`](../../docs/EVREN_LLM_API_Rehberi.md);
ölçüm ve kararlar: [`docs/evren/`](../../docs/evren/).

- **Akış (SSE)**, varsayılan açık (`EVREN_STREAM`). Üç ayrı süre sınırı:
  bağlantı 15 sn, **boşta kalma** 60 sn (`EVREN_BOSTA_SN`; `: keep-alive`
  veri sayılmaz), toplam süre giriş boyutuyla (120 sn + giriş token / 40,
  en çok 900; `EVREN_TOPLAM_SN`). Akış ayrıştırıcı `Ai\SseAkisi`.
- **Düşünme** (`EVREN_THINKING` = off | low | default, varsayılan low):
  `reasoning_effort` destekleyen modellere gider. Düşünme bütçesi yetmezse
  (boş içerik + `length`) aynı modelde bir kez `max_tokens` ×2.
- **JSON:** `json_schema` (strict, `EVREN_JSON_SCHEMA`); model reddederse
  `json_object`, o da olmazsa biçimsiz (öğrenilir).
- **Model seçimi:** Transkript ayarları → Yapay zekâ: EVREN modeli + bölüm
  analizi ve rapor birleştirme için ayrı model (boş: EVREN modeli). Liste
  `GET /v1/models`'ten günde bir (`Ai\EvrenModelleri`, görev
  `transkript-evren-modeller`); görsel / OCR / ses modelleri ve kalkan
  `deepseek-v4-flash` listede yok (kayıtlıysa `deepseek-v4.1-flash`'a taşınır).
- **`auto` sabitlenir:** analizin ilk başarılı yanıtındaki METİN modeli
  `transkript_analizler.evren_model`'e yazılır; bölümler ve birleştirme o
  modelle sürer (görsel modele çözülürse sabitlenmez).
- **EVREN içi yedek:** asıl model zaman aşımı / düşünme bütçesi / 503
  verince başka sağlayıcıdan önce hızlı bir EVREN modeli bir kez denenir
  (`EVREN_YEDEK_MODEL`, varsayılan `gemma-4-31b`; "yok" kapatır). 503'te aynı
  model 2 / 4 / 8 sn arayla 3 kez denenir.
- **Ölçüm:** `php modules/Transkript/bin/evren-olcum.php --suite=… [--dry-run]`
  (üretim istemcisiyle; bütçe ve kota korumalı); canlı deneme
  `EVREN_LIVE_TESTS=1 php tests/evren-canli.php`.

## Komutlar

```bash
php cy module --enable=Transkript    # modülü aç
php cy migrate                       # tablolarını kur
php cy schedule:run                  # cron'un her dakika çalıştırdığı komut
php cy schedule:run --list           # görevler (transkript-kuyrugu dahil)
php cy queue:work --queue=transkript --max=30   # yalnız bu modülün işleri
php tests/transkript.php             # modül birim testleri (veritabanı gerekmez)
php modules/Transkript/bin/evren-teshis.php                  # EVREN: anahtar, şartlar, kota, modeller
php modules/Transkript/bin/evren-olcum.php --suite=smoke     # EVREN ölçümü (önce --dry-run)
EVREN_LIVE_TESTS=1 php tests/evren-canli.php                 # EVREN canlı testi (varsayılan kapalı)
```

## Kaldırmak

```bash
php cy migrate:status                     # modül migration'larının parti numarasına bak
php cy migrate:rollback --step=N --force  # o partiye kadar geri al (her migration'ın down()'u var)
php cy module --disable=Transkript
```

`ai_kullanim_kaydi` yalnızca içinde başka modülün satırı yoksa silinir;
varsa yalnız bu modülün satırları temizlenir.
