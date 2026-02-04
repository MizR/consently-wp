/**
 * Consently Live Scan Orchestrator
 *
 * Manages Phase 2 live scanning by loading pages in hidden iframes
 * with parallel execution, adaptive timeouts, and retry logic.
 *
 * @package Consently
 */
(function(window) {
	'use strict';

	function ConsentlyScanOrchestrator(pages, token, config) {
		this.pages = pages;
		this.token = token;
		this.config = config; // { restUrl, nonce }

		// Concurrency settings
		this.concurrency = 3;
		this.iframes = [];
		this.availableSlots = [];
		this.activeScans = {};  // scanId -> { slot, timeout }
		this.queue = pages.slice(); // shallow copy
		this.completedCount = 0;
		this.scanResults = {}; // scanId -> { status: 'ok'|'timeout', label: '' }
		this.isRetry = false;
		this.pageTimeoutMs = 20000; // 20 seconds per page

		// Callbacks
		this.onProgress = null;   // function(completed, total, label)
		this.onComplete = null;   // function(data)
		this.onError = null;      // function(errorMessage)
		this.onPageResult = null; // function(pageId, status, label)

		var self = this;
		window.addEventListener('message', function(event) {
			if (event.data && event.data.type === 'consently_scan_complete') {
				var scanId = event.data.scanId;
				if (self.activeScans[scanId]) {
					self.scanResults[scanId] = {
						status: 'ok',
						label: self.activeScans[scanId].label
					};
					self.onPageScanComplete(scanId);
				}
			}
		});
	}

	ConsentlyScanOrchestrator.prototype.start = function() {
		// Create iframe pool
		for (var i = 0; i < this.concurrency; i++) {
			var iframe = document.createElement('iframe');
			iframe.id = 'consently-scan-iframe-' + i;
			iframe.style.cssText = 'width:0;height:0;border:none;position:absolute;left:-9999px;';
			iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-forms');
			document.body.appendChild(iframe);
			this.iframes.push(iframe);
			this.availableSlots.push(i);
		}

		if (typeof this.onProgress === 'function') {
			this.onProgress(0, this.pages.length, '');
		}

		this.fillSlots();
	};

	/**
	 * Fill available iframe slots from the queue.
	 * Staggers starts by 200ms to avoid server spike.
	 */
	ConsentlyScanOrchestrator.prototype.fillSlots = function() {
		var self = this;
		var delay = 0;

		while (this.availableSlots.length > 0 && this.queue.length > 0) {
			var slotIndex = this.availableSlots.shift();
			var page = this.queue.shift();

			(function(slot, pg, d) {
				setTimeout(function() {
					self.scanPage(pg, slot);
				}, d);
			})(slotIndex, page, delay);

			delay += 200;
		}

		// Check if all done
		if (Object.keys(this.activeScans).length === 0 && this.queue.length === 0) {
			this.onAllPagesComplete();
		}
	};

	/**
	 * Load a page in a specific iframe slot.
	 */
	ConsentlyScanOrchestrator.prototype.scanPage = function(page, slotIndex) {
		var self = this;
		var separator = page.url.indexOf('?') > -1 ? '&' : '?';
		var iframeUrl = page.url
			+ separator
			+ 'consently_scan_token=' + encodeURIComponent(this.token)
			+ '&consently_scan_id=' + encodeURIComponent(page.id);

		// Track active scan
		this.activeScans[page.id] = {
			slot: slotIndex,
			label: page.label,
			timeout: setTimeout(function() {
				// Timeout — mark as timeout if not already resolved
				if (self.activeScans[page.id]) {
					if (!self.scanResults[page.id]) {
						self.scanResults[page.id] = {
							status: 'timeout',
							label: page.label
						};
					}
					self.onPageScanComplete(page.id);
				}
			}, this.pageTimeoutMs)
		};

		this.iframes[slotIndex].src = iframeUrl;
	};

	/**
	 * Handle page scan completion (success or timeout).
	 */
	ConsentlyScanOrchestrator.prototype.onPageScanComplete = function(scanId) {
		var scan = this.activeScans[scanId];
		if (!scan) return;

		// Clear timeout
		clearTimeout(scan.timeout);

		// Free up the slot
		this.availableSlots.push(scan.slot);
		delete this.activeScans[scanId];

		this.completedCount++;

		// Report per-page result
		var result = this.scanResults[scanId];
		if (result && typeof this.onPageResult === 'function') {
			this.onPageResult(scanId, result.status, result.label);
		}

		// Report progress
		if (typeof this.onProgress === 'function') {
			this.onProgress(this.completedCount, this.pages.length, '');
		}

		// Fill more slots
		this.fillSlots();
	};

	/**
	 * Called when all pages (including retries) are complete.
	 */
	ConsentlyScanOrchestrator.prototype.onAllPagesComplete = function() {
		// Check for timed-out pages and retry once
		if (!this.isRetry) {
			var timedOut = [];
			var self = this;

			this.pages.forEach(function(page) {
				if (self.scanResults[page.id] && self.scanResults[page.id].status === 'timeout') {
					timedOut.push(page);
				}
			});

			if (timedOut.length > 0 && timedOut.length < this.pages.length * 0.5) {
				this.isRetry = true;
				this.queue = timedOut.slice();
				this.pageTimeoutMs = 30000; // Longer timeout for retries
				this.concurrency = 1; // Single iframe for retries

				if (typeof this.onProgress === 'function') {
					this.onProgress(
						this.completedCount,
						this.pages.length + timedOut.length,
						'Retrying ' + timedOut.length + ' slow page' + (timedOut.length !== 1 ? 's' : '') + '...'
					);
				}

				this.fillSlots();
				return;
			}
		}

		this.finalize();
	};

	/**
	 * Clean up iframes and trigger server-side HTML parsing.
	 */
	ConsentlyScanOrchestrator.prototype.finalize = function() {
		// Clean up all iframes
		for (var i = 0; i < this.iframes.length; i++) {
			if (this.iframes[i].parentNode) {
				this.iframes[i].parentNode.removeChild(this.iframes[i]);
			}
		}
		this.iframes = [];

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
					// Attach scan results metadata
					data.scan_results = self.scanResults;
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
