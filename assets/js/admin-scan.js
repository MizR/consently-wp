/**
 * Consently Live Scan Orchestrator
 *
 * Manages Phase 2 live scanning by loading pages in a hidden iframe
 * one at a time and collecting cookie/storage data.
 *
 * @package Consently
 */
(function(window) {
	'use strict';

	function ConsentlyScanOrchestrator(pages, token, config) {
		this.pages = pages;
		this.token = token;
		this.config = config; // { restUrl, nonce }
		this.currentIndex = 0;
		this.pageTimeout = null;
		this.iframe = null;

		// Callbacks
		this.onProgress = null; // function(current, total, pageLabel)
		this.onComplete = null; // function(data)
		this.onError = null;    // function(errorMessage)

		var self = this;
		window.addEventListener('message', function(event) {
			if (event.data && event.data.type === 'consently_scan_complete') {
				self.onPageScanComplete(event.data.scanId);
			}
		});
	}

	ConsentlyScanOrchestrator.prototype.start = function() {
		if (typeof this.onProgress === 'function') {
			this.onProgress(0, this.pages.length, '');
		}
		this.scanNextPage();
	};

	ConsentlyScanOrchestrator.prototype.scanNextPage = function() {
		if (this.currentIndex >= this.pages.length) {
			this.onAllPagesComplete();
			return;
		}

		var page = this.pages[this.currentIndex];
		var separator = page.url.indexOf('?') > -1 ? '&' : '?';
		var iframeUrl = page.url
			+ separator
			+ 'consently_scan_token=' + encodeURIComponent(this.token)
			+ '&consently_scan_id=' + encodeURIComponent(page.id);

		if (typeof this.onProgress === 'function') {
			this.onProgress(this.currentIndex, this.pages.length, page.label);
		}

		// Create or reuse iframe
		if (!this.iframe) {
			this.iframe = document.createElement('iframe');
			this.iframe.id = 'consently-scan-iframe';
			this.iframe.style.cssText = 'width:0;height:0;border:none;position:absolute;left:-9999px;';
			this.iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-forms');
			document.body.appendChild(this.iframe);
		}

		// Set timeout per page (15 seconds)
		var self = this;
		this.pageTimeout = setTimeout(function() {
			self.onPageScanComplete(page.id);
		}, 15000);

		this.iframe.src = iframeUrl;
	};

	ConsentlyScanOrchestrator.prototype.onPageScanComplete = function(scanId) {
		if (this.pageTimeout) {
			clearTimeout(this.pageTimeout);
			this.pageTimeout = null;
		}

		this.currentIndex++;
		this.scanNextPage();
	};

	ConsentlyScanOrchestrator.prototype.onAllPagesComplete = function() {
		// Clean up iframe
		if (this.iframe) {
			this.iframe.parentNode.removeChild(this.iframe);
			this.iframe = null;
		}

		var self = this;

		// Trigger server-side HTML parsing for all pages
		var xhr = new XMLHttpRequest();
		xhr.open('POST', this.config.restUrl + 'consently/v1/parse-pages-html');
		xhr.setRequestHeader('Content-Type', 'application/json');
		xhr.setRequestHeader('X-WP-Nonce', this.config.nonce);
		xhr.onload = function() {
			if (xhr.status >= 200 && xhr.status < 300) {
				try {
					var data = JSON.parse(xhr.responseText);
					if (typeof self.onComplete === 'function') {
						self.onComplete(data);
					}
				} catch (e) {
					if (typeof self.onError === 'function') {
						self.onError('Failed to parse scan results');
					}
				}
			} else {
				if (typeof self.onError === 'function') {
					self.onError('Server returned status ' + xhr.status);
				}
			}
		};
		xhr.onerror = function() {
			if (typeof self.onError === 'function') {
				self.onError('Network error');
			}
		};
		xhr.send(JSON.stringify({
			pages: this.pages,
			token: this.token
		}));
	};

	// Export
	window.ConsentlyScanOrchestrator = ConsentlyScanOrchestrator;

})(window);
