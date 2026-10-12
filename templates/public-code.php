<?php
/** @var \OCP\IL10N $l */
declare(strict_types=1);
?>
<div id="content" class="wc-public" data-wc-page="code">
		<a class="wc-skip" href="#main-content"><?php p($l->t('Skip to content')); ?></a>
<main class="wc-public-main" id="main-content">
		<section class="wc-code-panel" tabindex="-1" autofocus>
			<h1><?php p($l->t('Report received')); ?></h1>
			<?php if (empty($_['e2e'])): ?>
				<p class="wc-honesty" role="note"><?php p($l->t('This report was encrypted on our server (JavaScript was off).')); ?></p>
			<?php endif; ?>
			<h2><?php p($l->t('Your case code')); ?></h2>
			<p class="wc-code-value"><code><?php p($_['code'] ?? ''); ?></code></p>
			<p><?php p($l->t('Write this code down now — it is shown only once and cannot be recovered. Use it to read replies and to answer questions.')); ?></p>
			<p><a class="wc-btn-secondary" href="<?php p($_['urlGenerator']->linkToRoute('whistlecheck.publicPage.followForm')); ?>"><?php p($l->t('Check your case later')); ?></a></p>
		</section>
	</main>
</div>
