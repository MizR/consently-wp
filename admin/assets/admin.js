/**
 * Consently Admin JavaScript
 *
 * @package Consently
 */

(function($) {
	'use strict';

	var Consently = {
		/**
		 * Initialize.
		 */
		init: function() {
			this.bindEvents();
			this.checkAdBlocker();
		},

		/**
		 * Bind event handlers.
		 */
		bindEvents: function() {
			// Connection
			$('#consently-connect-btn').on('click', this.handleConnect);
			$('#consently-disconnect-btn').on('click', this.handleDisconnect);
			$('#consently-api-key').on('keypress', function(e) {
				if (e.which === 13) {
					e.preventDefault();
					$('#consently-connect-btn').click();
				}
			});

			// Test mode banner ID
			$('#consently-save-test-id').on('click', this.handleSaveTestBannerId);

			// Diagnostics toggle
			$('.consently-diagnostics-toggle').on('click', this.toggleDiagnostics);
			$('#consently-copy-diagnostics').on('click', this.copyDiagnostics);

			// Audit - single button
			$('#consently-run-audit').on('click', this.handleRunAudit);

			// Settings
			$('#consently-settings-form').on('submit', this.handleSaveSettings);

			// Copy text elements
			$('.consently-copy-text').on('click', this.handleCopyText);

			// Dismiss notices
			$(document).on('click', '[data-consently-notice] .notice-dismiss', this.handleDismissNotice);
		},

		/**
		 * Check for ad blocker.
		 */
		checkAdBlocker: function() {
			setTimeout(function() {
				if (typeof window.consentlyCanRunAds === 'undefined') {
					$('#consently-adblocker-warning').show();
				}
			}, 500);
		},

		/**
		 * Handle connect button click.
		 */
		handleConnect: function(e) {
			e.preventDefault();

			var $button = $(this);
			var $input = $('#consently-api-key');
			var $error = $('#consently-connect-error');
			var apiKey = $input.val().trim();

			if (!apiKey) {
				$error.text(consentlyAdmin.strings.enterApiKey || 'Please enter an API key.').show();
				$input.focus();
				return;
			}

			$button.prop('disabled', true).addClass('consently-loading').text(consentlyAdmin.strings.connecting);
			$error.hide();

			$.ajax({
				url: consentlyAdmin.ajaxUrl,
				method: 'POST',
				data: {
					action: 'consently_connect',
					nonce: consentlyAdmin.nonce,
					api_key: apiKey
				},
				success: function(response) {
					if (response.success) {
						window.location.reload();
					} else {
						$error.text(response.data.message).show();
						$button.prop('disabled', false).removeClass('consently-loading').text('Connect');
					}
				},
				error: function() {
					$error.text('An error occurred. Please try again.').show();
					$button.prop('disabled', false).removeClass('consently-loading').text('Connect');
				}
			});
		},

		/**
		 * Handle save test banner ID.
		 */
		handleSaveTestBannerId: function(e) {
			e.preventDefault();

			var $button = $(this);
			var $input = $('#consently-test-banner-id');
			var $message = $('#consently-test-id-message');
			var bannerId = $input.val().trim();

			$button.prop('disabled', true).addClass('consently-loading');
			$message.hide();

			$.ajax({
				url: consentlyAdmin.ajaxUrl,
				method: 'POST',
				data: {
					action: 'consently_save_test_banner_id',
					nonce: consentlyAdmin.nonce,
					banner_id: bannerId
				},
				success: function(response) {
					if (response.success) {
						$message.text(response.data.message).removeClass('error').addClass('success').show();
						$input.val(response.data.banner_id);
					} else {
						$message.text(response.data.message).removeClass('success').addClass('error').show();
					}
					$button.prop('disabled', false).removeClass('consently-loading');
				},
				error: function() {
					$message.text('An error occurred.').removeClass('success').addClass('error').show();
					$button.prop('disabled', false).removeClass('consently-loading');
				}
			});
		},

		/**
		 * Handle disconnect button click.
		 */
		handleDisconnect: function(e) {
			e.preventDefault();

			if (!confirm(consentlyAdmin.strings.confirmDisconnect)) {
				return;
			}

			var $button = $(this);
			$button.prop('disabled', true).addClass('consently-loading').text(consentlyAdmin.strings.disconnecting);

			$.ajax({
				url: consentlyAdmin.ajaxUrl,
				method: 'POST',
				data: {
					action: 'consently_disconnect',
					nonce: consentlyAdmin.nonce
				},
				success: function(response) {
					if (response.success) {
						window.location.reload();
					} else {
						alert(response.data.message);
						$button.prop('disabled', false).removeClass('consently-loading').text('Disconnect');
					}
				},
				error: function() {
					alert('An error occurred. Please try again.');
					$button.prop('disabled', false).removeClass('consently-loading').text('Disconnect');
				}
			});
		},

		/**
		 * Toggle diagnostics section.
		 */
		toggleDiagnostics: function() {
			var $toggle = $(this);
			var $content = $toggle.next('.consently-diagnostics-content');
			var isExpanded = $toggle.attr('aria-expanded') === 'true';

			$toggle.attr('aria-expanded', !isExpanded);
			$content.slideToggle(200);
		},

		/**
		 * Copy diagnostics to clipboard.
		 */
		copyDiagnostics: function() {
			var text = $('#consently-diagnostics-text').val();

			Consently.copyToClipboard(text, function(success) {
				if (success) {
					var $button = $('#consently-copy-diagnostics');
					var originalText = $button.text();
					$button.text(consentlyAdmin.strings.copied);
					setTimeout(function() {
						$button.text(originalText);
					}, 2000);
				} else {
					alert(consentlyAdmin.strings.copyFailed);
				}
			});
		},

		// ─── Progress helpers ────────────────────────────────────────

		/**
		 * Update progress bar and status text.
		 */
		setProgress: function(percent, statusText) {
			$('#consently-progress-fill').css('width', percent + '%');
			$('#consently-progress-percent').text(Math.round(percent) + '%');
			if (statusText) {
				$('#consently-progress-status').text(statusText);
			}
		},

		// ─── Audit flow ──────────────────────────────────────────────

		/**
		 * Handle "Run Audit" click.
		 * Chains Phase 1 (static) then Phase 2 (live scan) automatically.
		 */
		handleRunAudit: function(e) {
			e.preventDefault();

			var $button = $(this);
			var $progress = $('#consently-scan-progress');
			var $results = $('#consently-audit-results');

			// Reset UI
			$button.prop('disabled', true).addClass('consently-loading');
			$results.hide().empty();
			$progress.show();

			Consently.setProgress(0, 'Analyzing installed plugins...');

			// Phase 1: static analysis
			$.ajax({
				url: consentlyAdmin.ajaxUrl,
				method: 'POST',
				data: {
					action: 'consently_run_audit',
					nonce: consentlyAdmin.nonce
				},
				success: function(response) {
					if (!response.success) {
						Consently.setProgress(0, 'Static analysis failed.');
						$button.prop('disabled', false).removeClass('consently-loading');
						return;
					}

					// Store Phase 1 results — render later when everything is done
					Consently.phase1Results = response.data.results;
					Consently.setProgress(5, 'Analyzing installed plugins... done');

					// Start Phase 2 automatically
					Consently.startLiveScan($button);
				},
				error: function() {
					Consently.setProgress(0, 'An error occurred during analysis.');
					$button.prop('disabled', false).removeClass('consently-loading');
				}
			});
		},

		/**
		 * Start Phase 2 live scan.
		 */
		startLiveScan: function($button) {
			Consently.setProgress(8, 'Preparing live scan...');

			$.ajax({
				url: consentlyAdmin.ajaxUrl,
				method: 'POST',
				data: {
					action: 'consently_start_live_scan',
					nonce: consentlyAdmin.nonce
				},
				success: function(response) {
					if (!response.success) {
						Consently.renderPhase1Results(Consently.phase1Results);
						$('#consently-audit-results').show();
						Consently.setProgress(100, 'Scan complete.');
						Consently.finishAudit($button);
						return;
					}

					var pages = response.data.pages;
					var token = response.data.token;

					if (!pages || pages.length === 0) {
						Consently.renderPhase1Results(Consently.phase1Results);
						$('#consently-audit-results').show();
						Consently.setProgress(100, 'Scan complete.');
						Consently.finishAudit($button);
						return;
					}

					var orchestrator = new window.ConsentlyScanOrchestrator(
						pages,
						token,
						{
							restUrl: consentlyAdmin.restUrl,
							nonce: consentlyAdmin.restNonce
						}
					);

					// Progress: Phase 1 takes ~10%, page scanning takes 10-90%, finalization 90-100%
					orchestrator.onProgress = function(current, total, pageLabel) {
						var pct = 10 + (current / total) * 80;
						var label = 'Scanning pages: ' + (current + 1) + ' / ' + total;
						if (pageLabel) {
							label += ' \u2014 ' + pageLabel;
						}
						Consently.setProgress(pct, label);
					};

					orchestrator.onComplete = function(data) {
						Consently.setProgress(95, 'Finalizing results...');

						// Render all results now that both phases are done
						Consently.renderPhase1Results(Consently.phase1Results);
						Consently.renderPhase2Results(data);
						$('#consently-audit-results').show();

						// Count total cookies found
						var cookieCount = 0;
						if (data.live_cookies) {
							cookieCount = data.live_cookies.length;
						}

						var summary = 'Scan complete.';
						if (cookieCount > 0) {
							summary += ' ' + cookieCount + ' cookie' + (cookieCount !== 1 ? 's' : '') + ' found';
							if (data.pages_scanned) {
								summary += ' across ' + data.pages_scanned + ' page' + (data.pages_scanned !== 1 ? 's' : '') + '.';
							} else {
								summary += '.';
							}
						}

						Consently.setProgress(100, summary);
						Consently.finishAudit($button);
					};

					orchestrator.onError = function(error) {
						// Still show Phase 1 results even if live scan failed
						Consently.renderPhase1Results(Consently.phase1Results);
						$('#consently-audit-results').show();
						Consently.setProgress(100, 'Live scan completed with errors.');
						Consently.finishAudit($button);
					};

					orchestrator.start();
				},
				error: function() {
					Consently.renderPhase1Results(Consently.phase1Results);
					$('#consently-audit-results').show();
					Consently.setProgress(100, 'Scan complete.');
					Consently.finishAudit($button);
				}
			});
		},

		/**
		 * Re-enable the audit button when everything is done.
		 */
		finishAudit: function($button) {
			$button.prop('disabled', false).removeClass('consently-loading');
		},

		// ─── Result rendering ────────────────────────────────────────

		/**
		 * Render Phase 1 (static analysis) results.
		 */
		renderPhase1Results: function(results) {
			var html = '';

			// Known plugins with tracking
			if (results.known_plugins && results.known_plugins.length > 0) {
				html += '<div class="consently-card consently-tracking-plugins">';
				html += '<h3><span class="dashicons dashicons-warning"></span> ';
				html += results.known_plugins.length + ' known tracking plugin' + (results.known_plugins.length !== 1 ? 's' : '') + ' detected</h3>';
				html += '<table class="consently-audit-table widefat">';
				html += '<thead><tr><th>Plugin</th><th>Cookies</th><th>Category</th></tr></thead>';
				html += '<tbody>';

				results.known_plugins.forEach(function(plugin) {
					html += '<tr>';
					html += '<td><strong>' + Consently.escapeHtml(plugin.name) + '</strong>';
					if (plugin.domains && plugin.domains.length) {
						html += '<br><small class="consently-muted">' + Consently.escapeHtml(plugin.domains.join(', ')) + '</small>';
					}
					html += '</td>';
					html += '<td>' + Consently.renderCookieList(plugin.cookies) + '</td>';
					html += '<td><span class="consently-category consently-category-' + Consently.escapeHtml(plugin.category) + '">' + Consently.escapeHtml(Consently.capitalize(plugin.category)) + '</span></td>';
					html += '</tr>';
				});

				html += '</tbody></table></div>';
			}

			// Enqueued tracking scripts
			if (results.enqueued_scripts && results.enqueued_scripts.length > 0) {
				html += '<div class="consently-card">';
				html += '<h3><span class="dashicons dashicons-media-code"></span> Tracking scripts detected in page output</h3>';
				html += '<table class="consently-audit-table widefat">';
				html += '<thead><tr><th>Handle</th><th>Domain</th></tr></thead>';
				html += '<tbody>';

				results.enqueued_scripts.forEach(function(script) {
					html += '<tr>';
					html += '<td><code>' + Consently.escapeHtml(script.handle) + '</code></td>';
					html += '<td>' + Consently.escapeHtml(script.domain) + '</td>';
					html += '</tr>';
				});

				html += '</tbody></table></div>';
			}

			// Options table tracking
			if (results.options_tracking && results.options_tracking.length > 0) {
				html += '<div class="consently-card">';
				html += '<h3><span class="dashicons dashicons-admin-settings"></span> Tracking configurations in options</h3>';
				html += '<ul class="consently-options-list">';

				results.options_tracking.forEach(function(item) {
					html += '<li><strong>' + Consently.escapeHtml(item.service) + '</strong>';
					html += ' <span class="consently-category consently-category-' + Consently.escapeHtml(item.category) + '">' + Consently.escapeHtml(Consently.capitalize(item.category)) + '</span>';
					if (item.tracking_ids && item.tracking_ids.length) {
						html += ' <small class="consently-muted">(' + Consently.escapeHtml(item.tracking_ids.join(', ')) + ')</small>';
					}
					html += '</li>';
				});

				html += '</ul></div>';
			}

			// Theme tracking
			if (results.theme_tracking && results.theme_tracking.length > 0) {
				html += '<div class="consently-card">';
				html += '<h3><span class="dashicons dashicons-admin-appearance"></span> Tracking found in theme files</h3>';
				html += '<ul class="consently-theme-list">';

				results.theme_tracking.forEach(function(item) {
					html += '<li>';
					html += '<strong>' + Consently.escapeHtml(item.domain || item.tracking_id || 'Unknown') + '</strong>';
					html += ' in <code>' + Consently.escapeHtml(item.file) + '</code>';
					if (item.category) {
						html += ' <span class="consently-category consently-category-' + Consently.escapeHtml(item.category) + '">' + Consently.escapeHtml(Consently.capitalize(item.category)) + '</span>';
					}
					html += '</li>';
				});

				html += '</ul></div>';
			}

			// WordPress core cookies
			if (results.wordpress_cookies && results.wordpress_cookies.length > 0) {
				html += '<div class="consently-card consently-wp-cookies">';
				html += '<h3><span class="dashicons dashicons-wordpress"></span> WordPress core cookies</h3>';
				html += '<details><summary>' + results.wordpress_cookies.length + ' WordPress cookies identified</summary>';
				html += '<table class="consently-audit-table widefat">';
				html += '<thead><tr><th>Cookie</th><th>Category</th><th>Duration</th><th>Purpose</th></tr></thead>';
				html += '<tbody>';

				results.wordpress_cookies.forEach(function(cookie) {
					html += '<tr>';
					html += '<td><code>' + Consently.escapeHtml(cookie.name) + '</code></td>';
					html += '<td><span class="consently-category consently-category-' + Consently.escapeHtml(cookie.category) + '">' + Consently.escapeHtml(Consently.capitalize(cookie.category)) + '</span></td>';
					html += '<td>' + Consently.escapeHtml(cookie.duration) + '</td>';
					html += '<td><small>' + Consently.escapeHtml(cookie.purpose) + '</small></td>';
					html += '</tr>';
				});

				html += '</tbody></table></details></div>';
			}

			// Clean plugins
			if (results.clean_plugins && results.clean_plugins.length > 0) {
				html += '<div class="consently-card consently-clean-plugins">';
				html += '<h3><span class="dashicons dashicons-yes"></span> ';
				html += results.clean_plugins.length + ' plugin' + (results.clean_plugins.length !== 1 ? 's' : '') + ' without detected tracking</h3>';
				html += '<details><summary>Show clean plugins</summary>';
				html += '<ul class="consently-clean-list">';

				results.clean_plugins.forEach(function(plugin) {
					html += '<li>' + Consently.escapeHtml(plugin.name);
					if (plugin.cookies && plugin.cookies.length) {
						html += ' <small class="consently-muted">(' + plugin.cookies.length + ' functional cookies)</small>';
					}
					html += '</li>';
				});

				html += '</ul></details></div>';
			}

			$('#consently-audit-results').html(html);
		},

		/**
		 * Render Phase 2 (live scan) results — appended below Phase 1.
		 */
		renderPhase2Results: function(results) {
			var html = '';

			// Confirmed cookies
			if (results.live_cookies && results.live_cookies.length > 0) {
				html += '<div class="consently-card">';
				html += '<h3><span class="dashicons dashicons-visibility"></span> Confirmed Cookies (' + results.live_cookies.length + ')</h3>';
				html += '<table class="consently-audit-table widefat">';
				html += '<thead><tr><th>Cookie</th><th>Category</th><th>Service</th><th>Pages</th></tr></thead>';
				html += '<tbody>';

				results.live_cookies.forEach(function(cookie) {
					html += '<tr>';
					html += '<td><code>' + Consently.escapeHtml(cookie.name) + '</code>';
					if (cookie.duration) {
						html += '<br><small class="consently-muted">' + Consently.escapeHtml(cookie.duration) + '</small>';
					}
					html += '</td>';
					html += '<td><span class="consently-category consently-category-' + Consently.escapeHtml(cookie.category) + '">' + Consently.escapeHtml(Consently.capitalize(cookie.category)) + '</span></td>';
					html += '<td>' + Consently.escapeHtml(cookie.service || 'Unknown') + '</td>';
					html += '<td><small>' + (cookie.page ? cookie.page.join(', ') : '') + '</small></td>';
					html += '</tr>';
				});

				html += '</tbody></table></div>';
			}

			// Storage items
			if (results.live_storage && results.live_storage.length > 0) {
				html += '<div class="consently-card">';
				html += '<h3><span class="dashicons dashicons-database"></span> Local/Session Storage (' + results.live_storage.length + ')</h3>';
				html += '<table class="consently-audit-table widefat">';
				html += '<thead><tr><th>Key</th><th>Type</th><th>Category</th><th>Service</th></tr></thead>';
				html += '<tbody>';

				results.live_storage.forEach(function(item) {
					html += '<tr>';
					html += '<td><code>' + Consently.escapeHtml(item.name) + '</code></td>';
					html += '<td>' + Consently.escapeHtml(item.type) + '</td>';
					html += '<td><span class="consently-category consently-category-' + Consently.escapeHtml(item.category || 'other') + '">' + Consently.escapeHtml(Consently.capitalize(item.category || 'other')) + '</span></td>';
					html += '<td>' + Consently.escapeHtml(item.service || 'Unknown') + '</td>';
					html += '</tr>';
				});

				html += '</tbody></table></div>';
			}

			// HTML parsing results
			var hasHtmlResults = (results.social_media && results.social_media.length > 0)
				|| (results.thirdparty && results.thirdparty.length > 0)
				|| (results.statistics && results.statistics.length > 0)
				|| (results.tracking_ids && results.tracking_ids.length > 0);

			if (hasHtmlResults) {
				html += '<div class="consently-card">';
				html += '<h3><span class="dashicons dashicons-editor-code"></span> HTML Content Analysis</h3>';

				// Third-party services
				if (results.thirdparty && results.thirdparty.length > 0) {
					html += '<h4>Third-Party Services</h4>';
					html += '<div class="consently-tag-list">';
					results.thirdparty.forEach(function(service) {
						html += '<span class="consently-tag consently-tag-thirdparty">' + Consently.escapeHtml(service) + '</span>';
					});
					html += '</div>';
				}

				// Social media
				if (results.social_media && results.social_media.length > 0) {
					html += '<h4>Social Media</h4>';
					html += '<div class="consently-tag-list">';
					results.social_media.forEach(function(service) {
						html += '<span class="consently-tag consently-tag-social">' + Consently.escapeHtml(service) + '</span>';
					});
					html += '</div>';
				}

				// Statistics
				if (results.statistics && results.statistics.length > 0) {
					html += '<h4>Statistics & Analytics</h4>';
					html += '<div class="consently-tag-list">';
					results.statistics.forEach(function(service) {
						html += '<span class="consently-tag consently-tag-analytics">' + Consently.escapeHtml(service) + '</span>';
					});
					html += '</div>';
				}

				// Tracking IDs detected
				if (results.tracking_ids && results.tracking_ids.length > 0) {
					html += '<h4>Tracking IDs Detected</h4>';
					html += '<ul class="consently-tracking-ids-list">';
					results.tracking_ids.forEach(function(item) {
						html += '<li><strong>' + Consently.escapeHtml(item.type) + '</strong>: ' + Consently.escapeHtml(item.service) + '</li>';
					});
					html += '</ul>';
				}

				// Double stats warning
				if (results.double_stats && results.double_stats.length > 0) {
					html += '<div class="consently-notice consently-notice-warning">';
					html += '<span class="dashicons dashicons-warning"></span>';
					html += '<p><strong>Duplicate statistics detected:</strong> ' + Consently.escapeHtml(results.double_stats.join(', '));
					html += '. Multiple instances of the same analytics service may cause inaccurate data.</p>';
					html += '</div>';
				}

				html += '</div>';
			}

			// Append Phase 2 results below Phase 1
			$('#consently-audit-results').append(html);
		},

		/**
		 * Render a list of cookies as compact HTML.
		 */
		renderCookieList: function(cookies) {
			if (!cookies || cookies.length === 0) {
				return '<em>No cookie data</em>';
			}

			var html = '<div class="consently-cookie-list">';
			var shown = Math.min(cookies.length, 3);

			for (var i = 0; i < shown; i++) {
				html += '<code>' + Consently.escapeHtml(cookies[i].name) + '</code>';
				if (cookies[i].duration) {
					html += ' <small class="consently-muted">(' + Consently.escapeHtml(cookies[i].duration) + ')</small>';
				}
				if (i < shown - 1) {
					html += '<br>';
				}
			}

			if (cookies.length > 3) {
				html += '<br><small class="consently-muted">+' + (cookies.length - 3) + ' more</small>';
			}

			html += '</div>';
			return html;
		},

		// ─── Settings ────────────────────────────────────────────────

		/**
		 * Handle save settings form submit.
		 */
		handleSaveSettings: function(e) {
			e.preventDefault();

			var $form = $(this);
			var $button = $('#consently-save-settings');
			var $message = $('#consently-settings-message');

			var bannerEnabled = $('#consently-banner-enabled').is(':checked');
			var showToAdmins = $('#consently-show-to-admins').is(':checked');

			$button.prop('disabled', true).addClass('consently-loading').text(consentlyAdmin.strings.saving);
			$message.hide();

			$.ajax({
				url: consentlyAdmin.ajaxUrl,
				method: 'POST',
				data: {
					action: 'consently_save_settings',
					nonce: consentlyAdmin.nonce,
					banner_enabled: bannerEnabled ? 1 : 0,
					show_to_admins: showToAdmins ? 1 : 0
				},
				success: function(response) {
					if (response.success) {
						$message.text(response.data.message).removeClass('error').addClass('success').show();
					} else {
						$message.text(response.data.message).removeClass('success').addClass('error').show();
					}
					$button.prop('disabled', false).removeClass('consently-loading').text('Save Settings');
				},
				error: function() {
					$message.text('An error occurred. Please try again.').removeClass('success').addClass('error').show();
					$button.prop('disabled', false).removeClass('consently-loading').text('Save Settings');
				}
			});
		},

		// ─── Utilities ───────────────────────────────────────────────

		/**
		 * Handle copy text click.
		 */
		handleCopyText: function() {
			var $el = $(this);
			var text = $el.data('copy') || $el.text();

			Consently.copyToClipboard(text, function(success) {
				if (success) {
					var originalBg = $el.css('background-color');
					$el.css('background-color', '#d4edda');
					setTimeout(function() {
						$el.css('background-color', originalBg);
					}, 1000);
				}
			});
		},

		/**
		 * Handle dismiss notice.
		 */
		handleDismissNotice: function() {
			var $notice = $(this).closest('[data-consently-notice]');
			var noticeType = $notice.data('consently-notice');

			$.ajax({
				url: consentlyAdmin.ajaxUrl,
				method: 'POST',
				data: {
					action: 'consently_dismiss_notice',
					nonce: consentlyAdmin.nonce,
					notice: noticeType
				}
			});
		},

		/**
		 * Copy text to clipboard.
		 */
		copyToClipboard: function(text, callback) {
			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(text).then(function() {
					callback(true);
				}).catch(function() {
					callback(false);
				});
			} else {
				var textArea = document.createElement('textarea');
				textArea.value = text;
				textArea.style.position = 'fixed';
				textArea.style.left = '-999999px';
				textArea.style.top = '-999999px';
				document.body.appendChild(textArea);
				textArea.focus();
				textArea.select();

				try {
					document.execCommand('copy');
					callback(true);
				} catch (err) {
					callback(false);
				}

				document.body.removeChild(textArea);
			}
		},

		/**
		 * Escape HTML entities.
		 */
		escapeHtml: function(text) {
			if (!text) return '';
			var div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		},

		/**
		 * Capitalize first letter.
		 */
		capitalize: function(str) {
			if (!str) return '';
			return str.charAt(0).toUpperCase() + str.slice(1);
		}
	};

	// Initialize on document ready
	$(document).ready(function() {
		Consently.init();
	});

})(jQuery);
