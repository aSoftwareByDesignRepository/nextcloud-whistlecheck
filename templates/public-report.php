<?php
/** @var \OCP\IL10N $l */
/** @var array $_ */
declare(strict_types=1);

$cats = $_['categories'] ?? [];
$fields = $_['fields'] ?? [];
?>
<div id="content" class="wc-public" data-wc-page="report"
	data-wc-api-base="<?php p($_['api_base'] ?? ''); ?>"
	data-wc-officers="<?php p(json_encode($_['officers'] ?? [])); ?>"
	data-wc-i18n="<?php p(json_encode($_['i18n'] ?? [])); ?>">
	<main class="wc-public-main" id="main-content">
		<a class="wc-skip" href="#wc-report-form"><?php p($l->t('Skip to report form')); ?></a>

		<header class="wc-public-header">
			<h1><?php p($l->t('Report a concern')); ?></h1>
			<?php if (!empty($_['org'])): ?>
				<p class="wc-org"><?php p($_['org']); ?></p>
			<?php endif; ?>
			<p class="wc-e2e-badge" data-wc-e2e-badge><?php p($l->t('Checking encryption…')); ?></p>
			<noscript>
				<p class="wc-honesty" role="note"><?php p($l->t('JavaScript is off: your report will be encrypted on our server instead of in your browser. End-to-end encryption requires JavaScript.')); ?></p>
			</noscript>
		</header>

		<?php if (!empty($_['error'])): ?>
			<div id="wc-form-error" class="wc-error" role="alert" tabindex="-1"><?php p($_['error']); ?></div>
		<?php else: ?>
			<div id="wc-form-error" class="wc-error" role="alert" tabindex="-1" hidden></div>
		<?php endif; ?>

		<?php if (!empty($_['welcome'])): ?>
			<p class="wc-welcome"><?php p($_['welcome']); ?></p>
		<?php endif; ?>

		<form id="wc-report-form" method="post" action="" enctype="multipart/form-data"
			data-max-mb="<?php p((string)($_['max_attachment_mb'] ?? '10')); ?>">
			<input type="hidden" name="form_token" value="<?php p($_['form_token'] ?? ''); ?>">
			<!-- Honeypot — invisible to humans, bots fill it. -->
			<div class="wc-hp" aria-hidden="true">
				<label><?php p($l->t('Website')); ?> <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
			</div>

			<div class="wc-field">
				<label for="wc-title"><?php p($l->t('Subject (optional)')); ?></label>
				<input id="wc-title" name="title" type="text" maxlength="300" autocomplete="off">
			</div>

			<?php if (!empty($cats)): ?>
			<div class="wc-field">
				<label for="wc-category"><?php p($l->t('Category')); ?></label>
				<select id="wc-category" name="category_id">
					<option value=""><?php p($l->t('Choose…')); ?></option>
					<?php foreach ($cats as $c): ?>
						<option value="<?php p((string)$c['id']); ?>"><?php p($c['label'][$l->getLanguageCode()] ?? $c['label']['en'] ?? ''); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<?php endif; ?>

			<div class="wc-field">
				<label for="wc-body"><?php p($l->t('What happened?')); ?> <span aria-hidden="true">*</span></label>
				<textarea id="wc-body" name="body" rows="10" required
					aria-describedby="wc-body-hint"></textarea>
				<p id="wc-body-hint" class="wc-hint"><?php p($l->t('Describe the facts. Do not include your name if you wish to stay anonymous.')); ?></p>
			</div>

			<?php if (!empty($_['attachments_enabled'])): ?>
			<div class="wc-field">
				<label for="wc-attach"><?php p($l->t('Attachments (optional)')); ?></label>
				<input id="wc-attach" name="attachments[]" type="file" multiple
					accept="image/png,image/jpeg,image/webp,image/gif,application/pdf,text/plain"
					aria-describedby="wc-attach-hint">
				<p id="wc-attach-hint" class="wc-hint"><?php p($l->t('Images and PDFs only. Metadata is removed before encryption.')); ?></p>
			</div>
			<?php endif; ?>

			<details class="wc-contact-opt">
				<summary><?php p($l->t('Optional: tell us how to reach you')); ?></summary>
				<p class="wc-hint wc-warn"><?php p($l->t('Warning: providing contact details may reduce your anonymity.')); ?></p>
				<div class="wc-field">
					<label for="wc-contact"><?php p($l->t('Contact details (optional)')); ?></label>
					<textarea id="wc-contact" name="contact" rows="3" maxlength="2000"></textarea>
				</div>
			</details>

			<button type="submit" class="wc-btn-primary"><?php p($l->t('Send report')); ?></button>
		</form>

		<section id="wc-code-panel" class="wc-code-panel" hidden tabindex="-1" aria-live="polite">
			<h2><?php p($l->t('Your case code')); ?></h2>
			<p class="wc-code-value" aria-label="<?php p($l->t('Case code')); ?>"></p>
			<p><?php p($l->t('Write this code down and keep it safe. It is shown only once and cannot be recovered. You need it to read answers.')); ?></p>
		</section>
	</main>
</div>
