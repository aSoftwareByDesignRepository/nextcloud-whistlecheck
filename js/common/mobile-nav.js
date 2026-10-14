/**
 * WhistleCheck mobile navigation: in-page Menu button + slide-in drawer.
 * On mobile open, nav + backdrop portal to document.body so #content
 * overflow cannot clip the drawer (arbeitszeitcheck#33 / Atlas
 * ATLAS_MOBILE_NAV_CONTRACT). Core #app-navigation-toggle alone is not enough.
 *
 * @license AGPL-3.0-or-later
 */
(function () {
	'use strict';

	const APP_ID = 'whistlecheck';
	const DESKTOP_MQ = '(min-width: 1024px)';
	const BACKDROP_ID = 'wc-nav-backdrop';
	const FOCUSABLE_SELECTOR = [
		'a[href]',
		'button:not([disabled])',
		'input:not([disabled])',
		'select:not([disabled])',
		'textarea:not([disabled])',
		'[tabindex]:not([tabindex="-1"])',
	].join(', ');

	let initialized = false;
	let setOpenFn = null;

	function translate(key, fallback) {
		if (typeof t === 'function') {
			const value = t(APP_ID, key);
			if (value && value !== key) {
				return value;
			}
		}
		return fallback;
	}

	function disableCoreToggle() {
		const coreToggle = document.getElementById('app-navigation-toggle');
		if (!coreToggle) {
			return;
		}
		coreToggle.setAttribute('aria-hidden', 'true');
		coreToggle.setAttribute('tabindex', '-1');
		coreToggle.style.display = 'none';
		coreToggle.style.pointerEvents = 'none';
	}

	function init() {
		if (initialized) {
			return;
		}

		const toggle = document.getElementById('wc-nav-toggle');
		const nav = document.getElementById('app-navigation');
		const shellRoot = document.getElementById('content');
		const appContent = document.getElementById('app-content');
		if (!toggle || !nav || !shellRoot || !appContent) {
			return;
		}
		initialized = true;

		document.body.classList.add('wc-has-nav');
		document.body.classList.remove('snapjs-left');
		disableCoreToggle();

		let backdrop = document.getElementById(BACKDROP_ID);
		if (!backdrop) {
			backdrop = document.createElement('div');
			backdrop.id = BACKDROP_ID;
			backdrop.className = 'wc-nav-backdrop';
			backdrop.hidden = true;
			shellRoot.insertBefore(backdrop, appContent);
		}

		const shell = document.querySelector('#app-content.wc-app > #app-content-wrapper.wc-shell')
			|| document.querySelector('#app-content.wc-app > .wc-shell');
		const openLabel = toggle.getAttribute('data-aria-label-open')
			|| translate('Open navigation menu', 'Open navigation menu');
		const closeLabel = toggle.getAttribute('data-aria-label-close')
			|| translate('Close navigation menu', 'Close navigation menu');

		let trapHandler = null;
		const desktopMq = window.matchMedia(DESKTOP_MQ);

		function isMobileViewport() {
			return !desktopMq.matches;
		}

		function restoreDomOrder() {
			if (nav.parentElement !== shellRoot) {
				shellRoot.insertBefore(nav, appContent);
			}
			if (backdrop.parentElement !== shellRoot) {
				shellRoot.insertBefore(backdrop, appContent);
			}
		}

		function portalDrawerToBody() {
			document.body.appendChild(backdrop);
			document.body.appendChild(nav);
		}

		function getFocusableNavItems() {
			return Array.from(nav.querySelectorAll(FOCUSABLE_SELECTOR));
		}

		function setMainInert(inert) {
			if (!shell) {
				return;
			}
			if (inert) {
				shell.setAttribute('inert', '');
				shell.setAttribute('aria-hidden', 'true');
			} else {
				shell.removeAttribute('inert');
				shell.removeAttribute('aria-hidden');
			}
		}

		function setOpen(open) {
			const mobile = isMobileViewport();

			if (open && mobile) {
				portalDrawerToBody();
			} else {
				restoreDomOrder();
			}

			nav.classList.toggle('wc-nav--open', open);
			toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
			toggle.setAttribute('aria-label', open ? closeLabel : openLabel);
			document.body.classList.toggle('wc-nav-open', open);
			document.body.classList.remove('snapjs-left');
			backdrop.hidden = !open;
			setMainInert(open && mobile);

			if (mobile) {
				nav.setAttribute('aria-hidden', open ? 'false' : 'true');
			} else {
				nav.removeAttribute('aria-hidden');
			}

			if (open) {
				const items = getFocusableNavItems();
				if (items.length > 0) {
					items[0].focus();
				}
				trapHandler = function (event) {
					if (!nav.classList.contains('wc-nav--open') || event.key !== 'Tab') {
						return;
					}
					const focusables = getFocusableNavItems();
					if (focusables.length === 0) {
						return;
					}
					const first = focusables[0];
					const last = focusables[focusables.length - 1];
					if (event.shiftKey && document.activeElement === first) {
						event.preventDefault();
						last.focus();
					} else if (!event.shiftKey && document.activeElement === last) {
						event.preventDefault();
						first.focus();
					}
				};
				document.addEventListener('keydown', trapHandler);
			} else if (trapHandler) {
				document.removeEventListener('keydown', trapHandler);
				trapHandler = null;
			}
		}

		setOpenFn = setOpen;

		toggle.addEventListener('click', (event) => {
			event.preventDefault();
			event.stopPropagation();
			setOpen(!nav.classList.contains('wc-nav--open'));
		});

		backdrop.addEventListener('click', () => {
			setOpen(false);
			toggle.focus();
		});

		nav.querySelectorAll('a').forEach((link) => {
			link.addEventListener('click', () => setOpen(false));
		});

		document.addEventListener('keydown', (event) => {
			if (event.key === 'Escape' && nav.classList.contains('wc-nav--open')) {
				setOpen(false);
				toggle.focus();
			}
		});

		function onViewportChange() {
			disableCoreToggle();
			if (desktopMq.matches) {
				setOpen(false);
				restoreDomOrder();
				nav.removeAttribute('aria-hidden');
			} else if (!nav.classList.contains('wc-nav--open')) {
				restoreDomOrder();
				nav.setAttribute('aria-hidden', 'true');
			}
		}
		if (typeof desktopMq.addEventListener === 'function') {
			desktopMq.addEventListener('change', onViewportChange);
		} else if (typeof desktopMq.addListener === 'function') {
			desktopMq.addListener(onViewportChange);
		}
		onViewportChange();

		const observer = new MutationObserver(() => {
			if (typeof document === 'undefined') {
				return;
			}
			disableCoreToggle();
		});
		observer.observe(document.body, { childList: true, subtree: true });
	}

	function boot() {
		init();
		if (!initialized) {
			window.setTimeout(init, 0);
		}
	}

	window.WhistleCheckMobileNav = {
		init: boot,
		close() {
			if (typeof setOpenFn === 'function') {
				setOpenFn(false);
			}
		},
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
	window.addEventListener('load', boot);
})();
