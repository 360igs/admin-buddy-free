=== Admin Buddy ===
Contributors:      igse
Tags:              admin, white-label, coming-soon, smtp, login
Requires at least: 6.4
Tested up to:      6.9
Requires PHP:      8.1
Stable tag:        1.0.1
Donate link:       https://wpadminbuddy.com
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

A modular WordPress admin enhancement suite - branding, colour schemes, login page, maintenance mode, SMTP, snippets, user roles, and quick settings.

== Description ==

Admin Buddy is a modular admin enhancement suite for WordPress. Each feature is an independent module you can enable or disable from the Setup screen. No feature runs unless you turn it on.

**Modules included:**

* **White Label** - rebrand the admin: custom greeting, agency name, sidebar logo, admin bar cleanup, dashboard widgets, hide update nags, footer text, and more
* **Colours** - apply a colour scheme to the entire WP admin, login page, and admin bar
* **Login** - style the WordPress login page with your brand
* **Maintenance** - Coming Soon and Maintenance mode with bypass URLs and emergency access
* **SMTP** - configure outgoing email with multiple provider presets and email logging
* **Snippets** - manage CSS, JavaScript, and HTML code snippets with hook targeting and scope control
* **User Roles** - capability matrix editor with backup and restore
* **Quick Settings** - one-click toggles for common WordPress housekeeping (disable feeds, emojis, XML-RPC, REST API, etc.)

**Additional modules in [Admin Buddy Pro](https://wpadminbuddy.com):**

* Menu Customiser, Custom Pages, Notices & Updates, Auto Palette, Icon Library, Option Pages, Collections, Activity Log, Bricks Builder, Remote Sync, Export / Import, Demo Data, Blueprints, PHP Snippets

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

1. Setup screen - enable or disable each module independently.
2. Colour scheme editor - full admin palette with live preview.
3. SMTP configuration - encrypted password storage, test email, provider presets.
4. Maintenance mode - Coming Soon and Maintenance pages with HMAC bypass tokens.
5. Code Snippets - CSS, JS, HTML snippets with syntax checking.

== Source Code ==

The complete source code for the free build is available at:
https://github.com/360igs/admin-buddy-free

The repository is published on each release of the free build and contains the exact files in the WordPress.org-hosted zip. Pro source is private; only the licensed Pro distribution is available to customers.

== Changelog ==

= 1.0.1 =
* Initial public release on WordPress.org. Admin Buddy is a modular admin enhancement suite - White Label, Colours, Login, Maintenance, SMTP, Snippets, User Roles, and Quick Settings - that you enable per-module from the Setup screen.

== Upgrade Notice ==

= 1.0.1 =
Initial public release.
