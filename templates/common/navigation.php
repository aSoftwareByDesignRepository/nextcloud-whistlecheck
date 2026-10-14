<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * WhistleCheck sidebar navigation (#app-navigation).
 * Expects $_['urlGenerator'], $_['wc_nav_current'], and — while on the
 * settings pages — $_['section'].
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\WhistleCheck\Service\IconCatalog;

$l = \OCP\Util::getL10N('whistlecheck');
$navCurrent = (string)($_['wc_nav_current'] ?? '');
$section = (string)($_['section'] ?? 'channel');

$navItems = [
	'cases' => ['whistlecheck.page.inbox', 'inbox', $l->t('Cases'), $l->t('Incoming reports')],
	'officers' => ['whistlecheck.page.officers', 'users', $l->t('Officers'), $l->t('Keys and access')],
	'audit' => ['whistlecheck.page.audit', 'scroll-text', $l->t('Audit'), $l->t('Tamper-evident log')],
	'stats' => ['whistlecheck.page.stats', 'bar-chart', $l->t('Statistics'), $l->t('Anonymous counts')],
	'verbal' => ['whistlecheck.page.verbal', 'phone', $l->t('Verbal channel'), $l->t('Call text blocks')],
	'settings' => ['whistlecheck.page.settingsIndex', 'settings', $l->t('Settings'), $l->t('Channel behaviour')],
];

$settingsChildren = [];
if ($navCurrent === 'settings') {
	foreach (['channel', 'categories', 'attachments', 'retention', 'access', 'companion', 'support'] as $s) {
		$settingsChildren[] = [
			'url' => $_['urlGenerator']->linkToRoute('whistlecheck.page.settings', ['section' => $s]),
			'label' => $l->t(ucfirst($s)),
			'active' => $s === $section,
		];
	}
}
?>
<nav id="app-navigation" class="wc-nav" role="navigation" aria-label="<?php p($l->t('WhistleCheck')); ?>">
	<div class="wc-brand">
		<span class="wc-brand__icon" aria-hidden="true">
			<?php print_unescaped(IconCatalog::render('shield-check', 'wc-brand__icon-svg')); ?>
		</span>
		<div class="wc-brand__text">
			<h2 class="wc-brand__title"><?php p($l->t('WhistleCheck')); ?></h2>
			<p class="wc-brand__subtitle"><?php p($l->t('Anonymous reporting')); ?></p>
		</div>
	</div>

	<ul class="nav-menu wc-nav__list" aria-label="<?php p($l->t('Main navigation')); ?>">
		<?php foreach ($navItems as $key => $item):
			$active = $key === $navCurrent;
			$children = $key === 'settings' ? $settingsChildren : [];
			// With an expanded sub-list, aria-current belongs to the active
			// child link only; the parent keeps the visual active state.
			$parentAriaCurrent = $active && $children === [];
			?>
			<li class="wc-nav__item <?php p($active ? 'is-active active' : ''); ?>">
				<a class="wc-nav__link" href="<?php p($_['urlGenerator']->linkToRoute($item[0])); ?>"
					<?php if ($parentAriaCurrent): ?>aria-current="page"<?php endif; ?>>
					<span class="wc-nav__icon" aria-hidden="true">
						<?php print_unescaped(IconCatalog::render($item[1])); ?>
					</span>
					<span class="wc-nav__label">
						<span class="wc-nav__name"><?php p($item[2]); ?></span>
						<span class="wc-nav__hint"><?php p($item[3]); ?></span>
					</span>
				</a>
				<?php if ($children !== []): ?>
					<ul class="wc-nav__sublist">
						<?php foreach ($children as $child): ?>
							<li class="wc-nav__subitem <?php p($child['active'] ? 'is-active active' : ''); ?>">
								<a class="wc-nav__sublink" href="<?php p($child['url']); ?>"
									<?php if ($child['active']): ?>aria-current="page"<?php endif; ?>>
									<?php p($child['label']); ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>

	<div class="wc-nav__footer">
		<a class="wc-nav__footer-link" href="<?php p($_['urlGenerator']->linkToRoute('whistlecheck.page.settings', ['section' => 'support'])); ?>">
			<span class="wc-nav__icon" aria-hidden="true">
				<?php print_unescaped(IconCatalog::render('help')); ?>
			</span>
			<span><?php p($l->t('Support')); ?></span>
		</a>
	</div>
</nav>
