<?php
/**
 * The Lesson Builder page in wp-admin: the form shell, its assets and the
 * dependency check. builder.js renders the dynamic parts.
 */

defined( 'ABSPATH' ) || exit;

final class TBTLB_Admin {

	const SLUG = 'tbt-lesson-builder';

	/** @var string Hook suffix of the page, for loading assets only there. */
	private static $hook = '';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Under the TBT Hub menu when Hub is active, otherwise a menu of its own
	 * so the page is never unreachable.
	 */
	public static function menu() {
		$title = __( 'Lesson Builder', 'tbt-lesson-builder' );
		if ( defined( 'TBT_HUB_SLUG' ) ) {
			self::$hook = (string) add_submenu_page( TBT_HUB_SLUG, $title, $title, 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
		} else {
			self::$hook = (string) add_menu_page( $title, $title, 'manage_options', self::SLUG, array( __CLASS__, 'render' ), 'dashicons-welcome-learn-more', 3 );
		}
	}

	/**
	 * @param string $hook Current admin page hook.
	 */
	public static function assets( $hook ) {
		if ( ! self::$hook || $hook !== self::$hook || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( '' !== TBTLB_Rest::missing_dependencies() ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'tbtlb-tokens', TBTLB_URL . 'assets/css/tbt-tokens.css', array(), TBTLB_VERSION );
		wp_enqueue_style( 'tbtlb-builder', TBTLB_URL . 'assets/css/builder.css', array( 'tbtlb-tokens' ), TBTLB_VERSION );
		wp_enqueue_script( 'tbtlb-builder', TBTLB_URL . 'assets/js/builder.js', array(), TBTLB_VERSION, true );

		$categories = array();
		foreach ( TBTLB_Config::CATEGORIES as $key => $cat ) {
			$categories[ $key ] = array(
				'label'  => $cat['label'],
				'family' => $cat['family'],
			);
		}
		$config = array(
			'rest'        => esc_url_raw( rest_url( TBTLB_Rest::NS . '/' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'storageKey'  => 'tbtlb:' . get_current_user_id(),
			'characters'  => array_keys( TBTLB_Config::VOICES ),
			'categories'  => $categories,
			'levels'      => array_keys( TBTLB_Config::LEVELS ),
			'maxParts'    => TBTLB_Config::MAX_PARTS,
			'mindmapHost' => TBTLB_Config::MINDMAP_HOST,
		);
		wp_add_inline_script( 'tbtlb-builder', 'window.tbtlbConfig = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		nocache_headers();

		$missing = TBTLB_Rest::missing_dependencies();
		if ( '' !== $missing ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Lesson Builder', 'tbt-lesson-builder' ) . '</h1><p>' . esc_html( $missing ) . '</p></div>';
			return;
		}

		$images = array(
			'featured' => __( 'Featured image', 'tbt-lesson-builder' ),
			'hero'     => __( 'Hero', 'tbt-lesson-builder' ),
			'warmup'   => __( 'Warm-up', 'tbt-lesson-builder' ),
			'poll'     => __( 'Poll', 'tbt-lesson-builder' ),
		);
		$stages = array(
			'basics'     => __( 'Basics', 'tbt-lesson-builder' ),
			'images'     => __( 'Images', 'tbt-lesson-builder' ),
			'warmup'     => __( 'Warm-up', 'tbt-lesson-builder' ),
			'video'      => __( 'Video parts', 'tbt-lesson-builder' ),
			'poll'       => __( 'Poll', 'tbt-lesson-builder' ),
			'discussion' => __( 'Discussion', 'tbt-lesson-builder' ),
			'glossary'   => __( 'Glossary', 'tbt-lesson-builder' ),
		);
		?>
<div class="tbtlb" id="tbtlb-app" data-look="gi">
	<div class="tbtlb-wrap">
		<header class="tbtlb-top">
			<div>
				<h1><?php esc_html_e( 'LESSON BUILDER', 'tbt-lesson-builder' ); ?></h1>
				<p><?php esc_html_e( 'Fill in the lesson once. The builder creates the Divi draft, the flashcard sets, the comprehension tables and the glossary.', 'tbt-lesson-builder' ); ?></p>
			</div>
			<div class="tbtlb-actions">
				<button class="tbtlb-btn tbtlb-ghost" id="tbtlb-clear" type="button"><?php esc_html_e( 'Start over', 'tbt-lesson-builder' ); ?></button>
			</div>
		</header>

		<div class="tbtlb-notice tbtlb-info" id="tbtlb-restored" hidden>
			<?php esc_html_e( 'Your unfinished lesson was restored.', 'tbt-lesson-builder' ); ?>
			<button type="button" class="tbtlb-link" id="tbtlb-restart"><?php esc_html_e( 'Start over', 'tbt-lesson-builder' ); ?></button>
		</div>

		<div class="tbtlb-layout">
			<nav class="tbtlb-nav" aria-label="<?php esc_attr_e( 'Lesson stages', 'tbt-lesson-builder' ); ?>">
				<?php foreach ( $stages as $key => $label ) : ?>
					<a href="#tbtlb-s-<?php echo esc_attr( $key ); ?>"><span class="tbtlb-dot" data-for="<?php echo esc_attr( $key ); ?>">✓</span><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
				<div class="tbtlb-sum" id="tbtlb-navsum"></div>
			</nav>

			<main class="tbtlb-stages">

				<section class="tbtlb-stage" id="tbtlb-s-basics">
					<h2><?php esc_html_e( 'Basics', 'tbt-lesson-builder' ); ?></h2>
					<p class="tbtlb-lead"><?php esc_html_e( 'What the lesson is called, where it lives, and which header set it wears.', 'tbt-lesson-builder' ); ?></p>
					<div class="tbtlb-field">
						<label for="tbtlb-title"><?php esc_html_e( 'Lesson title', 'tbt-lesson-builder' ); ?></label>
						<input type="text" id="tbtlb-title" class="tbtlb-content" data-bind="title" placeholder="How to Make Stress Your Friend | Kelly McGonigal | TED">
						<span class="tbtlb-hint tbtlb-err-text" id="tbtlb-title-err"></span>
					</div>
					<div class="tbtlb-field">
						<div class="tbtlb-row-between"><label for="tbtlb-excerpt"><?php esc_html_e( 'Excerpt', 'tbt-lesson-builder' ); ?></label><span class="tbtlb-hint" id="tbtlb-excerpt-count">0 / 160</span></div>
						<textarea id="tbtlb-excerpt" class="tbtlb-content tbtlb-short" rows="2" data-bind="excerpt" placeholder="<?php esc_attr_e( 'Start with a question, then the promise.', 'tbt-lesson-builder' ); ?>"></textarea>
					</div>
					<div class="tbtlb-field">
						<span class="tbtlb-lbl" id="tbtlb-cat-lbl"><?php esc_html_e( 'Category', 'tbt-lesson-builder' ); ?> <span class="tbtlb-hint"><?php esc_html_e( '— also sets the headers, accent colour and category icon', 'tbt-lesson-builder' ); ?></span></span>
						<div class="tbtlb-seg" role="group" aria-labelledby="tbtlb-cat-lbl" id="tbtlb-cat">
							<?php foreach ( TBTLB_Config::CATEGORIES as $key => $cat ) : ?>
								<button type="button" data-v="<?php echo esc_attr( $key ); ?>" aria-pressed="false"><span class="tbtlb-sw tbtlb-sw-<?php echo esc_attr( $key ); ?>"></span><?php echo esc_html( $cat['label'] ); ?></button>
							<?php endforeach; ?>
						</div>
					</div>
					<div class="tbtlb-grid2">
						<div class="tbtlb-field">
							<span class="tbtlb-lbl" id="tbtlb-lvl-lbl"><?php esc_html_e( 'Level', 'tbt-lesson-builder' ); ?></span>
							<div class="tbtlb-seg" role="group" aria-labelledby="tbtlb-lvl-lbl" id="tbtlb-level">
								<?php foreach ( array_keys( TBTLB_Config::LEVELS ) as $level ) : ?>
									<button type="button" data-v="<?php echo esc_attr( $level ); ?>" aria-pressed="false"><?php echo esc_html( $level ); ?></button>
								<?php endforeach; ?>
							</div>
						</div>
						<div class="tbtlb-field">
							<label for="tbtlb-tags"><?php esc_html_e( 'Tags', 'tbt-lesson-builder' ); ?> <span class="tbtlb-hint"><?php esc_html_e( '— comma-separated', 'tbt-lesson-builder' ); ?></span></label>
							<input type="text" id="tbtlb-tags" data-bind="tags" placeholder="stress, psychology, TED">
						</div>
					</div>
				</section>

				<section class="tbtlb-stage" id="tbtlb-s-images">
					<h2><?php esc_html_e( 'Images', 'tbt-lesson-builder' ); ?></h2>
					<p class="tbtlb-lead"><?php esc_html_e( 'Each one opens the WordPress media library.', 'tbt-lesson-builder' ); ?></p>
					<div class="tbtlb-pickers">
						<?php foreach ( $images as $key => $label ) : ?>
							<button type="button" class="tbtlb-pick" data-img="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?><small><?php esc_html_e( 'Choose from Media', 'tbt-lesson-builder' ); ?></small></button>
						<?php endforeach; ?>
					</div>
				</section>

				<section class="tbtlb-stage" id="tbtlb-s-warmup">
					<h2><?php esc_html_e( 'Warm-up', 'tbt-lesson-builder' ); ?></h2>
					<p class="tbtlb-lead"><?php esc_html_e( 'Three questions, each with one character’s answer and its recording. The answer becomes the transcript.', 'tbt-lesson-builder' ); ?></p>
					<div id="tbtlb-warmups"></div>
				</section>

				<section class="tbtlb-stage" id="tbtlb-s-video">
					<h2><?php esc_html_e( 'Video parts', 'tbt-lesson-builder' ); ?></h2>
					<p class="tbtlb-lead"><?php esc_html_e( 'Paste the video once. Then fill in each part: its time, its flashcards, its questions.', 'tbt-lesson-builder' ); ?></p>
					<div class="tbtlb-field">
						<label for="tbtlb-yt"><?php esc_html_e( 'YouTube video', 'tbt-lesson-builder' ); ?> <span class="tbtlb-hint"><?php esc_html_e( '— the link or the whole embed code', 'tbt-lesson-builder' ); ?></span></label>
						<input type="text" id="tbtlb-yt" data-bind="youtube" placeholder="https://youtu.be/…  or  <iframe … >">
						<span class="tbtlb-hint" id="tbtlb-yt-info"></span>
					</div>
					<div class="tbtlb-parts" id="tbtlb-parts"></div>
					<div class="tbtlb-addrow">
						<button type="button" class="tbtlb-btn" id="tbtlb-add-part"><?php esc_html_e( 'Add part', 'tbt-lesson-builder' ); ?></button>
						<span class="tbtlb-hint" id="tbtlb-part-count"></span>
					</div>
				</section>

				<section class="tbtlb-stage" id="tbtlb-s-poll">
					<h2><?php esc_html_e( 'Poll', 'tbt-lesson-builder' ); ?></h2>
					<p class="tbtlb-lead"><?php esc_html_e( 'Paste the questions from Word, each followed by its answers (A–D). The builder makes the poll, and students earn points for voting.', 'tbt-lesson-builder' ); ?></p>
					<div class="tbtlb-field">
						<div class="tbtlb-row-between"><label for="tbtlb-poll"><?php esc_html_e( 'Poll questions', 'tbt-lesson-builder' ); ?></label><span class="tbtlb-hint" id="tbtlb-poll-count"></span></div>
						<textarea id="tbtlb-poll" class="tbtlb-content" rows="8" data-bind="poll" placeholder="Which insight about stress do you find most surprising?&#10;A. …&#10;B. …&#10;C. …&#10;D. …"></textarea>
					</div>
					<div id="tbtlb-poll-preview"></div>
				</section>

				<section class="tbtlb-stage" id="tbtlb-s-discussion">
					<h2><?php esc_html_e( 'Discussion', 'tbt-lesson-builder' ); ?></h2>
					<p class="tbtlb-lead"><?php esc_html_e( 'Use **double asterisks** for bold, as in your Word document.', 'tbt-lesson-builder' ); ?></p>
					<div class="tbtlb-field">
						<label for="tbtlb-scenario"><?php esc_html_e( 'Role-play scenario', 'tbt-lesson-builder' ); ?></label>
						<textarea id="tbtlb-scenario" class="tbtlb-content tbtlb-short" rows="2" data-bind="scenario" placeholder="Alita and Pete are catching up over coffee…"></textarea>
					</div>
					<div class="tbtlb-field">
						<div class="tbtlb-row-between"><label for="tbtlb-dialogue"><?php esc_html_e( 'Dialogue', 'tbt-lesson-builder' ); ?></label><span class="tbtlb-hint" id="tbtlb-dlg-count"></span></div>
						<textarea id="tbtlb-dialogue" class="tbtlb-content" rows="8" data-bind="dialogue" placeholder="Alita: You look surprisingly relaxed…&#10;Pete: Honestly, I’ve **changed my mind** about it."></textarea>
					</div>
					<div class="tbtlb-field">
						<div class="tbtlb-row-between"><label for="tbtlb-dq"><?php esc_html_e( 'Discussion questions', 'tbt-lesson-builder' ); ?></label><span class="tbtlb-hint" id="tbtlb-dq-count"></span></div>
						<textarea id="tbtlb-dq" class="tbtlb-content" rows="5" data-bind="questions" placeholder="**Many people believe** that avoiding stressful situations…"></textarea>
					</div>
					<div class="tbtlb-field">
						<label for="tbtlb-mindmap"><?php esc_html_e( 'Mindomo map link', 'tbt-lesson-builder' ); ?></label>
						<input type="url" id="tbtlb-mindmap" data-bind="mindmap" placeholder="https://www.mindomo.com/mindmap/…">
						<span class="tbtlb-hint tbtlb-err-text" id="tbtlb-mindmap-err"></span>
					</div>
				</section>

				<section class="tbtlb-stage" id="tbtlb-s-glossary">
					<h2><?php esc_html_e( 'Glossary', 'tbt-lesson-builder' ); ?></h2>
					<p class="tbtlb-lead"><?php esc_html_e( 'Built from the flashcards in every part. Nothing to type here.', 'tbt-lesson-builder' ); ?></p>
					<div id="tbtlb-glossary"></div>
				</section>

				<section class="tbtlb-stage">
					<div class="tbtlb-finish">
						<ul id="tbtlb-checklist"></ul>
						<button type="button" class="tbtlb-btn tbtlb-primary" id="tbtlb-create" disabled><?php esc_html_e( 'Create draft', 'tbt-lesson-builder' ); ?></button>
					</div>
				</section>
			</main>
		</div>
	</div>

	<dialog id="tbtlb-done" class="tbtlb-dialog">
		<h3 id="tbtlb-done-head"></h3>
		<p id="tbtlb-done-title" class="tbtlb-muted"></p>
		<ul id="tbtlb-done-list"></ul>
		<p id="tbtlb-done-error" class="tbtlb-notice tbtlb-err" hidden></p>
		<div class="tbtlb-actions">
			<button class="tbtlb-btn" type="button" id="tbtlb-close-done"><?php esc_html_e( 'Back to the form', 'tbt-lesson-builder' ); ?></button>
			<a class="tbtlb-btn" id="tbtlb-edit-post" href="#" hidden><?php esc_html_e( 'Edit post', 'tbt-lesson-builder' ); ?></a>
			<a class="tbtlb-btn tbtlb-primary" id="tbtlb-open-divi" href="#" hidden><?php esc_html_e( 'Open in Divi', 'tbt-lesson-builder' ); ?></a>
		</div>
	</dialog>
</div>
		<?php
	}
}
