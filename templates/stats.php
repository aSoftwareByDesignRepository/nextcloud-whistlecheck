<?php
/** @var \OCP\IL10N $l */
declare(strict_types=1);
$_['wc_nav_current'] = 'stats';
$_['wc_page'] = 'stats';
$_['wc_page_title'] = $l->t('Statistics');
$_['wc_page_help'] = $l->t('Anonymous aggregates — small counts are suppressed to protect reporters.');
include __DIR__ . '/common/page-start.php';
?>
		<div data-wc-stats-body aria-busy="true">
			<p class="wc-loading" data-wc-loading><?php p($l->t('Loading…')); ?></p>
			<section class="wc-stat-strip" aria-label="<?php p($l->t('Case totals')); ?>">
				<article class="wc-stat-tile wc-stat-tile--primary">
					<span class="wc-stat-tile__label"><?php p($l->t('Total cases')); ?></span>
					<span class="wc-stat-tile__value" data-wc-total>0</span>
				</article>
				<article class="wc-stat-tile wc-stat-tile--warning">
					<span class="wc-stat-tile__label"><?php p($l->t('Open')); ?></span>
					<span class="wc-stat-tile__value" data-wc-open>0</span>
				</article>
				<article class="wc-stat-tile wc-stat-tile--danger">
					<span class="wc-stat-tile__label"><?php p($l->t('Overdue')); ?></span>
					<span class="wc-stat-tile__value" data-wc-overdue>0</span>
				</article>
			</section>
			<h2><?php p($l->t('By status')); ?></h2>
			<div class="wc-table-wrap"><table class="wc-table wc-table--cards">
				<thead><tr><th scope="col"><?php p($l->t('Status')); ?></th><th scope="col"><?php p($l->t('Count')); ?></th></tr></thead>
				<tbody data-wc-statstatus></tbody></table></div>
			<h2><?php p($l->t('By category')); ?></h2>
			<p class="wc-hint"><?php p($l->t('Counts below 5 are suppressed to protect reporters.')); ?></p>
			<div class="wc-table-wrap"><table class="wc-table wc-table--cards">
				<thead><tr><th scope="col"><?php p($l->t('Category')); ?></th><th scope="col"><?php p($l->t('Count')); ?></th></tr></thead>
				<tbody data-wc-statcat></tbody></table></div>
		</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
