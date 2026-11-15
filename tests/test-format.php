<?php
/**
 * Standalone tests for TBTLB_Format (no WordPress).
 *
 *   php tests/test-format.php
 *
 * The comprehension fixtures are the output of TBT Comprehension's original
 * buildHtml() (tests/generate-fixtures.js); the GI and PD ones are also
 * byte-identical to the tables in the two live lessons.
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/class-tbtlb-config.php';
require __DIR__ . '/../includes/class-tbtlb-format.php';

$GLOBALS['tbtlb_pass'] = 0;
$GLOBALS['tbtlb_fail'] = 0;

function check( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		$GLOBALS['tbtlb_pass']++;
		return;
	}
	$GLOBALS['tbtlb_fail']++;
	echo "FAIL: $label\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
}

function fixture( $name ) {
	return file_get_contents( __DIR__ . '/fixtures/' . $name );
}

$F = 'TBTLB_Format';

/* ---------- time_to_seconds / seconds_to_time ---------- */

check( 'm:ss', $F::time_to_seconds( '1:20' ), 80 );
check( 'mm:ss', $F::time_to_seconds( '14:28' ), 868 );
check( 'mmm:ss', $F::time_to_seconds( '112:05' ), 6725 );
check( 'leading zero', $F::time_to_seconds( '00:22' ), 22 );
check( 'h:mm:ss', $F::time_to_seconds( '1:02:03' ), 3723 );
check( 'h:mm:ss with zero hours', $F::time_to_seconds( '0:03:08' ), 188 );
check( 'Excel a:bb:00 is m:ss', $F::time_to_seconds( '1:20:00' ), 80 );
check( 'Excel 14:28:00', $F::time_to_seconds( '14:28:00' ), 868 );
check( 'no-break spaces trimmed', $F::time_to_seconds( "\u{00A0}3:08 " ), 188 );
check( 'seconds over 59', $F::time_to_seconds( '1:75' ), null );
check( 'one-digit seconds', $F::time_to_seconds( '1:5' ), null );
check( 'bare number', $F::time_to_seconds( '80' ), null );
check( 'words', $F::time_to_seconds( 'time' ), null );
check( 'empty', $F::time_to_seconds( '' ), null );
check( 'seconds_to_time 80', $F::seconds_to_time( 80 ), '1:20' );
check( 'seconds_to_time 868', $F::seconds_to_time( 868 ), '14:28' );
check( 'seconds_to_time 5', $F::seconds_to_time( 5 ), '0:05' );
check( 'seconds_to_time 3725', $F::seconds_to_time( 3725 ), '62:05' );

/* ---------- parse_qa ---------- */

$qa = $F::parse_qa( "time\tquestion\tanswer\n\n1:00\tSecond?\tB\n0:22\tFirst?\tA\n  \n1:20:00\tThird\t\n" );
check( 'parse_qa count (header and blanks skipped)', count( $qa ), 3 );
check( 'parse_qa sorted', array_column( $qa, 'question' ), array( 'First?', 'Second?', 'Third' ) );
check( 'parse_qa display time', array_column( $qa, 'time' ), array( '0:22', '1:00', '1:20' ) );
check( 'parse_qa empty answer', $qa[2]['answer'], '' );
$qa = $F::parse_qa( "0:05,\"Hello, world?\",\"He said \"\"hi\"\"\"\n0:06,Q,an, unquoted, answer" );
check( 'parse_qa CSV quoted question', $qa[0]['question'], 'Hello, world?' );
check( 'parse_qa CSV escaped quotes', $qa[0]['answer'], 'He said "hi"' );
check( 'parse_qa extra columns rejoined', $qa[1]['answer'], 'an, unquoted, answer' );
$qa = $F::parse_qa( "0:10\tSame A\tx\n0:05\tEarlier\ty\n0:10\tSame B\tz" );
check( 'parse_qa stable for equal times', array_column( $qa, 'question' ), array( 'Earlier', 'Same A', 'Same B' ) );
check( 'parse_qa row without question skipped', count( $F::parse_qa( "0:10\t\tanswer" ) ), 0 );

/* ---------- resolve_parts / assign_questions ---------- */

$r = $F::resolve_parts(
	array(
		array( 'start' => '', 'end' => '1:20' ),
		array( 'start' => '', 'end' => '3:08' ),
		array( 'start' => '3:30', 'end' => '4:00' ),
	)
);
check( 'resolve_parts empty start continues', $r['ranges'][1], array( 'start' => 80, 'end' => 188 ) );
check( 'resolve_parts typed start skips', $r['ranges'][2], array( 'start' => 210, 'end' => 240 ) );
check( 'resolve_parts first starts at 0', $r['ranges'][0]['start'], 0 );
check( 'resolve_parts no errors', $r['errors'], array() );

