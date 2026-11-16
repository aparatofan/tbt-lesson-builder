<?php
/**
 * The Divi post content: the templates cut from the sample lesson, filled
 * with the lesson and with the global items from the Divi library.
 *
 * Global items are read from the library every time, never copied from the
 * sample, so a later edit of a global header still reaches new lessons.
 */

defined( 'ABSPATH' ) || exit;

final class TBTLB_Layout {

	/** Library items loaded during this request, by ID. */
	private static $library = array();

	/**
	 * The whole post content, in sample order.
	 *
	 * Called twice by Create draft: first without $made, before anything is
	 * created, so a missing library item stops it early; then with the IDs
	 * of the sets and the poll it made.
	 *
	 * @param array $lesson Validated lesson (TBTLB_Rest::validate()).
	 * @param array $made   { sets: int[] keyed by part index (none for a linked part), poll: int }.
	 * @return string|WP_Error
	 */
	public static function build( array $lesson, array $made = array() ) {
		$cat = TBTLB_Config::category( $lesson['category'] );

		// Every library item first, so one missing item fails before anything else.
		$items = array(
			'mobile'   => array( TBTLB_Config::MOBILE_SECTION, 'et_pb_section', false ),
			'category' => array( $cat['icon'], 'et_pb_blurb', true ),
			'level'    => array( TBTLB_Config::LEVELS[ $lesson['level'] ], 'et_pb_blurb', false ),
			'warmup'   => array( $cat['header_warmup'], 'et_pb_row', true ),
			'poll'     => array( $cat['header_poll'], 'et_pb_row', true ),
			'discuss'  => array( $cat['header_discuss'], 'et_pb_row', true ),
			'glossary' => array( $cat['header_glossary'], 'et_pb_row', true ),
			'comments' => array( $cat['header_comments'], 'et_pb_row', true ),
		);
		foreach ( array_keys( $lesson['parts'] ) as $i ) {
			$items[ 'part' . $i ] = array( $cat['header_parts'][ $i ], 'et_pb_row', true );
		}
		$g = array();
		foreach ( $items as $key => $item ) {
			$markup = self::library_item( $item[0], $item[1], $item[2] );
			if ( is_wp_error( $markup ) ) {
				return $markup;
			}
			$g[ $key ] = $markup;
		}

		$sets    = isset( $made['sets'] ) ? array_map( 'intval', $made['sets'] ) : array();
		$poll_id = isset( $made['poll'] ) ? (int) $made['poll'] : 0;
		$sc      = static function ( $html ) {
			return TBTLB_Format::escape_shortcode_text( $html );
		};
		$url     = static function ( $value ) {
			return TBTLB_Format::escape_shortcode_text( esc_url( $value ) );
		};

		$out = array();

		$out[] = self::fill( 'mobile', array( '{{MOBILE}}' => $g['mobile'] ) );
		$out[] = self::fill(
			'banner',
			array(
				'{{CATEGORY_ICON}}' => $g['category'],
				'{{LEVEL_ICON}}'    => $g['level'],
			)
		);
		$out[] = self::fill(
			'hero',
			array(
				'{{HERO_SRC}}' => $url( $lesson['images']['hero']['url'] ),
				'{{HERO_ALT}}' => esc_attr( TBTLB_Config::HERO_ALT ),
			)
		);

		$warmup = array(
			'{{HEADER_ROW}}'       => $g['warmup'],
			'{{WARMUP_SRC}}'       => $url( $lesson['images']['warmup']['url'] ),
			'{{TOGGLE_TEXT}}'      => esc_attr( $cat['toggle_text'] ),
			'{{TOGGLE_CLOSED_BG}}' => esc_attr( $cat['toggle_closed_bg'] ),
			'{{TOGGLE_BODY}}'      => esc_attr( $cat['toggle_body'] ),
		);
		foreach ( $lesson['warmup'] as $i => $w ) {
			$n                       = $i + 1;
			$warmup[ "{{Q{$n}}}" ]   = $sc( TBTLB_Format::esc( $w['question'] ) );
			$warmup[ "{{MP3_{$n}}}" ] = $url( $w['audio_url'] );
			$warmup[ "{{T{$n}}}" ]   = $sc( TBTLB_Format::paragraphs( $w['answer'] ) );
		}
		$out[] = self::fill( 'warmup', $warmup );

		foreach ( $lesson['parts'] as $i => $part ) {
			$out[] = self::fill(
				'part',
				array(
					'{{HEADER_ROW}}' => $g[ 'part' . $i ],
					'{{N}}'          => (string) ( $i + 1 ),
					'{{FLASHCARDS}}' => '' !== $part['media_url'] ? '[tbt_flashcards url="' . esc_url( $part['media_url'] ) . '"]' : '[tbt_flashcards id="' . ( isset( $sets[ $i ] ) ? (int) $sets[ $i ] : 0 ) . '"]',
					'{{ORDINAL}}'    => TBTLB_Format::ordinal( $i + 1 ),
					'{{IFRAME}}'     => TBTLB_Format::embed_iframe( $lesson['video_id'], $part['start'], $part['end'] ),
					'{{TABLE}}'      => $sc( TBTLB_Format::comprehension_html( $part['rows'], $cat['color'] ) ),
				)
			);
		}

		$out[] = self::fill(
			'poll',
			array(
				'{{HEADER_ROW}}' => $g['poll'],
				'{{POLL}}'       => tbt_poll_shortcode( $poll_id, $lesson['category'] ),
				'{{POLL_SRC}}'   => $url( $lesson['images']['poll']['url'] ),
			)
		);

		$out[] = self::fill(
			'discussion',
			array(
				'{{HEADER_ROW}}'  => $g['discuss'],
				'{{SCENARIO}}'    => $sc( TBTLB_Format::paragraphs( $lesson['scenario'], true ) ),
				'{{DIALOGUE}}'    => $sc( TBTLB_Format::parse_dialogue( $lesson['dialogue'] ) ),
				'{{QUESTIONS}}'   => $sc( TBTLB_Format::questions_table( TBTLB_Format::parse_lines( $lesson['questions'] ), $cat['bullet'] ) ),
				'{{MINDMAP_URL}}' => $url( $lesson['mindmap'] ),
			)
		);

		// Sets and linked files, each in part order; an empty attribute is left out.
		$ids  = array();
		$urls = array();
		foreach ( $lesson['parts'] as $i => $part ) {
			if ( '' !== $part['media_url'] ) {
				$urls[] = esc_url( $part['media_url'] );
			} else {
				$ids[] = isset( $sets[ $i ] ) ? (int) $sets[ $i ] : 0;
			}
		}
		$glossary = '[tbt_flashcards_glossary'
			. ( $ids ? ' ids="' . implode( ',', $ids ) . '"' : '' )
			. ( $urls ? ' urls="' . implode( ',', $urls ) . '"' : '' )
			. ' domain="' . esc_attr( $lesson['category'] ) . '"]';

		$out[] = self::fill(
			'glossary',
			array(
				'{{HEADER_ROW}}' => $g['glossary'],
				'{{GLOSSARY}}'   => $glossary,
			)
		);

		$out[] = self::fill( 'comments', array( '{{HEADER_ROW}}' => $g['comments'] ) );

		foreach ( $out as $piece ) {
			if ( is_wp_error( $piece ) ) {
				return $piece;
			}
		}
		return implode( '', $out );
	}

