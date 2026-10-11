# Transkript modülü: değişiklik günlüğü

Starter'ın sürüm geçmişi kökteki `CHANGELOG.md`'dedir; bu dosya yalnız
`modules/Transkript/` içindir. Biçim [Keep a Changelog](https://keepachangelog.com/tr/1.1.0/).

## 0.10.0 (2026-10-11): EVREN geliştirme + cowork 3. test turu

Kullanıcı isteği (bilgisayar başında değil, "tüm onaylar sende"): cowork 3.
tur (kuyruk işçisi durdu, EVREN "auto" yavaş, log süreleri, zaman damgası,
sessiz gönderim) ve EVREN entegrasyonu ölç–analiz et–geliştir görevi.
Ayrıntı ve rakamlar: `docs/evren/01-kesif.md`, `02-olcum-raporu.md`,
`03-gelistirme-raporu.md`; API: `docs/EVREN_LLM_API_Rehberi.md`.

### EVREN istemcisi
- Akış (SSE, `Ai\SseAkisi`), bağlantı 15 sn / boşta 60 sn / toplam girişle
  ölçekli (120 sn + 40 tok/sn, ≤900); `EVREN_STREAM`, `EVREN_BOSTA_SN`,
  `EVREN_TOPLAM_SN`.
- Düşünme `EVREN_THINKING=off|low|default` (low); 400'de parametresiz bir
  kez. Düşünme bütçesi yetmezse aynı modelde `max_tokens` ×2 bir kez.
- `json_schema` (strict) — `json_object`'te modeller alan adlarını
  değiştiriyordu ("point", "claim") ve `at` koymuyordu; reddeden model
  `json_object`'e düşer (`EVREN_JSON_SCHEMA`).
- 503: aynı modelde 3 kez 2 / 4 / 8 sn. Kullanım (kısmi dahil) ve ölçüm
  (ilk token, keep-alive, bitiş nedeni, düşünme / önbellek token'ı, kalan
  kota) her yanıtta.
- Model bağlamı: `auto` 32K → giriş sınırı 16.384 (büyük birleştirme
  `auto`'ya gitmez, kademeli olur).

### Model seçimi
- Aşama başına EVREN modeli (bölüm / rapor; boş: EVREN modeli).
- `auto` analiz başına sabitlenir (`transkript_analizler.evren_model`;
  görsel / kalkan model sabitlenmez).
- EVREN içi hızlı yedek halka (`EVREN_YEDEK_MODEL`, gemma-4-31b): zaman
  aşımı / düşünme bütçesi / 503'te başka sağlayıcıdan önce.
- Dinamik model listesi (`Ai\EvrenModelleri`, `/v1/models` günde bir;
  görsel / OCR / ses / kalkan model yok); kayıtlı `deepseek-v4-flash` →
  `deepseek-v4.1-flash`. Ücretsiz dönem sonu ve CR fiyatı oradan.

### Zincir ve günlük
- Devre kesici: art arda 429'da 60 sn → 5 → 15 → 60 dk; başarı sıfırlar.
- Zincir süre sınırı 900 sn (aşılırsa iş 1 dk sonra yeniden).
- Çağrı günlüğü: kendi başlangıç saati ve süresi (eskiden zincirin), bitiş,
  aşama, istenen → yanıttaki model, akış, bütçe, bitiş nedeni, düşünme /
  önbellek token'ı, TTFT, HTTP, kalan kota (migration `…140000`).
- Ekranda çalışan model: "… — EVREN · glm-5.3, 2. deneme".
- Şart kapısı: 403'ün istediği sürüm saklanır, Durum "yeni şart sürümü
  onay bekliyor" der (kod onaylamaz).

### Kuyruk (cowork 3. tur 1)
- Neden: Görev Zamanlayıcı'da işçi kayıtlı değildi; tarayıcıdan işleme
  sayfanın analizi bitince / sekme gizlenince duruyordu.
- İş nabzı (`Jobs\IsNabzi`): kuyruk satırı, aşama ve parça kilidi 30 sn'de
  bir; ölen iş 5 dk sonra kuyruğa geri döner, biten parça AI'a gitmez.
- Tik döngüsü bütün Transkript sayfalarında, kuyruk boşalana kadar.
- `kuyruk-isci-kur.ps1`: 5 dk'da bir tetik → ölen işçi yeniden başlar.
- Durum: işçi son görülme, bekleyen / çalışan / takılı, kopyalanabilir
  komutlar; EVREN kota %10 uyarısı, ücretsiz dönem sonu, 7 günlük token,
  EVREN model sağlığı tablosu.
- Rapor sayfası çelişkili cümleyi göstermez; gönderim hatası kutuda kalır
  (419'da bağlantı kutuya yeniden yazılır).

### Zaman damgaları (cowork 3. tur 4)
- Doğrulayıcı alternatif alan adlarını ("point", "claim", "idea", "time")
  ve "[00:01:25]" biçimini okur.
- Bölüm analizinde boş `at`: transcript satırından, yoksa bölüm başından.

### Araçlar ve testler
- `bin/evren-olcum.php` (smoke, models, budget, stream, context,
  consistency, json, fallback; bütçe / kota korumalı, eşzamanlı, kalite
  puanı), `tests/evren-canli.php` (`EVREN_LIVE_TESTS=1`).
- Testler: `tests/transkript.php` 466.

## 0.9.2 (2026-10-11): cowork 2. test turu — zaman damgaları, 413, rapordaki modeller

Kullanıcı isteği: cowork'ün 2. test turunda kalan beş küçük sorunu düzelt.
Biten raporlara dokunulmadı; değişiklikler yeni analizlere uygulanır.

### Kademeli birleştirmede zaman damgaları
- Analiz 3910'da (1:17:08) 11 önemli noktanın 5'i, 25 teknik bilginin 10'u
  zamansızdı. Birleştirme modeli maddeyi derlerken `at` alanını boş bırakıyordu.
- Ara özet istemi `ara_birlestirme.v2`: her maddede `at` zorunlu, kaynak
  maddenin zamanı aynen kopyalanır, yoksa bölümün başlangıcı yazılır.
- `Services\ZamanYedegi`: ara ve son birleştirmede `at` boş, okunamaz ya da
  aralık dışıysa madde metni girdideki maddelerle eşleştirilir (Türkçe
  harf katlanmış sözcük kökleri). Önce en çok örtüşen kaynak maddenin
  zamanı alınır, o da yoksa maddenin geldiği bölümün başlangıcı. Hiçbiri
  tutmazsa zaman uydurulmaz. Önemli noktalar, teknik bilgiler, iddialar,
  fikirler ve zaman çizelgesi için geçerli.

### 413 sonrası aynı istek
- 413'te isteğin %80'i o sağlayıcı + modelin sınırı olur
  (`GirisSiniri::GUVENLIK_PAYI`). Aynı boyut o sağlayıcıya yeniden
  gitmez; birleştirme önce küçük gruplara böler.
- Groq dakikalık (TPM) 413: sınır yine %80 ama 1 saatlik, sağlayıcı da 60 sn
  dinlenir. Eski "dakika dolu → aynı isteği 60 sn sonra yeniden gönder"
  kuralı kaldırıldı; #3910'da üç 413 bu kural yüzünden art arda gitmişti.
- Hata metnindeki Groq kuruluş kimliği (`org_…`) maskelenir.

### Rapordaki modeller
- "Bu rapor" kutusu ve Markdown künyesi bütün modelleri gösterir, örneğin
  "Bölümler: EVREN · qwen3.8-flash-next — Birleştirme: EVREN · gemma-4-31b".
  Ara özetler de varsa ayrı satırda görünür. Hepsi aynı modelse tek satır
  yazılır (`Services\ModelOzeti`).
- Migration `…120000`: `transkript_parcalar.saglayici`, `.model` ve
  `transkript_analizler.modeller` (JSON). Eski raporlarda yalnız `model`
  görünür.
- API rapor yanıtına `analiz.modeller` alanı eklendi; ekrandaki satırlar
  `{etiket, deger}` biçiminde.

### EVREN şart sayfası
- Metin `Services\GuvenliMarkdown` ile biçimlenir. Bağımlılık eklenmedi;
  CommonMark'ın bu metinde geçen alt kümesi destekleniyor: başlık, alıntı,
  liste, kalın, eğik, kod, çizgi, bağlantı. Ham HTML kapalı. Bağlantılar
  yalnız http(s) ve mailto, `rel="noopener nofollow noreferrer"` ile.
  Dış resimler (shields.io rozetleri) yüklenmez; yerlerine alt metinleri
  yazılır.

### Durum tablosu
- Model sütunu tek satır: `auto` seçiliyse son yanıtı veren model
  (`auto → google/gemma-4-31b`).
- Son başarılı çağrıdan eski "Son hata" soluk renkte ve "(çözüldü)" etiketiyle.

- Testler: `tests/transkript.php` 453.

## 0.9.1 (2026-10-11): cowork test bulguları — EVREN şartları, uzun videoda birleştirme

Kullanıcı isteği: cowork'ün test bulgularını düzelt (EVREN 403, 1:17'lik
videonun %80'de takılması, "auto" modeli, arayüz).

### EVREN 403
- Teşhis: `php modules/Transkript/bin/evren-teshis.php`. Sonuç: anahtar doğru
  türde (`evren_llm_`), `/terms/status` → `accepted:false`. Şartlar anahtar
  başına; Test Alanı'ndaki onay bu anahtara gitmemiş.
- Durum → EVREN satırı → **Şartları oku ve onayla**: metin EVREN'den gelir,
  düz metin olarak gösterilir. "Okudum" kutusu ve güncel sürüm denetimiyle
  `POST /terms/accept` gönderilir (CSRF, yalnız yönetici). Onaydan sonra EVREN
  dinlenmesi biter; sürüm ve tarih Durum'da görünür. Kod kendiliğinden
  onaylamaz (`Ai\EvrenHesap`).
- Başarısız çağrının mesajı (anahtarlar maskeli, 500 karakter) kayda girer:
  `ai_kullanim_kaydi.hata_ayrinti`. "Son hata"nın üzerine gelince görünür.

### Uzun videoda birleştirme (413/429 döngüsü)
- Sağlayıcı başına giriş sınırı (`Ai\GirisSiniri`, config
  `ai.giris_siniri`, `.env` `<AD>_MAX_INPUT_TOKENS`). Sığmayan istek o
  sağlayıcıya gitmez ve sağlayıcı dinlenmez; 413 ya da bağlam aşımında sınır
  öğrenilir.
- Groq'un dakika kotası 413'ü (Requested ≫ istek) hız sınırı sayılır:
  60 sn beklenir, sınır öğrenilmez.
- Kademeli birleştirme: girdi açık sağlayıcılara sığmazsa bölümler gruplar
  hâlinde ara özete, sonra son rapora gider. Ara özetler
  `transkript_analizler.ara_sonuclar`'da (migration `…030017`), yeniden
  denemede korunur.
- Ekran gerçek sebebi söyler: kademeli ilerleme ya da "büyük bağlamlı servis
  açılınca sürecek". "Meşgul" yalnız sağlayıcılar gerçekten dinlenirken.
- Canlı: analiz 3910 (1:17:08, 10 bölüm) Groq'la tamamlandı (3 ara özet +
  son rapor).

### Diğer
- EVREN `auto`: kayda, rapora ve sağlayıcı tablosuna gerçek model girer
  (`auto → zai/glm-5.3-fp8`).
- OpenAI uyumlu servislerde 400 + bağlam aşımı `cok_buyuk` sayılır.
- "Dinlenmeleri sıfırla" sonrası sayfa Durum sekmesinde kalır.
- Çağrı günlüğünde "Analiz" sütunu analiz sayfasının numarasını gösterir (iş
  no üzerine gelince).
- Testler: `tests/transkript.php` 444.

## 0.9.0 (2026-10-11): EVREN yapay zekâ sağlayıcısı (varsayılan ilk sırada)

Kullanıcı isteği: "Yerli EVREN modelini yapay zekâ ayarlarına ekle; varsayılan
ilk model bu olsun."

### Eklendi
- `Ai\EvrenProvider` (EVREN LLM API, SSYZ; `OpenAiCompatibleProvider`'dan
  kalıtım). Hata ve kota davranışı:
  - 403 `terms_not_accepted` → `sartlar_onaylanmadi`, 1 saat devre dışı. Kod
    şartları onaylamaz.
  - 401 → `yetkisiz`.
  - 429 günlük kota (`x-evren-daily-remaining-tokens: 0`) → 03:00'e (İstanbul)
    kadar dinlenir; dakikalık sınır → `Retry-After` ya da 60 sn.
  - 503 → 2 dk dinlenir, sıradakine geçilir.
  - İçerik boş ve `length` ise `dusunme_butcesi`.
  - JSON modu 400 alırsa bir kez parametresiz denenir.
  - Bütçe ve süre: en az 2048 token ve 180 sn; bağlantı 10 sn.
  - Maliyet 0 (1 Kasım 2026'ya kadar ücretsiz).
  - Anahtar mesajda geçerse maskelenir.
- Zincir: varsayılan `evren,gemini,openrouter,groq`, `AI_PROVIDER` varsayılanı
  `evren`. `.env`'de `EVREN_API_KEY`, isteğe bağlı `EVREN_BASE_URL` ve
  `EVREN_MODEL`.
- Transkript ayarları → Yapay zekâ:
  - En üstte **EVREN modeli**: liste glm-5.3, deepseek-v4.1-flash,
    mimo-v2.6-pro, qwen3.8-flash-next, gemma-4-31b ve auto; sunucu yalnız
    listedekini kabul eder.
  - Her model alanında zincirdeki yeri ("Asıl sağlayıcı · 1. sırada",
    "Yedek · 2. sırada"). Anahtar yoksa "Anahtar yok · atlanır".
- Durum sekmesi:
  - Zincirdeki her sağlayıcının anahtarı (yalnız adı ve tanımlı mı).
  - EVREN'in kalan günlük kotası, son yanıtın başlığından (panel API'ye gitmez).
  - Şartların onaylanması notu.

### Değişti
- "Asıl sağlayıcının modeli" (`ai_model`) artık **Google Gemini modeli**.
  Kayıtlı değer `ai.modeller.gemini`'ye gider, EVREN'e gitmez. Ayrı
  `model_gemini` alanı kaldırıldı.
- Her sağlayıcı kendi modelini kullanır, asıl olan dahil:
  `Ai::varsayilanModel` → panel, `<AD>_MODEL`, varsayılan. `AI_MODEL`,
  `AI_PROVIDER`'ındır; yazılmamışsa Gemini'nin.
- `HttpProvider`:
  - Bağlantı süresi sınırı (`BAGLANTI_ZAMAN_ASIMI`).
  - Yanıt başlıkları sağlayıcıya iletilir (`basliklariIsle`).
  - Günlük kotada sağlayıcının bildirdiği yenilenme süresi (`hataBeklemesi`).
    Zincir ve iş ertelemesi bunu kullanır.
  - Testler için sahte HTTP (`tasiyici`).
- Testler: `tests/transkript.php` 429 (sahte HTTP ile EVREN hataları, yedeğe
  geçiş, beyaz liste, maliyet, ekran).

## 0.8.1 (2026-10-10): sol menü

Kullanıcı isteği: "Soldaki sidebar alanını düzenleyip geliştir."

### Çekirdek
- Yeni kanca `App\Core\SidebarMenu`: modül kendi menü bölümünü kaydeder
  (birden çok sayfa, sayaç, etiket, etkin sayfa, sayfa içi bölüm, vurgu);
  bölüm "Genel"in altına girer, modül "Modüller" altında tekrar edilmez.
  Hatalı sağlayıcı atlanır (`views/partials/sidebar.php`).
- Menü düzeni: İçerik + İletişim → **Site ve iletişim**; okunmamış mesaj sayacı
  sarı; telefonda çekmecenin sağ üstünde **kapatma** düğmesi; altta kullanıcı
  kartının yanında **Siteyi yeni sekmede aç** ve **Çıkış**; daraltılmış menüde
  sayaç ikonun köşesinde nokta, bölümler arasında ince çizgi
  (`assets/css/admin.css`, `assets/js/app.js`).

### Modül
- Sol menüde **Transkript** bölümü: Yeni analiz (vurgulu; liste sayfasındaki
  bağlantı kutusuna odaklanır), Analizler (süren analiz sayacı: yöneticide
  herkesinki, ötekinde kendi başlattıkları), Üyeliğim (plan etiketi "Ücretsiz" /
  "Pro") ya da yöneticiye Üyelikler (onay bekleyen talep sayacı) ve Transkript
  ayarları. `AnalizRepository::surenSayisi`.
- Testler: `tests/transkript.php` 408 (kanca girdi temizliği, hatalı sağlayıcı,
  şablon), `tests/transkript-yetki.php` 31 (rol başına menü ve sayaçlar).

## 0.8.0 (2026-10-10): üyelik planları (Ücretsiz · Pro)

Kullanıcı isteği: "Üye normal giriş yapan, günlük limitli kullanıcı; editör
ücretli üyelik, daha yüksek limitler." Ayrıca yönetici ayarlarının
düzenlenmesi ve alanların anlatımı.

### Çekirdek
- `editor` rolünün ekrandaki adı **"Pro Üye"** (önceki "Analist"); rol ve üye
  açıklamaları ücretli / ücretsiz plana göre (`Role::LABELS`, `DESCRIPTIONS`).
  Rol anahtarı, veritabanı ve yetkiler değişmedi.

### Modül
- **Planlar** (`Uyelik\Planlar`): plan rolden gelir. Ücretsiz 2/gün · 1/saat ·
  30 dk, Pro 20/gün · 5/saat · 180 dk; yeniden analiz ve SRT / VTT indirme Pro'da
  (ikisi de panelden açılıp kapanır), API anahtarı Pro'da (çekirdek `profile.api`).
  **Ücretsiz plan artık varsayılan olarak analiz başlatır** (önceki "Üyeler analiz
  başlatabilir" ayarı kapalıydı). Eski genel `analiz.gunluk_sinir` /
  `saatlik_sinir` / `uye_baslatabilir` kaldırıldı (panelde değer girilmemişti).
- Planın süre sınırı: istek ve işlerde (`KaynakBilgisiAl`, `TranskriptAl`)
  başlatanın planına göre; yeni kalıcı hata kodu `plan_video_uzun` (yalnız
  başlatana ayrıntılı). Altyazı indirme ucu 403 ("Pro planında"); rapor sayfasında
  kilitli düğme Üyeliğim'e gider.
- **Pro üyelik yönetimi** (`Uyelik\UyelikYonetimi`, migration `2026_10_10_230016`:
  `transkript_uyelikler`, `transkript_uyelik_talepleri`): üye "Pro'ya geç" talebi
  gönderir ve ödeme talimatını görür; yönetici onaylar / reddeder ya da talepsiz
  verir, uzatır, kaldırır. Süre dolunca rol Ücretsiz'e döner, API anahtarları
  silinir; bitime 3 gün kala hatırlatma. Saatte bir zamanlanmış görev + görev
  zamanlayıcısı yoksa panel isteklerinde en çok 10 dakikada bir (`araSira`).
  E-postalar (`Uyelik\Bildirim`): talep yöneticiye; açıldı / uzatıldı / bitiyor /
  bitti / reddedildi üyeye. E-posta ayarı yoksa yönetici bunu mesajda görür.
- Ekranlar: **Üyeliğim** (plan, kullanım ölçerleri, plan kartları, talep, dönemler),
  **Üyelikler** (yönetici), Transkript sayfalarında **bölüm menüsü** ve plan çipi,
  Kontrol Paneli'nde "Planınız" ve "Pro üye" kartları, ana sayfada **Planlar**
  bölümü; SSS'deki sınırlar Ücretsiz planınkini söyler.
- **Ayarlar sekmelere ayrıldı**: Üyelik planları (Ücretsiz | Pro tablosu, fiyat,
  para birimi, ödeme talimatı) · Yapay zekâ · Bütçe ve maliyet · Site ve kuyruk ·
  Durum. Her sekmede açıklama; seçili sekme adreste ve kayıttan sonra korunur;
  hatalı alanın sekmesi önce açılır; yapışkan "Kaydet"; birimli sayı kutuları.
  Yeni alan türleri `acik_kapali` ve `metin`. Uyarılar plan başına (saatlik ≥
  günlük; Ücretsiz'in hakkı Pro'dan yüksekse).
- Silinen hesapların talepleri gece temizliğine eklendi (dönemler ödeme kaydı
  olarak kalır).
- Testler: `tests/transkript.php` 404 (planlar, tarih hesabı, tutar okuma, görünümler,
  çok tablolu migration denetimi), `tests/transkript-yetki.php` 29 (üyelik uçları);
  canlı deneme 35 adım (geçici hesaplarla talep → onay → uzatma → süre dolumu →
  ret → geri çekme → kaldırma → ayar kaydı). K2 testindeki geçmişte kalan sabit
  tarih ileri alındı.

## 0.7.0 (2026-10-10): cowork 6. tur (K1–K10)

### Çekirdek
- **K1** `/api/v1` altında bilinmeyen adres (404) ve yanlış yöntem (405) de API
  zarfıyla döner: `{"success":false,"error":{"code":"bulunamadi"|"gecersiz_yontem","message"}}`
  (`ErrorHandler::respondApi`; `Allow` başlığı korunur). `tests/smoke.php` +4.
- **K5** Girişte kademeli gecikme: sınırdan önceki her hatadan sonra 1, 2, 4, 8 sn
  (`RateLimiter::gradualDelay`), sonra 15 dk kilit. İkisi de (kullanıcı adı + IP)
  çiftine bağlı; beklerken gelen deneme sayılmaz. Kısa bekleme kendi mesajıyla.
- **K8** İletişim sayfasının özeti, kurulumun varsayılan cümlesiyse tanımlı
  kanallardan üretilir ("Bize ulaşmanın yolları: e-posta ve iletişim formu.");
  yöneticinin kendi özeti kalır (`ContactController::summary`). `test_sayisi` 361.

### Modül
- **K2** Sağlayıcı dinlenmeleri önbellekten kalıcı dosyaya taşındı
  (`DinlenmeDeposu`: `storage/files/transkript-dinlenme.json`). Kök neden: test
  sırasında "Dinlenmeleri sıfırla" Gemini'nin günlük kota dinlenmesini de siliyordu.
  Sıfırlama artık günlük kotası dolanları korur; "Günlük kotası dolanları da
  sıfırla" işaretlenirse siler.
- **K3** Saatlik sınır varsayılanı 5 (günlük 10); saatlik ≥ günlük kaydedilince uyarı.
- **K4** Hiç yapay zekâ çağrısı yapılmadan iptal edilen ya da kalıcı hatayla düşen
  analiz günlük haktan düşmez (`AnalizBaslatici::BUGUN_SAYILAN`).
- **K6** "Son analizler": "X sürdü" işlem süresi (ertelenen ve durup sürdürülene
  kadar geçen süre hariç), "Y sırada bekledi" ayrı. Yeni sütun
  `transkript_analizler.bekleme_sn` (migration `2026_10_09_230015`); eski kayıtlar 0.
- **K7** Analiz hakkı olmayan üyede "Bugün eklediğiniz" yerine "Sizinle paylaşılan N"
  kartı (Paylaşılanlar süzgecine gider).
- **K9** Silinen hesapların kütüphane, sohbet, ilgi alanı ve kişisel özet kayıtları
  her gece 03:40'ta silinir (`HesapTemizligi`). Aydınlatma metni (veritabanındaki
  "Gizlilik ve KVKK" sayfası) güncellendi: yapay zekâ sağlayıcılarına ve YouTube'a
  aktarım, saklama süreleri; adres alanı "[adres]" olarak bekliyor.
- **K10** Yönetici koruma testleri: kendini silemez / pasife alamaz / rolünü
  düşüremez (vardı) ve son aktif yönetici silinemez / rolü düşürülemez (yeni;
  ayrı bağlantıda geçici tabloyla, gerçek hesaplara dokunmadan).
- Testler: `tests/transkript.php` 367, `tests/transkript-yetki.php` 29,
  `tests/unit.php` 342 (CI 361), `tests/smoke.php` 31.

## 0.6.6 (2026-10-09): her sağlayıcının modeli ayarlarda

Kullanıcının isteği: "Ayarlarda yalnız Gemini görünüyor, ama 3 API de burada."

- Transkript ayarları → "Yapay zekâ ve transcript": asıl sağlayıcının modeli
  (eskisi gibi) ve zincirdeki her yedek için "OpenRouter modeli", "Groq modeli"
  alanı. Yalnız `.env` `AI_PROVIDERS`'taki sağlayıcılar çizilir; ekranda
  olmayan alanın kayıtlı değeri korunur.
- Serbest model kimliği + öneriler (varsayılan, `.env`'deki); kimlik
  doğrulanır (boşluk, Türkçe harf, tırnak olmaz; ≤ 100).
- Öncelik: panel → `<AD>_MODEL` → varsayılan (`Ai::varsayilanModel`,
  `Ai::ortamModeli`). Sağlayıcı tablosu ve "model yok (404)" dinlenmesi
  seçilen modeli izler.
- Testler: `tests/transkript.php` 364 (+2).

## 0.6.5 (2026-10-09): liste: yöneticide "Kim", üye ve analistte yalnız kendi kayıtları

Kullanıcının isteği: "Yönetici kimin hangi işlemi yaptığını görsün; üye ve
analist yalnız kendi transkript işlemlerini görsün."

- Yönetici listesinde yeni "Kim" sütunu: son analizi başlatan (ad · rol;
  kendisiyse "Siz") ve raporu kütüphanesine ekleyenler ("Kütüphanede: A, B +2").
  Telefonda kartın içinde satır olarak.
- Yöneticiye kişi süzgeci ("Bütün kişiler" / "Ad (Rol)"): o kişinin yüklediği,
  kütüphanesindeki ya da analizini başlattığı kayıtlar. Kapsam seçeneğinin
  adı "Herkesin kayıtları".
- Üye ve analist varsayılan olarak yalnız kendi kütüphanesini görür
  (`KaynakRepository::kendi()`); yöneticinin paylaştıkları ayrı
  "Paylaşılanlar (N)" seçeneğinde. Kendi listesi boşsa "Yöneticinin
  paylaştığı N raporu göster" bağlantısı çıkar. Rapor sayfasının
  görünürlüğü değişmedi (paylaşılan rapor açılır).
- Sayaçlar, Kontrol Paneli kartı ve API aynı kapsamı sayar:
  `gorunenIstatistik` → `kendiIstatistik` ("kütüphanenizdeki").
- API `GET api/v1/transkript/kaynaklar`: `kapsam` (kutuphanem · paylasilan),
  yöneticide `kullanici` süzgeci ve satırda `baslatan` + `kutuphanede`.
- Testler: `tests/transkript.php` 362 (+2), `tests/transkript-yetki.php` 24 (+2;
  liste = sayaç = kart = API her rolde).

## 0.6.4 (2026-10-09): kullanıcı olarak oturum açma (çekirdek)

Modül kodu değişmedi; kullanıcının isteğiyle çekirdeğe eklenen özellik burada kayıtlı.

- Kullanıcılar ekranında satırda ve ayrıntı penceresinde "Bu kullanıcı olarak
  gir" (`POST api/kullanicilar/oturum-ac`). Parola sorulmaz. Yalnız yönetici,
  yalnız aktif ve yönetici olmayan hesaba (`Auth::impersonationBlock`:
  yönetici olmayan 403, yönetici hesabı 403, kendisi ya da pasif / askıda /
  onay bekleyen hesap 409). Demo modunda kilitli.
- Gezinirken her sayfanın altında bant: "X (Üye) olarak geziyorsunuz ·
  Yönetici hesabına dön" (`POST oturum/yoneticiye-don`; bakım modunda da açık).
  Panel, site ve hata sayfalarında görünür.
- Oturum değişirken kimlik ve CSRF anahtarı yenilenir, kişisel oturum verisi
  silinir (`Session::keepOnly`). Yönetici her istekte yeniden doğrulanır:
  pasife alınırsa, parolası değişirse ya da gezilen hesap yönetici yapılırsa
  oturum kapanır.
- Üyenin son giriş bilgisi, "beni hatırla" jetonu ve cihaz çerezi değişmez.
  Gezinirken çıkış yapılırsa yöneticinin jetonu iptal edilir.
- Hesabım'daki değişiklikler (bilgiler, parola, fotoğraf, API anahtarı,
  oturumlar, bildirimler, hesabı silme) gezinirken kapalı: yeni
  `kendi-oturumu` ara katmanı.
- Güvenlik günlüğüne giriş ve dönüş yazılır (yönetici, gezilen hesap, IP).
- Birim testleri +8; `config/app.php` → `test_sayisi` 360. Canlı HTTP
  denemesi geçici hesaplarla 35 kontrol.

## 0.6.3 (2026-10-09): ayar doğrulamaları (cowork 5. tur, 2. prompt)

### Transkript ayarları
- "Dinlenmeleri sıfırla" onay ister; günlük kotası dolan sağlayıcı varsa
  açılış saatiyle uyarır (sıfırlamak yeni bir hata getirir).
- Açıklamadaki `AI_PROVIDERS` örneği gerçek sıradan üretilir (sabit
  "gemini,openrouter,mistral,groq" metni kalktı); kullanılabilecek adlar listelenir.
- Model alanının etiketi asıl sağlayıcının adıyla ("Google Gemini modeli");
  yedeklerin modeli sağlayıcı tablosunda.
- Ana sayfa kancasına "şu an etkin mi" ve ayar yolu verilir (aşağıda).

### Çekirdek (`SettingsController`, `views/settings/group.php`, `HomePage`)
- Genel: site adı zorunlu, 2–60; marka ≤ 60, slogan ≤ 120, açıklama ≤ 300
  (160'ı aşınca "arama sonuçlarında kesilir" bilgisi). Düz metin
  alanlarından HTML etiketi temizlenir.
- İletişim: telefon WhatsApp kuralıyla; adres ≤ 500, çalışma saatleri ≤ 120;
  iletişim e-postası boşsa E-posta ayarlarında gönderen adresi şart
  (yönetici bildirimleri ikisinden birine gider).
- SEO: başlık şablonu ≤ 120 ve `%sayfa%` içerir; robots.txt'ye yeni
  "Disallow: /" onay kutusu işaretlenmeden kaydedilmez.
- Sistem: aydınlatma metni sayfası serbest metin değil, yayındaki
  sayfalardan seçilir; var olmayan adres kaydedilemez; onay açıkken sayfa şart.
- E-posta: SMTP seçiliyken sunucu, port ve gönderen zorunlu; kayıttan sonra
  sınama düğmesine yönlendirir.
- PWA: kısa ad ≤ 12, ad ≤ 45. Sosyal: her alan kendi alan adını kabul eder.
- Ana Sayfa ayarları: bir modül ana sayfayı üstlendiyse üstte şerit
  ("Şu an ana sayfa Transkript tanıtım sayfası…" + ayar bağlantısı).
  `HomePage::set()` isteğe bağlı `$aktif` ve `$ayarYolu` alır,
  `HomePage::activeOwner()` söyler.
- Hata olursa hiçbir alan kaydedilmez (mevcut davranış).
- `config/app.php` → `test_sayisi` 352.

## 0.6.2 (2026-10-09): güvenlik (çekirdek; cowork 5. tur, 3. prompt)

Modül kodu değişmedi; çekirdek düzeltmeleri ve testleri burada kayıtlı.

### Düzeltilenler (çekirdek)
- Güvenlik başlıkları iki kez gidiyordu (`X-Frame-Options: DENY, DENY`):
  `.htaccess` artık yalnız yanıtta o başlık yoksa ekler
  (`expr=-z resp(...)`); PHP sayfası, statik dosya, 403 ve yönlendirmede
  birer kopya (curl ile denendi). HSTS zaten yalnız HTTPS'te.
- `api/kullanicilar/save`: `action` yalnız `add` / `edit`; başka değer
  yetkiden önce 422 (eskiden "add" sayılıyordu).

### Doğrulananlar (yeni testler)
- Yönetici kendi hesabını silemez (403), pasife alamaz (403), rolünü
  düşüremez (422); geçici bir yönetici satırında canlı
  (`tests/transkript-yetki.php`). Son aktif yönetici koruması kodda
  (`changeAdminSafely`, 409); canlı denenmedi (gerçek yöneticiyi riske atar).
- Analist başkasının API anahtarını `anahtar_id` ile iptal edemez (canlı).
- E-posta konusu ve gönderen adı CRLF ile başlık (Bcc / Cc) ekleyemez
  (`tests/unit.php`).
- G1 yanlış alarm: giriş kilidi kullanıcı adı + IP çiftine bağlı
  (`RateLimiter::lockedFor`), ayrıca IP başına 30 deneme sınırı ve
  güvenilir cihaz muafiyeti var; başka yerden yönetici kilitlenemez.

## 0.6.1 (2026-10-09): yönetici Kontrol Paneli

### Eklenenler
- "Yapay zekâ sağlayıcıları" kartı: kaç sağlayıcı hazır, ilk açılacak
  olanın saati, bugünkü çağrı sayısı; Transkript ayarlarındaki tabloya gider.
- "Son analizler" bölümü: son 5 analiz, durum, başlatan, süre.
- Kuyruk kartı: işçi yokken tarayıcıdan işleme açıksa "Kuyruk tarayıcı
  üzerinden işleniyor", kapalıysa kırmızı "Kuyruk işçisi çalışmıyor"
  (eskiden yalnız iş 2 dakikadır bekliyorsa görünüyordu).

### Düzeltilenler
- Birim testleri liste / rapor görünümünü çizerken kütüphane sorusunu
  veritabanına soruyordu (yerelde fark edilmedi, CI'da çökerdi); örnek
  satırlar `kutuphanede` taşıyor.

### Çekirdek
- `app/Core/DashboardPanels.php`: modüllerin Kontrol Paneli'ne liste bölümü
  eklediği genel kanca (`DashboardCards`'ın eşi; modüle özel kod yok);
  `DashboardController` ve `views/dashboard/index.php`'de birer satır.
- `config/app.php` → `test_sayisi` 341 (CI'daki starter test sayısı; önceki
  turda eklenen editör testinden beri bir eksikti).

## 0.6.0 (2026-10-09): kişisel kütüphane, paylaşım, rol modeli

Cowork 5. tur: kayıt açıkken kayıt olan herkes bütün raporları görüyordu.
Kullanıcı kararı: önbellek ortak, görünürlük kişisel.

### Değişenler
- Rapor artık yalnız onu kütüphanesine ekleyene (analiz eden ya da
  önbellekten ekleyen) ve yöneticinin paylaştığına görünür. Yeni tablo
  `transkript_kutuphane`, yeni sütun `transkript_kaynaklar.paylasildi`
  (migration `2026_10_09_230014`; var olan analizler başlatanın
  kütüphanesine geri dolduruldu, hiçbiri paylaşılmadı).
- Görünürlük kuralı tek yerde: `TranskriptPolicy::canViewSource` ve SQL
  karşılığı `KaynakRepository::gorunurluk()`; liste, sayaçlar, Kontrol
  Paneli, rapor, indirme, ilerleme ve API ikisinden birine sorar.
- Analist (editör) kendi raporunu yeniden analiz eder (haktan düşer),
  başkasının duran analizini sürdüremez (aynı videoyu gönderirse kendi
  analizi açılır), kendi kütüphanesinden çıkarır. "Tamamen sil" yalnız
  yönetici; video kaç kullanıcının kütüphanesindeyse uyarır.
- Kontrol sırası: yetki → sahiplik (404 / 403) → durum (409 / 422). İptal
  ucu başlatan olmayana 409 yerine 403; üyenin bozuk bağlantısı 422 yerine 403.
- Sayaçlar: "Raporlarınız" (kütüphane + paylaşılan) ve "Bugün eklediğiniz".
- Kontrol Paneli açıklamasındaki "ses / video dosyası yükleme sonraki
  aşamada" sözü kaldırıldı; ana sayfa API kartı "yönetici onayıyla" demiyor.

### Eklenenler
- "Üyelerle paylaş" / "Paylaşımı kaldır" (`POST panel/transkript/{id}/paylas`,
  yönetici) ve listede "Paylaşılan" rozeti.
- "Kütüphanemden çıkar" (`POST panel/transkript/{id}/kutuphaneden-cikar`).
- Liste kapsam süzgeci: Kütüphanem · Paylaşılanlar.
- "Bugünkü hakkınız: 3 / 10 · saatlik 1 / 20" (liste ve Kontrol Paneli);
  hak dolunca yenilenme saati. Form açık kalır: önbellekteki video hakkı
  tüketmez.
- Transkript ayarları → "Üyeler analiz başlatabilir" (varsayılan kapalı).
  Analiz başlatan panel uçları (analiz, kuyruk/tik, iptal) rotada
  `transkript.view` ister; kararı `TranskriptPolicy::canAnalyze` verir.
- `tests/transkript-yetki.php`: veritabanıyla, gerçek rota ve ara
  katmanlarla rol × eylem × sahiplik → HTTP kodu; Kontrol Paneli = liste =
  API sayısı; önbellekten ekleme haktan düşmez. `tests/transkript.php`'de
  19 eylem × 5 rollük karar matrisi.

### Çekirdek
- `app/Models/Role.php`: `editor` rolünün ekrandaki adı "Analist",
  açıklaması "analiz yapabilen üye" (kullanıcı kararı).

## 0.5.0 (2026-10-09): zincir onarımı, erteleme, komut satırısız kuyruk

### Düzeltilenler
- Yedek zinciri "model yok (404)" ile duruyordu: OpenRouter ve Groq'un
  varsayılan modelleri kaldırılmıştı. Yeni: `google/gemma-4-31b-it:free`,
  `openai/gpt-oss-120b`.
- Günlük kota 1 saat değil, bir sonraki sıfırlamaya (Pasifik gece yarısı)
  kadar dinlendirir; 429 `Retry-After` / `X-RateLimit-Reset` kadar; anahtar
  ve model hatası devre dışı (model hatası yalnız o modele); dinlenen
  sağlayıcıya boşuna gidilmez.
- Hatayla duran analizde adım listesi "tamamlandı" görünmüyor: durulan aşama
  kırmızı, "1 / 1 · 1 hatalı".
- İptal edilen analiz "Hata" süzgecinde ve hata sayaçlarında yok; ayrı
  "İptal edildi" süzgeci.

### Eklenenler
- Bütün sağlayıcılar dinlenirken iş hata vermez, en erken açılışa ertelenir
  (biten parçalar korunur; en çok 2 gün).
- Kaldığı yerden devam: "Yeniden dene" ve yöneticinin "Bekleyenleri yeniden
  dene" düğmesi (`POST panel/transkript/yeniden-dene`).
- Transkript ayarları: sağlayıcı tablosu, "Dinlenmeleri sıfırla"
  (`POST panel/transkript/ayarlar/dinlenme-sifirla`), son 50 çağrı günlüğü.
- Duran analizde yöneticiye zincir özeti; yöneticiye "yöneticinize bildirin"
  denmez.
- Komut satırısız kuyruk: "Kuyruğu tarayıcı üzerinden işle" ayarı ve
  `POST panel/transkript/kuyruk/tik` (veritabanı kilidi, tek iş, 202).
- Proje kökünde `kuyruk-isci-kur.bat` / `.ps1`: işçiyi Görev Zamanlayıcı'ya
  kaydeder (oturum açılınca, gizli, tek örnek).

### Veritabanı
- `transkript_analizler.hata_ozeti`, `ai_kullanim_kaydi.hata_kodu`
  (`2026_10_09_230013_transkript_hata_ayrintisi`, geri alınabilir).

## 0.4.2 (2026-10-09): editör kapsamı, token yalnız yöneticide

### Değişenler
- Token sayısı yalnız yöneticiye: rapor kartındaki "Token" satırı, API
  özetindeki `toplam_token` ve listedeki token / maliyet kartları üye ve
  editörden kaldırıldı (0.4.1'deki "Token kullanımınız" kartı da).

### Çekirdek
- `app/Models/Role.php`: editörden `pages.*`, `messages.*` ve `mail.view`
  kaldırıldı. Sayfalar, iletişim mesajları ve e-posta geçmişi yalnız
  yöneticide. `tests/rol-matrisi.php`'de 17 satır `EY` → `Y` oldu.

## 0.4.1 (2026-10-09): rol testi düzeltmeleri

### Düzeltilenler
- Editör sayaçları 0 gösteriyordu (yalnız kendi başlattığı analizleri sayıyordu):
  yönetici dışında her rol görebildiği raporları sayar; rol adına değil yetkiye bağlı.
- "Bugün" kartı yönetici dışında farklı video sayar (yeniden analiz sayılmaz);
  yönetici "Bugün tamamlanan analiz" görür.
- Token / maliyet kartları editörde "Token kullanımınız", "Tahmini maliyetiniz".
- Kota, servis ve ayar hatası yalnız yöneticiye ve analizi başlatana adıyla;
  ötekiler "Bu video için rapor hazırlanamadı." (liste, rapor, ilerleme, API).
- Analiz başlatamayanın listesi varsayılan olarak yalnız hazır raporlar;
  "Tüm durumlar" seçilince hepsi. Kontrol paneli kartında hata sayısı yok.
- Ana sayfa yapılamayacak şeyi vaat etmiyor: "bağlantıyı yapıştırın" yalnız
  analiz başlatabilene, API anahtarı bağlantısı yalnız `profile.api` olana
  (misafirde kayıt olunca açılacak üye hesabına bakılır).

### Testler
- İki editör arasında iptal (403), hata metni maskesi, sayaç sorguları, liste
  süzgeci ve kartlar, ana sayfa vaadi (17 yeni test).

## 0.4.0 (2026-10-09): yapay zekâ yedek zinciri, üye sayaçları

### Eklenenler
- **Yedek zinciri** (`Ai\YedekliSaglayici`): `.env` `AI_PROVIDERS=gemini,openrouter,…`
  ile sağlayıcılar sırayla denenir; kota ya da hata veren sıradakine bırakır,
  bir süre dinlenir (Cache, bütün işçiler). Yeni sağlayıcılar: OpenRouter,
  Mistral, Cerebras (OpenAI uyumlu). Sağlayıcı başına `<AD>_MODEL`.
- Ayarlar → Durum: yedek sırası, tanımlı / tanımsız, dinlenme süresi.

### Değişenler
- Zincirde arkasında yedek olan sağlayıcı 429'da beklemeden geçer.
- Kullanım kaydına yanıtı veren sağlayıcının adı yazılır; düşen denemeler
  `hata` satırı.
- Analiz başlatamayan üye, sayaçlarda ve kontrol paneli kartında görebildiği
  raporları görür; token / maliyet kartı üyeye çizilmez.

## 0.3.0 (2026-10-09): tanıtım ana sayfası

### Eklenenler
- **Ana sayfa:** sitenin vitrini Transkript'in tanıtım sayfası
  (`views/anasayfa.php`): kendi kendine oynayan ürün önizlemesi (bağlantı
  yazılır → adımlar ilerler → rapor belirir), sayılar, dört adım, sekmeli
  rapor vitrini, güven kartları, kimler için, SSS (FAQPage), son çağrı.
  Açık / koyu tema, telefon, "hareketi azalt" ve JavaScript kapalı hâli denendi.
- Transkript ayarları → **Ana sayfa:** tanıtım sayfası ya da çekirdeğin genel
  vitrini (`anasayfa.tanitim`).
- Girişsiz varlık rotası `transkript/varlik/{dosya}` (yalnız ana sayfanın CSS / JS'i).

### Çekirdek
- `App\Core\HomePage` (yeni, genel kanca) ve `Site\HomeController::index`'te
  çiziciye soran üç satır. Modüle özel kod yok; çizici yoksa, `false` dönerse
  ya da hata verirse davranış eskisiyle aynı.

## 0.2.0 (2026-10-09): test raporu düzeltmeleri

Uçtan uca test raporundaki (`transkript-test-raporu.md`) bulgular; her biri
ayrı dalda (`duzeltme-1` … `duzeltme-7`).

### Eklenenler
- **Kuyruk işçisi uyarısı:** işçi 2 dakikadır görülmediyse ve iş bekliyorsa
  liste, rapor ve kontrol paneli uyarır; ilerleme JSON'unda `isci_calisiyor`,
  `kuyruk_takili`, `sirada`. Proje kökünde `kuyruk-isci.bat` (Windows), README'de
  Görev Zamanlayıcı. Geliştirme ortamında yöneticiye "Şimdi işle".
- **Video ön kontrolü:** kayıttan önce oEmbed; olmayan / gizli video 422,
  kayıt açılmaz. Oynatma listesi ve kanal bağlantısına ayrı mesaj. En uzun
  video sınırı (180 dk).
- **Kayıt yönetimi:** sil, iptal, yeniden dene; panelin onay penceresi.
- **Kotalar:** kullanıcı başına günlük analiz, genel günlük token / maliyet
  tavanı (429).
- **Ayarlar ekranı** ve kontrol paneli kartları.
- Markdown künyesi (tarih, model, istem, transcript dili); eski istem notu.

### Değişenler
- Sayaçlar raporu sayar: "raporu hazır video", "bugün tamamlanan"; altında
  "2 sürüyor · 1 hata".
- Liste: Tür sütunu kalktı, durum tek sütun, işlemler sütunu; video kimliğinin
  parçasıyla arama.
- Konu aralıkları kesintisiz (ilk konu 00:00, çakışma yok).
- İstem v3 (aşağıda). "Teknik bilgiler" → "Kavramlar ve teknik bilgiler".

### Düzeltilenler
- Listede Türkçe karakterli arama 500 veriyordu ("Illegal mix of collations").
- Filtre açıkken önem rozeti değişmiyordu; tek harf aramada "0 sonuç";
  transcript yüklenemezse yeniden denenmiyordu; bekleyen raporda zaman damgası
  ipucu görünüyordu.
- İptal edilen analize geç gelen iş rapor yazıp önceki raporu güncellikten
  düşürebilirdi.

## İstem sürümleri (`prompts/*.vN.txt`)

Analiz, kullandığı istemin sürümünü `transkript_analizler.prompt_surumu`
sütununda saklar; eski sürüm dosyaları silinmez (eski raporun nasıl
üretildiği okunabilsin). Güncel sürüm `config.php` → `ai.istem_surumu`.
Rapor sayfası eski sürümle üretilmiş rapora not düşer.

### v3 (2026-10-09)

Neden: v2, 18 saniyelik tek cümlelik videoya ("Me at the zoo") 5 cümlelik
özet, 3 genel geçer öneri ("belgesellerden öğrenin") ve 1 iddia yazdı; bir
eğitim konuşmasında kişi adlarını "Teknik bilgiler"e koydu; konu aralıkları
çakıştı (test raporu, bulgu 7, 8, 10).

- **Uzunluk videoya göre:** PHP, video süresi ve transcript'in kelime
  sayısından bir "Uzunluk:" kuralı hesaplar (`Pipeline::raporUzunlugu`,
  `parcaUzunlugu`) ve kullanıcı mesajına ekler. 2 dakikadan kısa ya da 300
  kelimeden az: özet 1–2 cümle, detaylı özet boş ya da tek paragraf, en çok
  2 konu / 3 nokta. 2–15 dakika: özet 3–4 cümle. Daha uzunu v2 gibi.
- **Sayılar üst sınır, hedef değil:** "en önemli 5–15 nokta" gibi alt
  sınırlar kaldırıldı; içerik yoksa liste boş dizi. Öneri, iddia, fikir ve
  teknik bilgi için "genel geçer tavsiye uydurma, yoksa boş dizi" kuralı.
- **Teknik bilgi:** araç, yöntem, kavram ve terim, sayı, kaynak; kişi adı
  tek başına yazılmaz. Sayfada başlık "Kavramlar ve teknik bilgiler".
- **Konular:** zaman sırasıyla, üst üste binmeden, ilk konu 00:00:00.
  PHP de bunu zorlar (`ResultValidator::konulariDuzenle`).
- Gerçek deneme (ücretsiz Gemini 2.5 Flash): "Me at the zoo" v2 → v3 özet
  5 → 2 cümle, öneri 3 → 0, iddia 1 → 0, zaman çizelgesi 3 → 1. TED (19 dk):
  konu 2 (çakışan) → 3 (kesintisiz, 19:19'da biter).

### v2 (2026-10-09)

- Başlık ve kanal adı yalnız bağlamdır: transcript'te geçmeyen tarih, unvan,
  sıralama ("ilk", "en çok") yazılmaz. v1, "Me at the zoo"ya "YouTube'a
  yüklenen ilk video" yazmıştı.

### v1 (2026-10-09)

- İlk sürüm: parça analizi (map) ve birleştirme (reduce), JSON şeması,
  zaman damgası yalnız transcript satırlarından.
