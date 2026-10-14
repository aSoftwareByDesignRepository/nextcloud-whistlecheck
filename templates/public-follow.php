<?php
/** @var \OCP\IL10N $l */
/** @var array $_ */
declare(strict_types=1);
$fields = $_['fields'] ?? [];
?>
<div id="content" class="wc-public" data-wc-page="follow"
	data-wc-api-base="<?php p($_['api_base'] ?? ''); ?>"
	data-wc-i18n="<?php p(json_encode($_['i18n'] ?? [])); ?>">
	<main class="wc-public-main" id="main-content">
		<a class="wc-skip" href="#wc-follow-form"><?php p($l->t('Skip to case access')); ?></a>
		<header class="wc-public-header">
			<h1><?php p($l->t('Check your case')); ?></h1>
			<?php if (!empty($_['org'])): ?><p class="wc-org"><?php p($_['org']); ?></p><?php endif; ?>
		</header>

		<?php if (!empty($_['error'])): ?>
			<div id="wc-form-error" class="wc-error" role="alert" tabindex="-1"><?php p($_['error']); ?></div>
		<?php else: ?>
			<div id="wc-form-error" class="wc-error" role="alert" tabindex="-1" hidden></div>
		<?php endif; ?>
		<?php if (!empty($_['notice'])): ?>
			<div class="wc-notice" role="status"><?php p($_['notice']); ?></div>
		<?php endif; ?>

		<form id="wc-follow-form" method="post" action="">
			<input type="hidden" name="form_token" value="<?php p($_['form_token'] ?? ''); ?>">
			<div class="wc-field">
				<label for="wc-code"><?php p($l->t('Case code')); ?></label>
				<input id="wc-code" name="code" type="text" required autocomplete="off" spellcheck="false"
					inputmode="text" autocapitalize="characters" maxlength="31" value="<?php p($_['code'] ?? ''); ?>"
					aria-describedby="wc-code-hint" aria-invalid="<?php p(!empty($_['error']) ? 'true' : 'false'); ?>">
				<p id="wc-code-hint" class="wc-hint"><?php p($l->t('The 26-character code shown after your report, e.g. XXXXX-XXXXX-…')); ?></p>
			</div>
			<button type="submit" class="wc-btn-primary"><?php p($l->t('Open case')); ?></button>
		</form>

		<section id="wc-thread" class="wc-thread" hidden tabindex="-1">
			<h2><?php p($l->t('Your case')); ?></h2>
			<p><?php p($l->t('Status:')); ?> <strong class="wc-status"></strong>
			 · <?php p($l->t('Feedback due:')); ?> <span class="wc-due"></span></p>
			<ul class="wc-messages"></ul>
			<p class="wc-hint" data-wc-readonly-note role="note" hidden><?php p($l->t('This case is closed. The procedure has ended.')); ?></p>
			<form id="wc-reply-form" hidden>
				<div class="wc-field">
					<label for="wc-reply-body"><?php p($l->t('Reply')); ?></label>
					<textarea id="wc-reply-body" name="body" rows="4"></textarea>
				</div>
				<button type="submit" class="wc-btn-primary"><?php p($l->t('Send reply')); ?></button>
			</form>
		</section>
	</main>
</div>
