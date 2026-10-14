<?php
/** @var \OCP\IL10N $l */
declare(strict_types=1);
$_['wc_nav_current'] = 'audit';
$_['wc_page'] = 'audit';
$_['wc_page_title'] = $l->t('Audit log');
$_['wc_page_help'] = $l->t('Tamper-evident record of every action on this channel.');
include __DIR__ . '/common/page-start.php';
?>
		<div class="wc-callout" data-wc-chain role="status" aria-live="polite">
			<p class="wc-loading"><?php p($l->t('Verifying chain…')); ?></p>
		</div>
		<div class="wc-list-head">
			<h2 class="wc-list-head__title"><?php p($l->t('Events')); ?></h2>
			<span class="wc-list-head__count" data-wc-audit-count role="status"></span>
		</div>
		<div class="wc-table-wrap">
		<table class="wc-table wc-table--cards">
			<thead><tr>
				<th scope="col">#</th>
				<th scope="col"><?php p($l->t('Event')); ?></th>
				<th scope="col"><?php p($l->t('Actor')); ?></th>
				<th scope="col"><?php p($l->t('Case')); ?></th>
				<th scope="col"><?php p($l->t('Time')); ?></th>
			</tr></thead>
			<tbody data-wc-audit aria-busy="true">
				<tr><td colspan="5"><p class="wc-loading"><?php p($l->t('Loading…')); ?></p></td></tr>
			</tbody>
		</table>
		</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
