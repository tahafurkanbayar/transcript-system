# transcript-system: Claude Code talimatları

Bu repo, YouTube videolarından ve (sonraki aşamada) yüklenen ses / video
dosyalarından zaman damgalı transcript çıkarıp AI ile analiz eden sistemdir:
kısa ve detaylı özet, önemli noktalar, zaman çizelgesi, teknik bilgiler,
iddialar, fikirler ve kullanıcıya özel bölüm. Türkçe önceliklidir.

- **Temel:** [cy-php-starter](https://github.com/CilginYazilim/cy-php-starter) 1.6.2'nin kopyası.
  Yeni kodun tamamı `modules/Transkript/` modülünde durur.
- **Onaylı plan:** [`docs/TEKNIK_RAPOR.md`](docs/TEKNIK_RAPOR.md). Tablo, sınıf, rota ve
  yetki adları oradan gelir. Plan ile gerçek kod çelişirse dur ve sor; planı
  sessizce değiştirme.
- **Starter kılavuzu:** `SISTEM.md` (mimari), `docs/STARTER-README.md` (komutlar),
  `modules/Ornek/` (kopyalanacak çalışan modül örneği, `OrnekPolicy` dahil).
- **Adım promptları:** [`docs/ADIM_PROMPTLARI.md`](docs/ADIM_PROMPTLARI.md).

## Çalışma kuralları

1. **Bir oturum, bir adım.** Yalnız istenen MVP adımını yap (`docs/TEKNIK_RAPOR.md`
   12. bölüm). Adım bitince dur, ne yaptığını ve nasıl denendiğini kısaca yaz,
   sonraki adıma kendiliğinden geçme. Adım bitince rapordaki ilgili `- [ ]`
   kutusunu `- [x]` yap.
2. **Git:** her adım kendi dalında (`adim-1-kurulum`, `adim-2-iskelet` …).
   Küçük, açıklamalı commit'ler. `main`'e doğrudan commit yok. Push ve PR
   kullanıcının onayıyla.
3. **Çekirdeğe dokunma.** `app/`, çekirdek `views/`, çekirdek `routes/`,
   `config/` değişmez. İzinli istisnalar: `routes/schedule.php` (worker
   tetikleme, 6. adım), `.env.example` (boş anahtar satırları),
   `tests/transkript.php` (yeni dosya) ve `.github/workflows/ci.yml`'e bu testi
   çalıştıran tek adım. Başka bir çekirdek değişikliği gerekirse önce sor.
4. **Bağımlılık yok.** Composer, npm paketi, CDN eklenmez (starter kuralı).
   Dış servislere PHP `curl` ile gidilir.
5. **Gizli bilgi.** API anahtarları yalnız `.env`'de, starter'ın `Env` sınıfıyla
   okunur; `.env.example`'a boş eklenir. Koda, veritabanına, log'a, API
   yanıtına ve sayfaya yazılmaz. `.env` commit edilmez.
6. **HTTP isteğinde uzun iş yok.** Transcript ve AI çağrıları yalnız kuyruk
   işlerinde (`Queue::push`) çalışır; panel ve API istekleri 1 saniyenin altında döner.
7. **AI çıktısına güvenme.** `ResultValidator`'dan geçmeyen JSON kaydedilmez;
   zaman damgaları gerçek segmentlere çekilir ya da atılır; AI ve transcript
   metni HTML olarak basılmaz (PHP'de `e()`, JS'de `.textContent`).
8. **YouTube'dan ses / video indirilmez** (yt-dlp yok, YouTube şartları).
   ASR yalnız kullanıcının yüklediği dosyada çalışır.
9. **Lisans.** GPL / AGPL / BSL kodu kopyalanmaz. MIT, BSD, Apache, WTFPL
   kodundan alınan parçanın kaynağı dosya başında yorumla belirtilir.
10. **Maliyet.** AI çağrısı yapan her test ya da deneme kullanıcıya önce sorulur;
    birim testleri gerçek API'ye gitmez.

## Starter kuralları (SISTEM.md'nin özeti)

- PHP 8.1+, her dosyada `declare(strict_types=1);`, PDO/MySQL, `utf8mb4_turkish_ci`.
- Tablo ve sütun adları Türkçe ve ASCII (`transkript_kaynaklar`, `baslangic_ms`).
- Modül düzeni: `module.json` (künye, menü, rollere dağıtılan `yetkiler`),
  `routes.php`, `events.php`, `migrations/`, `src/` (`Modules\Transkript\…`), `views/`.
  Ad alanı ve dosya düzeni için `modules/Ornek/`'i örnek al.
- Yetkiler `module.json` → `"yetkiler"` bloğunda rollere dağıtılır; `app/Models/Role.php`'ye
  dokunulmaz. Yetkiler: `transkript.view`, `transkript.analyze`, `transkript.chat`,
  `transkript.admin`. Kayda sahibine bağlı kurallar `TranskriptPolicy`'de.
- Rotalar: GET `['installed', 'auth', 'can:transkript.view']`, POST
  `['installed', 'auth', 'csrf', 'can:…']`. API rotaları starter'ın `ApiGuard` /
  Bearer anahtar düzeniyle.
- Görünüm: `$this->view('Transkript::index', [...])` → `modules/Transkript/views/index.php`.
- Kuyruk işi: `extends Job`; `handle()`, `toPayload()`, `fromPayload()`, `failed()`.
  Payload yalnız kimlik taşır (`['analiz_id' => 12, 'parca' => 7]`). İş iki kez
  çalışırsa ikinci kez para harcamaz (bitmiş parça yeniden AI'a gitmez).
- Yanıt zarfları: panel AJAX `{success, type, description}`; API `ApiResponse` ile
  `{success, data, meta}` / `{success: false, error: {code, message}}`.
- Hız sınırı `Throttle`, günlük `Log`, ortam `Env`. Kullanıcıya Türkçe ve anlaşılır
  mesaj, teknik ayrıntı yalnız log'a.
- Migration'lar `up()` / `down()` ile geri alınabilir yazılır.

## Mimari özeti (ayrıntı `docs/TEKNIK_RAPOR.md`'de)

**Tablolar** (13 modül tablosu + `ai_kullanim_kaydi`):
`transkript_kaynaklar` (`tur`: youtube / ses_dosyasi / video_dosyasi, `dis_kimlik`,
`sahip_id`, UNIQUE(`tur`, `dis_kimlik`, `sahip_id`), `paylasildi`), `transkript_metinler`,
`transkript_segmentler`, `transkript_analizler`, `transkript_parcalar`,
`transkript_analiz_ogeleri`, `transkript_kisisel_ozetler`, `transkript_sohbetler`,
`transkript_sohbet_mesajlari`, `transkript_ilgi_alanlari`, `transkript_kutuphane`,
`transkript_uyelikler` (Pro dönemleri), `transkript_uyelik_talepleri`.
Kaynak türünü yalnız `transkript_kaynaklar` bilir; diğer tablolar `kaynak_id` ile
bağlanır. Önbellek ortak, görünürlük kişisel: rapor yalnız kütüphanesine
ekleyene ve yöneticinin paylaştığına görünür (`TranskriptPolicy::canViewSource`,
SQL'de `KaynakRepository::gorunurluk()`).

**Üyelik planları** (2026-10-10, kullanıcı isteği): plan = rol. `uye` Ücretsiz
(kısıtlı sınırlar), `editor` Pro (ücretli, ekranda "Pro Üye"). Sınırlar
`config.php` → `uyelik` ve Transkript ayarları → Üyelik planları; kural
`Uyelik\Planlar::haklar()`, süre / talep `Uyelik\UyelikYonetimi`. Çevrim içi
ödeme yok: talep → ödeme talimatı → yönetici onayı.

**Yapay zekâ zinciri** (`Ai\YedekliSaglayici`, sıra `.env` `AI_PROVIDERS`): varsayılan
`evren,gemini,openrouter,groq` (2026-10-11, kullanıcı isteği: yerli EVREN başta).
`Ai\EvrenProvider` OpenAI uyumludur. Kullanım şartlarını kod kendiliğinden ONAYLAMAZ;
yalnız yönetici Durum → "Şartları oku ve onayla" ile onaylar (`Ai\EvrenHesap`). 403
gelirse 1 saat atlanır. Maliyet 0 (1 Kasım 2026'ya kadar). Her sağlayıcının
modeli panelden (`model_evren`, Gemini için `ai_model`).

**Transcript zinciri** (`TranscriptProviderInterface`, sıra `.env` / config'ten):
1. `YouTubeTranscriptProvider`: YouTube altyazısı.
2. `GeminiVideoProvider`: Gemini API'ye YouTube URL'si (MVP'de yedek).
3. `ExternalTranscriptProvider`: ücretli transcript API, varsayılan kapalı (5c).
4. `AsrProvider`: yalnız yüklenen dosya, OpenAI uyumlu tek istemci (5b).

**Pipeline:** temizle → parçala (~8–10 dk / ~4.000 token, 30 sn örtüşme) → map
(satır başı `[hh:mm:ss]`) → reduce → kişisel filtre → doğrulanmış rapor.
Promptlar `modules/Transkript/prompts/*.v1.txt`, sürüm analizle birlikte kaydedilir.

**İşler:** `KaynakBilgisiAl` → `TranskriptAl` → `ParcaAnalizEt` × N → `AnalizBirlestir`
→ `RaporuTamamla`. Durumlar: `bekliyor`, `transkript_aliniyor`, `transkript_hazir`,
`analiz_ediliyor`, `tamamlandi`, `hata`.

## Kaynak repolar (yalnız okuma)

Bu repoları proje klasörünün **dışına** klonla (ör. `../_kaynak/`), ilgili
parçayı modüle uyarlayarak taşı, repo içine kopya klasör bırakma.

| Kaynak | Alınacak parça | Modüldeki yeri |
|---|---|---|
| [CilginYazilim/ai-integration-system](https://github.com/CilginYazilim/ai-integration-system) | `app/Core/Ai/` (Provider, HttpProvider, Ai, Gemini / Claude / OpenAiCompatible, `PRICING` + `priceFor()`) | `src/Ai/` |
| [CilginYazilim/search-autocomplete](https://github.com/CilginYazilim/search-autocomplete) | `system/function.php` içindeki `tr_fold()`, `highlight()`, FULLTEXT + LIKE yedeği; `assets/js/search.js` debounce + `seq` | `src/Services/Search.php`, `assets/js/transkript.js` |
| [CilginYazilim/job-queue](https://github.com/CilginYazilim/job-queue) | Cron yoksa panelden tek iş çalıştırma fikri | İlerleme isteği, varsayılan kapalı |
| [MrMySQL/youtube-transcript](https://github.com/MrMySQL/youtube-transcript) (WTFPL) | Altyazı listesini bulma ve okuma mantığı | `src/Transcript/YouTubeTranscriptProvider.php` |

## Komutlar

```bash
php cy serve                          # XAMPP'siz geliştirme: http://127.0.0.1:8000
php cy module --enable=Transkript     # modülü aç
php cy migrate                        # bekleyen migration'lar
php cy migrate:status
php cy queue:work --max=30            # kuyruk işçisi
php cy schedule:run                   # cron'un her dakika çalıştırdığı komut
php tests/unit.php                    # starter birim testleri (veritabanı gerekmez)
php tests/transkript.php              # modül birim testleri (UrlParser, Chunker, ResultValidator)
php tests/transkript-yetki.php        # rol × eylem × sahiplik → HTTP (yerel veritabanı ister; yoksa atlanır)
```

Her adımdan sonra iki test dosyası da geçmeli; GitHub Actions (`ci.yml`) yeşil kalmalı.
Modül testleri `tests/unit.php`'deki `dogrula()` desenini izler ve veritabanı
ya da ağ gerektirmez.
