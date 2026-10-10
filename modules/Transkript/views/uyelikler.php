<?php
/**
 * =====================================================================
 *  GÖRÜNÜM: Üyelikler (panel/transkript/uyelikler, yönetici)
 * ---------------------------------------------------------------------
 *  Üstte özet; sonra bekleyen talepler (onayla / reddet), "Pro üyelik
 *  ver" formu, Pro üyeler (uzat / kaldır), ödeme geçmişi ve geçerli
 *  plan sınırları. Bütün işlemler sıradan POST + Flash (UyelikController).
 *  Kullanıcının yazdığı not ve adlar e() ile basılır.
 *
 *  @var list<array<string,mixed>> $talepler
 *  @var list<array<string,mixed>> $proUyeler
 *  @var list<array<string,mixed>> $donemler
 *  @var array{bu_ay: array<string,float>, toplam: array<string,float>} $gelir
 *  @var list<array<string,mixed>> $karsilastirma
 *  @var string                    $onKullanici
 * =====================================================================
 */

use App\Core\Auth;
use App\Core\View;
use Modules\Transkript\TranskriptPolicy;
use Modules\Transkript\Uyelik\Planlar;
use Modules\Transkript\Varliklar;

$tarih = static fn (?string $t): string => $t === null ? '—' : date('d.m.Y', (int) strtotime($t));
$para  = static function (array $tutarlar): string {
    if ($tutarlar === []) {
        return Planlar::tutar(0.0);
    }

    return implode(' + ', array_map(static fn (string $kod, float $t): string => Planlar::tutar($t, $kod), array_keys($tutarlar), $tutarlar));
};
$sureSecenekleri = static function (?int $secili = 1): string {
    $html = '';

    foreach ([1, 3, 6, 12, 24, 36] as $ay) {
        $html .= '<option value="' . $ay . '"' . ($ay === $secili ? ' selected' : '') . '>' . $ay . ' ay</option>';
    }

    return $html . '<option value="0"' . ($secili === 0 ? ' selected' : '') . '>Süresiz</option>';
};
$aylik = Planlar::tanim(Planlar::PRO)['fiyat_aylik'];
?>
<link rel="stylesheet" href="<?= e(Varliklar::adres('transkript.css')) ?>">

<?php View::partial('Transkript::_parcalar/alt-menu', ['policy' => new TranskriptPolicy(Auth::user()), 'etkin' => 'uyelikler']); ?>

<div class="cy-stats mb-3 tk-ozet-kartlar" style="--cy-stats-lg: 4; --cy-stats-md: 2">
    <div class="cy-stat">
        <span class="cy-stat__icon cy-stat__icon--brand"><?= icon('star') ?></span>
        <span>
            <span class="cy-stat__label">Pro üye</span>
            <span class="cy-stat__value"><?= count($proUyeler) ?></span>
            <span class="cy-stat__hint">rolü "Pro Üye" olan hesap</span>
        </span>
    </div>
    <div class="cy-stat">
        <span class="cy-stat__icon cy-stat__icon--<?= $talepler !== [] ? 'warning' : 'success' ?>"><?= icon('inbox') ?></span>
        <span>
            <span class="cy-stat__label">Bekleyen talep</span>
            <span class="cy-stat__value"><?= count($talepler) ?></span>
            <span class="cy-stat__hint"><?= $talepler !== [] ? 'ödemeyi görünce onaylayın' : 'yanıt bekleyen yok' ?></span>
        </span>
    </div>
    <div class="cy-stat">
        <span class="cy-stat__icon cy-stat__icon--success"><?= icon('calendar') ?></span>
        <span>
            <span class="cy-stat__label">Bu ay girilen ödeme</span>
            <span class="cy-stat__value"><?= e($para($gelir['bu_ay'])) ?></span>
            <span class="cy-stat__hint">"Pro ver" formundaki tutarlar</span>
        </span>
    </div>
    <div class="cy-stat">
        <span class="cy-stat__icon cy-stat__icon--warning"><?= icon('activity') ?></span>
        <span>
            <span class="cy-stat__label">Toplam</span>
            <span class="cy-stat__value"><?= e($para($gelir['toplam'])) ?></span>
            <span class="cy-stat__hint">kapatılan dönemler hariç</span>
        </span>
    </div>
</div>

