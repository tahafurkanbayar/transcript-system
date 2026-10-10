<?php
/**
 * =====================================================================
 *  E-POSTA: üyelik bildirimleri (Uyelik\Bildirim)
 * ---------------------------------------------------------------------
 *  Tek şablon: başlık, paragraflar, isteğe bağlı bilgi tablosu ve
 *  düğme. Bütün metin MailUi ile kaçışlanır (üyenin yazdığı not dahil).
 *
 *  @var string                    $baslik
 *  @var list<string>              $paragraflar
 *  @var array<string,string>      $bilgi
 *  @var array{0:string,1:string}  $dugme  [mutlak adres, etiket]
 * =====================================================================
 */

use App\Core\Mail\MailUi;

?>
<?= MailUi::baslik($baslik) ?>
<?php foreach ($paragraflar as $paragraf): ?>
<?= MailUi::paragraf($paragraf) ?>
<?php endforeach; ?>
<?php if (($bilgi ?? []) !== []): ?>
<?= MailUi::bilgiTablosu($bilgi) ?>
<?php endif; ?>
<?php if (isset($dugme)): ?>
<?= MailUi::dugme($dugme[0], $dugme[1]) ?>
<?php endif; ?>
<?= MailUi::kucukNot('Bu e-posta Transkript üyeliğinizle ilgili olduğu için gönderildi.') ?>
