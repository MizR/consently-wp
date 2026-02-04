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
		this.config = config; // { restUrl, nonce, ajaxUrl, adminNonce }
		this.currentIndex = 0;
		this.pageTimeout = null;
		this.iframe = null;

		var self = this;
		window.addEventListener('message', function(event) {
			if (event.data && event.data.type === 'consently_scan_complete') {
				self.onPageScanComplete(event.data.scanId);
			}
		});
	}

	ConsentlyScanOrchestrator.prototype.start = function() {
		this.updateProgress(0, this.pages.length);
		this.updateStatus('Starting live scan...');
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

		this.updateStatus('Scanning: ' + page.label + '...');

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
		this.updateProgress(this.currentIndex, this.pages.length);
		this.scanNextPage();
	};

	ConsentlyScanOrchestrator.prototype.onAllPagesComplete = function() {
		this.updateStatus('Parsing page content...');

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
					self.updateStatus('Live scan complete!');
					self.updateProgress(self.pages.length, self.pages.length);

					if (typeof self.onComplete === 'function') {
						self.onComplete(data);
					}
				} catch (e) {
					self.updateStatus('Error parsing results.');
					if (typeof self.onError === 'function') {
						self.onError('Failed to parse scan results');
					}
				}
			} else {
				self.updateStatus('Error during HTML parsing.');
				if (typeof self.onError === 'function') {
					self.onError('Server returned status ' + xhr.status);
				}
			}
		};
		xhr.onerror = function() {
			self.updateStatus('Network error during scan.');
			if (typeof self.onError === 'function') {
				self.onError('Network error');
			}
		};
		xhr.send(JSON.stringify({
			pages: this.pages,
			token: this.token
		}));
	};

	ConsentlyScanOrchestrator.prototype.updateProgress = function(current, total) {
		var pct = total > 0 ? Math.round((current / total) * 100) : 0;
		var bar = document.getElementById('consently-scan-progress-bar');
		if (bar) {
			bar.style.width = pct + '%';
		}
		var text = document.getElementById('consently-scan-progress-text');
		if (text) {
			text.textContent = current + ' / ' + total + ' pages scanned';
		}
	};

	ConsentlyScanOrchestrator.prototype.updateStatus = function(message) {
		var el = document.getElementById('consently-scan-status');
		if (el) {
			el.textContent = message;
		}
	};

	// Export
	window.ConsentlyScanOrchestrator = ConsentlyScanOrchestrator;

})(window);
