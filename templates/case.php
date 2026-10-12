<?php
/** @var \OCP\IL10N $l */
declare(strict_types=1);
$_['wc_nav_current'] = 'cases';
?>
<div id="app-content" class="wc-app" data-wc-i18n="<?php p(json_encode($_['i18n'] ?? [])); ?>"
	data-wc-page="case" data-wc-case-id="<?php p((string)($_['caseId'] ?? 0)); ?>"
	data-wc-uid="<?php p($_['wc_uid'] ?? ''); ?>"
	data-wc-officers="<?php p(json_encode($_['officers'] ?? [])); ?>"
	data-wc-revision="0">
	<a class="wc-skip-link" href="#wc-main"><?php p($l->t('Skip to content')); ?></a>
	<div class="wc-app-wrap" id="wc-main">
		<header class="wc-app-header">
			<h1 class="wc-case-title"><?php p($l->t('Case')); ?> #<?php p((string)($_['caseId'] ?? 0)); ?></h1>
			<?php include __DIR__ . '/parts/nav.php'; ?>
		</header>
		<p><?php p($l->t('Status:')); ?> <strong class="wc-status" data-wc-status-badge></strong></p>
		<p class="wc-pending-close" data-wc-close-pending role="status" hidden>
			<span data-wc-close-pending-text></span>
		</p>

		<section class="wc-panel wc-unlock" data-wc-unlock hidden
			aria-labelledby="wc-unlock-heading">
			<h2 id="wc-unlock-heading"><?php p($l->t('Unlock your key')); ?></h2>
			<p class="wc-hint"><?php p($l->t('This case is end-to-end encrypted. Enter your passphrase to read it.')); ?></p>
			<div class="wc-field">
				<label for="wc-unlock-pass"><?php p($l->t('Passphrase')); ?></label>
				<input id="wc-unlock-pass" type="password" autocomplete="current-password" data-wc-unlock-pass>
			</div>
			<p class="wc-field-error" data-wc-unlock-err role="alert" hidden></p>
			<button type="button" class="wc-btn-primary" data-wc-unlock-go><?php p($l->t('Unlock')); ?></button>
		</section>

		<div data-wc-case-body aria-busy="true">
		<p class="wc-loading" data-wc-loading><?php p($l->t('Loading…')); ?></p>

		<div class="wc-status-actions" role="group" aria-label="<?php p($l->t('Set status')); ?>">
			<?php foreach (['in_review', 'action_taken', 'closed', 'archived'] as $s): ?>
				<button type="button" class="wc-btn-secondary" data-wc-status="<?php p($s); ?>"><?php p($l->t($s)); ?></button>
			<?php endforeach; ?>
		</div>

		<div class="wc-field wc-assign">
			<label for="wc-assignee"><?php p($l->t('Assigned to')); ?></label>
			<select id="wc-assignee" data-wc-assign>
				<option value=""><?php p($l->t('Unassigned')); ?></option>
				<?php foreach (($_['officers'] ?? []) as $o): ?>
					<option value="<?php p($o['uid']); ?>"><?php p($o['uid']); ?></option>
				<?php endforeach; ?>
			</select>
		</div>

		<div data-wc-contact-block hidden>
			<h2><?php p($l->t('Contact (self-disclosed)')); ?></h2>
			<p data-wc-contact></p>
		</div>

		<h2><?php p($l->t('Messages')); ?></h2>
		<ul data-wc-messages class="wc-messages"></ul>

		<h2><?php p($l->t('Attachments')); ?></h2>
		<ul data-wc-attachments></ul>

		<form data-wc-reply-form>
			<div class="wc-field">
				<label for="wc-reply"><?php p($l->t('Reply to reporter')); ?></label>
				<textarea id="wc-reply" name="body" rows="5" required></textarea>
			</div>
			<button type="submit" class="wc-btn-primary"><?php p($l->t('Send (encrypted)')); ?></button>
		</form>

		<section class="wc-panel" data-wc-grant hidden aria-labelledby="wc-grant-heading">
			<h2 id="wc-grant-heading"><?php p($l->t('Grant access')); ?></h2>
			<p class="wc-hint"><?php p($l->t('These officers can no longer read this case (for example after a key change without re-encryption). Granting re-seals the case key to their current key.')); ?></p>
			<ul class="wc-grant-list" data-wc-grant-list></ul>
		</section>

		<h2><?php p($l->t('Internal notes')); ?></h2>
		<ul data-wc-notes class="wc-notes"></ul>
		<form data-wc-note-form>
			<div class="wc-field">
				<label for="wc-note"><?php p($l->t('New note (encrypted, officers only)')); ?></label>
				<textarea id="wc-note" name="note" rows="3" required></textarea>
			</div>
			<button type="submit" class="wc-btn-secondary"><?php p($l->t('Save note')); ?></button>
		</form>

		<details>
			<summary><?php p($l->t('Legal hold')); ?></summary>
			<form data-wc-hold-form>
				<label><input type="checkbox" name="hold" value="1"> <?php p($l->t('Pause retention deletion')); ?></label>
				<div class="wc-field">
					<label for="wc-hold-reason"><?php p($l->t('Reason')); ?></label>
					<input id="wc-hold-reason" name="reason" type="text" maxlength="255">
				</div>
				<button type="submit" class="wc-btn-secondary"><?php p($l->t('Save hold')); ?></button>
			</form>
		</details>

		<p><a class="wc-btn-secondary" download href="<?php p($_['urlGenerator']->linkToRoute('whistlecheck.api.auditExport', ['id' => $_['caseId'] ?? 0])); ?>"><?php p($l->t('Export sealed evidence (PDF)')); ?></a></p>
		</div>
	</div>
</div>