$r = $F::resolve_parts(
	array(
		array( 'start' => '', 'end' => '1:00' ),
		array( 'start' => '', 'end' => '3.08' ),
		array( 'start' => '', 'end' => '2:00' ),
		array( 'start' => '0:30', 'end' => '3:00' ),
		array( 'start' => '', 'end' => '' ),
		array( 'start' => '5:00', 'end' => '4:00' ),
		array( 'start' => 'x', 'end' => '9:00' ),
	)
);
check( 'resolve_parts bad end', $r['errors'][1], 'Use minutes:seconds, e.g. 3:08' );
check( 'resolve_parts after a bad part continues from the last good end', $r['ranges'][2], array( 'start' => 60, 'end' => 120 ) );
check( 'resolve_parts overlap', $r['errors'][3], 'Overlaps the previous part' );
check( 'resolve_parts missing end', $r['errors'][4], 'Enter the end' );
check( 'resolve_parts end before start', $r['errors'][5], 'End must be after the start' );
check( 'resolve_parts bad start', $r['errors'][6], 'Use minutes:seconds, e.g. 3:08' );

$r = $F::resolve_parts( array( array( 'start' => '', 'end' => 'x' ), array( 'start' => '', 'end' => '2:00' ) ) );
check( 'resolve_parts no previous end', $r['errors'][1], 'Enter the start' );

$a = $F::assign_questions(
	array( array( 'start' => 0, 'end' => 80 ), array( 'start' => 80, 'end' => 188 ), null, array( 'start' => 215, 'end' => 240 ) ),
	$F::parse_qa( "1:20\tBoundary\t\n1:21\tAfter\t\n3:21\tSkipped\t\n3:32\tSkipped 2\t\n3:40\tLast\t" )
);
check( 'assign boundary goes to the earlier part', array_column( $a['buckets'][0], 'question' ), array( 'Boundary' ) );
check( 'assign second part', array_column( $a['buckets'][1], 'question' ), array( 'After' ) );
check( 'assign bad part gets nothing', $a['buckets'][2], array() );
check( 'assign outside', array_column( $a['outside'], 'time' ), array( '3:21', '3:32' ) );

/* ---------- End to end: the Stress lesson paste → the live tables ---------- */

$stress = json_decode( fixture( 'stress-paste.json' ), true );
$r      = $F::resolve_parts(
	array_map(
		function ( $end ) {
			return array( 'start' => '', 'end' => $end );
		},
		$stress['ends']
	)
);
$a      = $F::assign_questions( $r['ranges'], $F::parse_qa( $stress['qa'] ) );
check( 'Stress: 4 / 7 / 7 / 12 / 12 / 9 / 3', array_map( 'count', $a['buckets'] ), array( 4, 7, 7, 12, 12, 9, 3 ) );
check( 'Stress: nothing outside', $a['outside'], array() );
foreach ( $a['buckets'] as $i => $rows ) {
	check( 'Stress part ' . ( $i + 1 ) . ' table = live table', $F::comprehension_html( $rows, '#663366' ), fixture( 'comprehension-gi-' . ( $i + 1 ) . '-663366.html' ) );
}

/* ---------- comprehension_html vs TBT Comprehension's buildHtml() ---------- */

$input = json_decode( fixture( 'comprehension-input.json' ), true );
foreach ( array( array( 'gi', '#663366' ), array( 'gi', '#006600' ), array( 'pd', '#006600' ), array( 'edge', '#663366' ) ) as $run ) {
	foreach ( $input[ $run[0] ] as $i => $rows ) {
		$name = 'comprehension-' . $run[0] . '-' . ( $i + 1 ) . '-' . substr( $run[1], 1 ) . '.html';
		check( $name, $F::comprehension_html( $rows, $run[1] ), fixture( $name ) );
	}
}
check( 'comprehension_html no rows', $F::comprehension_html( array(), '#663366' ), '' );

/* ---------- parse_cards / incomplete ---------- */

$cards = $F::parse_cards( "Freak out\n\nDie prematurely\n" );
check( 'cards words only', $cards, array(
	array( 'word' => 'Freak out', 'phonetic' => '', 'translation' => '', 'example' => '' ),
	array( 'word' => 'Die prematurely', 'phonetic' => '', 'translation' => '', 'example' => '' ),
) );
check( 'cards incomplete', $F::incomplete( $cards ), 2 );

$cards = $F::parse_cards( "word,phonetic,translation,example\nMake an estimate,/meɪk ən ˈestɪmət/,oszacować,\"Could you, please, make an estimate?\"\nFreak out,/friːk aʊt/,wpaść w panikę,She said \"\"no\"\"" );
check( 'cards header skipped', count( $cards ), 2 );
check( 'cards quoted example', $cards[0]['example'], 'Could you, please, make an estimate?' );
check( 'cards phonetic', $cards[0]['phonetic'], '/meɪk ən ˈestɪmət/' );
check( 'cards complete', $F::incomplete( $cards ), 0 );
check( 'cards unquoted commas rejoin the example', $F::parse_cards( 'Get rid of,/ɡet rɪd əv/,pozbyć się,Well, she did.' )[0]['example'], 'Well, she did.' );

