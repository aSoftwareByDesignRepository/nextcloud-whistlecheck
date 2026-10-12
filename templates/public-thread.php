<?php
/** @var \OCP\IL10N $l */
/** @var array $_ */
declare(strict_types=1);
$view = $_['view'] ?? ['messages' => []];
?>
<div id="content" class="wc-public" data-wc-page="thread"
	data-wc-api-base="<?php p($_['api_base'] ?? ''); ?>"
	data-wc-i18n="<?php p(json_encode($_['i18n'] ?? [])); ?>">
	<main class="wc-public-main" id="main-content">
		<h1><?php p($l->t('Your case')); ?></h1>
		<?php if (empty($view['e2e'])): ?>
			<p class="wc-honesty" role="note"><?php p($l->t('Decrypted on the server (JavaScript off).')); ?></p>
		<?php endif; ?>
		<p><?php p($l->t('Status:')); ?> <strong><?php p($view['status']); ?></strong>
			· <?php p($l->t('Feedback due:')); ?> <?php p($view['feedback_due']); ?></p>

		<ul class="wc-messages">
			<?php foreach ($view['messages'] as $m): ?>
				<li class="wc-msg wc-msg-<?php p($m['direction'] === 'reporter' ? 'me' : 'them'); ?>">
					<?php if (trim((string)$m['text']) === 'wchk:receipt'): ?>
						<p><?php p($l->t('Your report was received. Case officers can now read and answer it.')); ?></p>
					<?php else: ?>
						<p><?php p($m['text']); /* sanitized + escaped plaintext */ ?></p>
					<?php endif; ?>
					<time datetime="<?php p($m['at']); ?>"><?php p($m['at']); ?></time>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php if (empty($view['readonly'])): ?>
		<form method="post" action="<?php p($_['urlGenerator']->linkToRoute('whistlecheck.publicPage.followReply')); ?>">
			<input type="hidden" name="form_token" value="<?php p($_['form_token'] ?? ''); ?>">
			<input type="hidden" name="code" value="<?php p($_['code'] ?? ''); ?>">
			<div class="wc-field">
				<label for="wc-reply-body"><?php p($l->t('Reply')); ?></label>
				<textarea id="wc-reply-body" name="body" rows="4" required></textarea>
			</div>
			<button type="submit" class="wc-btn-primary"><?php p($l->t('Send reply')); ?></button>
		</form>
		<?php else: ?>
			<p class="wc-hint" role="note"><?php p($l->t('This case is closed. The procedure has ended.')); ?></p>
		<?php endif; ?>
	</main>
</div>
