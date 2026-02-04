/**
 * Consently Scan Cookies Collector
 *
 * Injected into iframe pages during live scan.
 * Waits for page load + scripts to execute, then captures
 * cookies, localStorage, and sessionStorage.
 *
 * @package Consently
 */
(function() {
	'use strict';

	var SCAN_DELAY = 3000; // 3 seconds after load for third-party scripts

	window.addEventListener('load', function() {
		setTimeout(function() {
			var data = {
				cookies:        getCookies(),
				localStorage:   getLocalStorageItems(),
				sessionStorage: getSessionStorageItems(),
				scanId:         getScanId(),
				token:          getScanToken()
			};

			// POST to REST endpoint
			var xhr = new XMLHttpRequest();
			xhr.open('POST', consentlyScanConfig.restUrl + 'consently/v1/store-scan-cookies');
			xhr.setRequestHeader('Content-Type', 'application/json');
			xhr.setRequestHeader('X-WP-Nonce', consentlyScanConfig.nonce);
			xhr.send(JSON.stringify(data));

			// Signal parent window that scan is complete for this page
			xhr.onload = function() {
				if (window.parent && window.parent !== window) {
					window.parent.postMessage({
						type: 'consently_scan_complete',
						scanId: data.scanId
					}, '*');
				}
			};

			// Timeout fallback in case REST call hangs
			xhr.onerror = function() {
				if (window.parent && window.parent !== window) {
					window.parent.postMessage({
						type: 'consently_scan_complete',
						scanId: data.scanId
					}, '*');
				}
			};
		}, SCAN_DELAY);
	});

	function getCookies() {
		var cookies = [];
		if (document.cookie && document.cookie !== '') {
			var seen = {};
			document.cookie.split(';').forEach(function(c) {
				var parts = c.trim().split('=');
				var name;
				try {
					name = decodeURIComponent(parts[0]);
				} catch (e) {
					name = parts[0];
				}
				// Only send cookie name, not value (privacy)
				if (!seen[name]) {
					seen[name] = true;
					cookies.push({
						name: name,
						hasValue: parts.length > 1 && parts[1] !== ''
					});
				}
			});
		}
		return cookies;
	}

	function getLocalStorageItems() {
		var items = [];
		try {
			for (var i = 0; i < localStorage.length; i++) {
				items.push(localStorage.key(i));
			}
		} catch (e) {
			// localStorage might be disabled
		}
		return items;
	}

	function getSessionStorageItems() {
		var items = [];
		try {
			for (var i = 0; i < sessionStorage.length; i++) {
				items.push(sessionStorage.key(i));
			}
		} catch (e) {
			// sessionStorage might be disabled
		}
		return items;
	}

	function getScanId() {
		var params = new URLSearchParams(window.location.search);
		return params.get('consently_scan_id') || 'unknown';
	}

	function getScanToken() {
		var params = new URLSearchParams(window.location.search);
		return params.get('consently_scan_token') || '';
	}
})();
