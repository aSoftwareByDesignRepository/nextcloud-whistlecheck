<?php
/** @var \OCP\IL10N $l */
declare(strict_types=1);
$_['wc_nav_current'] = 'officers';
$_['wc_page'] = 'officers';
$_['wc_page_title'] = $l->t('Case officers');
$_['wc_page_help'] = $l->t('Manage officer keys and access to encrypted cases.');
include __DIR__ . '/common/page-start.php';
?>
		<section data-wc-keyreg class="wc-panel" aria-labelledby="wc-keyreg-heading">
			<h2 id="wc-keyreg-heading" data-wc-keyreg-title><?php p($l->t('Set up your key')); ?></h2>
			<p data-wc-keyreg-copy><?php p($l->t('Generate your encryption key. The private key stays in this browser, wrapped by your passphrase. The server only sees the public key. If you lose the passphrase and no other officer remains, case content is unrecoverable.')); ?></p>
			<p class="wc-honesty" data-wc-rotate-warn hidden></p>
			<div class="wc-field" data-wc-oldpass-wrap hidden>
				<label for="wc-oldpass"><?php p($l->t('Current passphrase (keeps your cases accessible)')); ?></label>
				<input id="wc-oldpass" type="password" autocomplete="current-password" data-wc-oldpass>
			</div>
			<div class="wc-field">
				<label for="wc-newpass"><?php p($l->t('New passphrase (at least 10 characters)')); ?></label>
				<input id="wc-newpass" type="password" autocomplete="new-password" data-wc-newpass>
			</div>
			<div class="wc-field">
				<label for="wc-newpass2"><?php p($l->t('Repeat new passphrase')); ?></label>
				<input id="wc-newpass2" type="password" autocomplete="new-password" data-wc-newpass2>
			</div>
			<p class="wc-field-error" data-wc-keyreg-err role="alert" hidden></p>
			<button type="button" class="wc-btn-primary" data-wc-genkey><?php p($l->t('Generate my key')); ?></button>
		</section>

		<div class="wc-list-head">
			<h2 class="wc-list-head__title"><?php p($l->t('All officers')); ?></h2>
		</div>
		<div class="wc-table-wrap">
		<table class="wc-table wc-table--cards">
			<thead><tr>
				<th scope="col"><?php p($l->t('User')); ?></th>
				<th scope="col"><?php p($l->t('Fingerprint')); ?></th>
				<th scope="col"><?php p($l->t('Status')); ?></th>
				<th scope="col"><?php p($l->t('Key version')); ?></th>
				<th scope="col"><span class="hidden-visually"><?php p($l->t('Actions')); ?></span></th>
			</tr></thead>
			<tbody data-wc-officers aria-busy="true">
				<tr><td colspan="5"><p class="wc-loading"><?php p($l->t('Loading…')); ?></p></td></tr>
			</tbody>
		</table>
		</div>
<?php include __DIR__ . '/common/page-end.php'; ?>
