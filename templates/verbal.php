<?php
/** @var \OCP\IL10N $l */
declare(strict_types=1);
$_['wc_nav_current'] = 'verbal';
$_['wc_page'] = 'verbal';
$_['wc_page_title'] = $l->t('Verbal channel');
$_['wc_page_help'] = $l->t('Ready-made text for phone or video appointments.');
include __DIR__ . '/common/page-start.php';
?>
		<p class="wc-hint"><?php p($l->t('Copy this text into a case reply when a reporter asks for a phone or video call. The appointment is arranged inside the encrypted thread.')); ?></p>
		<h2>Deutsch</h2>
		<blockquote data-wc-verbal-de class="wc-quote"></blockquote>
		<p><button type="button" class="wc-btn-secondary" data-wc-copy="de"><?php p($l->t('Copy German text')); ?></button></p>
		<h2>English</h2>
		<blockquote data-wc-verbal-en class="wc-quote"></blockquote>
		<p><button type="button" class="wc-btn-secondary" data-wc-copy="en"><?php p($l->t('Copy English text')); ?></button></p>
<?php include __DIR__ . '/common/page-end.php'; ?>
