<?php
/** @var \OCP\IL10N $l */
/** @var array $_ */
declare(strict_types=1);
$cfg = $_['config'] ?? [];
$rev = $_['revisions'] ?? [];
$section = $_['section'] ?? 'channel';
$val = static fn (string $k) => $cfg[$k] ?? '';
$rv = static fn (string $k) => (string)($rev[$k] ?? 0);
?>
<div id="app-content" class="wc-app" data-wc-i18n="<?php p(json_encode($_['i18n'] ?? [])); ?>" data-wc-page="<?php p($section === 'categories' ? 'categories' : ($section === 'companion' ? 'companion' : 'settings')); ?>"
	data-wc-uid="<?php p($_['wc_uid'] ?? ''); ?>">
	<div class="wc-app-wrap">
		<h1><?php p($l->t('WhistleCheck settings')); ?></h1>
		<nav class="wc-nav" aria-label="<?php p($l->t('Settings sections')); ?>">
			<?php foreach (['channel', 'categories', 'attachments', 'retention', 'access', 'companion', 'support'] as $s): ?>
				<a href="<?php p($_['urlGenerator']->linkToRoute('whistlecheck.page.settings', ['section' => $s])); ?>"
					<?php if ($s === $section): ?>aria-current="page"<?php endif; ?>><?php p($l->t(ucfirst($s))); ?></a>
			<?php endforeach; ?>
		</nav>

		<?php if ($section === 'categories'): ?>
			<h2><?php p($l->t('Categories')); ?></h2>
			<table class="wc-table"><tbody data-wc-cats></tbody></table>
			<form data-wc-cat-form>
				<div class="wc-field"><label for="wc-cat-de"><?php p($l->t('Label (German)')); ?></label>
					<input id="wc-cat-de" name="label_de" maxlength="200" required></div>
				<div class="wc-field"><label for="wc-cat-en"><?php p($l->t('Label (English)')); ?></label>
					<input id="wc-cat-en" name="label_en" maxlength="200" required></div>
				<button type="submit" class="wc-btn-primary"><?php p($l->t('Add category')); ?></button>
			</form>

		<?php elseif ($section === 'companion'): ?>
			<h2><?php p($l->t('Companion app devices')); ?></h2>
			<ul data-wc-devices></ul>
			<form data-wc-pair-form>
				<div class="wc-field"><label for="wc-dev-name"><?php p($l->t('Device name')); ?></label>
					<input id="wc-dev-name" name="name" maxlength="64" required></div>
				<button type="submit" class="wc-btn-primary"><?php p($l->t('Pair device')); ?></button>
			</form>
			<div data-wc-token-panel hidden>
				<h3><?php p($l->t('Device token (shown once)')); ?></h3>
				<code data-wc-token></code>
				<p class="wc-hint"><?php p($l->t('Copy this into the companion app now. It will not be shown again.')); ?></p>
			</div>

		<?php elseif ($section === 'support'): ?>
			<h2><?php p($l->t('Support')); ?></h2>
			<p><a href="https://github.com/aSoftwareByDesignRepository/nextcloud-whistlecheck/issues" rel="noopener noreferrer"><?php p($l->t('Report a bug')); ?></a></p>
			<p><a href="mailto:info@software-by-design.de"><?php p($l->t('Contact support')); ?></a></p>

		<?php else: ?>
			<form data-wc-settings-form>
				<?php if ($section === 'channel'): ?>
					<div class="wc-field"><label for="wc-org"><?php p($l->t('Organisation name')); ?></label>
						<input id="wc-org" data-wc-config="org_name" data-wc-revision="<?php p($rv('org_name')); ?>" value="<?php p($val('org_name')); ?>" maxlength="2000"></div>
					<div class="wc-field"><label for="wc-welcome"><?php p($l->t('Welcome text (shown on the report page)')); ?></label>
						<textarea id="wc-welcome" data-wc-config="welcome_text" data-wc-revision="<?php p($rv('welcome_text')); ?>" rows="4" maxlength="2000"><?php p($val('welcome_text')); ?></textarea></div>
					<div class="wc-field"><label for="wc-esc"><?php p($l->t('Escalation officer (uid)')); ?></label>
						<input id="wc-esc" data-wc-config="escalation_uid" data-wc-revision="<?php p($rv('escalation_uid')); ?>" value="<?php p($val('escalation_uid')); ?>"></div>
				<?php elseif ($section === 'attachments'): ?>
					<label><input type="checkbox" data-wc-config="attachments_enabled" data-wc-revision="<?php p($rv('attachments_enabled')); ?>" <?php if ($val('attachments_enabled') === '1'): ?>checked<?php endif; ?>> <?php p($l->t('Allow attachments')); ?></label>
					<div class="wc-field"><label for="wc-maxmb"><?php p($l->t('Max attachment size (MB)')); ?></label>
						<input id="wc-maxmb" type="number" min="1" max="50" data-wc-config="max_attachment_mb" data-wc-revision="<?php p($rv('max_attachment_mb')); ?>" value="<?php p($val('max_attachment_mb')); ?>"></div>
				<?php elseif ($section === 'retention'): ?>
					<div class="wc-field"><label for="wc-ret"><?php p($l->t('Retention after close (months, 1–120)')); ?></label>
						<input id="wc-ret" type="number" min="1" max="120" data-wc-config="retention_months" data-wc-revision="<?php p($rv('retention_months')); ?>" value="<?php p($val('retention_months')); ?>"></div>
					<div class="wc-field"><label for="wc-rem"><?php p($l->t('Reminder before deadline (days)')); ?></label>
						<input id="wc-rem" type="number" min="0" max="90" data-wc-config="reminder_days" data-wc-revision="<?php p($rv('reminder_days')); ?>" value="<?php p($val('reminder_days')); ?>"></div>
				<?php elseif ($section === 'access'): ?>
					<label><input type="checkbox" data-wc-config="nojs_enabled" data-wc-revision="<?php p($rv('nojs_enabled')); ?>" <?php if ($val('nojs_enabled') === '1'): ?>checked<?php endif; ?>> <?php p($l->t('Allow no-JavaScript submission (server-side encryption, honestly flagged)')); ?></label>
					<div class="wc-field"><label for="wc-pow"><?php p($l->t('PoW escalation after N hits over limit')); ?></label>
						<input id="wc-pow" type="number" min="1" max="100" data-wc-config="require_pow_after" data-wc-revision="<?php p($rv('require_pow_after')); ?>" value="<?php p($val('require_pow_after')); ?>"></div>
				<?php endif; ?>
				<button type="submit" class="wc-btn-primary"><?php p($l->t('Save')); ?></button>
			</form>
		<?php endif; ?>
	</div>
</div>
