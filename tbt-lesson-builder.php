<?php
/**
 * Plugin Name:       TBT Lesson Builder
 * Description:       Fill in a lesson once and get the Divi draft: flashcard sets, poll, warm-up recordings and the whole layout.
 * Version:           0.2.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            The Blue Tree
 * Text Domain:       tbt-lesson-builder
 */

defined( 'ABSPATH' ) || exit;

define( 'TBTLB_VERSION', '0.2.0' );
define( 'TBTLB_FILE', __FILE__ );
define( 'TBTLB_DIR', plugin_dir_path( __FILE__ ) );
define( 'TBTLB_URL', plugin_dir_url( __FILE__ ) );

require_once TBTLB_DIR . 'includes/class-tbtlb-config.php';
require_once TBTLB_DIR . 'includes/class-tbtlb-format.php';
require_once TBTLB_DIR . 'includes/class-tbtlb-layout.php';
require_once TBTLB_DIR . 'includes/class-tbtlb-rest.php';
require_once TBTLB_DIR . 'includes/class-tbtlb-admin.php';

/**
 * Register this plugin on the TBT Hub Overview page. A filter on Hub's own
 * hook: when Hub is absent nothing applies it.
 *
 * @param array $items Existing hub items.
 * @return array
 */
function tbtlb_register_hub_item( $items ) {
	$items   = is_array( $items ) ? $items : array();
	$items[] = array(
		'slug'        => TBTLB_Admin::SLUG,
		'title'       => 'TBT Lesson Builder',
		'description' => 'Fill in a lesson once and get the Divi draft',
		'capability'  => 'manage_options',
		'url'         => admin_url( 'admin.php?page=' . TBTLB_Admin::SLUG ),
	);
	return $items;
}
add_filter( 'tbt_hub_items', 'tbtlb_register_hub_item' );

// Flashcards, Poll, Divi and Hub are checked when used, never here, so none
// of them being missing can stop this plugin loading.
add_action(
	'plugins_loaded',
	static function () {
		TBTLB_Rest::register();
		TBTLB_Admin::register();
	}
);