	/**
	 * One global item's markup from the Divi library, linked to the library
	 * with global_module="ID" (and saved_tabs="all" where the live lessons
	 * have it).
	 *
	 * Refuses an item that is missing, not global, of the wrong kind, or has
	 * options excluded from syncing (those would be taken from the page copy
	 * instead of the library).
	 *
	 * @param int    $id         et_pb_layout post ID.
	 * @param string $tag        Expected outer shortcode.
	 * @param bool   $saved_tabs Add saved_tabs="all".
	 * @return string|WP_Error
	 */
	public static function library_item( $id, $tag, $saved_tabs ) {
		$id  = (int) $id;
		$key = $id . '|' . $tag . '|' . (int) $saved_tabs;
		if ( isset( self::$library[ $key ] ) ) {
			return self::$library[ $key ];
		}

		$post = get_post( $id );
		if ( ! $post || 'et_pb_layout' !== $post->post_type || 'trash' === $post->post_status ) {
			/* translators: %d: Divi library item ID. */
			return new WP_Error( 'tbtlb_library', sprintf( __( 'Divi library item %d was not found. Check its ID in class-tbtlb-config.php.', 'tbt-lesson-builder' ), $id ) );
		}
		if ( ! has_term( 'global', 'scope', $post ) ) {
			/* translators: %d: Divi library item ID. */
			return new WP_Error( 'tbtlb_library', sprintf( __( 'Divi library item %d is not a global item.', 'tbt-lesson-builder' ), $id ) );
		}

		$excluded = get_post_meta( $id, '_et_pb_excluded_global_options', true );
		if ( is_string( $excluded ) && '' !== trim( $excluded ) ) {
			$decoded  = json_decode( $excluded, true );
			$excluded = null === $decoded ? array( $excluded ) : $decoded;
		}
		if ( ! empty( $excluded ) ) {
			/* translators: %d: Divi library item ID. */
			return new WP_Error( 'tbtlb_library', sprintf( __( 'Divi library item %d has options that are not synced with the library. Make every option global in the Divi library.', 'tbt-lesson-builder' ), $id ) );
		}

		$content = trim( (string) $post->post_content );
		if ( ! preg_match( '/^\[(et_pb_[a-z_]+)((?:\s[^\]]*)?)\]/', $content, $m ) || $m[1] !== $tag ) {
			/* translators: 1: Divi library item ID, 2: Divi module name. */
			return new WP_Error( 'tbtlb_library', sprintf( __( 'Divi library item %1$d is not a %2$s.', 'tbt-lesson-builder' ), $id, $tag ) );
		}

		$attrs = $m[2];
		if ( preg_match( '/\sglobal_module="[^"]*"/', $attrs ) ) {
			$attrs = preg_replace( '/\sglobal_module="[^"]*"/', ' global_module="' . $id . '"', $attrs );
		} else {
			$attrs .= ' global_module="' . $id . '"';
		}
		if ( $saved_tabs && ! preg_match( '/\ssaved_tabs="/', $attrs ) ) {
			$attrs .= ' saved_tabs="all"';
		}

		self::$library[ $key ] = '[' . $tag . $attrs . ']' . substr( $content, strlen( $m[0] ) );
		return self::$library[ $key ];
	}

