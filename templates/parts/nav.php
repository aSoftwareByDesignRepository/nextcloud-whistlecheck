<?php
/** Shared officer navigation — rendered on every app page so no section
 * is a dead end. Expects $_['urlGenerator'] and $_['wc_nav_current']. */
declare(strict_types=1);

/** @var \OCP\IL10N $l */
$l = \OCP\Util::getL10N('whistlecheck');
$wcNavCurrent = (string)($_['wc_nav_current'] ?? '');
$wcNavItems = [
	'cases'    => ['whistlecheck.page.inbox', $l->t('Cases')],
	'officers' => ['whistlecheck.page.officers', $l->t('Officers')],
	'audit'    => ['whistlecheck.page.audit', $l->t('Audit')],
	'stats'    => ['whistlecheck.page.stats', $l->t('Statistics')],
	'verbal'   => ['whistlecheck.page.verbal', $l->t('Verbal channel')],
	'settings' => ['whistlecheck.page.settingsIndex', $l->t('Settings')],
];
?>
<nav class="wc-nav" aria-label="<?php p($l->t('WhistleCheck')); ?>">
	<?php foreach ($wcNavItems as $wcNavKey => $wcNavItem): ?>
		<a href="<?php p($_['urlGenerator']->linkToRoute($wcNavItem[0])); ?>"<?php if ($wcNavKey === $wcNavCurrent): ?> aria-current="page"<?php endif; ?>><?php p($wcNavItem[1]); ?></a>
	<?php endforeach; ?>
</nav>
