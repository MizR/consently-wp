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

			// Diagnostics toggle
			$('.consently-diagnostics-toggle').on('click', this.toggleDiagnostics);
			$('#consently-copy-diagnostics').on('click', this.copyDiagnostics);

			// Audit
			$('#consently-run-audit').on('click', this.handleRunAudit);

			// Settings
			$('#consently-settings-form').on('submit', this.handleSaveSettings);

			// Copy text elements
			$('.consently-copy-text').on('click', this.handleCopyText);

			// Dismiss notices
			$(document).on('click', '[data-consently-notice] .notice-dismiss', this.handleDismissNotice);
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

			// Show loading state
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
						// Reload page to show connected state
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
		 * Handle disconnect button click.
		 */
		handleDisconnect: function(e) {
			e.preventDefault();

			if (!confirm(consentlyAdmin.strings.confirmDisconnect)) {
				return;
			}

			var $button = $(this);

			// Show loading state
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
						// Reload page to show disconnected state
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

		/**
		 * Handle run audit button click.
		 */
		handleRunAudit: function(e) {
			e.preventDefault();

			var $button = $(this);
			var $results = $('#consently-audit-results');

			// Show loading state
			$button.prop('disabled', true).addClass('consently-loading').text(consentlyAdmin.strings.analyzing);

			$.ajax({
				url: consentlyAdmin.ajaxUrl,
				method: 'POST',
				data: {
					action: 'consently_run_audit',
					nonce: consentlyAdmin.nonce
				},
				success: function(response) {
					if (response.success) {
						Consently.renderAuditResults(response.data.results);
						$results.show();
					} else {
						alert(response.data.message);
					}
					$button.prop('disabled', false).removeClass('consently-loading').text('Analyze Plugins');
				},
				error: function() {
					alert('An error occurred. Please try again.');
					$button.prop('disabled', false).removeClass('consently-loading').text('Analyze Plugins');
				}
			});
		},

		/**
		 * Render audit results.
		 */
		renderAuditResults: function(results) {
			var html = '';

			// Partial scan warning
			if (results.partial_scan) {
				html += '<div class="consently-notice consently-notice-warning">';
				html += '<span class="dashicons dashicons-warning"></span>';
				html += 'Partial scan completed. Some plugins may not have been fully analyzed due to time or file limits.';
				html += '</div>';
			}

			// Tracking plugins
			if (results.tracking_plugins && results.tracking_plugins.length > 0) {
				html += '<div class="consently-card consently-tracking-plugins">';
				html += '<h3><span class="dashicons dashicons-warning"></span> ';
				html += results.tracking_plugins.length + ' plugin' + (results.tracking_plugins.length !== 1 ? 's' : '') + ' may set tracking cookies</h3>';
				html += '<table class="consently-audit-table widefat">';
				html += '<thead><tr><th>Plugin</th><th>Detected Trackers</th><th>Category</th></tr></thead>';
				html += '<tbody>';

				results.tracking_plugins.forEach(function(plugin) {
					html += '<tr>';
					html += '<td>' + Consently.escapeHtml(plugin.name) + '</td>';
					html += '<td>' + (plugin.domains && plugin.domains.length ? Consently.escapeHtml(plugin.domains.join(', ')) : '<em>Pattern detected</em>') + '</td>';
					html += '<td><span class="consently-category consently-category-' + Consently.escapeHtml(plugin.category) + '">' + Consently.escapeHtml(plugin.category.charAt(0).toUpperCase() + plugin.category.slice(1)) + '</span></td>';
					html += '</tr>';
				});

				html += '</tbody></table></div>';
			}

			// Clean plugins
			if (results.clean_plugins && results.clean_plugins.length > 0) {
				html += '<div class="consently-card consently-clean-plugins">';
				html += '<h3><span class="dashicons dashicons-yes"></span> ';
				html += results.clean_plugins.length + ' plugin' + (results.clean_plugins.length !== 1 ? 's' : '') + ' without detected tracking</h3>';
				html += '<details><summary>Show clean plugins</summary>';
				html += '<ul class="consently-clean-list">';

				results.clean_plugins.forEach(function(plugin) {
					html += '<li>' + Consently.escapeHtml(plugin.name) + '</li>';
				});

				html += '</ul></details></div>';
			}

			// No plugins
			if ((!results.tracking_plugins || results.tracking_plugins.length === 0) &&
				(!results.clean_plugins || results.clean_plugins.length === 0)) {
				html += '<div class="consently-card"><p>No active plugins found to analyze.</p></div>';
			}

			// Scan time
			html += '<p class="consently-scan-time">Scan completed in ' + results.scan_time + ' seconds.</p>';

			$('#consently-audit-results').html(html);
		},

		/**
		 * Handle save settings form submit.
		 */
		handleSaveSettings: function(e) {
			e.preventDefault();

			var $form = $(this);
			var $button = $('#consently-save-settings');
			var $message = $('#consently-settings-message');

			// Get form values
			var bannerEnabled = $('#consently-banner-enabled').is(':checked');
			var showToAdmins = $('#consently-show-to-admins').is(':checked');

			// Show loading state
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
				// Fallback for older browsers
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
			var div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		}
	};

	// Initialize on document ready
	$(document).ready(function() {
		Consently.init();
	});

})(jQuery);
