<?php
/**
 * =====================================================================
 *  PARÇA: Kontrol Paneli → "Son analizler" (yalnız yönetici)
 * ---------------------------------------------------------------------
 *  events.php'deki DashboardPanels sağlayıcısı çizer. Çekirdeğin "Son
 *  mesajlar" listesiyle aynı düzen (cy-people). Bütün metin e() ile.
 *
 *  @var list<array<string,mixed>> $analizler KaynakRepository::sonAnalizler()
 * =====================================================================
 */

use Modules\Transkript\HataKodlari;
use Modules\Transkript\Services\RaporVerisi;

$durumAdi = [
    'bekliyor'            => ['Sırada', ''],
    'transkript_aliniyor' => ['Transcript alınıyor', 'is-info'],
    'transkript_hazir'    => ['Transcript hazır', 'is-info'],
    'analiz_ediliyor'     => ['Analiz ediliyor', 'is-info'],
    'tamamlandi'          => ['Tamamlandı', 'is-ok'],
    'hata'                => ['Hata', 'is-danger'],
    'iptal'               => ['İptal edildi', ''],
];
?>
<section class="cy-panel">
    <header class="cy-panel__head">
        <h3 class="cy-panel__title"><?= icon('file', 'cy-icon cy-icon--sm') ?> Son analizler</h3>
        <a class="cy-link small" href="<?= e(url('panel/transkript')) ?>">Tümü →</a>
    </header>
    <ul class="cy-people">
        <?php foreach ($analizler as $a): ?>
            <?php
            $iptal = $a['durum'] === 'hata' && $a['hata_kodu'] === 'iptal_edildi';
            [$metin, $renk] = $durumAdi[$iptal ? 'iptal' : (string) $a['durum']] ?? [(string) $a['durum'], ''];
            $kim  = trim((string) ($a['baslatan'] ?? '')) !== '' ? (string) $a['baslatan'] : (string) ($a['kullanici_adi'] ?? 'silinmiş kullanıcı');
            $bitti = in_array($a['durum'], ['tamamlandi', 'hata'], true);
            ?>
            <li class="cy-people__row">
                <span class="cy-avatar cy-avatar--sm cy-avatar--initial"><?= e(mb_strtoupper(mb_substr($kim, 0, 1, 'UTF-8'), 'UTF-8')) ?></span>
                <span class="cy-people__body">
                    <strong><a class="cy-link" href="<?= e(url('panel/transkript/' . (int) $a['kaynak_id'])) ?>"><?= e((string) ($a['baslik'] ?: $a['dis_kimlik'])) ?></a></strong>
                    <small>
                        <?= e($kim) ?> · <?= e(human_date((string) $a['created_at'])) ?>
                        <?php if ($bitti && $a['sure_sn'] !== null): ?> · <?= e(RaporVerisi::sure((int) $a['sure_sn'])) ?> sürdü<?php endif; ?>
                        <?php if ($a['durum'] === 'hata' && !$iptal): ?> · <?= e(HataKodlari::gorunenMesaj($a['hata_kodu'], true, true)) ?><?php endif; ?>
                    </small>
                </span>
                <span class="cy-pill <?= e($renk) ?>"><?= e($metin) ?><?= !$bitti ? ' %' . (int) $a['ilerleme'] : '' ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if ($analizler === []): ?>
        <p class="cy-panel__empty">Henüz analiz yok.</p>
    <?php endif; ?>
</section>