$cards = $F::parse_cards( "English\tPhonetic\tPolish\tExample\nCrave\t/kreɪv/\tpragnąć\t\"After a long week, \"\"we\"\" crave rest.\"\nResilience\t\todporność\t" );
check( 'cards tab header alias skipped', count( $cards ), 2 );
check( 'cards tab quoted cell', $cards[0]['example'], 'After a long week, "we" crave rest.' );
check( 'cards tab missing fields', $cards[1], array( 'word' => 'Resilience', 'phonetic' => '', 'translation' => 'odporność', 'example' => '' ) );
check( 'cards header only on first line', count( $F::parse_cards( "Crave\nword" ) ), 2 );

/* ---------- Discussion and warm-up text ---------- */

check(
	'dialogue',
	$F::parse_dialogue( "Alita: How do you **handle stress**?\n\nPete: I'd say <calmly> & [quietly].\nA line with no name" ),
	"<p><strong>Alita:</strong> How do you <strong>handle stress</strong>?</p>\n<p><strong>Pete:</strong> I'd say &lt;calmly&gt; &amp; [quietly].</p>\n<p>A line with no name</p>"
);
check( 'parse_lines', $F::parse_lines( "**Why** not?\n\n  Second  \n" ), array( '<strong>Why</strong> not?', 'Second' ) );
check( 'paragraphs', $F::paragraphs( "One\n\nTwo **b**" ), "<p>One</p>\n<p>Two **b**</p>" );
check( 'paragraphs bold', $F::paragraphs( 'Two **b**', true ), '<p>Two <strong>b</strong></p>' );

$dq = "Many people believe that avoiding stressful situations leads to a happier life. Why might this approach actually be harmful in the long run?\n"
	. "What's best when facing a difficult challenge — focusing on calming the body down or accepting the physical signs of stress as helpful?\n"
	. "Advise someone who tends to bottle up their emotions instead of reaching out to others when under pressure.\n"
	. "Why do some people find it easier to help others under stress than to ask for support themselves?\n"
	. "Many people believe that a stress-free job is always the better choice. What's the strongest argument for choosing a meaningful but more stressful path?";
check( 'questions table = live table', $F::questions_table( $F::parse_lines( $dq ), TBTLB_Config::CATEGORIES['gi']['bullet'] ), fixture( 'discussion-questions-gi.html' ) );

/* ---------- Video ---------- */

$iframe = '<iframe width="1080" height="615" src="https://www.youtube.com/embed/RcGyVTAoXEU?si=8S25ZGIHo9BwGF6p&start=0&end=80" title="YouTube video player" frameborder="0" allowfullscreen></iframe>';
check( 'youtube_id iframe', $F::youtube_id( $iframe ), 'RcGyVTAoXEU' );
check( 'youtube_id youtu.be', $F::youtube_id( 'https://youtu.be/RcGyVTAoXEU?si=abc' ), 'RcGyVTAoXEU' );
check( 'youtube_id watch', $F::youtube_id( 'https://www.youtube.com/watch?v=RcGyVTAoXEU&t=10s' ), 'RcGyVTAoXEU' );
check( 'youtube_id watch, v later', $F::youtube_id( 'https://www.youtube.com/watch?feature=share&v=RcGyVTAoXEU' ), 'RcGyVTAoXEU' );
check( 'youtube_id shorts', $F::youtube_id( 'https://youtube.com/shorts/RcGyVTAoXEU' ), 'RcGyVTAoXEU' );
check( 'youtube_id embed', $F::youtube_id( 'https://www.youtube-nocookie.com/embed/RcGyVTAoXEU' ), 'RcGyVTAoXEU' );
check( 'youtube_id not youtube', $F::youtube_id( 'https://vimeo.com/123456789' ), null );
check( 'youtube_id too short', $F::youtube_id( 'https://youtu.be/abc' ), null );
check(
	'embed_iframe = live embed without si=',
	$F::embed_iframe( 'RcGyVTAoXEU', 0, 80 ),
	'<iframe width="1080" height="615" src="https://www.youtube.com/embed/RcGyVTAoXEU?start=0&end=80" title="YouTube video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>'
);

/* ---------- Names and escaping ---------- */

check( 'ordinal 1', $F::ordinal( 1 ), 'first' );
check( 'ordinal 5', $F::ordinal( 5 ), 'fifth' );
check( 'ordinal 10', $F::ordinal( 10 ), 'tenth' );
check( 'ordinal 11', $F::ordinal( 11 ), '' );
check( 'slug title', $F::slug( 'How to Make Stress Your Friend | Kelly McGonigal | TED' ), 'how-to-make-stress-your-friend' );
check( 'slug accents', $F::slug( 'Zażółć gęślą jaźń — Café Señor' ), 'zazolc-gesla-jazn-cafe-senor' );
check( 'slug empty', $F::slug( ' | TED' ), '' );
check( 'escape_shortcode_text', $F::escape_shortcode_text( 'a [b] c' ), 'a &#91;b&#93; c' );
check( 'esc keeps quotes', $F::esc( "It's \"<b>\" & co" ), "It's \"&lt;b&gt;\" &amp; co" );

echo "\n{$GLOBALS['tbtlb_pass']} passed, {$GLOBALS['tbtlb_fail']} failed\n";
exit( $GLOBALS['tbtlb_fail'] ? 1 : 0 );
