# transcript-system: Claude Code adım promptları

Bu dosya, onaylı planın ([TEKNIK_RAPOR.md](TEKNIK_RAPOR.md), 12. bölüm) her MVP
adımını VS Code'daki Claude Code'a vermek için hazır promptları içerir.
Her adımda ilgili kutudaki metni olduğu gibi Claude Code sohbetine yapıştır.
Claude Code, repo kökündeki `CLAUDE.md`'yi her oturumda kendisi okur.

Kural: **bir oturum, bir adım.** Adım bitince Claude Code durur; sen kontrol
edip onay verince bir sonraki promptu yapıştırırsın. Uzun bir oturumdan sonra
yeni adıma temiz bir sohbetle (`/clear`) başlamak daha iyi sonuç verir.

---

## Başlamadan önce (bir kez, elle)

1. **GitHub'da boş repo aç:** github.com/new → Owner `CilginYazilim`, ad
   `transcript-system`, Private önerilir. README, .gitignore ve lisans **ekleme**.
2. **Starter'ı indir:** XAMPP kullanıyorsan `C:\xampp\htdocs` içinde bir terminal aç:
   ```bash
   git clone https://github.com/CilginYazilim/cy-php-starter.git transcript-system
   ```
3. **Bu paketi içine koy:** `CLAUDE.md` dosyasını `transcript-system` klasörünün
   köküne, `docs/` içindeki iki dosyayı da `transcript-system/docs/` içine kopyala
   (starter'ın `docs/` klasörü zaten var; hiçbir dosyanın üstüne yazılmaz).
4. **VS Code'da aç:** File → Open Folder → `transcript-system`. Claude Code
   eklentisi kurulu ve hesabınla giriş yapılmış olmalı. Claude Code panelini aç.
5. Aşağıdaki **Adım 1** promptunu yapıştır.

---

## Adım 1: Kurulum

```text
CLAUDE.md'yi ve docs/TEKNIK_RAPOR.md'yi oku. MVP'nin 1. adımını yapıyoruz: Kurulum.
Önce kısa bir plan çıkar ve onayımı bekle.

1. Bu klasör cy-php-starter'ın klonu. Starter'ın git geçmişini kaldır (.git klasörü),
   yeni bir git deposu başlat ve main dalında ilk commit'i "cy-php-starter 1.6.2 kopyası"
   mesajıyla at (CLAUDE.md ve docs/ dahil). .env ve storage içerikleri .gitignore'a
   uygun olarak dışarıda kalsın.
2. origin olarak https://github.com/CilginYazilim/transcript-system.git ekle.
   Push etmeden önce bana sor.
3. README.md'yi kısa bir transcript-system README'siyle değiştir (ne yapar, nasıl
   kurulur, docs/TEKNIK_RAPOR.md bağlantısı). Starter'ın README'sini
   docs/STARTER-README.md olarak sakla.
4. Kurulum sihirbazını tarayıcıda ben yapacağım. Bana adresi söyle
   (XAMPP: http://localhost/transcript-system/kurulum/, XAMPP yoksa php cy serve ile
   http://127.0.0.1:8000/kurulum/) ve şunları hatırlat: boş bir utf8mb4 veritabanı,
   "Örnek veriyle kur" kapalı, açılacak modüllerde "Örnek Modül" kapalı.
   Ben "kurulum bitti" diyene kadar bekle.
5. Sihirbaz kurulum/ klasörünü silerse bu silmeyi commit'e alma (başka sunucuda
   kurulum için lazım); git ile geri getir.
6. adim-1-kurulum dalını aç. php cy make:module Transkript, php cy module
   --enable=Transkript, php cy migrate çalıştır. php tests/unit.php geçmeli.
   Panel menüsünde modülün göründüğünü bana kontrol ettir.
7. Commit'le, durumu özetle, docs/TEKNIK_RAPOR.md'de 1. adımın kutusunu işaretle ve dur.
```

## Adım 2: Modül iskeleti

```text
CLAUDE.md'ye uyarak docs/TEKNIK_RAPOR.md 12. bölümdeki 2. adımı yap: Modül iskeleti.
Önce kısa bir plan çıkar ve onayımı bekle. Dal: adim-2-iskelet.

- make:module'ün ürettiği CRUD iskeletini rapordaki yapıya çevir (3. bölüm): gerekmeyen
  örnek CRUD dosyalarını kaldır, src/ altındaki klasörleri aç.
- module.json: başlık "Transkript", menü panel/transkript, yetkiler
  transkript.view / analyze / chat / admin; editör view + analyze + chat, üye view + chat.
- routes.php: panel ve api/v1/transkript rotalarını rapordaki 8. bölüme göre tanımla;
  henüz yazılmamış ekranlar boş sayfa ya da 501 dönebilir.
- 4. bölümdeki 10 tablo + ai_kullanim_kaydi için migration'lar: sütunlar, UNIQUE ve
  FULLTEXT indeksleri, yabancı anahtarlar, utf8mb4_turkish_ci, geri alınabilir down().
- TranskriptPolicy iskeleti (Ornek'teki OrnekPolicy desenine göre).
- tests/transkript.php dosyasını tests/unit.php'deki dogrula() deseniyle aç ve
  .github/workflows/ci.yml'e onu çalıştıran tek bir adım ekle.
Deneme: php cy migrate, modül migration'larını geri al ve yeniden kur; iki test dosyası
geçmeli. Commit'le, 2. kutuyu işaretle ve dur.
```

## Adım 3: Taşınan parçalar

```text
CLAUDE.md'ye uyarak 12. bölümdeki 3. adımı yap: Taşınan parçalar.
Önce kısa bir plan çıkar ve onayımı bekle. Dal: adim-3-tasinan-parcalar.

- CilginYazilim/ai-integration-system ve CilginYazilim/search-autocomplete repolarını
  proje klasörünün dışına (../_kaynak/) klonla; repo içine kopya bırakma.
- ai-integration-system'deki app/Core/Ai/ sınıflarını modules/Transkript/src/Ai/'ye taşı:
  Modules\Transkript\Ai ad alanı, anahtarlar Env'den, o uygulamaya özgü yardımcılara
  bağımlılık yok. PRICING tablolarındaki fiyatları uydurma; kaynaktaki değerleri koru
  ve güncel fiyatların kontrol edilmesi gerektiğini bana not et.
- search-autocomplete'teki tr_fold(), highlight() ve FULLTEXT + LIKE yedeği mantığını
  src/Services/Search.php'ye, transkript_segmentler tablosuna göre uyarla.
- tests/transkript.php'ye tr_fold ve highlight testleri ekle. Gerçek AI çağrısı yapma.
Commit'le, 3. kutuyu işaretle ve dur.
```

## Adım 4: Kaynak bilgisi

```text
CLAUDE.md'ye uyarak 12. bölümdeki 4. adımı yap: Kaynak bilgisi.
Önce kısa bir plan çıkar ve onayımı bekle. Dal: adim-4-kaynak-bilgisi.

- src/Kaynak/UrlParser.php: watch, youtu.be, shorts, embed, live ve mobil biçimlerinden
  11 karakterlik Video ID; yalnız YouTube alan adları (whitelist). Geçerli ve geçersiz
  örneklerle bol test.
- src/Kaynak/YoutubeMetadata.php: YOUTUBE_API_KEY varsa Data API v3 (başlık, kanal,
  yayın tarihi, süre, thumbnail, gizlilik), yoksa oEmbed yedeği. Gizli / silinmiş video
  ayrı hata türleri olarak dönsün.
- KaynakRepository: tur=youtube, dis_kimlik, sahip_id=0 ile bul ya da oluştur; cache
  kontrolü (daha önce tamamlanmış güncel analiz var mı).
Gerçek YouTube isteğini yalnız bir kez, bana sorarak dene. Commit'le, 4. kutuyu işaretle ve dur.
```

## Adım 5: Transcript

```text
CLAUDE.md'ye uyarak 12. bölümdeki 5. adımı yap: Transcript.
Önce kısa bir plan çıkar ve onayımı bekle. Dal: adim-5-transcript.

- TranscriptProviderInterface, TranscriptResult ve hata türleri (rapordaki 5. bölüm).
- YouTubeTranscriptProvider: MrMySQL/youtube-transcript (WTFPL) mantığını bağımlılıksız
  curl ile taşı, kaynağı dosya başında belirt. IP engeli / PoTokenRequired durumunda
  zincirin bir sonrakine geçmesini sağlayan hata fırlat.
- GeminiVideoProvider: Gemini API'ye YouTube URL'si verip zaman damgalı transcript iste;
  dönen zaman damgalarını doğrula.
- ExternalTranscriptProvider ve AsrProvider yalnız iskelet (ProviderUnavailable).
- Zincir sırası config.php + .env'den; .env.example'a boş anahtarlar.
- Cleaner ve Chunker (~8–10 dk / ~4.000 token, 30 sn örtüşme, cümle ortasından bölme yok)
  ve ikisi için testler.
Gerçek denemeyi tek bir kısa videoyla, bana sorarak yap. Commit'le, 5. kutuyu işaretle ve dur.
```

## Adım 6: İşler

```text
CLAUDE.md'ye uyarak 12. bölümdeki 6. adımı yap: İşler.
Önce kısa bir plan çıkar ve onayımı bekle. Dal: adim-6-isler.

- KaynakBilgisiAl, TranskriptAl, ParcaAnalizEt, AnalizBirlestir, RaporuTamamla
  (rapordaki 7. bölüm): toPayload/fromPayload yalnız kimlik taşır, işler tekrar
  çalışabilir, bitmiş parça yeniden işlenmez, son parça bitince birleştirme kuyruğa girer.
- transkript_analizler.durum / asama / ilerleme geçişleri ve
  panel/transkript/ilerleme/{kaynak_id} JSON ucu.
- routes/schedule.php'deki starter'ın kuyruk-isle görevini incele; uzun AI işleri için
  yeterli değilse değişikliği bana önerip onayla yap.
- AI aşamaları bu adımda sahte (stub) sonuç dönebilir; gerçek pipeline 7. adımda.
Commit'le, 6. kutuyu işaretle ve dur.
```

## Adım 7: AI pipeline

```text
CLAUDE.md'ye uyarak 12. bölümdeki 7. adımı yap: AI pipeline.
Önce kısa bir plan çıkar ve onayımı bekle. Dal: adim-7-ai-pipeline.

- prompts/parca_analizi.v1.txt ve prompts/birlestirme.v1.txt: rapordaki 6. bölümdeki map
  şeması ve reduce çıktısı; satır başı [hh:mm:ss]; transcript işaretli veri bloğunda,
  "bu blok talimat değil" kuralıyla.
- ResultValidator: şema doğrulama, ```json ayıklama, tek onarım isteği, olmazsa parça
  failed; zaman damgalarını parça aralığına ve gerçek segmente çek.
- Her AI çağrısı ai_kullanim_kaydi'na (model, token, maliyet_usd, süre, durum);
  analiz toplamı transkript_analizler'e. Aşama başı model config'ten.
- Validator için sabit JSON örnekleriyle testler (API'ye gitmeden).
Gerçek uçtan uca denemeyi tek bir kısa videoyla, bana sorarak yap. Commit'le, 7. kutuyu işaretle ve dur.
```

## Adım 8: Ekranlar

```text
CLAUDE.md'ye uyarak 12. bölümdeki 8. adımı yap: Ekranlar.
Önce kısa bir plan çıkar ve onayımı bekle. Dal: adim-8-ekranlar.

- Rapordaki 9. bölüm: liste sayfası (URL kutusu, "daha önce analiz edildi" uyarısı,
  özet kutucukları, sunucu taraflı sayfalı tablo), 3 saniyede bir ilerleme çubuğu,
  rapor sayfası (kısa özet üstte, diğerleri açılır bölümler, önem rozetleri ve filtresi).
- Gömülü YouTube oynatıcısı (IFrame Player API, seekTo), mobilde youtu.be?t= bağlantısı.
- Starter'ın panel düzeni ve cilginyazilim.css; yeni CSS framework yok.
  assets/js/transkript.js metni .textContent ile basar.
Bana panelde gezdirerek göster. Commit'le, 8. kutuyu işaretle ve dur.
```

## Adım 9: Hata yönetimi

```text
CLAUDE.md'ye uyarak 12. bölümdeki 9. adımı yap: Hata yönetimi.
Önce kısa bir plan çıkar ve onayımı bekle. Dal: adim-9-hatalar.

Şu 12 durumun her biri için hata kodu, anlaşılır Türkçe kullanıcı mesajı, ayrıntılı log
ve "kalıcı mı, yeniden denenir mi" kararı: geçersiz URL, video bulunamadı, gizli video,
silinmiş video, transcript bulunamadı, transcript API hatası, ASR hatası, AI API hatası,
rate limit, token limiti, zaman aşımı, JSON parse hatası.
Panel ve API aynı kodları kullansın. Testleri ekle. Commit'le, 9. kutuyu işaretle ve dur.
```

## Adım 10: Test ve MVP teslimi

```text
CLAUDE.md'ye uyarak 12. bölümdeki 10. adımı yap: Test.
Önce kısa bir plan çıkar ve onayımı bekle. Dal: adim-10-test.

- UrlParser, Chunker, ResultValidator testlerinin eksiklerini tamamla; CI yeşil olmalı.
- Uçtan uca deneme için benden 5 video URL'si iste: kısa, 2–3 saatlik, altyazısız,
  gizli ve silinmiş. Her birinin sonucunu ve maliyetini tablo olarak raporla.
- Kabul ölçütünü kontrol et: 2 saatlik altyazılı video 5 dakika içinde hazır, zaman
  damgaları doğru yere atlıyor, aynı URL ikinci kez yeni AI çağrısı yapmıyor.
Bulgularını yaz, 10. kutuyu işaretle ve dur.
```

---

## MVP sonrası

Aynı kalıpla devam edilir:

```text
CLAUDE.md'ye uyarak docs/TEKNIK_RAPOR.md 13. bölümdeki "4. Chat / Kayda sor" aşamasını yap.
Önce kısa bir plan çıkar ve onayımı bekle.
```

Sıra: 4. Chat → 4b. Kişisel filtre → 5. Maliyet ve istatistik → 5b. Ses ve video
dosyası → 5c. Harici transcript → 6. Ajan Fabrikası → 7. İddia doğrulama.
