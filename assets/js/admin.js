(function () {
	'use strict';

	// -------------------------------------------------------------------------
	// URL preset buttons → append to textarea
	// -------------------------------------------------------------------------
	document.addEventListener('click', function (event) {
		var target = event.target;
		if (!target || !target.classList || !target.classList.contains('wpg-url-preset')) {
			return;
		}

		var textarea = document.getElementById('wpg-sim-urls');
		var value = target.getAttribute('data-url') || '';
		if (!textarea || !value) {
			return;
		}

		var current = textarea.value ? textarea.value.split(/\r?\n/) : [];
		if (current.indexOf(value) === -1) {
			current.push(value);
			textarea.value = current.join('\n').replace(/^\n+/, '');
		}
	});

	// -------------------------------------------------------------------------
	// Copy to clipboard — .wpg-copy-btn[data-copy-target="<input-id>"]
	// -------------------------------------------------------------------------
	document.addEventListener('click', function (event) {
		var btn = event.target;
		if (!btn || !btn.classList || !btn.classList.contains('wpg-copy-btn')) {
			return;
		}

		var targetId = btn.getAttribute('data-copy-target');
		if (!targetId) {
			return;
		}

		var el = document.getElementById(targetId);
		if (!el) {
			return;
		}

		var text = el.tagName === 'TEXTAREA' ? el.value : el.value || el.textContent || '';
		if (!text) {
			return;
		}

		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(function () {
				wpgFlashButton(btn, '✓ Copied');
			}).catch(function () {
				wpgFallbackCopy(el, btn);
			});
		} else {
			wpgFallbackCopy(el, btn);
		}
	});

	/**
	 * Fallback copy via execCommand (older browsers / HTTP contexts).
	 *
	 * @param {HTMLElement} el  Input or textarea to copy from.
	 * @param {HTMLElement} btn Button to flash.
	 */
	function wpgFallbackCopy(el, btn) {
		try {
			el.select();
			document.execCommand('copy');
			wpgFlashButton(btn, '✓ Copied');
		} catch (e) {
			// Silent — can't copy.
		}
	}

	/**
	 * Temporarily change a button's text to show feedback, then restore it.
	 *
	 * @param {HTMLElement} btn      The button element.
	 * @param {string}      newLabel Temporary label.
	 * @param {number}      [ms]     Duration in ms (default 1500).
	 */
	function wpgFlashButton(btn, newLabel, ms) {
		var original = btn.textContent;
		btn.textContent = newLabel;
		setTimeout(function () {
			btn.textContent = original;
		}, ms || 1500);
	}

	// -------------------------------------------------------------------------
	// Mode banner height compensation
	// Adjusts the banner's top offset to clear the WP admin bar dynamically,
	// since the admin bar height varies (32px desktop, 46px collapsed mobile).
	// -------------------------------------------------------------------------
	function wpgAdjustBannerTop() {
		var adminBar = document.getElementById('wpadminbar');
		var banner   = document.querySelector('.wpg-mode-banner');
		if (!banner) {
			return;
		}
		// Banner is not sticky in this implementation (scroll naturally),
		// but if a future version makes it sticky, this provides the top value.
		var offset = adminBar ? adminBar.offsetHeight : 0;
		banner.style.setProperty('--wpg-adminbar-height', offset + 'px');
	}

	document.addEventListener('DOMContentLoaded', wpgAdjustBannerTop);
	window.addEventListener('resize', wpgAdjustBannerTop);

})();