<!-- BEKLEYEN TALEPLER -->
<section class="cy-card mb-3" aria-labelledby="tk-talep-baslik">
    <header class="cy-card__header">
        <h3 class="cy-section-title mb-1" id="tk-talep-baslik"><?= icon('inbox', 'cy-icon cy-icon--sm') ?> Bekleyen talepler</h3>
        <p class="cy-muted small mb-0">Üyeler "Üyeliğim → Pro'ya geç" ile talep gönderir ve ödeme talimatını görür. Ödemeyi görünce onaylayın: üyelik hemen açılır, üyeye e-posta gider.</p>
    </header>
    <?php if ($talepler === []): ?>
        <div class="cy-empty"><p class="mb-0">Bekleyen talep yok.</p></div>
    <?php else: ?>
        <ul class="tk-talepler">
            <?php foreach ($talepler as $t): ?>
                <?php $uzatma = $t['rol'] === 'editor'; ?>
                <li class="tk-talep">
                    <div class="tk-talep__kim">
                        <strong><?= e(trim($t['ad'] . ' ' . $t['soyad'])) ?></strong>
                        <small class="cy-muted">@<?= e((string) $t['kullanici_adi']) ?> · <?= e((string) $t['eposta']) ?></small>
                        <span class="tk-talep__istek">
                            <span class="cy-pill <?= $uzatma ? 'is-info' : 'is-warn' ?>"><?= $uzatma ? 'Uzatma' : 'Yeni Pro' ?></span>
                            <?= (int) $t['sure_ay'] ?> ay<?= $aylik > 0 ? ' · ' . e(Planlar::tutar($aylik * (int) $t['sure_ay'])) : '' ?> · <?= e(human_date((string) $t['created_at'])) ?>
                        </span>
                        <?php if ((string) $t['mesaj'] !== ''): ?>
                            <span class="tk-talep__not">“<?= e((string) $t['mesaj']) ?>”</span>
                        <?php endif; ?>
                    </div>
                    <div class="tk-talep__eylem">
                        <form method="post" action="<?= e(url('panel/transkript/uyelikler/ver')) ?>" class="tk-satir-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="talep_id" value="<?= (int) $t['id'] ?>">
                            <label class="visually-hidden" for="tk_onay_ay_<?= (int) $t['id'] ?>">Süre</label>
                            <select class="form-select form-select-sm" id="tk_onay_ay_<?= (int) $t['id'] ?>" name="ay"><?= $sureSecenekleri((int) $t['sure_ay']) ?></select>
                            <label class="visually-hidden" for="tk_onay_tutar_<?= (int) $t['id'] ?>">Alınan tutar</label>
                            <input class="form-control form-control-sm" id="tk_onay_tutar_<?= (int) $t['id'] ?>" name="tutar" inputmode="decimal" maxlength="15"
                                   placeholder="Tutar" value="<?= $aylik > 0 ? e(str_replace('.', ',', (string) ($aylik * (int) $t['sure_ay']))) : '' ?>">
                            <label class="visually-hidden" for="tk_onay_not_<?= (int) $t['id'] ?>">Ödeme notu</label>
                            <input class="form-control form-control-sm" id="tk_onay_not_<?= (int) $t['id'] ?>" name="odeme_notu" maxlength="255" placeholder="Not (dekont no…)">
                            <button type="submit" class="btn cy-btn cy-btn--primary cy-btn--sm"><?= icon('check', 'cy-icon cy-icon--sm') ?> Onayla</button>
                        </form>
                        <details class="tk-reddet">
                            <summary class="cy-link small">Reddet</summary>
                            <form method="post" action="<?= e(url('panel/transkript/uyelikler/reddet')) ?>" class="tk-satir-form mt-2">
                                <?= csrf_field() ?>
                                <input type="hidden" name="talep_id" value="<?= (int) $t['id'] ?>">
                                <label class="visually-hidden" for="tk_red_<?= (int) $t['id'] ?>">Üyeye not</label>
                                <input class="form-control form-control-sm" id="tk_red_<?= (int) $t['id'] ?>" name="yanit" maxlength="500" placeholder="Üyeye not (ör. ödeme görünmedi)">
                                <button type="submit" class="btn cy-btn cy-btn--ghost cy-btn--sm">Reddet</button>
                            </form>
                        </details>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<div class="row g-3 mb-3">
    <!-- PRO VER -->
    <div class="col-12 col-xl-5">
        <section class="cy-card h-100" aria-labelledby="tk-ver-baslik">
            <header class="cy-card__header">
                <h3 class="cy-section-title mb-1" id="tk-ver-baslik"><?= icon('plus', 'cy-icon cy-icon--sm') ?> Pro üyelik ver</h3>
                <p class="cy-muted small mb-0">Talep olmadan, örneğin ödemeyi başka yoldan aldıysanız. Kullanıcının süren Pro'su varsa yeni süre bitişin üstüne eklenir.</p>
            </header>
            <form method="post" action="<?= e(url('panel/transkript/uyelikler/ver')) ?>" class="cy-card__body" novalidate>
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label" for="tk_ver_kullanici">Kullanıcı</label>
                    <input class="form-control" id="tk_ver_kullanici" name="kullanici" maxlength="190" required autocomplete="off" spellcheck="false"
                           value="<?= e($onKullanici) ?>" placeholder="kullanıcı adı ya da e-posta" aria-describedby="tk_ver_kullanici_aciklama">
                    <div class="form-text" id="tk_ver_kullanici_aciklama">Üye ya da Pro hesabı; yönetici hesaplarına plan verilmez.</div>
                </div>
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label" for="tk_ver_ay">Süre</label>
                        <select class="form-select" id="tk_ver_ay" name="ay"><?= $sureSecenekleri(1) ?></select>
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="tk_ver_tutar">Alınan tutar <span class="cy-muted small">(<?= e(Planlar::paraBirimi()) ?>)</span></label>
                        <input class="form-control" id="tk_ver_tutar" name="tutar" inputmode="decimal" maxlength="15" placeholder="isteğe bağlı">
                    </div>
                </div>
                <div class="mt-3">
                    <label class="form-label" for="tk_ver_not">Ödeme notu <span class="cy-muted small">(isteğe bağlı)</span></label>
                    <input class="form-control" id="tk_ver_not" name="odeme_notu" maxlength="255" placeholder="Ör. havale, dekont no, kampanya">
                    <div class="form-text">Yalnız bu ekranda görünür; üyeye gösterilmez.</div>
                </div>
                <button type="submit" class="btn cy-btn cy-btn--primary mt-3"><?= icon('star', 'cy-icon cy-icon--sm') ?> Pro yap</button>
            </form>
        </section>
    </div>

    <!-- PRO ÜYELER -->
    <div class="col-12 col-xl-7">
        <section class="cy-card h-100" aria-labelledby="tk-pro-baslik">
            <header class="cy-card__header">
                <h3 class="cy-section-title mb-1" id="tk-pro-baslik"><?= icon('users', 'cy-icon cy-icon--sm') ?> Pro üyeler</h3>
                <p class="cy-muted small mb-0">Süresi dolan otomatik olarak Ücretsiz plana döner ve e-posta alır. Kullanıcılar ekranından elle "Pro Üye" yapılan hesap süresizdir.</p>
            </header>
            <?php if ($proUyeler === []): ?>
                <div class="cy-empty"><p class="mb-0">Henüz Pro üye yok.</p></div>
            <?php else: ?>
                <ul class="tk-prolar">
                    <?php foreach ($proUyeler as $u): ?>
                        <?php
                        $suresiz = (int) $u['donem'] === 0 || (int) $u['suresiz'] === 1;
                        $kalan   = !$suresiz && $u['bitis'] !== null ? max(0, (int) ceil(((int) strtotime((string) $u['bitis']) - time()) / 86400)) : null;
                        $adi     = trim($u['ad'] . ' ' . $u['soyad']);
                        ?>
                        <li class="tk-pro">
                            <div class="tk-pro__kim">
                                <strong><?= e($adi) ?></strong>
                                <small class="cy-muted">@<?= e((string) $u['kullanici_adi']) ?></small>
                            </div>
                            <div class="tk-pro__sure">
                                <?php if ($suresiz): ?>
                                    <span class="cy-pill is-info">Süresiz</span>
                                <?php else: ?>
                                    <span class="cy-pill <?= $kalan !== null && $kalan <= 3 ? 'is-warn' : 'is-ok' ?>"><?= e($tarih((string) $u['bitis'])) ?></span>
                                    <small class="cy-muted"><?= $kalan === 0 ? 'bugün bitiyor' : e((string) $kalan) . ' gün kaldı' ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="tk-pro__eylem">
                                <?php if (!$suresiz || (int) $u['donem'] === 0): ?>
                                    <details class="tk-uzat">
                                        <summary class="btn cy-btn cy-btn--ghost cy-btn--sm"><?= icon('refresh', 'cy-icon cy-icon--sm') ?> <?= (int) $u['donem'] === 0 ? 'Süre ver' : 'Uzat' ?></summary>
                                        <form method="post" action="<?= e(url('panel/transkript/uyelikler/ver')) ?>" class="tk-satir-form mt-2">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="kullanici_id" value="<?= (int) $u['id'] ?>">
                                            <label class="visually-hidden" for="tk_uzat_ay_<?= (int) $u['id'] ?>">Süre</label>
                                            <select class="form-select form-select-sm" id="tk_uzat_ay_<?= (int) $u['id'] ?>" name="ay"><?= $sureSecenekleri(1) ?></select>
                                            <label class="visually-hidden" for="tk_uzat_tutar_<?= (int) $u['id'] ?>">Alınan tutar</label>
                                            <input class="form-control form-control-sm" id="tk_uzat_tutar_<?= (int) $u['id'] ?>" name="tutar" inputmode="decimal" maxlength="15" placeholder="Tutar">
                                            <button type="submit" class="btn cy-btn cy-btn--primary cy-btn--sm">Kaydet</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                                <form method="post" action="<?= e(url('panel/transkript/uyelikler/kaldir')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="kullanici_id" value="<?= (int) $u['id'] ?>">
                                    <button type="submit" class="cy-btn-icon cy-btn-icon--danger js-tk-form-onay" title="Pro'yu kaldır" aria-label="<?= e($adi) ?>: Pro'yu kaldır"
                                            data-onay="<?= e($adi) ?> Ücretsiz plana düşecek: süren dönemleri kapatılır, API anahtarları silinir, e-posta gider. Raporları ve kütüphanesi kalır."
                                            data-onay-baslik="Pro'yu kaldır" data-onay-dugme="Evet, kaldır" data-onay-tehlike="1"><?= icon('trash', 'cy-icon cy-icon--sm') ?></button>
                                </form>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>

