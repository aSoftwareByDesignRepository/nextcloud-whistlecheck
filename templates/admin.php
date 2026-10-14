<?php
/** @var \OCP\IL10N $l */
declare(strict_types=1);
?>
<div class="wc-app wc-app--admin">
	<h2><?php p($l->t('WhistleCheck')); ?></h2>
	<p class="wc-hint"><?php p($l->t('Manage the whistleblowing channel inside the app. Add officers to the group')); ?> <code>whistlecheck_officers</code>.</p>
	<p><a class="wc-btn-primary" href="<?php p($_['urlGenerator']->linkToRoute('whistlecheck.page.settingsIndex')); ?>"><?php p($l->t('Open WhistleCheck settings')); ?></a></p>
</div>
