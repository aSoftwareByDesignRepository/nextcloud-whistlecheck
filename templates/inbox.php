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

		<section class="wc-stat-strip" data-wc-inbox-stats aria-label="<?php p($l->t('Inbox overview')); ?>" hidden>
			<article class="wc-stat-tile wc-stat-tile--primary">
				<span class="wc-stat-tile__label"><?php p($l->t('Open')); ?></span>
				<span class="wc-stat-tile__value" data-wc-stat-open>0</span>
			</article>
			<article class="wc-stat-tile wc-stat-tile--danger">
				<span class="wc-stat-tile__label"><?php p($l->t('Overdue')); ?></span>
				<span class="wc-stat-tile__value" data-wc-stat-overdue>0</span>
			</article>
			<article class="wc-stat-tile">
				<span class="wc-stat-tile__label"><?php p($l->t('Assigned to me')); ?></span>
				<span class="wc-stat-tile__value" data-wc-stat-mine>0</span>
			</article>
		</section>

		<section class="wc-filter-panel" aria-labelledby="wc-filter-title">
			<header class="wc-filter-panel__head">
				<h2 id="wc-filter-title"><?php p($l->t('Filter cases')); ?></h2>
				<p class="wc-filter-panel__intro"><?php p($l->t('Narrow the list — changes apply as you type.')); ?></p>
			</header>
			<div class="wc-filter-panel__body">
				<form class="wc-filter-panel__form" role="search" data-wc-inbox-filter novalidate
					aria-label="<?php p($l->t('Filter cases')); ?>">
					<div class="wc-filter-grid">
						<div class="wc-filter-field">
							<label class="wc-filter-field__label" for="wc-f-q"><?php p($l->t('Search')); ?></label>
							<div class="wc-filter-field__control">
								<input id="wc-f-q" name="q" type="search"
									placeholder="<?php p($l->t('Title or case number')); ?>">
							</div>
						</div>
						<div class="wc-filter-field">
							<label class="wc-filter-field__label" for="wc-f-status"><?php p($l->t('Status')); ?></label>
							<div class="wc-filter-field__control">
								<select id="wc-f-status" name="status">
									<option value=""><?php p($l->t('All statuses')); ?></option>
									<option value="received"><?php p($l->t('Received')); ?></option>
									<option value="in_review"><?php p($l->t('In review')); ?></option>
									<option value="action_taken"><?php p($l->t('Action taken')); ?></option>
									<option value="closed"><?php p($l->t('Closed')); ?></option>
									<option value="archived"><?php p($l->t('Archived')); ?></option>
								</select>
							</div>
						</div>
						<div class="wc-filter-field">
							<span class="wc-filter-field__label" id="wc-f-mine-g"><?php p($l->t('Assigned')); ?></span>
							<div class="wc-filter-field__control">
								<label class="wc-checkbox-row" for="wc-f-mine">
									<input id="wc-f-mine" type="checkbox" name="mine" value="1">
									<span><?php p($l->t('Only cases assigned to me')); ?></span>
								</label>
							</div>
						</div>
						<div class="wc-filter-field wc-filter-field--actions">
							<button type="submit" class="wc-btn-primary"><?php p($l->t('Apply')); ?></button>
							<button type="reset" class="wc-btn-secondary"><?php p($l->t('Clear')); ?></button>
						</div>
					</div>
				</form>
			</div>
		</section>

		<div class="wc-list-head">
			<h2 class="wc-list-head__title"><?php p($l->t('Cases')); ?></h2>
			<span class="wc-list-head__count" data-wc-inbox-count role="status"></span>
		</div>
		<p class="wc-hint" data-wc-inbox-hint><?php p($l->t('Entries stay encrypted until you open them.')); ?></p>
		<div class="wc-table-wrap">
			<table class="wc-table wc-table--cards">
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
					<tr><td colspan="5"><p class="wc-loading"><?php p($l->t('Loading…')); ?></p></td></tr>
				</tbody>
			</table>
		</div>
		<p class="wc-hint" data-wc-truncated hidden><?php p($l->t('Showing the 500 most recent cases.')); ?></p>
<?php include __DIR__ . '/common/page-end.php'; ?>
