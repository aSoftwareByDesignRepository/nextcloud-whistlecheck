<?php
/** @var \OCP\IL10N $l */
declare(strict_types=1);
?>
<div id="app-content" class="wc-app" data-wc-i18n="<?php p(json_encode($_['i18n'] ?? [])); ?>" data-wc-page="case" data-wc-case-id="<?php p((string)($_['caseId'] ?? 0)); ?>"
	data-wc-uid="<?php p($_['wc_uid'] ?? ''); ?>"
	data-wc-officers="<?php p(json_encode($_['officers'] ?? [])); ?>"
	data-wc-revision="0">
	<div class="wc-app-wrap">
		<nav aria-label="breadcrumb"><a href="<?php p($_['urlGenerator']->linkToRoute('whistlecheck.page.inbox')); ?>">← <?php p($l->t('Inbox')); ?></a></nav>
		<h1 class="wc-case-title"><?php p($l->t('Case')); ?> #<?php p((string)($_['caseId'] ?? 0)); ?></h1>
		<p><?php p($l->t('Status:')); ?> <strong class="wc-status" data-wc-status-badge></strong></p>

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
				<textarea id="wc-reply" name="body" rows="5"></textarea>
			</div>
			<button type="submit" class="wc-btn-primary"><?php p($l->t('Send (encrypted)')); ?></button>
		</form>

		<h2><?php p($l->t('Internal notes')); ?></h2>
		<ul data-wc-notes class="wc-notes"></ul>
		<form data-wc-note-form>
			<div class="wc-field">
				<label for="wc-note"><?php p($l->t('New note (encrypted, officers only)')); ?></label>
				<textarea id="wc-note" name="note" rows="3"></textarea>
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
