<?php
/** @var \OCP\IL10N $l */
declare(strict_types=1);
$_['wc_nav_current'] = 'cases';
$_['wc_page'] = 'inbox';
$_['wc_page_title'] = $l->t('Case inbox');
$_['wc_page_help'] = $l->t('End-to-end encrypted reports from anonymous reporters.');
include __DIR__ . '/common/page-start.php';
?>
		<section class="wc-panel wc-unlock" data-wc-unlock hidden
			aria-labelledby="wc-unlock-heading">
			<h2 id="wc-unlock-heading"><?php p($l->t('Unlock your key')); ?></h2>
			<p class="wc-hint"><?php p($l->t('Case contents stay encrypted until you unlock your key with your passphrase.')); ?></p>
			<div class="wc-field">
				<label for="wc-unlock-pass"><?php p($l->t('Passphrase')); ?></label>
				<input id="wc-unlock-pass" type="password" autocomplete="current-password" data-wc-unlock-pass>
			</div>
			<p class="wc-field-error" data-wc-unlock-err role="alert" hidden></p>
			<button type="button" class="wc-btn-primary" data-wc-unlock-go><?php p($l->t('Unlock')); ?></button>
		</section>

		<p class="wc-hint" data-wc-inbox-hint><?php p($l->t('Entries stay encrypted until you open them.')); ?></p>
		<div class="wc-table-wrap">
			<table class="wc-table wc-table-cards">
				<thead>
					<tr>
						<th scope="col"><?php p($l->t('Case')); ?></th>
						<th scope="col"><?php p($l->t('Status')); ?></th>
						<th scope="col"><?php p($l->t('Feedback due')); ?></th>
						<th scope="col"><?php p($l->t('Received')); ?></th>
						<th scope="col"><?php p($l->t('Assigned')); ?></th>
					</tr>
				</thead>
				<tbody data-wc-inbox aria-busy="true">
					<tr><td colspan="5" class="wc-loading"><?php p($l->t('Loading…')); ?></td></tr>
				</tbody>
			</table>
		</div>
		<p class="wc-hint" data-wc-truncated hidden><?php p($l->t('Showing the 500 most recent cases.')); ?></p>
<?php include __DIR__ . '/common/page-end.php'; ?>
