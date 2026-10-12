<?php
/** @var \OCP\IL10N $l */
declare(strict_types=1);
?>
<div id="app-content" class="wc-app" data-wc-i18n="<?php p(json_encode($_['i18n'] ?? [])); ?>" data-wc-page="officers"
	data-wc-uid="<?php p($_['wc_uid'] ?? ''); ?>">
	<div class="wc-app-wrap">
		<h1><?php p($l->t('Case officers')); ?></h1>
		<section data-wc-keyreg hidden class="wc-panel">
			<h2><?php p($l->t('Set up your key')); ?></h2>
			<p><?php p($l->t('Generate your encryption key. The private key stays in this browser, wrapped by your passphrase. The server only sees the public key. If you lose the passphrase and no other officer remains, case content is unrecoverable.')); ?></p>
			<button type="button" class="wc-btn-primary" data-wc-genkey><?php p($l->t('Generate my key')); ?></button>
		</section>
		<table class="wc-table">
			<thead><tr>
				<th scope="col"><?php p($l->t('User')); ?></th>
				<th scope="col"><?php p($l->t('Fingerprint')); ?></th>
				<th scope="col"><?php p($l->t('Status')); ?></th>
				<th scope="col"><?php p($l->t('Key version')); ?></th>
				<th scope="col"><span class="hidden-visually"><?php p($l->t('Actions')); ?></span></th>
			</tr></thead>
			<tbody data-wc-officers></tbody>
		</table>
	</div>
</div>
