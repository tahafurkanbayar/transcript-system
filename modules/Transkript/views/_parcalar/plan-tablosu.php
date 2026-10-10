<?php
/**
 * =====================================================================
 *  PARÇA: Plan karşılaştırma kartları (Ücretsiz · Pro)
 * ---------------------------------------------------------------------
 *  Veri Uyelik\Planlar::karsilastirma(); Üyeliğim ve Üyelikler aynı
 *  kartları çizer. Masaüstünde yan yana, telefonda alt alta.
 *
 *  @var list<array{etiket:string,aciklama:string,degerler:array<string,string|bool>}> $karsilastirma
 *  @var string|null                       $etkinPlan  kullanıcının planı (kart "Planınız" rozetiyle)
 *  @var array<string,array{0:string,1:string}> $eylemler   plan => [adres, etiket] (kartın altındaki düğme)
 * =====================================================================
 */

use Modules\Transkript\Uyelik\Planlar;

$etkinPlan ??= null;
$eylemler  ??= [];
$ozetler = [
    Planlar::UCRETSIZ => 'Kayıt olan herkes. Sınırlı sayıda video analizi.',
    Planlar::PRO      => 'Düzenli kullananlar için: daha çok analiz, daha uzun video ve ek özellikler.',
];
?>
<div class="tk-planlar">
    <?php foreach (Planlar::ADLAR as $plan => $ad): ?>
        <?php
        $fiyat  = $plan === Planlar::PRO ? Planlar::fiyatMetni($plan) : '';
        $yillik = $plan === Planlar::PRO ? Planlar::fiyatMetni($plan, 'yillik') : '';
        ?>
        <article class="tk-plan<?= $plan === Planlar::PRO ? ' tk-plan--pro' : '' ?><?= $plan === $etkinPlan ? ' is-etkin' : '' ?>" aria-labelledby="tk-plan-<?= e($plan) ?>">
            <header class="tk-plan__ust">
                <div class="tk-plan__ad">
                    <h3 id="tk-plan-<?= e($plan) ?>"><?= icon($plan === Planlar::PRO ? 'star' : 'user', 'cy-icon cy-icon--sm') ?> <?= e($ad) ?></h3>
                    <?php if ($plan === $etkinPlan): ?><span class="tk-plan__rozet">Planınız</span><?php endif; ?>
                </div>
                <p class="tk-plan__fiyat">
                    <?php if ($plan === Planlar::UCRETSIZ): ?>
                        <strong>0 <?= e(Planlar::paraBirimi() === 'TL' ? '₺' : Planlar::PARA_BIRIMLERI[Planlar::paraBirimi()]) ?></strong>
                    <?php elseif ($fiyat !== ''): ?>
                        <strong><?= e($fiyat) ?></strong>
                        <?php if ($yillik !== ''): ?><small>ya da <?= e($yillik) ?></small><?php endif; ?>
                    <?php else: ?>
                        <strong class="tk-plan__fiyat--sor">Fiyat için iletişime geçin</strong>
                    <?php endif; ?>
                </p>
                <p class="tk-plan__ozet"><?= e($ozetler[$plan] ?? '') ?></p>
            </header>
            <ul class="tk-plan__liste">
                <?php foreach ($karsilastirma as $satir): ?>
                    <?php $deger = $satir['degerler'][$plan] ?? false; ?>
                    <li class="<?= $deger === false ? 'is-yok' : 'is-var' ?>">
                        <?= icon($deger === false ? 'close' : 'check', 'cy-icon cy-icon--sm') ?>
                        <span>
                            <?= e($satir['etiket']) ?><?php if (is_string($deger)): ?>: <strong><?= e($deger) ?></strong><?php endif; ?>
                            <small><?= e($satir['aciklama']) ?></small>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if (isset($eylemler[$plan])): ?>
                <footer class="tk-plan__alt">
                    <a class="btn cy-btn <?= $plan === Planlar::PRO ? 'cy-btn--primary' : 'cy-btn--ghost' ?> cy-btn--block" href="<?= e($eylemler[$plan][0]) ?>"><?= e($eylemler[$plan][1]) ?></a>
                </footer>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</div>
