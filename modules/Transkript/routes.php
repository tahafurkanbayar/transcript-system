<?php
/**
 * =====================================================================
 *  Transkript MODÜLÜ – Rotaları
 * ---------------------------------------------------------------------
 *  Bu dosya, modül AÇIKKEN çekirdek rotalarından SONRA yüklenir.
 *  $router değişkeni hazır gelir.
 *
 *  Yetkiler ROL düzeyindedir; editör ve üyenin yetkileri module.json →
 *  "yetkiler" bloğundan gelir, yönetici her yetkiye sahiptir. Kayda
 *  bağlı kurallar (kütüphane, paylaşım, analizi başlatan, yüklenen
 *  dosya, sohbet) TranskriptPolicy'dedir.
 *
 *  Analiz başlatan panel uçları (analiz, kuyruk/tik, iptal) rotada
 *  yalnız transkript.view ister: "Üyeler analiz başlatabilir" ayarı
 *  rol yetkisi değildir, TranskriptPolicy::canAnalyze denetler (403).
 *
 *  Erişim tablosu tests/transkript.php'de; rota eklenir ya da yetkisi
 *  değişirse oradaki matris de güncellenir.
 * =====================================================================
 */

declare(strict_types=1);

use App\Core\Router;
use Modules\Transkript\Controllers\AnasayfaController;
use Modules\Transkript\Controllers\Api\TranskriptApiController;
use Modules\Transkript\Controllers\AyarController;
use Modules\Transkript\Controllers\KaynakController;
use Modules\Transkript\Controllers\SohbetController;
use Modules\Transkript\Controllers\UyelikController;

/** @var Router $router */

/* ---------------------------------------------------------------------
 *  ANA SAYFA (sayfanın kendisi çekirdeğin "" rotasında; bkz. events.php)
 * ------------------------------------------------------------------ */
// Tanıtım sayfasının CSS / JS'i ziyaretçiye de gerekir; yalnız
// Varliklar::HERKESE_ACIK listesindekiler sunulur, panel dosyaları değil.
$router->get('transkript/varlik/{dosya}', AnasayfaController::class, 'varlik',
    ['installed']);

/* ---------------------------------------------------------------------
 *  PANEL
 * ------------------------------------------------------------------ */
$router->get('panel/transkript', KaynakController::class, 'index',
    ['installed', 'auth', 'can:transkript.view']);

$router->post('panel/transkript/analiz', KaynakController::class, 'analiz',
    ['installed', 'auth', 'csrf', 'can:transkript.view']);

$router->get('panel/transkript/ilerleme/{kaynak_id}', KaynakController::class, 'ilerleme',
    ['installed', 'auth', 'can:transkript.view']);

// İşçi çalışmıyorsa sıradaki işleri istek içinde çalıştırır (yalnız geliştirme
// ortamında ya da kuyruk.panelden_isle açıkken; bkz. Services\KuyrukDurumu).
$router->post('panel/transkript/kuyruk/isle', KaynakController::class, 'kuyruguIsle',
    ['installed', 'auth', 'csrf', 'can:transkript.admin']);

// Komut satırısız kuyruk: işçi yokken açık panel sayfası tek tek iş işletir (KaynakController::kuyrukTik).
$router->post('panel/transkript/kuyruk/tik', KaynakController::class, 'kuyrukTik',
    ['installed', 'auth', 'csrf', 'can:transkript.view']);

// "Bekleyenleri yeniden dene": geçici sebeple duran analizler kaldığı yerden sürer.
$router->post('panel/transkript/yeniden-dene', KaynakController::class, 'bekleyenleriSurdur',
    ['installed', 'auth', 'csrf', 'can:transkript.admin']);

// Modülün JS / CSS dosyaları (modules/ klasörü tarayıcıya kapalı; bkz. Varliklar).
$router->get('panel/transkript/varlik/{dosya}', KaynakController::class, 'varlik',
    ['installed', 'auth', 'can:transkript.view']);

// Yönetici ayarları ({kaynak_id} rotalarından ÖNCE: ilk eşleşen kazanır).
$router->get('panel/transkript/ayarlar', AyarController::class, 'goster',
    ['installed', 'auth', 'can:transkript.admin']);

$router->post('panel/transkript/ayarlar', AyarController::class, 'kaydet',
    ['installed', 'auth', 'csrf', 'can:transkript.admin']);

// Yedek zinciri: devre dışı / dinlenen sağlayıcılar hemen yeniden denensin.
$router->post('panel/transkript/ayarlar/dinlenme-sifirla', AyarController::class, 'dinlenmeSifirla',
    ['installed', 'auth', 'csrf', 'can:transkript.admin']);

// EVREN kullanım şartları: metni oku, yönetici onaylasın (onay yalnız bu POST ile).
$router->get('panel/transkript/ayarlar/evren-sartlar', AyarController::class, 'evrenSartlar',
    ['installed', 'auth', 'can:transkript.admin']);

$router->post('panel/transkript/ayarlar/evren-sartlar', AyarController::class, 'evrenSartOnayla',
    ['installed', 'auth', 'csrf', 'can:transkript.admin']);

