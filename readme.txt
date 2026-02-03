=== Consently - Cookie Consent & GDPR Compliance ===
Contributors: consently
Tags: cookie consent, GDPR, CCPA, privacy, cookie banner, consent management
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WordPress site to Consently.net for comprehensive cookie consent management, GDPR/CCPA compliance, and customizable privacy banners.

== Description ==

Consently is a powerful Consent Management Platform (CMP) that helps your WordPress site comply with privacy regulations like GDPR, CCPA, and ePrivacy. This official WordPress plugin seamlessly connects your site to Consently.net, providing a complete solution for cookie consent management.

= Key Features =

* **Easy Setup** - Connect your site with a single API key
* **Cookie Consent Banner** - Customizable banner designed in the Consently dashboard
* **Automatic Script Blocking** - Block tracking scripts until consent is given
* **Google Consent Mode v2** - Full support for Google's consent framework
* **Consent Logging** - Maintain records of visitor consent for compliance
* **Cookie Scanner** - Identify and categorize cookies on your site
* **Plugin Audit** - Analyze installed plugins for potential tracking behavior
* **WP Consent API** - Integration with WordPress ecosystem

= How It Works =

1. Create a free account at [Consently.net](https://consently.net)
2. Install and activate this plugin
3. Enter your API key in Settings > Consently
4. Customize your banner in the Consently dashboard
5. Your site is now compliant!

= Dashboard Features =

Access powerful features through the Consently dashboard:

* **Banner Editor** - Customize colors, text, and behavior
* **Cookie Scanner** - Automatically detect cookies on your site
* **Consent Logs** - View and export consent records
* **Site Settings** - Configure blocking rules and consent model

= Plugin Audit =

The built-in Plugin Audit feature analyzes your active WordPress plugins to identify which ones may set tracking cookies. This helps you understand your site's tracking landscape and configure Consently appropriately.

Note: The audit is informational only and results do not imply regulatory non-compliance.

= WP Consent API Compatible =

Consently integrates with the [WP Consent API](https://wordpress.org/plugins/wp-consent-api/) to communicate consent preferences to other compatible plugins.

== External Service ==

This plugin connects to Consently.net, an external consent management service. A Consently account is required for this plugin to function.

**Data sent to Consently:**

* Site URL (during connection setup)
* Site name (during connection setup)

**Data handled by the Consently CDN script (consently.js):**

* Visitor consent preferences (stored in browser and sent to Consently)
* Cookie scanning data (via the Consently dashboard scanner)

The Consently CDN script (https://app.consently.net/consently.js) is loaded on your site to display the consent banner, manage script blocking, and handle consent storage.

**Service Links:**

* [Consently Website](https://consently.net)
* [Privacy Policy](https://consently.net/privacy)
* [Terms of Service](https://consently.net/terms)

== Installation ==

1. Upload the `consently` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > Consently
4. Enter your API key (get one at [app.consently.net](https://app.consently.net))
5. Click Connect

== Frequently Asked Questions ==

= Do I need a Consently account? =

Yes, a Consently account is required. You can create a free account at [consently.net](https://consently.net).

= Where do I get my API key? =

Log in to your Consently dashboard at [app.consently.net](https://app.consently.net) and navigate to your account settings to find your API key.

= Does this plugin work with caching plugins? =

Yes, but you may need to exclude the Consently script from JavaScript optimization. The plugin detects popular cache plugins and provides exclusion rules in the Settings tab.

= Does this work with multisite? =

Yes, the plugin supports WordPress multisite installations. Each site in the network can have its own Consently connection and settings.

= What privacy regulations does Consently support? =

Consently helps with compliance for GDPR (EU), CCPA (California), LGPD (Brazil), POPIA (South Africa), and other privacy regulations.

= Can I customize the consent banner? =

Yes, the banner is fully customizable through the Consently dashboard. You can change colors, text, button styles, position, and behavior.

= How does script blocking work? =

The Consently CDN script automatically detects and blocks tracking scripts until the visitor provides consent. This is handled entirely by the CDN script, not the WordPress plugin.

== Screenshots ==

1. Connection tab - Connect your site to Consently
2. Connected state with dashboard links
3. Plugin Audit - Analyze plugins for tracking
4. Settings tab - Configure plugin options

== Changelog ==

= 0.0.1 =
* Initial development build
* API connection and site registration
* CDN script injection with banner ID
* Plugin Audit for tracking detection
* WP Consent API integration
* Cache plugin compatibility detection
* Multisite support
* Diagnostics for troubleshooting
* Test mode for development

== Upgrade Notice ==

= 0.0.1 =
Initial development build. Not for production use.
