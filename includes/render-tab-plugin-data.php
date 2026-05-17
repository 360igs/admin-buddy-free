<?php
/**
 * Plugin Data tab - Data Management (reset/cleanup).
 * Always visible standalone tab.
 *
 * @package Admbud
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Variables scoped to this included template file.

/** @var \Admbud\Settings $settings */

require ADMBUD_DIR . 'includes/render-tab-data-management.php';