/* Üyelik planları (Uyelik\Planlar; plan = rol). Üyeliğim herkese,
 * yönetim yalnız yöneticiye. {kaynak_id} rotalarından ÖNCE; ikinci
 * parçası "iptal", "sil" … olan adres kullanılmaz (kaynak rotasıyla
 * karışmasın). */
$router->get('panel/transkript/uyelik', UyelikController::class, 'benim',
    ['installed', 'auth', 'can:transkript.view']);

$router->post('panel/transkript/uyelik/talep', UyelikController::class, 'talep',
    ['installed', 'auth', 'csrf', 'can:transkript.view']);

$router->post('panel/transkript/uyelik/talebi-geri-cek', UyelikController::class, 'talebiGeriCek',
    ['installed', 'auth', 'csrf', 'can:transkript.view']);

$router->get('panel/transkript/uyelikler', UyelikController::class, 'yonetim',
    ['installed', 'auth', 'can:transkript.admin']);

$router->post('panel/transkript/uyelikler/ver', UyelikController::class, 'ver',
    ['installed', 'auth', 'csrf', 'can:transkript.admin']);

$router->post('panel/transkript/uyelikler/kaldir', UyelikController::class, 'kaldir',
    ['installed', 'auth', 'csrf', 'can:transkript.admin']);

$router->post('panel/transkript/uyelikler/reddet', UyelikController::class, 'reddet',
    ['installed', 'auth', 'csrf', 'can:transkript.admin']);

$router->get('panel/transkript/{kaynak_id}/transkript', KaynakController::class, 'transkript',
    ['installed', 'auth', 'can:transkript.view']);

// Dışa aktarma: rapor.md, transkript.txt / .srt / .vtt (Services\DisaAktarim)
$router->get('panel/transkript/{kaynak_id}/indir/{bicim}', KaynakController::class, 'indir',
    ['installed', 'auth', 'can:transkript.view']);

$router->get('panel/transkript/{kaynak_id}', KaynakController::class, 'goster',
    ['installed', 'auth', 'can:transkript.view']);

// Kayıt yönetimi (Services\KayitYonetimi). Kayda bağlı kural TranskriptPolicy'de:
// ortak YouTube kaynağını yalnız yönetici TAMAMEN siler; kullanıcı kendi
// kütüphanesinden çıkarır; süren analizi yönetici ya da başlatan iptal eder.
$router->post('panel/transkript/{kaynak_id}/sil', KaynakController::class, 'sil',
    ['installed', 'auth', 'csrf', 'can:transkript.view']);

$router->post('panel/transkript/{kaynak_id}/kutuphaneden-cikar', KaynakController::class, 'kutuphanedenCikar',
    ['installed', 'auth', 'csrf', 'can:transkript.view']);

$router->post('panel/transkript/{kaynak_id}/paylas', KaynakController::class, 'paylas',
    ['installed', 'auth', 'csrf', 'can:transkript.admin']);

// Video kategorisini yönetici düzeltir (Kategori\Kategoriler; "elle" işaretlenir).
$router->post('panel/transkript/{kaynak_id}/kategori', KaynakController::class, 'kategori',
    ['installed', 'auth', 'csrf', 'can:transkript.admin']);

$router->post('panel/transkript/{kaynak_id}/iptal', KaynakController::class, 'iptal',
    ['installed', 'auth', 'csrf', 'can:transkript.view']);

$router->post('panel/transkript/{kaynak_id}/sohbet', SohbetController::class, 'sor',
    ['installed', 'auth', 'csrf', 'can:transkript.chat']);

/* ---------------------------------------------------------------------
 *  REST API — "Authorization: Bearer cy_…" (docs/TEKNIK_RAPOR.md, 8. bölüm)
 *  "okuma" kapsamlı anahtar POST atamaz (ApiGuard).
 * ------------------------------------------------------------------ */
$router->group('api/v1/transkript', ['installed'], function (Router $r): void {
    $r->post('analiz', TranskriptApiController::class, 'analiz', ['api', 'api.can:transkript.analyze']);
    $r->post('dosya',  TranskriptApiController::class, 'dosya',  ['api', 'api.can:transkript.analyze']);

    $r->get('kaynaklar',                         TranskriptApiController::class, 'kaynaklar',  ['api', 'api.can:transkript.view']);
    $r->get('kaynaklar/{kaynak_id}',             TranskriptApiController::class, 'kaynak',     ['api', 'api.can:transkript.view']);
    $r->get('kaynaklar/{kaynak_id}/ozet',        TranskriptApiController::class, 'ozet',       ['api', 'api.can:transkript.view']);
    $r->get('kaynaklar/{kaynak_id}/transkript',  TranskriptApiController::class, 'transkript', ['api', 'api.can:transkript.view']);
    $r->post('kaynaklar/{kaynak_id}/sohbet',     TranskriptApiController::class, 'sohbet',     ['api', 'api.can:transkript.chat']);

    $r->get('istatistik', TranskriptApiController::class, 'istatistik', ['api', 'api.can:transkript.admin']);
});
