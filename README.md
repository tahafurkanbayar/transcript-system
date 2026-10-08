# transcript-system

YouTube videolarından (sonraki aşamada yüklenen ses / video dosyalarından da)
zaman damgalı transcript çıkarıp AI ile analiz eden sistem: kısa ve detaylı
özet, önemli noktalar, zaman çizelgesi, teknik bilgiler, iddialar ve fikirler.
Rapordaki her zaman damgası videoda ilgili saniyeye atlar. Türkçe önceliklidir.

> **Durum:** MVP geliştiriliyor. Adımlar ve ilerleme
> [docs/TEKNIK_RAPOR.md](docs/TEKNIK_RAPOR.md) 12. bölümde.

## Mimari kısaca

- Temel: [cy-php-starter](https://github.com/CilginYazilim/cy-php-starter) 1.6.2
  (giriş, rol / yetki, kuyruk, zamanlayıcı, REST API, panel). Starter'ın
  kendi belgesi: [docs/STARTER-README.md](docs/STARTER-README.md) ve [SISTEM.md](SISTEM.md).
- Projeye ait kodun tamamı `modules/Transkript/` modülünde; çekirdeğe dokunulmaz.
- Transcript sağlayıcı zinciri: YouTube altyazısı → Gemini video yedeği
  (sonra: harici transcript API, yüklenen dosya için ASR).
- AI analizi kuyruk işlerinde Map → Reduce olarak çalışır; aynı video ikinci
  kez AI'a gitmez.
- Bağımlılık yok: Composer ve npm paketi kullanılmaz, dış servislere PHP `curl` ile gidilir.

## Kurulum (XAMPP)

Gereksinimler: PHP 8.1+ (`pdo_mysql`, `mbstring`, `curl`, `fileinfo`),
MySQL 5.7+ / MariaDB 10.3+, Apache `mod_rewrite`.

1. Klasörü `C:\xampp\htdocs\transcript-system` olarak yerleştir.
2. Boş bir veritabanı aç:
   ```sql
   CREATE DATABASE transcript_system CHARACTER SET utf8mb4 COLLATE utf8mb4_turkish_ci;
   ```
3. `http://localhost/transcript-system/kurulum/` adresinde sihirbazı tamamla:
   **Örnek veriyle kur** ve **Demo modu** kapalı, Modüller bölümünde
   **Örnek Modül** işaretsiz.
4. Modülü aç ve tablolarını kur:
   ```bash
   php cy module --enable=Transkript
   php cy migrate
   ```
5. API anahtarlarını `.env`'e yaz (`.env.example`'daki boş satırlar). Anahtarlar
   koda ve veritabanına yazılmaz.
6. Kuyruk için zamanlayıcıyı kur: Linux'ta tek cron satırı
   `* * * * * cd /yol/transcript-system && php cy schedule:run`, Windows'ta aynı
   komut Görev Zamanlayıcı'dan her dakika.

XAMPP yoksa: `php cy serve` → `http://127.0.0.1:8000/kurulum/`.

## Komutlar

```bash
php cy migrate:status          # migration durumu
php cy queue:work --max=30     # kuyruk işçisi
php cy schedule:run            # zamanlanmış görevler (cron her dakika)
php tests/unit.php             # starter birim testleri
php tests/transkript.php       # modül birim testleri (2. adımda eklenir)
```

## Belgeler

- [docs/TEKNIK_RAPOR.md](docs/TEKNIK_RAPOR.md): onaylı plan (tablolar, pipeline, API, güvenlik, MVP adımları)
- [docs/ADIM_PROMPTLARI.md](docs/ADIM_PROMPTLARI.md): adım adım geliştirme promptları
- [CLAUDE.md](CLAUDE.md): Claude Code çalışma kuralları
