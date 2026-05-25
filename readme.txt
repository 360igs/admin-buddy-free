=== Admin Buddy ===
Contributors:      igse
Tags:              admin, white-label, coming-soon, smtp, login
Requires at least: 6.4
Tested up to:      7.0
Requires PHP:      8.1
Stable tag:        1.0.2
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

A modular WordPress admin enhancement suite - branding, colour schemes, login page, maintenance mode, SMTP, user roles, and quick settings.

== Description ==

Admin Buddy is a modular admin enhancement suite for WordPress. Each feature is an independent module you can enable or disable from the Setup screen. No feature runs unless you turn it on.

**Modules included:**

* **White Label** - rebrand the admin: custom greeting, agency name, sidebar logo, admin bar cleanup, dashboard widgets, hide update nags, footer text, and more
* **Colours** - apply a colour scheme to the entire WP admin, login page, and admin bar
* **Login** - style the WordPress login page with your brand
* **Maintenance** - Coming Soon and Maintenance mode with bypass URLs and emergency access
* **SMTP** - configure outgoing email with multiple provider presets and email logging
* **User Roles** - capability matrix editor with backup and restore
* **Quick Settings** - one-click toggles for common WordPress housekeeping (disable feeds, emojis, XML-RPC, REST API, etc.)

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate through **Plugins → Installed Plugins**.
3. Go to **Admin Buddy** in the admin menu.
4. Enable the modules you want in **Setup → Modules**.

== Frequently Asked Questions ==

= Does it work with my theme? =

Admin Buddy only affects the WordPress admin area. It has no effect on your public-facing theme except for the Login module (which styles `wp-login.php`) and Maintenance mode (which gates the frontend for non-logged-in users).

= What PHP version do I need? =

PHP 8.1 or higher.

= Does it use jQuery? =

No. All Admin Buddy scripts in the free build are Vanilla ES6+. jQuery is not declared as a dependency for any free-build script.

= Can I build add-on modules? =

Not yet - a public module API is planned for a future release. Right now, all modules are bundled.

== Screenshots ==

1. Setup screen - enable any of seven modules independently with a single toggle.
2. Colours - apply a ready-made colour scheme across the admin, login, and admin bar, with a live preview.
3. White Label - replace WordPress branding with your own: site favicon, sidebar logo, agency name, admin bar, and dashboard greeting.
4. Login - restyle the WordPress login page with three layouts (Left, Center, Right), brand colours, and your logo, with a live preview.
5. Maintenance - Coming Soon and Maintenance modes with custom bypass paths and a regeneratable emergency access URL.
6. SMTP - route outgoing WordPress mail through a custom server with encrypted credentials and a built-in test-email tool.
7. User Roles - a capability-matrix editor with per-role backups, cloning, rename, and reset.
8. Quick Settings - one-toggle housekeeping for common WordPress tweaks: disable feeds, emojis, XML-RPC, REST API access, and more.

== Source Code ==

The complete source code is available at:
https://github.com/360igs/admin-buddy-free

The repository is published on each release and contains the exact files in the WordPress.org-hosted zip.

== External services ==

The SMTP module can route outgoing WordPress email through a third-party SMTP server when the site administrator chooses to enable it.

The plugin does not include hardcoded connections to any specific email service. The administrator supplies the SMTP host, port, encryption setting, and credentials in the SMTP module's settings. No data is sent to any external server until the administrator saves those credentials and an email is dispatched (either by WordPress itself or by the SMTP module's "Send test email" button).

When email is sent, the recipient address, message body, and any attachments are transmitted to the SMTP server the administrator configured, using the credentials supplied. The plugin neither chooses the destination server nor stores credentials anywhere outside the local site database.

Because the destination is administrator-supplied, no fixed terms-of-service or privacy-policy link applies; the relevant policy is the one published by whichever SMTP provider the administrator chooses.

== Changelog ==

= 1.0.2 =
* Fix mismatched "Tested up to" header between admin-buddy.php (6.9) and readme.txt (7.0) that triggered a Plugin Check error on install. Both now declare 7.0. No functional change.

= 1.0.1 =
* Initial public release on WordPress.org. Admin Buddy is a modular admin enhancement suite - White Label, Colours, Login, Maintenance, SMTP, User Roles, and Quick Settings - that you enable per-module from the Setup screen.

== Upgrade Notice ==

= 1.0.2 =
Fixes a Plugin Check header mismatch warning. No functional change.

= 1.0.1 =
Initial public release.
