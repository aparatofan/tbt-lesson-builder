<?php
/**
 * REST: /fill (AI for flashcards), /audio (warm-up MP3) and /create (the
 * draft). Administrators only, wp_rest nonce, no-cache headers.
 *
 * The content work belongs to other plugins: Flashcards owns sets, AI and
 * ElevenLabs (the keys never leave it), TBT Poll owns polls.
 */

defined( 'ABSPATH' ) || exit;

final class TBTLB_Rest {

	const NS = 'tbt-lesson-builder/v1';

	/** Functions this plugin calls in TBT Flashcards and TBT Poll. */
	const REQUIRED = array(
		'TBT Flashcards 2.2.0' => array( 'tbt_fc_create_set', 'tbt_fc_fill_cards', 'tbt_fc_tts_mp3', 'tbt_fc_read_csv_text', 'tbt_fc_parse_csv' ),
		'TBT Poll 0.1.0'       => array( 'tbt_poll_parse', 'tbt_poll_create', 'tbt_poll_shortcode' ),
	);

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'nocache_all' ), 10, 3 );
	}

	/**
	 * What is missing, as one line, or '' when everything is there.
	 *
	 * @return string
	 */
	public static function missing_dependencies() {
		$missing = array();
		foreach ( self::REQUIRED as $plugin => $functions ) {
			$absent = array_filter(
				$functions,
				static function ( $fn ) {
					return ! function_exists( $fn );
				}
			);
			if ( 'TBT Flashcards 2.2.0' === $plugin ) {
				if ( ! defined( 'TBT_FC_VERSION' ) || ! version_compare( TBT_FC_VERSION, '2.2.0', '>=' ) ) {
					$absent[] = 'version 2.2.0';
				}
				if ( ! shortcode_exists( 'tbt_flashcards_glossary' ) ) {
					$absent[] = '[tbt_flashcards_glossary]';
				}
			}
			if ( $absent ) {
				$missing[] = $plugin . ' (' . implode( ', ', $absent ) . ')';
			}
		}
		if ( ! $missing ) {
			return '';
		}
		/* translators: %s: plugins and the functions missing from them. */
		return sprintf( __( 'The Lesson Builder needs %s. Install or update it, then reload this page.', 'tbt-lesson-builder' ), implode( __( ' and ', 'tbt-lesson-builder' ), $missing ) );
	}

	public static function routes() {
		$common = array(
			'methods'             => 'POST',
			'permission_callback' => array( __CLASS__, 'can_build' ),
		);
		register_rest_route( self::NS, '/fill', $common + array( 'callback' => array( __CLASS__, 'fill' ) ) );
		register_rest_route( self::NS, '/audio', $common + array( 'callback' => array( __CLASS__, 'audio' ) ) );
		register_rest_route( self::NS, '/create', $common + array( 'callback' => array( __CLASS__, 'create' ) ) );
	}

	/**
	 * Administrators only. Cookie auth only counts with a valid wp_rest
	 * nonce, so without one the user is logged out here.
	 *
	 * @return bool
	 */
	public static function can_build() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * No-cache headers on every answer under this namespace, including the
	 * ones WordPress itself produces (401, an invalid nonce).
	 *
	 * @param WP_HTTP_Response $response Response.
	 * @param WP_REST_Server   $server   Server.
	 * @param WP_REST_Request  $request  Request.
	 * @return WP_HTTP_Response
	 */
	public static function nocache_all( $response, $server, $request ) {
		if ( $response instanceof WP_HTTP_Response && $request instanceof WP_REST_Request && 0 === strpos( (string) $request->get_route(), '/' . self::NS . '/' ) ) {
			foreach ( wp_get_nocache_headers() as $name => $value ) {
				if ( $value ) {
					$response->header( $name, $value );
				}
			}
		}
		return $response;
	}

	/* ---------- /fill ---------- */

	/**
	 * { items: string[], context: string } → { cards }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function fill( WP_REST_Request $request ) {
		$missing = self::missing_dependencies();
		if ( $missing ) {
			return self::error( $missing, 503 );
		}

		$items = $request->get_param( 'items' );
		if ( ! is_array( $items ) ) {
			return self::error( __( 'Send the words to fill.', 'tbt-lesson-builder' ) );
		}
		$items = array_values( array_filter( array_map( array( 'TBTLB_Format', 'js_trim' ), array_filter( $items, 'is_scalar' ) ), 'strlen' ) );
		if ( ! $items || count( $items ) > 40 ) {
			return self::error( __( 'Send between 1 and 40 words at a time.', 'tbt-lesson-builder' ) );
		}

		$context = $request->get_param( 'context' );
		$cards   = tbt_fc_fill_cards( $items, is_scalar( $context ) ? (string) $context : '' );
		if ( is_wp_error( $cards ) ) {
			return self::pass_error( $cards );
		}
		return rest_ensure_response( array( 'cards' => array_values( $cards ) ) );
	}

	/* ---------- /audio ---------- */

	/**
	 * A fingerprint of what an MP3 says and who says it, so Create draft can
	 * refuse a recording made before the answer or the character changed.
	 *
	 * @param string $character Character.
	 * @param string $text      Answer.
	 * @return string
	 */
	public static function audio_source( $character, $text ) {
		return md5( (string) $character . "\n" . TBTLB_Format::js_trim( $text ) );
	}

	/**
	 * { text, character, n, title, replace? } → { id, url }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function audio( WP_REST_Request $request ) {
		$missing = self::missing_dependencies();
		if ( $missing ) {
			return self::error( $missing, 503 );
		}

		$text      = (string) ( is_scalar( $request->get_param( 'text' ) ) ? $request->get_param( 'text' ) : '' );
		$character = (string) ( is_scalar( $request->get_param( 'character' ) ) ? $request->get_param( 'character' ) : '' );
		$n         = absint( $request->get_param( 'n' ) );
		$title     = (string) ( is_scalar( $request->get_param( 'title' ) ) ? $request->get_param( 'title' ) : '' );
		$replace   = absint( $request->get_param( 'replace' ) );

		if ( ! isset( TBTLB_Config::VOICES[ $character ] ) ) {
			return self::error( __( 'Choose who answers the question.', 'tbt-lesson-builder' ) );
		}
		if ( $n < 1 || $n > 3 ) {
			return self::error( __( 'The warm-up has questions 1 to 3.', 'tbt-lesson-builder' ) );
		}
		if ( '' === TBTLB_Format::js_trim( $text ) ) {
			return self::error( __( 'Write the answer first.', 'tbt-lesson-builder' ) );
		}

		$bytes = tbt_fc_tts_mp3( $text, TBTLB_Config::VOICES[ $character ] );
		if ( is_wp_error( $bytes ) ) {
			return self::pass_error( $bytes );
		}

		$slug     = TBTLB_Format::slug( $title );
		$filename = 'Q' . $n . '-' . ( '' !== $slug ? $slug : 'lesson' ) . '.mp3';
		$upload   = wp_upload_bits( $filename, null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			return self::error( __( 'The recording could not be saved to the Media library.', 'tbt-lesson-builder' ), 500 );
		}

		$label = sanitize_text_field( $title );
		$id    = wp_insert_attachment(
			array(
				'post_mime_type' => 'audio/mpeg',
				/* translators: 1: question number, 2: lesson title. */
				'post_title'     => sprintf( __( 'Warm-up Q%1$d — %2$s', 'tbt-lesson-builder' ), $n, '' !== $label ? $label : __( 'lesson', 'tbt-lesson-builder' ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
				'post_author'    => get_current_user_id(),
			),
			$upload['file'],
			0,
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			wp_delete_file( $upload['file'] );
			return self::error( __( 'The recording could not be saved to the Media library.', 'tbt-lesson-builder' ), 500 );
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		update_post_meta( $id, '_tbtlb_generated', 1 );
		update_post_meta( $id, '_tbtlb_source', self::audio_source( $character, $text ) );

		if ( $replace && $replace !== $id ) {
			self::delete_generated_audio( $replace );
		}

		return rest_ensure_response(
			array(
				'id'  => (int) $id,
				'url' => wp_get_attachment_url( $id ),
			)
		);
	}

	/**
	 * Delete an MP3 this form made earlier: only if this plugin generated it,
	 * the current user made it, and no post uses it yet.
	 *
	 * @param int $id Attachment ID.
	 */
	private static function delete_generated_audio( $id ) {
		$post = get_post( $id );
		if ( $post
			&& 'attachment' === $post->post_type
			&& get_post_meta( $id, '_tbtlb_generated', true )
			&& (int) $post->post_author === get_current_user_id()
			&& 0 === (int) $post->post_parent ) {
			wp_delete_attachment( $id, true );
		}
	}

	/* ---------- /create ---------- */

	/**
	 * The whole form → a draft post, flashcard sets and a poll.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create( WP_REST_Request $request ) {
		$missing = self::missing_dependencies();
		if ( $missing ) {
			return self::error( $missing, 503 );
		}

		// Without it WordPress strips the iframes and the table styles from the content.
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			return self::error( __( 'Your account cannot save embed code (unfiltered_html), so the videos and tables would be stripped. Nothing was created.', 'tbt-lesson-builder' ), 403 );
		}

		$lesson = self::validate( (array) $request->get_json_params() );
		if ( is_wp_error( $lesson ) ) {
			return $lesson;
		}

		// 1. The layout once in memory: a missing library item stops here.
		$dry = TBTLB_Layout::build( $lesson );
		if ( is_wp_error( $dry ) ) {
			return self::error( $dry->get_error_message(), 409 );
		}

		$made = array(
			'sets' => array(),
			'poll' => 0,
		);

		// 2. One flashcard set per part, except parts linked to a file in Media.
		foreach ( $lesson['parts'] as $i => $part ) {
			if ( '' !== $part['media_url'] ) {
				continue;
			}
			/* translators: 1: lesson title, 2: part number. */
			$set = tbt_fc_create_set( sprintf( __( '%1$s — part %2$d', 'tbt-lesson-builder' ), $lesson['title'], $i + 1 ), $part['cards'], $lesson['title'] );
			if ( is_wp_error( $set ) ) {
				self::undo( $made );
				/* translators: 1: part number, 2: error message. */
				return self::error( sprintf( __( 'Part %1$d: the flashcard set could not be made. %2$s', 'tbt-lesson-builder' ), $i + 1, $set->get_error_message() ), 500 );
			}
			$made['sets'][ $i ] = (int) $set;
		}

		// 3. The poll.
		$poll = tbt_poll_create( $lesson['title'], $lesson['poll'] );
		if ( is_wp_error( $poll ) ) {
			self::undo( $made );
			/* translators: %s: error message. */
			return self::error( sprintf( __( 'Poll: the poll could not be made. %s', 'tbt-lesson-builder' ), $poll->get_error_message() ), 500 );
		}
		$made['poll'] = (int) $poll;

		// 4. The final content.
		$content = TBTLB_Layout::build( $lesson, $made );
		if ( is_wp_error( $content ) ) {
			self::undo( $made );
			return self::error( $content->get_error_message(), 409 );
		}

		// 5. The draft.
		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'      => 'post',
					'post_status'    => 'draft',
					'post_title'     => $lesson['title'],
					'post_content'   => $content,
					'post_excerpt'   => $lesson['excerpt'],
					'post_category'  => array( $lesson['wp_category'] ),
					'tags_input'     => $lesson['tags'],
					'post_author'    => get_current_user_id(),
					'comment_status' => 'open',
				)
			),
			true
		);
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			self::undo( $made );
			return self::error( __( 'The draft post could not be created. Nothing was kept.', 'tbt-lesson-builder' ), 500 );
		}

		set_post_thumbnail( $post_id, $lesson['images']['featured']['id'] );
		foreach ( TBTLB_Config::POST_META as $key => $value ) {
			update_post_meta( $post_id, $key, wp_slash( $value ) );
		}
		update_post_meta( $post_id, '_et_pb_custom_css', wp_slash( TBTLB_Config::CUSTOM_CSS ) );
		update_post_meta( $post_id, '_tbtlb_level', $lesson['level'] );
		update_post_meta( $post_id, '_tbtlb_built', TBTLB_VERSION . ' ' . gmdate( 'Y-m-d H:i:s' ) );

		foreach ( $lesson['warmup'] as $w ) {
			if ( 0 === (int) get_post_field( 'post_parent', $w['audio_id'] ) ) {
				wp_update_post(
					array(
						'ID'          => $w['audio_id'],
						'post_parent' => $post_id,
					)
				);
			}
		}

		$cards = 0;
		foreach ( $lesson['parts'] as $part ) {
			$cards += count( $part['cards'] );
		}
		$questions = 0;
		foreach ( $lesson['parts'] as $part ) {
			$questions += count( $part['rows'] );
		}
		$cat    = TBTLB_Config::category( $lesson['category'] );
		$parts  = count( $lesson['parts'] );
		$sets   = count( $made['sets'] );
		$linked = $parts - $sets;
		$what   = array();
		if ( $sets ) {
			/* translators: %d: number of flashcard sets. */
			$what[] = sprintf( _n( '%d flashcard set', '%d flashcard sets', $sets, 'tbt-lesson-builder' ), $sets );
		}
		if ( $linked ) {
			/* translators: %d: number of linked CSV files. */
			$what[] = sprintf( _n( '%d linked file', '%d linked files', $linked, 'tbt-lesson-builder' ), $linked );
		}

		return rest_ensure_response(
			array(
				'post_id'     => (int) $post_id,
				'edit_url'    => get_edit_post_link( $post_id, 'raw' ),
				'builder_url' => add_query_arg(
					array(
						'et_fb'     => '1',
						'PageSpeed' => 'off',
					),
					get_permalink( $post_id )
				),
				'made'        => array(
					/* translators: 1: category, 2: level, 3: header family. */
					sprintf( __( 'Divi draft in %1$s, level %2$s, with %3$s headers', 'tbt-lesson-builder' ), $cat['label'], $lesson['level'], $cat['family'] ),
					/* translators: 1: number of parts, 2: YouTube video ID. */
					sprintf( _n( '%1$d video part cut from %2$s', '%1$d video parts cut from %2$s', $parts, 'tbt-lesson-builder' ), $parts, $lesson['video_id'] ),
					/* translators: 1: "1 flashcard set and 1 linked file", 2: number of cards. */
					sprintf( _n( '%1$s, %2$d card', '%1$s, %2$d cards', $cards, 'tbt-lesson-builder' ), implode( __( ' and ', 'tbt-lesson-builder' ), $what ), $cards ),
					/* translators: 1: number of questions, 2: number of tables. */
					sprintf( __( '%1$d comprehension questions in %2$d tables', 'tbt-lesson-builder' ), $questions, $parts ),
					/* translators: %d: number of poll questions. */
					sprintf( _n( 'TBT poll with %d question', 'TBT poll with %d questions', count( $lesson['poll'] ), 'tbt-lesson-builder' ), count( $lesson['poll'] ) ),
					__( 'Glossary over the lesson’s flashcard sets', 'tbt-lesson-builder' ),
					__( 'Three warm-up recordings attached to the post', 'tbt-lesson-builder' ),
					__( 'Featured image, excerpt, category and tags set', 'tbt-lesson-builder' ),
				),
			)
		);
	}

	/**
	 * Delete what this request created.
	 *
	 * @param array $made { sets: int[], poll: int }.
	 */
	private static function undo( array $made ) {
		foreach ( $made['sets'] as $id ) {
			wp_delete_post( $id, true );
		}
		if ( $made['poll'] ) {
			wp_delete_post( $made['poll'], true );
		}
	}

	/* ---------- Validation ---------- */

	/**
	 * A part's flashcards file from the Media library: the attachment must be
	 * a .csv, .tsv or .txt in uploads, and its cards are read from the file
	 * itself (what the lesson will show), not from the box on the page.
	 *
	 * @param int $id Attachment ID.
	 * @param int $n  Part number, for the messages.
	 * @return array|WP_Error { url: string, cards: array[] }
	 */
	private static function linked_cards( $id, $n ) {
		$url     = 'attachment' === get_post_type( $id ) ? (string) wp_get_attachment_url( $id ) : '';
		$file    = (string) get_attached_file( $id );
		$uploads = wp_upload_dir( null, false );
		$base    = empty( $uploads['baseurl'] ) ? '' : preg_replace( '#^https?:#i', '', trailingslashit( $uploads['baseurl'] ) );
		$in_up   = '' !== $base && 0 === strpos( preg_replace( '#^https?:#i', '', $url ), $base );
		if ( '' === $url || ! $in_up || ! preg_match( '/\.(csv|tsv|txt)$/i', '' !== $file ? $file : (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) {
			/* translators: %d: part number. */
			return self::error( sprintf( __( 'Part %d: the flashcards file is missing from Media.', 'tbt-lesson-builder' ), $n ) );
		}
		$name = rawurldecode( wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		$text = tbt_fc_read_csv_text( $url );
		if ( is_wp_error( $text ) ) {
			/* translators: %d: part number. */
			return self::error( sprintf( __( 'Part %d: the flashcards file is missing from Media.', 'tbt-lesson-builder' ), $n ) );
		}
		$cards = array();
		foreach ( (array) tbt_fc_parse_csv( (string) $text ) as $card ) {
			$card    = (array) $card;
			$cards[] = array(
				'word'        => isset( $card['word'] ) ? TBTLB_Format::js_trim( (string) $card['word'] ) : '',
				'phonetic'    => isset( $card['phonetic'] ) ? TBTLB_Format::js_trim( (string) $card['phonetic'] ) : '',
				'translation' => isset( $card['translation'] ) ? TBTLB_Format::js_trim( (string) $card['translation'] ) : '',
				'example'     => isset( $card['example'] ) ? TBTLB_Format::js_trim( (string) $card['example'] ) : '',
			);
		}
		if ( ! $cards ) {
			/* translators: 1: part number, 2: file name. */
			return self::error( sprintf( __( 'Part %1$d: %2$s has no flashcards.', 'tbt-lesson-builder' ), $n, $name ) );
		}
		$todo = TBTLB_Format::incomplete( $cards );
		if ( $todo ) {
			return self::error(
				sprintf(
					/* translators: 1: part number, 2: number of cards, 3: file name. */
					_n( 'Part %1$d: %2$d card in %3$s is incomplete.', 'Part %1$d: %2$d cards in %3$s are incomplete.', $todo, 'tbt-lesson-builder' ),
					$n,
					$todo,
					$name
				)
			);
		}
		if ( count( $cards ) > 300 ) {
			/* translators: 1: part number, 2: file name. */
			return self::error( sprintf( __( 'Part %1$d: %2$s has more than 300 cards.', 'tbt-lesson-builder' ), $n, $name ) );
		}
		return array(
			'url'   => $url,
			'cards' => $cards,
		);
	}

	/**
	 * Re-validate the whole form with the same helpers as the page. Returns
	 * the lesson Create draft and the layout work from, or the first problem
	 * as a plain sentence naming the stage.
	 *
	 * @param array $p Request JSON.
	 * @return array|WP_Error
	 */
	public static function validate( array $p ) {
		$str = static function ( $key, $from = null ) use ( $p ) {
			$src = null === $from ? $p : $from;
			return isset( $src[ $key ] ) && is_scalar( $src[ $key ] ) ? (string) $src[ $key ] : '';
		};
		$fail = static function ( $message ) {
			return self::error( $message );
		};

		/* Basics */
		$title = TBTLB_Format::js_trim( sanitize_text_field( $str( 'title' ) ) );
		if ( '' === $title ) {
			return $fail( __( 'Basics: give the lesson a title.', 'tbt-lesson-builder' ) );
		}
		if ( preg_match( '/[\[\]"]/', $title ) ) {
			return $fail( __( 'Basics: the title cannot contain square brackets [ ] or double quotes ".', 'tbt-lesson-builder' ) );
		}
		$excerpt = TBTLB_Format::js_trim( sanitize_textarea_field( $str( 'excerpt' ) ) );
		if ( '' === $excerpt ) {
			return $fail( __( 'Basics: write the excerpt.', 'tbt-lesson-builder' ) );
		}
		$category = $str( 'category' );
		$cat      = TBTLB_Config::category( $category );
		if ( ! $cat ) {
			return $fail( __( 'Basics: choose the category.', 'tbt-lesson-builder' ) );
		}
		if ( ! empty( $cat['wp_category_id'] ) ) {
			$term = get_term( (int) $cat['wp_category_id'], 'category' );
			if ( ! $term || is_wp_error( $term ) ) {
				/* translators: 1: category name, 2: category ID. */
				return $fail( sprintf( __( 'Category ‘%1$s’ (ID %2$d) was not found.', 'tbt-lesson-builder' ), $cat['wp_category'], (int) $cat['wp_category_id'] ) );
			}
		} else {
			$term = get_term_by( 'name', $cat['wp_category'], 'category' );
			if ( ! $term ) {
				/* translators: %s: category name. */
				return $fail( sprintf( __( 'Category ‘%s’ was not found.', 'tbt-lesson-builder' ), $cat['wp_category'] ) );
			}
		}
		$level = $str( 'level' );
		if ( ! isset( TBTLB_Config::LEVELS[ $level ] ) ) {
			return $fail( __( 'Basics: choose the level.', 'tbt-lesson-builder' ) );
		}
		$tags = array_values( array_filter( array_map( 'sanitize_text_field', array_map( array( 'TBTLB_Format', 'js_trim' ), explode( ',', $str( 'tags' ) ) ) ), 'strlen' ) );

		/* Images */
		$images = array();
		$names  = array(
			'featured' => __( 'the featured image', 'tbt-lesson-builder' ),
			'hero'     => __( 'the hero image', 'tbt-lesson-builder' ),
			'warmup'   => __( 'the warm-up image', 'tbt-lesson-builder' ),
			'poll'     => __( 'the poll image', 'tbt-lesson-builder' ),
		);
		$img_in = isset( $p['images'] ) && is_array( $p['images'] ) ? $p['images'] : array();
		foreach ( $names as $key => $name ) {
			$id = isset( $img_in[ $key ] ) ? absint( $img_in[ $key ] ) : 0;
			if ( ! $id || ! wp_attachment_is_image( $id ) ) {
				/* translators: %s: which image. */
				return $fail( sprintf( __( 'Images: choose %s.', 'tbt-lesson-builder' ), $name ) );
			}
			$images[ $key ] = array(
				'id'  => $id,
				'url' => wp_get_attachment_url( $id ),
			);
		}

		/* Warm-up */
		$warmup = array();
		$w_in   = isset( $p['warmup'] ) && is_array( $p['warmup'] ) ? array_values( $p['warmup'] ) : array();
		for ( $i = 0; $i < 3; $i++ ) {
			$n         = $i + 1;
			$w         = isset( $w_in[ $i ] ) && is_array( $w_in[ $i ] ) ? $w_in[ $i ] : array();
			$question  = TBTLB_Format::js_trim( sanitize_text_field( $str( 'question', $w ) ) );
			$character = $str( 'character', $w );
			$answer    = TBTLB_Format::js_trim( sanitize_textarea_field( $str( 'answer', $w ) ) );
			$audio     = isset( $w['audio'] ) ? absint( $w['audio'] ) : 0;
			if ( '' === $question ) {
				/* translators: %d: question number. */
				return $fail( sprintf( __( 'Warm-up: question %d has no question text.', 'tbt-lesson-builder' ), $n ) );
			}
			if ( ! isset( TBTLB_Config::VOICES[ $character ] ) ) {
				/* translators: %d: question number. */
				return $fail( sprintf( __( 'Warm-up: choose who answers question %d.', 'tbt-lesson-builder' ), $n ) );
			}
			if ( '' === $answer ) {
				/* translators: %d: question number. */
				return $fail( sprintf( __( 'Warm-up: question %d has no answer.', 'tbt-lesson-builder' ), $n ) );
			}
			if ( ! $audio || 'attachment' !== get_post_type( $audio ) || ! get_post_meta( $audio, '_tbtlb_generated', true ) ) {
				/* translators: %d: question number. */
				return $fail( sprintf( __( 'Warm-up: question %d has no audio.', 'tbt-lesson-builder' ), $n ) );
			}
			if ( get_post_meta( $audio, '_tbtlb_source', true ) !== self::audio_source( $character, $str( 'answer', $w ) ) ) {
				/* translators: %d: question number. */
				return $fail( sprintf( __( 'Warm-up: the audio for question %d is out of date. Regenerate it.', 'tbt-lesson-builder' ), $n ) );
			}
			$warmup[] = array(
				'question'  => $question,
				'character' => $character,
				'answer'    => $answer,
				'audio_id'  => $audio,
				'audio_url' => wp_get_attachment_url( $audio ),
			);
		}

		/* Video parts */
		$video_id = TBTLB_Format::youtube_id( $str( 'youtube' ) );
		if ( ! $video_id ) {
			return $fail( __( 'Video parts: paste the YouTube link or embed code.', 'tbt-lesson-builder' ) );
		}
		$parts_in = isset( $p['parts'] ) && is_array( $p['parts'] ) ? array_values( $p['parts'] ) : array();
		if ( ! $parts_in ) {
			return $fail( __( 'Video parts: add at least one part.', 'tbt-lesson-builder' ) );
		}
		if ( count( $parts_in ) > TBTLB_Config::MAX_PARTS ) {
			/* translators: %d: maximum number of parts. */
			return $fail( sprintf( __( 'Video parts: a lesson has at most %d parts.', 'tbt-lesson-builder' ), TBTLB_Config::MAX_PARTS ) );
		}
		$times = array();
		foreach ( $parts_in as $part ) {
			$part    = is_array( $part ) ? $part : array();
			$times[] = array(
				'start' => $str( 'start', $part ),
				'end'   => $str( 'end', $part ),
			);
		}
		$resolved = TBTLB_Format::resolve_parts( $times );
		$parts    = array();
		/* Per part, in the order of its rows on screen: range → flashcards → questions. */
		foreach ( $parts_in as $i => $part ) {
			$part = is_array( $part ) ? $part : array();
			if ( isset( $resolved['errors'][ $i ] ) ) {
				/* translators: 1: part number, 2: the problem. */
				return $fail( sprintf( __( 'Part %1$d: %2$s.', 'tbt-lesson-builder' ), $i + 1, $resolved['errors'][ $i ] ) );
			}
			$range     = $resolved['ranges'][ $i ];
			$media_id  = isset( $part['media'] ) && is_numeric( $part['media'] ) ? (int) $part['media'] : 0;
			$media_url = '';
			if ( $media_id > 0 ) {
				$linked = self::linked_cards( $media_id, $i + 1 );
				if ( is_wp_error( $linked ) ) {
					return $linked;
				}
				$cards     = $linked['cards'];
				$media_url = $linked['url'];
			} else {
				$cards = TBTLB_Format::parse_cards( $str( 'cards', $part ) );
				if ( ! $cards ) {
					/* translators: %d: part number. */
					return $fail( sprintf( __( 'Part %d: add the flashcards.', 'tbt-lesson-builder' ), $i + 1 ) );
				}
				$todo = TBTLB_Format::incomplete( $cards );
				if ( $todo ) {
					/* translators: 1: part number, 2: number of cards. */
					return $fail( sprintf( _n( 'Part %1$d: %2$d card still needs AI.', 'Part %1$d: %2$d cards still need AI.', $todo, 'tbt-lesson-builder' ), $i + 1, $todo ) );
				}
				if ( count( $cards ) > 300 ) {
					/* translators: %d: part number. */
					return $fail( sprintf( __( 'Part %d: a flashcard set has at most 300 cards.', 'tbt-lesson-builder' ), $i + 1 ) );
				}
			}
			$rows = TBTLB_Format::parse_qa( $str( 'qa', $part ) );
			if ( ! $rows ) {
				/* translators: %d: part number. */
				return $fail( sprintf( __( 'Part %d: paste the comprehension questions (time, question, answer).', 'tbt-lesson-builder' ), $i + 1 ) );
			}
			$outside = TBTLB_Format::questions_outside( $range, $rows );
			if ( $outside ) {
				$count = count( $outside );
				$shown = array_slice( array_column( $outside, 'time' ), 0, 5 );
				$list  = implode( ', ', $shown ) . ( $count > 5 ? ', …' : '' );
				$span  = TBTLB_Format::seconds_to_time( $range['start'] ) . '–' . TBTLB_Format::seconds_to_time( $range['end'] );
				return $fail(
					sprintf(
						/* translators: 1: part number, 2: number of questions, 3: the part's range, 4: the questions' times. */
						_n( 'Part %1$d: %2$d question is outside %3$s (%4$s).', 'Part %1$d: %2$d questions are outside %3$s (%4$s).', $count, 'tbt-lesson-builder' ),
						$i + 1,
						$count,
						$span,
						$list
					)
				);
			}
			$parts[] = array(
				'start' => $range['start'],
				'end'   => $range['end'],
				'cards'     => $cards,
				'media_url' => $media_url,
				'rows'      => $rows,
			);
		}

		/* Poll */
		$poll = tbt_poll_parse( $str( 'poll' ) );
		if ( is_wp_error( $poll ) ) {
			/* translators: %s: the problem TBT Poll found. */
			return $fail( sprintf( __( 'Poll: %s', 'tbt-lesson-builder' ), self::sentence( $poll->get_error_message() ) ) );
		}

		/* Discussion */
		$scenario  = sanitize_textarea_field( $str( 'scenario' ) );
		$dialogue  = sanitize_textarea_field( $str( 'dialogue' ) );
		$questions = sanitize_textarea_field( $str( 'questions' ) );
		if ( ! TBTLB_Format::lines( $scenario ) ) {
			return $fail( __( 'Discussion: write the role-play scenario.', 'tbt-lesson-builder' ) );
		}
		if ( ! TBTLB_Format::lines( $dialogue ) ) {
			return $fail( __( 'Discussion: write the dialogue.', 'tbt-lesson-builder' ) );
		}
		if ( ! TBTLB_Format::lines( $questions ) ) {
			return $fail( __( 'Discussion: add the discussion questions.', 'tbt-lesson-builder' ) );
		}
		$mindmap = self::mindmap_url( $str( 'mindmap' ) );
		if ( '' === $mindmap ) {
			return $fail( __( 'Discussion: the mind map must be a mindomo.com link.', 'tbt-lesson-builder' ) );
		}

		return array(
			'title'       => $title,
			'excerpt'     => $excerpt,
			'category'    => $category,
			'wp_category' => (int) $term->term_id,
			'level'       => $level,
			'tags'        => $tags,
			'images'      => $images,
			'warmup'      => $warmup,
			'video_id'    => $video_id,
			'parts'       => $parts,
			'poll'        => $poll,
			'scenario'    => $scenario,
			'dialogue'    => $dialogue,
			'questions'   => $questions,
			'mindmap'     => $mindmap,
		);
	}

	/**
	 * An https link on mindomo.com (or a subdomain), or ''.
	 *
	 * @param string $value Link.
	 * @return string
	 */
	public static function mindmap_url( $value ) {
		$url  = esc_url_raw( TBTLB_Format::js_trim( $value ), array( 'https' ) );
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$ok   = TBTLB_Config::MINDMAP_HOST === $host || str_ends_with( $host, '.' . TBTLB_Config::MINDMAP_HOST );
		return '' !== $url && $ok ? $url : '';
	}

	/* ---------- Errors ---------- */

	/** First letter lowercased, so "Poll: question 2 …" reads as one sentence. */
	private static function sentence( $message ) {
		$message = (string) $message;
		return preg_match( '/^[A-Z][a-z]/', $message ) ? lcfirst( $message ) : $message;
	}

	/**
	 * @param string $message Plain sentence.
	 * @param int    $status  HTTP status.
	 * @return WP_Error
	 */
	private static function error( $message, $status = 400 ) {
		return new WP_Error( 'tbtlb_error', $message, array( 'status' => $status ) );
	}

	/**
	 * An error from Flashcards with its message, never its data.
	 *
	 * @param WP_Error $error Error.
	 * @return WP_Error
	 */
	private static function pass_error( WP_Error $error ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) && (int) $data['status'] >= 400 ? (int) $data['status'] : 502;
		return self::error( $error->get_error_message(), $status );
	}
}