<!-- ÖDEME GEÇMİŞİ -->
<section class="cy-card mb-3" aria-labelledby="tk-gecmis-baslik">
    <header class="cy-card__header">
        <h3 class="cy-section-title mb-0" id="tk-gecmis-baslik"><?= icon('clock', 'cy-icon cy-icon--sm') ?> Son 30 Pro dönemi</h3>
    </header>
    <?php if ($donemler === []): ?>
        <div class="cy-empty"><p class="mb-0">Henüz dönem yok.</p></div>
    <?php else: ?>
        <div class="cy-table-wrap">
            <table class="table cy-table cy-table--cards w-100">
                <thead>
                    <tr>
                        <th scope="col" data-kart="ana">Kullanıcı</th>
                        <th scope="col">Dönem</th>
                        <th scope="col">Tutar</th>
                        <th scope="col">Durum</th>
                        <th scope="col">Not</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($donemler as $d): ?>
                        <tr>
                            <td><?= e(trim((string) ($d['adi'] ?? '')) !== '' ? (string) $d['adi'] : 'Silinmiş kullanıcı') ?></td>
                            <td><small><?= e($tarih((string) $d['baslangic'])) ?> → <?= e($d['bitis'] !== null ? $tarih((string) $d['bitis']) : 'süresiz') ?></small></td>
                            <td><?= $d['tutar'] !== null ? e(Planlar::tutar((float) $d['tutar'], (string) $d['para_birimi'])) : '—' ?></td>
                            <td><?= match ((string) $d['durum']) {
                                'aktif' => '<span class="cy-pill is-ok">Aktif</span>',
                                'bitti' => '<span class="cy-pill">Bitti</span>',
                                default => '<span class="cy-pill is-danger">Kapatıldı</span>',
                            } ?></td>
                            <td><small class="cy-muted"><?= e(trim((string) $d['odeme_notu']) !== '' ? (string) $d['odeme_notu'] : '—') ?><?= ($d['olusturan_adi'] ?? null) !== null ? ' · ' . e((string) $d['olusturan_adi']) : '' ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<!-- GEÇERLİ PLANLAR -->
<section class="mb-3" aria-labelledby="tk-gecerli-baslik">
    <div class="tk-bolum-baslik">
        <h3 class="cy-section-title mb-1" id="tk-gecerli-baslik">Şu an geçerli planlar</h3>
        <p class="cy-muted small mb-0">Üyelerin gördüğü kartlar. Sınırları, fiyatı ve ödeme talimatını
            <a class="cy-link" href="<?= e(url('panel/transkript/ayarlar#uyelik')) ?>">Ayarlar → Üyelik planları</a>'ndan değiştirin.</p>
    </div>
    <details class="tk-acilir">
        <summary class="btn cy-btn cy-btn--ghost cy-btn--sm"><?= icon('eye', 'cy-icon cy-icon--sm') ?> Üyelerin gördüğü plan kartlarını göster</summary>
        <div class="mt-3"><?php View::partial('Transkript::_parcalar/plan-tablosu', ['karsilastirma' => $karsilastirma]); ?></div>
    </details>
</section>

<?php View::partial('Transkript::_parcalar/onay-penceresi'); ?>

<script src="<?= e(Varliklar::adres('transkript.js')) ?>" defer></script>
