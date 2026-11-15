<?php
/**
 * Every ID, colour, voice and ordinal the builder uses. Nowhere else.
 *
 * Pure PHP (no WordPress calls), so tests/test-format.php can load it.
 * The category keys are the poll and glossary `domain` values.
 */

defined( 'ABSPATH' ) || exit;

final class TBTLB_Config {

	/**
	 * What the category decides. Header rows, icons and the mobile section
	 * are Divi library post IDs (global items). Colours and the bullet come
	 * from the live GI and PD lessons.
	 */
	const CATEGORIES = array(
		'gi' => array(
			'label'            => 'Man and Society',
			'wp_category'      => 'Man and Society',
			'family'           => 'General Interest',
			'icon'             => 30080,
			'color'            => '#663366',
			'header_warmup'    => 365603,
			'header_parts'     => array( 365477, 365476, 365475, 365474, 365473, 365472, 365471, 365470, 365469, 365468 ),
			'header_poll'      => 365605,
			'header_discuss'   => 365607,
			'header_glossary'  => 365609,
			'header_comments'  => 365611,
			'toggle_text'      => '#663366',
			'toggle_closed_bg' => 'rgba(102,51,102,0.44)',
			'toggle_body'      => '#660000',
			'bullet'           => 'https://thebluetree.pl/wp-content/uploads/2020/12/bullet-general.svg',
		),
		'pd' => array(
			'label'            => 'Personal Development',
			'wp_category'      => 'Personal Development',
			'family'           => 'Personal Development',
			'icon'             => 30087,
			'color'            => '#006600',
			'header_warmup'    => 365564,
			'header_parts'     => array( 365565, 365566, 365567, 365568, 365569, 365570, 365571, 365572, 367473, 370906 ),
			'header_poll'      => 365573,
			'header_discuss'   => 365575,
			'header_glossary'  => 365576,
			'header_comments'  => 365577,
			'toggle_text'      => '#006600',
			'toggle_closed_bg' => 'rgba(0,102,0,0.31)',
			'toggle_body'      => '#660000',
			'bullet'           => 'https://thebluetree.pl/wp-content/uploads/2020/12/bullet-personal.svg',
		),
	);

	/** Level icons (global modules). */
	const LEVELS = array(
		'A1' => 30105,
		'A2' => 30113,
		'B1' => 30115,
		'B2' => 30116,
		'C1' => 30117,
		'C2' => 30118,
	);

	/** The mobile-only notice section (global). */
	const MOBILE_SECTION = 232953;

	/** Warm-up characters and their ElevenLabs voice IDs. */
	const VOICES = array(
		'Alita' => 'cgSgspJ2msm6clMCkdW9',
		'Pete'  => 'UaYTS0wayjmO9KD1LR4R',
		'Sven'  => 'L0Dsvb3SLTyegXwtm47J',
		'Kat'   => 'hpp4J3VqNfWAUOO0d1Us',
	);

	/** "Watch the {ordinal} part of the video." */
	const ORDINALS = array( 'first', 'second', 'third', 'fourth', 'fifth', 'sixth', 'seventh', 'eighth', 'ninth', 'tenth' );

	const MAX_PARTS = 10;

	/** Hero image alt and title text, as on the live lessons. */
	const HERO_ALT = 'Practise speaking by describing this image';

	/** The only host a mind-map link may point to (or a subdomain of it). */
	const MINDMAP_HOST = 'mindomo.com';

	/**
	 * Divi post meta, copied from a live lesson. Divi's caches and A/B
	 * testing keys are deliberately absent: Divi rebuilds the caches.
	 */
	const POST_META = array(
		'_et_post_bg_color'          => '#ffffff',
		'_et_post_bg_layout'         => 'light',
		'_et_pb_show_title'          => 'off',
		'_et_pb_post_hide_nav'       => 'default',
		'_et_pb_page_layout'         => 'et_full_width_page',
		'_et_pb_side_nav'            => 'off',
		'_et_pb_use_builder'         => 'on',
		'_et_pb_old_content'         => '',
		'_et_pb_built_for_post_type' => 'page',
		'_et_pb_gutter_width'        => '2',
		'_et_builder_version'        => 'VB|Divi|4.27.9',
		'_et_pb_show_page_creation'  => 'off',
	);

	/** `_et_pb_custom_css` on every new lesson: the warm-up audio player. */
	const CUSTOM_CSS = <<<'CSS'
/* Basic styles for the audio player */
.mejs-container, .mejs-embed, .mejs-embed body, .mejs-container .mejs-controls {
    width: 50px !important;
    background: #0856C9 !important;
    border-radius: 15px;
}

/* Hide unnecessary elements and only show the play button */
.mejs-container .mejs-controls > *:not(.mejs-playpause-button) {
    display: none;
}

/* Hover effects for the audio player */
.mejs-container:hover, .mejs-embed:hover, .mejs-embed:hover body, .mejs-container:hover .mejs-controls {
    background: #B3D1F2 !important; /* Light blue color on hover */
}

/* Styles for the play button */
.mejs-play button:before, .mejs-pause button:before {
    color: #FFF !important;
}

.mejs-container:hover .mejs-play button:before, .mejs-container:hover .mejs-pause button:before {
    color: #0856C9 !important;
}
CSS;

	/**
	 * @param string $key 'gi' or 'pd'.
	 * @return array|null
	 */
	public static function category( $key ) {
		return isset( self::CATEGORIES[ $key ] ) ? self::CATEGORIES[ $key ] : null;
	}
}
