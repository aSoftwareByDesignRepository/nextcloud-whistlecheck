<?php
/** @var \OCP\IL10N $l */
/** @var array $_ */
declare(strict_types=1);
?>
<div id="content" class="wc-public" data-wc-page="limited"
	data-wc-api-base="<?php p($_['api_base'] ?? ''); ?>"
	data-wc-i18n="<?php p(json_encode($_['i18n'] ?? [])); ?>">
	<main class="wc-public-main" id="main-content">
		<a class="wc-skip" href="#main-content"><?php p($l->t('Skip to content')); ?></a>
		<header class="wc-public-header">
			<h1><?php p($l->t('Please wait a moment')); ?></h1>
			<?php if (!empty($_['org'])): ?><p class="wc-org"><?php p($_['org']); ?></p><?php endif; ?>
		</header>

		<div class="wc-honesty" role="alert">
			<p><?php p($l->t('Too many requests — please try again later.')); ?></p>
			<?php if (!empty($_['retry_after'])): ?>
				<p class="wc-hint"><?php p($l->t('This protects the reporting channel against abuse. You can try again in about {n} seconds.', ['n' => (int)$_['retry_after']])); ?></p>
			<?php endif; ?>
		</div>
	</main>
</div>
