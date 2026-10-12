<?php
/** @var \OCP\IL10N $l */
declare(strict_types=1);
?>
<div id="app-content" class="wc-app" data-wc-i18n="<?php p(json_encode($_['i18n'] ?? [])); ?>">
	<a class="wc-skip-link" href="#wc-main"><?php p($l->t('Skip to content')); ?></a>
	<div class="wc-app-wrap" id="wc-main">
		<h1><?php p($l->t('WhistleCheck')); ?></h1>
		<p><?php p($l->t('Manage the whistleblowing channel inside the app. Add officers to the group')); ?> <code>whistlecheck_officers</code>.</p>
		<p><a class="wc-btn-primary" href="<?php p($_['urlGenerator']->linkToRoute('whistlecheck.page.settingsIndex')); ?>"><?php p($l->t('Open WhistleCheck settings')); ?></a></p>
	</div>
</div>
