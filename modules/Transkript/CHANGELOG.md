# Transkript modülü: değişiklik günlüğü

Starter'ın sürüm geçmişi kökteki `CHANGELOG.md`'dedir; bu dosya yalnız
`modules/Transkript/` içindir. Biçim [Keep a Changelog](https://keepachangelog.com/tr/1.1.0/).

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
