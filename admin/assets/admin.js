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
					Consently.setProgress(5, 'Plugin analysis complete. Starting live scan...');

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
						Consently.renderAuditResults(Consently.phase1Results, null);
						$('#consently-audit-results').show();
						Consently.setProgress(100, 'Audit complete. See results below.');
						Consently.finishAudit($button);
						return;
					}

					var pages = response.data.pages;
					var token = response.data.token;

					if (!pages || pages.length === 0) {
						Consently.renderAuditResults(Consently.phase1Results, null);
						$('#consently-audit-results').show();
						Consently.setProgress(100, 'Audit complete. See results below.');
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

					// Show scan log and reset it
					$('#consently-scan-log').show();
					$('#consently-scan-log-list').empty();

					// Progress: Phase 1 takes ~10%, page scanning takes 10-90%, finalization 90-100%
					orchestrator.onProgress = function(completed, total, statusText) {
						var pct = 10 + (completed / total) * 80;
						var label = statusText || ('Scanning pages: ' + completed + ' of ' + total + ' complete...');
						Consently.setProgress(pct, label);
					};

					// Per-page result callback — live scan log
					orchestrator.onPageResult = function(pageId, status, label) {
						var icon = status === 'ok' ? '✓' : '⚠';
						var cls = status === 'ok' ? 'consently-log-ok' : 'consently-log-warn';
						var $li = $('<li class="' + cls + '"><span class="consently-log-icon">' + icon + '</span> ' + Consently.escapeHtml(label || pageId) + '</li>');
						$('#consently-scan-log-list').append($li);
					};

					orchestrator.onComplete = function(data) {
						Consently.setProgress(95, 'Analyzing results...');

						// Check for high timeout rate and show warning
						var scanResults = data.scan_results || {};
						var timeoutCount = 0;
						var totalScanned = 0;
						Object.keys(scanResults).forEach(function(key) {
							totalScanned++;
							if (scanResults[key].status === 'timeout') {
								timeoutCount++;
							}
						});
						if (timeoutCount > 0 && totalScanned > 0 && (timeoutCount / totalScanned) > 0.3) {
							$('#consently-scan-log').after(
								'<div class="consently-notice consently-notice-warning" style="margin-top:10px;">' +
								'<span class="dashicons dashicons-warning"></span>' +
								'<p>' + timeoutCount + ' of ' + totalScanned + ' pages timed out during scanning. ' +
								'Your server may be slow or some pages may have issues loading.</p></div>'
							);
						}

						// Render unified results from both phases
						Consently.renderAuditResults(Consently.phase1Results, data);
						$('#consently-audit-results').show();

						Consently.setProgress(100, 'Audit complete. See results below.');
						Consently.finishAudit($button);
					};

					orchestrator.onError = function(error) {
						// Still show Phase 1 results even if live scan failed
						Consently.renderAuditResults(Consently.phase1Results, null);
						$('#consently-audit-results').show();
						Consently.setProgress(100, 'Audit complete. Live scan had errors.');
						Consently.finishAudit($button);
					};

					orchestrator.start();
				},
				error: function() {
					Consently.renderAuditResults(Consently.phase1Results, null);
					$('#consently-audit-results').show();
					Consently.setProgress(100, 'Audit complete. See results below.');
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
		 * Build a unified service map from Phase 1 and Phase 2 data.
		 * Groups all detection signals by service name.
		 */
		buildServiceMap: function(phase1, phase2) {
			var serviceMap = {};
			var categoryOrder = { marketing: 0, analytics: 1, other: 2, functional: 3 };

			// Helper to normalize service keys for matching
			function serviceKey(name) {
				return (name || '').toLowerCase().replace(/[^a-z0-9]/g, '');
			}

			// Helper to find or create a service entry
			function getService(name, category) {
				var key = serviceKey(name);
				if (!key) return null;
				if (!serviceMap[key]) {
					serviceMap[key] = {
						name: name,
						category: category || 'other',
						status: 'potential',
						plugin: null,
						domains: [],
						potentialCookies: [],
						confirmedCookies: [],
						scripts: [],
						trackingIds: [],
						themeFiles: [],
						pages: []
					};
				}
				return serviceMap[key];
			}

			// 1. Seed from known_plugins (Phase 1)
			if (phase1 && phase1.known_plugins) {
				phase1.known_plugins.forEach(function(plugin) {
					var svc = getService(plugin.name, plugin.category);
					if (!svc) return;
					svc.plugin = plugin.name;
					svc.potentialCookies = plugin.cookies || [];
					svc.domains = plugin.domains || [];
				});
			}

			// 2. Add enqueued_scripts (Phase 1)
			if (phase1 && phase1.enqueued_scripts) {
				phase1.enqueued_scripts.forEach(function(script) {
					// Try to match to existing service by domain
					var matched = false;
					Object.keys(serviceMap).forEach(function(key) {
						var svc = serviceMap[key];
						if (svc.domains && svc.domains.length) {
							svc.domains.forEach(function(d) {
								if (script.domain && script.domain.indexOf(d) !== -1) {
									svc.scripts.push(script);
									matched = true;
								}
							});
						}
					});
					if (!matched) {
						var svc = getService(script.domain, 'other');
						if (svc) {
							svc.scripts.push(script);
						}
					}
				});
			}

			// 3. Add options_tracking (Phase 1)
			if (phase1 && phase1.options_tracking) {
				phase1.options_tracking.forEach(function(item) {
					var svc = getService(item.service, item.category);
					if (svc && item.tracking_ids) {
						item.tracking_ids.forEach(function(id) {
							svc.trackingIds.push(id);
						});
					}
				});
			}

			// 4. Add theme_tracking (Phase 1)
			if (phase1 && phase1.theme_tracking) {
				phase1.theme_tracking.forEach(function(item) {
					var name = item.service || item.domain || item.tracking_id || 'Unknown';
					var svc = getService(name, item.category || 'other');
					if (svc) {
						svc.themeFiles.push(item.file);
					}
				});
			}

			// 5. Cross-reference live_cookies (Phase 2) — upgrades to confirmed
			if (phase2 && phase2.live_cookies) {
				phase2.live_cookies.forEach(function(cookie) {
					// Skip necessary/functional cookies — they go to WP core section
					if (cookie.category === 'necessary' || cookie.category === 'functional') {
						return;
					}
					var serviceName = cookie.service || 'Unknown';
					var svc = getService(serviceName, cookie.category);
					if (svc) {
						svc.status = 'confirmed';
						svc.confirmedCookies.push(cookie);
						if (cookie.page) {
							cookie.page.forEach(function(p) {
								if (svc.pages.indexOf(p) === -1) {
									svc.pages.push(p);
								}
							});
						}
					}
				});
			}

			// 6. Cross-reference HTML analysis (Phase 2) — upgrades to confirmed
			if (phase2) {
				var htmlServices = [].concat(
					phase2.statistics || [],
					phase2.social_media || [],
					phase2.thirdparty || []
				);
				htmlServices.forEach(function(name) {
					var key = serviceKey(name);
					// Check if this matches any existing service
					var matched = false;
					Object.keys(serviceMap).forEach(function(existingKey) {
						if (existingKey.indexOf(key) !== -1 || key.indexOf(existingKey) !== -1) {
							serviceMap[existingKey].status = 'confirmed';
							matched = true;
						}
					});
					// If not matched to existing, it will appear in third-party tags section
				});

				// Cross-reference tracking_ids
				if (phase2.tracking_ids) {
					phase2.tracking_ids.forEach(function(item) {
						var key = serviceKey(item.service);
						Object.keys(serviceMap).forEach(function(existingKey) {
							if (existingKey.indexOf(key) !== -1 || key.indexOf(existingKey) !== -1) {
								serviceMap[existingKey].status = 'confirmed';
							}
						});
					});
				}
			}

			// Convert to sorted array
			var services = Object.keys(serviceMap).map(function(key) {
				return serviceMap[key];
			});

			// Sort: confirmed first, then by category priority
			services.sort(function(a, b) {
				if (a.status !== b.status) {
					return a.status === 'confirmed' ? -1 : 1;
				}
				var catA = categoryOrder[a.category] !== undefined ? categoryOrder[a.category] : 2;
				var catB = categoryOrder[b.category] !== undefined ? categoryOrder[b.category] : 2;
				return catA - catB;
			});

			return services;
		},

		/**
		 * Render unified audit results from both phases.
		 */
		renderAuditResults: function(phase1, phase2) {
			var html = '';
			var services = Consently.buildServiceMap(phase1, phase2);

			// Filter to only services that need consent (not necessary/functional)
			var actionableServices = services.filter(function(svc) {
				return svc.category !== 'necessary' && svc.category !== 'functional';
			});

			// ── Section 1: Services Requiring Consent ──
			if (actionableServices.length > 0) {
				html += '<div class="consently-card consently-services-card">';
				html += '<h3><span class="dashicons dashicons-warning"></span> ';
				html += actionableServices.length + ' service' + (actionableServices.length !== 1 ? 's' : '') + ' requiring consent</h3>';
				html += '<p class="consently-muted">These services set tracking cookies or load third-party scripts. Add them to your consent banner.</p>';

				if (!phase2) {
					html += '<div class="consently-notice consently-notice-warning">';
					html += '<span class="dashicons dashicons-info"></span>';
					html += '<p>Live scan was not available. Results show potential tracking only — actual cookies could not be verified.</p>';
					html += '</div>';
				}

				html += '<table class="consently-audit-table widefat consently-services-table">';
				html += '<thead><tr><th>Service</th><th>Category</th><th>Status</th><th></th></tr></thead>';
				html += '<tbody>';

				actionableServices.forEach(function(svc, idx) {
					var statusClass = svc.status === 'confirmed' ? 'consently-status-confirmed' : 'consently-status-potential';
					var statusLabel = svc.status === 'confirmed' ? 'Confirmed' : 'Potential';
					var statusIcon = svc.status === 'confirmed' ? '&#9679;' : '&#9675;';

					html += '<tr class="consently-service-row" data-service-idx="' + idx + '">';
					html += '<td><strong>' + Consently.escapeHtml(svc.name) + '</strong>';
					if (svc.domains && svc.domains.length) {
						html += '<br><small class="consently-muted">' + Consently.escapeHtml(svc.domains.join(', ')) + '</small>';
					}
					html += '</td>';
					html += '<td><span class="consently-category consently-category-' + Consently.escapeHtml(svc.category) + '">' + Consently.escapeHtml(Consently.capitalize(svc.category)) + '</span></td>';
					html += '<td><span class="' + statusClass + '">' + statusIcon + ' ' + statusLabel + '</span></td>';
					html += '<td><button type="button" class="consently-service-toggle button-link" aria-expanded="false">Details</button></td>';
					html += '</tr>';

					// Expandable details row
					html += '<tr class="consently-service-details-row" style="display:none;">';
					html += '<td colspan="4"><div class="consently-service-details">';

					// Confirmed cookies
					if (svc.confirmedCookies.length > 0) {
						html += '<div class="consently-detail-group consently-detail-confirmed">';
						html += '<h4>Confirmed cookies (' + svc.confirmedCookies.length + ')</h4>';
						svc.confirmedCookies.forEach(function(cookie) {
							html += '<div class="consently-detail-item">';
							html += '<code>' + Consently.escapeHtml(cookie.name) + '</code>';
							if (cookie.duration) {
								html += ' <small class="consently-muted">' + Consently.escapeHtml(cookie.duration) + '</small>';
							}
							if (cookie.page && cookie.page.length) {
								html += ' <small class="consently-muted">— found on: ' + Consently.escapeHtml(cookie.page.join(', ')) + '</small>';
							}
							html += '</div>';
						});
						html += '</div>';
					}

					// Potential cookies from database
					if (svc.potentialCookies.length > 0) {
						html += '<div class="consently-detail-group consently-detail-potential">';
						html += '<h4>Known cookies for this plugin (' + svc.potentialCookies.length + ')</h4>';
						svc.potentialCookies.forEach(function(cookie) {
							html += '<div class="consently-detail-item">';
							html += '<code>' + Consently.escapeHtml(cookie.name) + '</code>';
							if (cookie.duration) {
								html += ' <small class="consently-muted">' + Consently.escapeHtml(cookie.duration) + '</small>';
							}
							if (cookie.purpose) {
								html += ' <small class="consently-muted">— ' + Consently.escapeHtml(cookie.purpose) + '</small>';
							}
							html += '</div>';
						});
						html += '</div>';
					}

					// Tracking scripts
					if (svc.scripts.length > 0) {
						html += '<div class="consently-detail-group">';
						html += '<h4>Tracking scripts</h4>';
						svc.scripts.forEach(function(script) {
							html += '<div class="consently-detail-item">';
							html += '<code>' + Consently.escapeHtml(script.handle) + '</code>';
							html += ' <small class="consently-muted">' + Consently.escapeHtml(script.domain) + '</small>';
							html += '</div>';
						});
						html += '</div>';
					}

					// Theme files
					if (svc.themeFiles.length > 0) {
						html += '<div class="consently-detail-group">';
						html += '<h4>Found in theme files</h4>';
						svc.themeFiles.forEach(function(file) {
							html += '<div class="consently-detail-item"><code>' + Consently.escapeHtml(file) + '</code></div>';
						});
						html += '</div>';
					}

					html += '</div></td></tr>';
				});

				html += '</tbody></table></div>';
			} else if (phase1) {
				html += '<div class="consently-card">';
				html += '<h3><span class="dashicons dashicons-yes-alt"></span> No tracking services detected</h3>';
				html += '<p>No cookies or tracking scripts requiring consent were found on your site.</p>';
				html += '</div>';
			}

			// ── Section 2: Third-Party Content (tags not already in services) ──
			if (phase2) {
				var serviceNames = actionableServices.map(function(s) { return s.name.toLowerCase(); });
				var extraTags = [];

				['thirdparty', 'social_media', 'statistics'].forEach(function(key) {
					if (phase2[key]) {
						phase2[key].forEach(function(name) {
							var matched = false;
							serviceNames.forEach(function(sn) {
								if (sn.indexOf(name.toLowerCase()) !== -1 || name.toLowerCase().indexOf(sn) !== -1) {
									matched = true;
								}
							});
							if (!matched) {
								extraTags.push({ name: name, type: key });
							}
						});
					}
				});

				if (extraTags.length > 0) {
					html += '<div class="consently-card">';
					html += '<h3><span class="dashicons dashicons-editor-code"></span> Additional third-party content</h3>';
					html += '<p class="consently-muted">These external services were detected loading on your pages.</p>';
					html += '<div class="consently-tag-list">';
					extraTags.forEach(function(tag) {
						var tagClass = 'consently-tag-thirdparty';
						if (tag.type === 'social_media') tagClass = 'consently-tag-social';
						if (tag.type === 'statistics') tagClass = 'consently-tag-analytics';
						html += '<span class="consently-tag ' + tagClass + '">' + Consently.escapeHtml(tag.name) + '</span>';
					});
					html += '</div></div>';
				}
			}

			// ── Section 3: Browser Storage ──
			if (phase2 && phase2.live_storage && phase2.live_storage.length > 0) {
				html += '<div class="consently-card">';
				html += '<h3><span class="dashicons dashicons-database"></span> Browser Storage (' + phase2.live_storage.length + ')</h3>';
				html += '<table class="consently-audit-table widefat">';
				html += '<thead><tr><th>Key</th><th>Type</th><th>Category</th><th>Service</th></tr></thead>';
				html += '<tbody>';

				phase2.live_storage.forEach(function(item) {
					html += '<tr>';
					html += '<td><code>' + Consently.escapeHtml(item.name) + '</code></td>';
					html += '<td>' + Consently.escapeHtml(item.type) + '</td>';
					html += '<td><span class="consently-category consently-category-' + Consently.escapeHtml(item.category || 'other') + '">' + Consently.escapeHtml(Consently.capitalize(item.category || 'other')) + '</span></td>';
					html += '<td>' + Consently.escapeHtml(item.service || 'Unknown') + '</td>';
					html += '</tr>';
				});

				html += '</tbody></table></div>';
			}

			// ── Section 4: Warnings ──
			var warnings = '';

			if (phase2 && phase2.double_stats && phase2.double_stats.length > 0) {
				warnings += '<div class="consently-notice consently-notice-warning">';
				warnings += '<span class="dashicons dashicons-warning"></span>';
				warnings += '<p><strong>Duplicate analytics detected:</strong> ' + Consently.escapeHtml(phase2.double_stats.join(', '));
				warnings += '. Multiple instances of the same analytics service may cause inaccurate data.</p>';
				warnings += '</div>';
			}

			if (phase2 && phase2.live_cookies) {
				var unclassified = phase2.live_cookies.filter(function(c) { return c.category === 'unclassified'; });
				if (unclassified.length > 0) {
					var names = unclassified.map(function(c) { return c.name; }).join(', ');
					warnings += '<div class="consently-notice consently-notice-warning">';
					warnings += '<span class="dashicons dashicons-info"></span>';
					warnings += '<p><strong>' + unclassified.length + ' unclassified cookie' + (unclassified.length !== 1 ? 's' : '') + ':</strong> ';
					warnings += '<code>' + Consently.escapeHtml(names) + '</code>. These may need manual review.</p>';
					warnings += '</div>';
				}
			}

			if (warnings) {
				html += '<div class="consently-card">';
				html += '<h3><span class="dashicons dashicons-flag"></span> Warnings</h3>';
				html += warnings;
				html += '</div>';
			}

			// ── Section 5: WordPress Core Cookies (collapsed) ──
			if (phase1 && phase1.wordpress_cookies && phase1.wordpress_cookies.length > 0) {
				html += '<div class="consently-card consently-wp-cookies">';
				html += '<h3><span class="dashicons dashicons-wordpress"></span> WordPress core cookies</h3>';
				html += '<details><summary>' + phase1.wordpress_cookies.length + ' WordPress cookies identified — these are necessary for site functionality</summary>';
				html += '<table class="consently-audit-table widefat">';
				html += '<thead><tr><th>Cookie</th><th>Category</th><th>Duration</th><th>Purpose</th></tr></thead>';
				html += '<tbody>';

				phase1.wordpress_cookies.forEach(function(cookie) {
					html += '<tr>';
					html += '<td><code>' + Consently.escapeHtml(cookie.name) + '</code></td>';
					html += '<td><span class="consently-category consently-category-' + Consently.escapeHtml(cookie.category) + '">' + Consently.escapeHtml(Consently.capitalize(cookie.category)) + '</span></td>';
					html += '<td>' + Consently.escapeHtml(cookie.duration) + '</td>';
					html += '<td><small>' + Consently.escapeHtml(cookie.purpose) + '</small></td>';
					html += '</tr>';
				});

				html += '</tbody></table></details></div>';
			}

			// ── Section 6: Clean Plugins (collapsed) ──
			if (phase1 && phase1.clean_plugins && phase1.clean_plugins.length > 0) {
				html += '<div class="consently-card consently-clean-plugins">';
				html += '<h3><span class="dashicons dashicons-yes"></span> ';
				html += phase1.clean_plugins.length + ' plugin' + (phase1.clean_plugins.length !== 1 ? 's' : '') + ' without detected tracking</h3>';
				html += '<details><summary>Show clean plugins</summary>';
				html += '<ul class="consently-clean-list">';

				phase1.clean_plugins.forEach(function(plugin) {
					html += '<li>' + Consently.escapeHtml(plugin.name);
					if (plugin.cookies && plugin.cookies.length) {
						html += ' <small class="consently-muted">(' + plugin.cookies.length + ' functional cookies)</small>';
					}
					html += '</li>';
				});

				html += '</ul></details></div>';
			}

			$('#consently-audit-results').html(html);

			// Bind expand/collapse toggles
			$('.consently-service-toggle').on('click', function() {
				var $btn = $(this);
				var $row = $btn.closest('tr');
				var $detailsRow = $row.next('.consently-service-details-row');
				var isExpanded = $btn.attr('aria-expanded') === 'true';

				$btn.attr('aria-expanded', !isExpanded);
				$btn.text(isExpanded ? 'Details' : 'Hide');
				$detailsRow.toggle();
			});
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
