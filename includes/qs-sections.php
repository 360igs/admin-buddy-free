<?php
/**
 * Quick Settings config: the single source of truth for every Quick Setting.
 *
 * Returned as an ordered array of sections, each with an 'items' map of
 * option_key => [ label, desc, roles? ]. Consumed by:
 *   - render-tab-quick-settings.php  (draws the toggle UI + the TOC)
 *   - Settings::qs_sections()        (wrapper the AJAX handlers call)
 *   - Settings::qs_option_keys()     (derives the save/bulk key list from this)
 *
 * Add or remove a toggle HERE and it propagates automatically to the UI, the
 * single-toggle save (ajax_qs_toggle), and Enable all / Disable all
 * (ajax_qs_bulk). Nothing else needs editing. Wrapping an item in the Pro
 * build markers keeps it out of the Free build for both the UI and the key
 * list, since this whole file is marker-stripped at build time.
 *
 * @package Admbud
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

return [
    [
        'icon'  => '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><polyline points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
        'title' => __( 'Performance', 'admin-buddy' ),
        'desc'  => __( 'Remove unnecessary scripts and markup WordPress loads by default.', 'admin-buddy' ),
        'items' => [
            'admbud_qs_disable_emoji'          => [
                'label' => __( 'Disable emoji scripts', 'admin-buddy' ),
                'desc'  => __( 'Removes the emoji detection script from every page load. Native browser emoji rendering still works.', 'admin-buddy' ),
            ],
            'admbud_qs_disable_jquery_migrate' => [
                'label' => __( 'Remove jQuery Migrate', 'admin-buddy' ),
                'desc'  => __( 'Dequeues jQuery Migrate on the frontend. Skip if your theme needs it.', 'admin-buddy' ),
            ],
            'admbud_qs_remove_feed_links'      => [
                'label' => __( 'Remove feed links from &lt;head&gt;', 'admin-buddy' ),
                'desc'  => __( 'Removes RSS/Atom discovery links from &lt;head&gt;. Feeds still work via direct URL.', 'admin-buddy' ),
            ],
            'admbud_qs_remove_rsd'             => [
                'label' => __( 'Remove RSD link', 'admin-buddy' ),
                'desc'  => __( 'Removes the Really Simple Discovery link. Only needed by external blog editors like MarsEdit.', 'admin-buddy' ),
            ],
            'admbud_qs_remove_wlw'             => [
                'label' => __( 'Remove WLW manifest link', 'admin-buddy' ),
                'desc'  => __( 'Removes the Windows Live Writer manifest link from the page source.', 'admin-buddy' ),
            ],
            'admbud_qs_remove_shortlink'       => [
                'label' => __( 'Remove shortlink', 'admin-buddy' ),
                'desc'  => __( 'Removes the shortlink tag from &lt;head&gt;. Not needed unless you use wp.me shortlinks.', 'admin-buddy' ),
            ],
            'admbud_qs_remove_restapi_link'    => [
                'label' => __( 'Remove REST API discovery link', 'admin-buddy' ),
                'desc'  => __( 'Removes the REST API link tag from &lt;head&gt;. Does not disable the REST API itself.', 'admin-buddy' ),
            ],
            'admbud_qs_disable_embeds'         => [
                'label' => __( 'Disable WordPress embeds', 'admin-buddy' ),
                'desc'  => __( 'Removes embed scripts and prevents your content from being embedded on other sites via oEmbed.', 'admin-buddy' ),
            ],
        ],
    ],
    [
        'icon'  => '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
        'title' => __( 'Security', 'admin-buddy' ),
        'desc'  => __( 'Close off common attack vectors and restrict sensitive areas.', 'admin-buddy' ),
        'items' => [
            'admbud_qs_disable_xmlrpc'    => [
                'label' => __( 'Disable XML-RPC', 'admin-buddy' ),
                'desc'  => __( 'Turns off the XML-RPC endpoint. Skip if you use Jetpack or mobile apps.', 'admin-buddy' ),
            ],
            'admbud_qs_disable_rest_api'  => [
                'label' => __( 'Restrict REST API to logged-in users', 'admin-buddy' ),
                'desc'  => __( 'Returns 401 for unauthenticated REST requests. Good for private sites.', 'admin-buddy' ),
            ],
            'admbud_qs_remove_version'    => [
                'label' => __( 'Remove generator meta tag', 'admin-buddy' ),
                'desc'  => __( 'Removes the WordPress version number from HTML source and RSS feeds. Prevents version fingerprinting.', 'admin-buddy' ),
            ],
        ],
    ],
    [
        'icon'  => '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>',
        'title' => __( 'Content', 'admin-buddy' ),
        'desc'  => __( 'Adjust WordPress content defaults that are rarely needed on most sites.', 'admin-buddy' ),
        'items' => [
            'admbud_qs_disable_feeds'            => [
                'label' => __( 'Disable RSS and Atom feeds', 'admin-buddy' ),
                'desc'  => __( 'Redirects all feed URLs to the homepage. Use on sites that don\'t need RSS.', 'admin-buddy' ),
            ],
            'admbud_qs_disable_self_ping'        => [
                'label' => __( 'Disable self-pingbacks', 'admin-buddy' ),
                'desc'  => __( 'Prevents WordPress pinging itself when you link between your own posts.', 'admin-buddy' ),
            ],
            'admbud_qs_disable_comments_default' => [
                'label' => __( 'Close comments on new posts by default', 'admin-buddy' ),
                'desc'  => __( 'New posts start with comments closed. You can still enable per post.', 'admin-buddy' ),
            ],
        ],
    ],
    [
        'icon'  => '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>',
        'title' => __( 'Admin', 'admin-buddy' ),
        'desc'  => __( 'Enhancements to the WordPress admin editing experience.', 'admin-buddy' ),
        'items' => [
            'admbud_notices_suppress' => [
                'label' => __( 'Suppress Plugin Promotional Notices', 'admin-buddy' ),
                'desc'  => __( 'Hides upsell banners, review requests, and promotional notices from plugins. Core WordPress notices are preserved.', 'admin-buddy' ),
            ],
            'admbud_qs_duplicate_post' => [
                'label' => __( 'Enable Duplicate Post', 'admin-buddy' ),
                'desc'  => __( 'Adds a "Duplicate" row action to posts, pages and CPTs. Creates a draft copy with all meta and taxonomies.', 'admin-buddy' ),
            ],
            'admbud_qs_user_last_seen' => [
                'label' => __( 'User Last Seen', 'admin-buddy' ),
                'desc'  => __( 'Records login timestamps and adds a sortable "Last Seen" column to the Users list.', 'admin-buddy' ),
            ],
            'admbud_qs_hide_adminbar_frontend' => [
                'label' => __( 'Hide Admin Bar (Frontend)', 'admin-buddy' ),
                'desc'  => __( 'Hides the WordPress admin bar on the frontend for selected roles.', 'admin-buddy' ),
                'roles' => true,
            ],
            'admbud_qs_hide_adminbar_backend' => [
                'label' => __( 'Hide Admin Bar (Backend)', 'admin-buddy' ),
                'desc'  => __( 'Hides the WordPress admin bar inside the admin dashboard for selected roles.', 'admin-buddy' ),
                'roles' => true,
            ],
            'admbud_qs_hide_adminbar_checklist' => [
                'label' => __( 'Hide Checklist pill from admin bar', 'admin-buddy' ),
                'desc'  => __( 'Removes the Checklist indicator from the WordPress admin bar for selected roles. The pill stays in the Admin Buddy settings topbar.', 'admin-buddy' ),
                'roles' => true,
            ],
            'admbud_qs_hide_adminbar_noindex' => [
                'label' => __( 'Hide Noindex pill from admin bar', 'admin-buddy' ),
                'desc'  => __( 'Removes the "Search Engines Blocked" indicator from the WordPress admin bar for selected roles. The pill stays in the Admin Buddy settings topbar.', 'admin-buddy' ),
                'roles' => true,
            ],
            'admbud_qs_collapse_menu' => [
                'label' => __( 'Collapse Sidebar by Default', 'admin-buddy' ),
                'desc'  => __( 'The WordPress admin sidebar starts collapsed (icon-only) for selected roles. Users can still expand it.', 'admin-buddy' ),
                'roles' => true,
            ],
            'admbud_qs_sidebar_user_menu' => [
                'label' => __( 'Show User Menu in Sidebar', 'admin-buddy' ),
                'desc'  => __( 'Adds a user avatar, name, and logout button at the top of the admin sidebar for selected roles. Collapses to icons when sidebar is folded.', 'admin-buddy' ),
                'roles' => true,
            ],
        ],
    ],
    [
        'icon'  => '<svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
        'title' => __( 'Media', 'admin-buddy' ),
        'desc'  => __( 'Control file upload permissions and allowed formats.', 'admin-buddy' ),
        'items' => [
            'admbud_qs_allow_svg' => [
                'label' => __( 'Allow SVG uploads', 'admin-buddy' ),
                'desc'  => __( 'Enables SVG file uploads to the media library. Files are sanitised on upload. Scripts and event handlers are stripped automatically.', 'admin-buddy' ),
                'roles' => true,
            ],
        ],
    ],
];
