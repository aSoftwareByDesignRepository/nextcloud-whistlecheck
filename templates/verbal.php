<?php
/** @var \OCP\IL10N $l */
declare(strict_types=1);
?>
<div id="app-content" class="wc-app" data-wc-i18n="<?php p(json_encode($_['i18n'] ?? [])); ?>
	<a class="wc-skip-link" href="#wc-main"><?php p($l->t('Skip to content')); ?></a>" data-wc-page="verbal">
	<div class="wc-app-wrap" id="wc-main">
		<h1><?php p($l->t('Verbal channel')); ?></h1>
		<p class="wc-hint"><?php p($l->t('Copy this text into a case reply when a reporter asks for a phone or video call. The appointment is arranged inside the encrypted thread.')); ?></p>
		<h2>Deutsch</h2>
		<blockquote data-wc-verbal-de class="wc-quote"></blockquote>
		<h2>English</h2>
		<blockquote data-wc-verbal-en class="wc-quote"></blockquote>
	</div>
</div>
