<?php
/** @var \OCP\IL10N $l */
declare(strict_types=1);
$_['wc_nav_current'] = 'verbal';
?>
<div id="app-content" class="wc-app" data-wc-i18n="<?php p(json_encode($_['i18n'] ?? [])); ?>"
	data-wc-page="verbal" data-wc-uid="<?php p($_['wc_uid'] ?? ''); ?>">
	<a class="wc-skip-link" href="#wc-main"><?php p($l->t('Skip to content')); ?></a>
	<div class="wc-app-wrap" id="wc-main">
		<header class="wc-app-header">
			<h1><?php p($l->t('Verbal channel')); ?></h1>
			<?php include __DIR__ . '/parts/nav.php'; ?>
		</header>
		<p class="wc-hint"><?php p($l->t('Copy this text into a case reply when a reporter asks for a phone or video call. The appointment is arranged inside the encrypted thread.')); ?></p>
		<h2>Deutsch</h2>
		<blockquote data-wc-verbal-de class="wc-quote"></blockquote>
		<p><button type="button" class="wc-btn-secondary" data-wc-copy="de"><?php p($l->t('Copy German text')); ?></button></p>
		<h2>English</h2>
		<blockquote data-wc-verbal-en class="wc-quote"></blockquote>
		<p><button type="button" class="wc-btn-secondary" data-wc-copy="en"><?php p($l->t('Copy English text')); ?></button></p>
	</div>
</div>