	/**
	 * A template with its placeholders filled. Every placeholder in the
	 * template must have a value, so nothing half-filled is written.
	 *
	 * @param string $name   Template name (templates/{name}.txt).
	 * @param array  $values Placeholder => value.
	 * @return string|WP_Error
	 */
	private static function fill( $name, array $values ) {
		$file = TBTLB_DIR . 'templates/' . $name . '.txt';
		$tpl  = is_readable( $file ) ? file_get_contents( $file ) : false;
		if ( false === $tpl ) {
			/* translators: %s: template file name. */
			return new WP_Error( 'tbtlb_template', sprintf( __( 'The template %s.txt is missing.', 'tbt-lesson-builder' ), $name ) );
		}
		$tpl = rtrim( $tpl, "\r\n" );

		preg_match_all( '/\{\{[A-Z0-9_]+\}\}/', $tpl, $m );
		$missing = array_diff( array_unique( $m[0] ), array_keys( $values ) );
		if ( $missing ) {
			/* translators: 1: template file name, 2: placeholder names. */
			return new WP_Error( 'tbtlb_template', sprintf( __( 'The template %1$s.txt has no value for %2$s.', 'tbt-lesson-builder' ), $name, implode( ', ', $missing ) ) );
		}

		// One pass, so a value that happens to contain {{…}} is not filled again.
		return strtr( $tpl, $values );
	}
}
