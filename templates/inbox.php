<?php
/** @var \OCP\IL10N $l */
declare(strict_types=1);
?>
<div id="app-content" class="wc-app" data-wc-i18n="<?php p(json_encode($_['i18n'] ?? [])); ?>" data-wc-page="inbox"
	data-wc-uid="<?php p($_['wc_uid'] ?? ''); ?>"
	data-wc-officers="<?php p(json_encode($_['officers'] ?? [])); ?>">
	<div class="wc-app-wrap">
		<header class="wc-app-header">
			<h1><?php p($l->t('Case inbox')); ?></h1>
			<nav class="wc-nav" aria-label="<?php p($l->t('WhistleCheck')); ?>">
				<a href="<?php p($_['urlGenerator']->linkToRoute('whistlecheck.page.inbox')); ?>"><?php p($l->t('Cases')); ?></a>
				<a href="<?php p($_['urlGenerator']->linkToRoute('whistlecheck.page.officers')); ?>"><?php p($l->t('Officers')); ?></a>
				<a href="<?php p($_['urlGenerator']->linkToRoute('whistlecheck.page.audit')); ?>"><?php p($l->t('Audit')); ?></a>
				<a href="<?php p($_['urlGenerator']->linkToRoute('whistlecheck.page.stats')); ?>"><?php p($l->t('Statistics')); ?></a>
				<a href="<?php p($_['urlGenerator']->linkToRoute('whistlecheck.page.verbal')); ?>"><?php p($l->t('Verbal channel')); ?></a>
				<a href="<?php p($_['urlGenerator']->linkToRoute('whistlecheck.page.settingsIndex')); ?>"><?php p($l->t('Settings')); ?></a>
			</nav>
		</header>
		<p class="wc-hint"><?php p($l->t('Unlock your key to see case titles. Entries stay encrypted until you open them.')); ?></p>
		<table class="wc-table">
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('Case')); ?></th>
					<th scope="col"><?php p($l->t('Status')); ?></th>
					<th scope="col"><?php p($l->t('Feedback due')); ?></th>
					<th scope="col"><?php p($l->t('Received')); ?></th>
					<th scope="col"><?php p($l->t('Assigned')); ?></th>
				</tr>
			</thead>
			<tbody data-wc-inbox></tbody>
		</table>
	</div>
</div>
