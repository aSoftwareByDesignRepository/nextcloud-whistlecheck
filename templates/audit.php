<?php
/** @var \OCP\IL10N $l */
declare(strict_types=1);
$_['wc_nav_current'] = 'audit';
?>
<div id="app-content" class="wc-app" data-wc-i18n="<?php p(json_encode($_['i18n'] ?? [])); ?>"
	data-wc-page="audit" data-wc-uid="<?php p($_['wc_uid'] ?? ''); ?>">
	<a class="wc-skip-link" href="#wc-main"><?php p($l->t('Skip to content')); ?></a>
	<div class="wc-app-wrap" id="wc-main">
		<header class="wc-app-header">
			<h1><?php p($l->t('Audit log')); ?></h1>
			<?php include __DIR__ . '/parts/nav.php'; ?>
		</header>
		<p><?php p($l->t('Integrity:')); ?> <strong data-wc-chain></strong></p>
		<table class="wc-table">
			<thead><tr>
				<th scope="col">#</th>
				<th scope="col"><?php p($l->t('Event')); ?></th>
				<th scope="col"><?php p($l->t('Actor')); ?></th>
				<th scope="col"><?php p($l->t('Case')); ?></th>
				<th scope="col"><?php p($l->t('Time')); ?></th>
			</tr></thead>
			<tbody data-wc-audit aria-busy="true">
				<tr><td colspan="5" class="wc-loading"><?php p($l->t('Loading…')); ?></td></tr>
			</tbody>
		</table>
	</div>
</div>
