<?php
/** @var \OCP\IL10N $l */
declare(strict_types=1);
?>
<div id="app-content" class="wc-app" data-wc-i18n="<?php p(json_encode($_['i18n'] ?? [])); ?>
	<a class="wc-skip-link" href="#wc-main"><?php p($l->t('Skip to content')); ?></a>" data-wc-page="audit">
	<div class="wc-app-wrap" id="wc-main">
		<h1><?php p($l->t('Audit log')); ?></h1>
		<p><?php p($l->t('Integrity:')); ?> <strong data-wc-chain></strong></p>
		<table class="wc-table">
			<thead><tr>
				<th scope="col">#</th>
				<th scope="col"><?php p($l->t('Event')); ?></th>
				<th scope="col"><?php p($l->t('Actor')); ?></th>
				<th scope="col"><?php p($l->t('Case')); ?></th>
				<th scope="col"><?php p($l->t('Time')); ?></th>
			</tr></thead>
			<tbody data-wc-audit></tbody>
		</table>
	</div>
</div>
