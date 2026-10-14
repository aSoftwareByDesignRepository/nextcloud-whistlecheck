<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * WhistleCheck officer page chrome — opens the shared app shell.
 * Renders the sidebar navigation, #app-content root, skip links, live
 * regions, breadcrumb, the icon-well page header and opens <main>.
 *
 * Per-page contract (variables read from $_):
 *  - wc_page        (string, required) value for data-wc-page (boots the JS init)
 *  - wc_page_title  (string, required) already translated
 *  - wc_page_help   (string, optional) lead under the title
 *  - wc_header_icon (string, optional) IconCatalog name for the header well
 *  - wc_extra_attrs (array, optional)  extra data-* attributes on #app-content,
 *                                     name => scalar, printed as data-{name}
 *  - wc_nav_current (string)           sidebar item to mark active
 *  - section        (string)           settings section, for the sub-nav
 *
 * Shared params from PageController::render(): i18n, wc_uid, officers,
 * urlGenerator.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\WhistleCheck\Service\IconCatalog;

$l = \OCP\Util::getL10N('whistlecheck');
$pageId = (string)($_['wc_page'] ?? '');
$pageTitle = (string)($_['wc_page_title'] ?? $l->t('WhistleCheck'));
$pageTitleClass = (string)($_['wc_title_class'] ?? '');
$pageHelp = (string)($_['wc_page_help'] ?? '');
$extraAttrs = is_array($_['wc_extra_attrs'] ?? null) ? $_['wc_extra_attrs'] : [];
$wcUid = (string)($_['wc_uid'] ?? '');

$pageIcons = [
	'inbox' => 'inbox',
	'case' => 'message-square',
	'officers' => 'users',
	'audit' => 'scroll-text',
	'stats' => 'bar-chart',
	'verbal' => 'phone',
	'settings' => 'settings',
	'categories' => 'settings',
	'companion' => 'smartphone',
	'admin' => 'shield-check',
];
$headerIconName = (string)($_['wc_header_icon'] ?? ($pageIcons[$pageId] ?? 'shield-check'));
?>
<?php include __DIR__ . '/navigation.php'; ?>
<div id="app-content" class="wc-app wc-app--<?php p($pageId); ?>"
	data-wc-i18n="<?php p(json_encode($_['i18n'] ?? [])); ?>"
	data-wc-page="<?php p($pageId); ?>"
	data-wc-uid="<?php p($wcUid); ?>"
	data-wc-officers="<?php p(json_encode($_['officers'] ?? [])); ?>"
	<?php foreach ($extraAttrs as $attrName => $attrValue): ?>
		data-<?php p((string)$attrName); ?>="<?php p((string)$attrValue); ?>"
	<?php endforeach; ?>>
	<a class="wc-skip-link" href="#wc-main"><?php p($l->t('Skip to content')); ?></a>
	<a class="wc-skip-link wc-skip-link--nav" href="#app-navigation"><?php p($l->t('Skip to navigation')); ?></a>
	<div id="wc-live-region" class="hidden-visually" role="status" aria-live="polite" aria-atomic="true"></div>
	<div id="wc-alert-region" class="hidden-visually" role="alert" aria-live="assertive" aria-atomic="true"></div>
	<div id="app-content-wrapper" class="wc-shell">
		<header class="wc-page-header" role="group" aria-labelledby="wc-page-title">
			<button type="button"
				class="wc-nav-toggle"
				id="wc-nav-toggle"
				aria-controls="app-navigation"
				aria-expanded="false"
				aria-label="<?php p($l->t('Open navigation menu')); ?>"
				data-aria-label-open="<?php p($l->t('Open navigation menu')); ?>"
				data-aria-label-close="<?php p($l->t('Close navigation menu')); ?>">
				<span class="wc-nav-toggle__icon" aria-hidden="true">
					<?php print_unescaped(IconCatalog::render('menu', 'wc-nav-toggle__icon-svg')); ?>
				</span>
				<span class="wc-nav-toggle__label"><?php p($l->t('Menu')); ?></span>
			</button>
			<nav class="wc-breadcrumb" aria-label="<?php p($l->t('Breadcrumb')); ?>">
				<ol>
					<li><a class="wc-breadcrumb__brand" href="<?php p($_['urlGenerator']->linkToRoute('whistlecheck.page.inbox')); ?>"><?php p($l->t('WhistleCheck')); ?></a></li>
					<li class="wc-breadcrumb__sep" aria-hidden="true">/</li>
					<li class="wc-breadcrumb__current" aria-current="page"><?php p($pageTitle); ?></li>
				</ol>
			</nav>
			<div class="wc-page-header__main">
				<div class="wc-page-header__icon" aria-hidden="true">
					<?php print_unescaped(IconCatalog::render($headerIconName, 'wc-page-header__icon-svg')); ?>
				</div>
				<div class="wc-page-header__text">
					<h1 id="wc-page-title"<?php if ($pageTitleClass !== ''): ?> class="<?php p($pageTitleClass); ?>"<?php endif; ?>><?php p($pageTitle); ?></h1>
					<?php if ($pageHelp !== ''): ?>
						<p class="wc-page-header__lead"><?php p($pageHelp); ?></p>
					<?php endif; ?>
				</div>
				<div id="wc-page-actions" class="wc-page-header__actions" aria-live="polite"></div>
			</div>
			<?php if ($wcUid !== ''): ?>
				<div class="wc-scope-strip" aria-label="<?php p($l->t('Signed-in context')); ?>">
					<span class="wc-scope-strip__name"><?php p($wcUid); ?></span>
					<span class="wc-scope-strip__badge wc-badge wc-badge--primary"><?php p($l->t('Case officer')); ?></span>
				</div>
			<?php endif; ?>
		</header>
		<main id="wc-main" class="wc-main wc-app-wrap" tabindex="-1" aria-labelledby="wc-page-title">
