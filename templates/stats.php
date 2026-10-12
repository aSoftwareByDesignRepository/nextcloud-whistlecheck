<?php
/** @var \OCP\IL10N $l */
declare(strict_types=1);
?>
<div id="app-content" class="wc-app" data-wc-i18n="<?php p(json_encode($_['i18n'] ?? [])); ?>" data-wc-page="stats">
	<div class="wc-app-wrap">
		<h1><?php p($l->t('Statistics')); ?></h1>
		<p><?php p($l->t('Total cases:')); ?> <strong data-wc-total>0</strong>
			· <?php p($l->t('Overdue:')); ?> <strong data-wc-overdue>0</strong></p>
		<h2><?php p($l->t('By status')); ?></h2>
		<table class="wc-table"><tbody data-wc-statstatus></tbody></table>
		<h2><?php p($l->t('By category')); ?></h2>
		<p class="wc-hint"><?php p($l->t('Counts below 5 are suppressed to protect reporters.')); ?></p>
		<table class="wc-table"><tbody data-wc-statcat></tbody></table>
	</div>
</div>
