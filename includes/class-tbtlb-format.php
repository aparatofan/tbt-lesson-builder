<?php
/**
 * Pure helpers: times, the Excel paste, parts, cards, discussion text, the
 * YouTube embed and the comprehension table.
 *
 * No WordPress calls, so tests/test-format.php can run it without
 * WordPress. builder.js mirrors the parsers for the live form; the server
 * re-validates with these. Change the tests (and builder.js) with it.
 *
 * comprehension_html() is a byte-for-byte port of buildHtml() in TBT
 * Comprehension's assets/admin.js, so new tables match the existing
 * lessons. Do not tidy it.
 */

defined( 'ABSPATH' ) || exit;

final class TBTLB_Format {

	/** TOOLTIP_STYLE from TBT Comprehension's admin.js, joined with no newlines. */
	const TOOLTIP_STYLE = '<style>'
		. '.tbt-tt-wrap{position:relative;display:inline-block;margin-left:6px;vertical-align:middle;line-height:1;}'
		. '.tbt-tt-wrap .tbt-tt-icon{display:inline-block;width:18px;height:18px;line-height:18px;border-radius:50%;background:#0856c9;color:#fff;font:bold 12px/18px Arial,sans-serif;text-align:center;cursor:help;user-select:none;}'
		. '.tbt-tt-wrap .tbt-tt-bubble{visibility:hidden;opacity:0;transition:opacity 120ms ease-in;position:absolute;z-index:9999;left:50%;transform:translateX(-50%);bottom:calc(100% + 10px);min-width:220px;max-width:320px;background:#1f2d3d;color:#fff;padding:10px 12px;border-radius:6px;font:14px/1.45 Arial,sans-serif;text-align:left;box-shadow:0 4px 14px rgba(0,0,0,0.18);}'
		. '.tbt-tt-wrap .tbt-tt-bubble::after{content:"";position:absolute;top:100%;left:50%;transform:translateX(-50%);border:6px solid transparent;border-top-color:#1f2d3d;}'
		. '.tbt-tt-wrap:hover .tbt-tt-bubble,.tbt-tt-wrap:focus-within .tbt-tt-bubble{visibility:visible;opacity:1;}'
		. '</style>';

	/** First cells that mark a flashcard header row (Flashcards' word aliases). */
	const CARD_HEADER_WORDS = array( 'word', 'english', 'en', 'front' );

	/* ---------- Text basics ---------- */

	/**
	 * Trim like JavaScript's String.prototype.trim(): also the no-break and
	 * other Unicode spaces that Excel and Word pastes carry.
	 *
	 * @param mixed $value Text.
	 * @return string
	 */
	public static function js_trim( $value ) {
		$ws      = '[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';
		$trimmed = preg_replace( '/^' . $ws . '+|' . $ws . '+$/u', '', (string) $value );
		return null === $trimmed ? trim( (string) $value ) : $trimmed;
	}

	/**
	 * Non-empty lines, trimmed.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function lines( $text ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = self::js_trim( $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/**
	 * Escape teacher text for an HTML text node. Quotes stay as typed, as in
	 * the live lessons; they are harmless outside attributes.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function esc( $text ) {
		return htmlspecialchars( (string) $text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * `**…**` → `<strong>…</strong>` on already escaped HTML.
	 *
	 * @param string $html Escaped text.
	 * @return string
	 */
	public static function bold( $html ) {
		return preg_replace( '/\*\*(.+?)\*\*/su', '<strong>$1</strong>', (string) $html );
	}

	/**
	 * `[` and `]` as entities, so teacher text cannot open a shortcode.
	 *
	 * @param string $text Text already escaped for its context.
	 * @return string
	 */
	public static function escape_shortcode_text( $text ) {
		return str_replace( array( '[', ']' ), array( '&#91;', '&#93;' ), (string) $text );
	}

	/**
	 * One line of comma-separated values. A field that starts with `"` is
	 * quoted; `""` inside it is a literal quote. (TBT Comprehension's
	 * parseCsvLine(); builder.js has the same function.)
	 *
	 * @param string $line Line.
	 * @return string[]
	 */
	public static function csv_line( $line ) {
		$out    = array();
		$cur    = '';
		$quoted = false;
		$len    = strlen( $line );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $line[ $i ];
			if ( $quoted ) {
				if ( '"' === $ch ) {
					if ( $i + 1 < $len && '"' === $line[ $i + 1 ] ) {
						$cur .= '"';
						$i++;
					} else {
						$quoted = false;
					}
				} else {
					$cur .= $ch;
				}
			} elseif ( ',' === $ch ) {
				$out[] = $cur;
				$cur   = '';
			} elseif ( '"' === $ch && '' === $cur ) {
				$quoted = true;
			} else {
				$cur .= $ch;
			}
		}
		$out[] = $cur;
		return $out;
	}

	/* ---------- Times ---------- */

	/**
	 * `m:ss`, `mm:ss`, `mmm:ss` or `h:mm:ss` → seconds. `a:bb:00` is read as
	 * `a:bb` minutes:seconds, because Excel shows m:ss times as h:mm:ss when
	 * they are copied.
	 *
	 * @param string $value Time.
	 * @return int|null
	 */
	public static function time_to_seconds( $value ) {
		$value = self::js_trim( $value );
		if ( preg_match( '/^(\d{1,3}):([0-5]\d)$/', $value, $m ) ) {
			return (int) $m[1] * 60 + (int) $m[2];
		}
		if ( preg_match( '/^(\d{1,2}):([0-5]\d):([0-5]\d)$/', $value, $m ) ) {
			if ( '00' === $m[3] ) {
				return (int) $m[1] * 60 + (int) $m[2];
			}
			return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
		}
		return null;
	}

	/**
	 * Seconds → `m:ss` (80 → 1:20, 868 → 14:28).
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	public static function seconds_to_time( $seconds ) {
		$seconds = max( 0, (int) $seconds );
		return intdiv( $seconds, 60 ) . ':' . sprintf( '%02d', $seconds % 60 );
	}

	/* ---------- Comprehension paste and parts ---------- */

	/**
	 * The Excel paste: time, question, answer per line, tab- or
	 * comma-separated. Lines whose first cell is not a time (a header,
	 * junk) and lines without a question are skipped. Extra columns are
	 * rejoined into the answer. Sorted by time; equal times keep their order.
	 *
	 * @param string $text Pasted text.
	 * @return array[] { t, time, question, answer }.
	 */
	public static function parse_qa( $text ) {
		$rows = array();
		foreach ( self::lines( $text ) as $line ) {
			if ( false !== strpos( $line, "\t" ) ) {
				$delim = "\t";
				$cols  = explode( "\t", $line );
			} else {
				$delim = ',';
				$cols  = self::csv_line( $line );
			}
			if ( count( $cols ) < 2 ) {
				continue;
			}
			$t = self::time_to_seconds( $cols[0] );
			if ( null === $t ) {
				continue;
			}
			$question = self::js_trim( $cols[1] );
			if ( '' === $question ) {
				continue;
			}
			$rows[] = array(
				't'        => $t,
				'time'     => self::seconds_to_time( $t ),
				'question' => $question,
				'answer'   => count( $cols ) >= 3 ? self::js_trim( implode( $delim, array_slice( $cols, 2 ) ) ) : '',
			);
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return $a['t'] <=> $b['t'];
			}
		);
		return $rows;
	}

	/**
	 * Part ranges. An empty start continues from the previous good part's
	 * end (0 for the first part); a typed start may skip a stretch.
	 *
	 * @param array[] $parts { start, end } as typed.
	 * @return array { ranges: (array{start,end}|null)[], errors: string[] keyed by part index }.
	 */
	public static function resolve_parts( array $parts ) {
		$ranges   = array();
		$errors   = array();
		$prev_end = null;

		foreach ( array_values( $parts ) as $i => $part ) {
			$start_raw = self::js_trim( isset( $part['start'] ) ? $part['start'] : '' );
			$end_raw   = self::js_trim( isset( $part['end'] ) ? $part['end'] : '' );
			$auto      = 0 === $i ? 0 : $prev_end;
			$start     = '' === $start_raw ? $auto : self::time_to_seconds( $start_raw );
			$end       = '' === $end_raw ? null : self::time_to_seconds( $end_raw );

			$error = '';
			if ( '' !== $start_raw && null === $start ) {
				$error = 'Use minutes:seconds, e.g. 3:08';
			} elseif ( null === $start ) {
				$error = 'Enter the start';
			} elseif ( '' === $end_raw ) {
				$error = 'Enter the end';
			} elseif ( null === $end ) {
				$error = 'Use minutes:seconds, e.g. 3:08';
			} elseif ( $end <= $start ) {
				$error = 'End must be after the start';
			} elseif ( null !== $prev_end && $start < $prev_end ) {
				$error = 'Overlaps the previous part';
			}

			if ( '' !== $error ) {
				$ranges[ $i ] = null;
				$errors[ $i ] = $error;
			} else {
				$ranges[ $i ] = array(
					'start' => $start,
					'end'   => $end,
				);
				$prev_end     = $end;
			}
		}

		return array(
			'ranges' => $ranges,
			'errors' => $errors,
		);
	}

	/**
	 * Each question goes to the first part whose range holds its time
	 * (start ≤ t ≤ end), so a time on a shared boundary goes to the earlier
	 * part.
	 *
	 * @param array $ranges Output ranges of resolve_parts().
	 * @param array $qa     Output of parse_qa().
	 * @return array { buckets: array[] per part, outside: array[] }.
	 */
	public static function assign_questions( array $ranges, array $qa ) {
		$buckets = array_fill( 0, count( $ranges ), array() );
		$outside = array();
		foreach ( $qa as $row ) {
			$placed = false;
			foreach ( array_values( $ranges ) as $i => $range ) {
				if ( $range && $row['t'] >= $range['start'] && $row['t'] <= $range['end'] ) {
					$buckets[ $i ][] = $row;
					$placed          = true;
					break;
				}
			}
			if ( ! $placed ) {
				$outside[] = $row;
			}
		}
		return array(
			'buckets' => $buckets,
			'outside' => $outside,
		);
	}

	/* ---------- Flashcards ---------- */

	/**
	 * Flashcards as typed: full CSV rows (header optional), just the words,
	 * or tab-separated rows from a spreadsheet. Missing fields are ''.
	 * Columns beyond the fourth are rejoined into the example.
	 *
	 * @param string $text Text.
	 * @return array[] { word, phonetic, translation, example }.
	 */
	public static function parse_cards( $text ) {
		$cards = array();
		$first = true;
		foreach ( self::lines( $text ) as $line ) {
			$tab   = false !== strpos( $line, "\t" );
			$delim = $tab ? "\t" : ',';
			$raw   = $tab ? explode( "\t", $line ) : self::csv_line( $line );
			if ( count( $raw ) > 4 ) {
				$raw = array_merge( array_slice( $raw, 0, 3 ), array( implode( $delim, array_slice( $raw, 3 ) ) ) );
			}
			$cols = array_map(
				static function ( $cell ) use ( $tab ) {
					$cell = self::js_trim( $cell );
					if ( $tab && preg_match( '/^"(.*)"$/s', $cell, $m ) ) {
						$cell = self::js_trim( str_replace( '""', '"', $m[1] ) );
					}
					return $cell;
				},
				$raw
			);

			if ( $first ) {
				$first = false;
				if ( in_array( strtolower( $cols[0] ), self::CARD_HEADER_WORDS, true ) ) {
					continue;
				}
			}
			if ( '' === $cols[0] ) {
				continue;
			}

			$cards[] = array(
				'word'        => $cols[0],
				'phonetic'    => isset( $cols[1] ) ? $cols[1] : '',
				'translation' => isset( $cols[2] ) ? $cols[2] : '',
				'example'     => isset( $cols[3] ) ? $cols[3] : '',
			);
		}
		return $cards;
	}

	/**
	 * How many cards still miss a phonetic, translation or example.
	 *
	 * @param array[] $cards Output of parse_cards().
	 * @return int
	 */
	public static function incomplete( array $cards ) {
		$n = 0;
		foreach ( $cards as $card ) {
			if ( '' === $card['phonetic'] || '' === $card['translation'] || '' === $card['example'] ) {
				$n++;
			}
		}
		return $n;
	}

	/* ---------- Discussion and warm-up text ---------- */

	/**
	 * `Name: text` per line → `<p><strong>Name:</strong> text</p>` lines;
	 * a line without a name becomes a plain paragraph. `**…**` → bold.
	 * Text is escaped first.
	 *
	 * @param string $text Dialogue.
	 * @return string HTML, paragraphs joined with "\n".
	 */
	public static function parse_dialogue( $text ) {
		$out = array();
		foreach ( self::lines( $text ) as $line ) {
			if ( preg_match( '/^([^:*]{1,40}):\s*(.+)$/su', $line, $m ) && '' !== self::js_trim( $m[1] ) ) {
				$out[] = '<p><strong>' . self::esc( self::js_trim( $m[1] ) ) . ':</strong> ' . self::bold( self::esc( self::js_trim( $m[2] ) ) ) . '</p>';
			} else {
				$out[] = '<p>' . self::bold( self::esc( $line ) ) . '</p>';
			}
		}
		return implode( "\n", $out );
	}

	/**
	 * Non-empty lines (discussion questions), escaped, `**…**` → bold.
	 *
	 * @param string $text Text.
	 * @return string[] HTML per line.
	 */
	public static function parse_lines( $text ) {
		return array_map(
			static function ( $line ) {
				return self::bold( self::esc( $line ) );
			},
			self::lines( $text )
		);
	}

	/**
	 * Paragraphs, one per non-empty line, escaped.
	 *
	 * @param string $text Text.
	 * @param bool   $bold Whether `**…**` becomes bold.
	 * @return string HTML, paragraphs joined with "\n".
	 */
	public static function paragraphs( $text, $bold = false ) {
		$out = array();
		foreach ( self::lines( $text ) as $line ) {
			$html  = self::esc( $line );
			$out[] = '<p>' . ( $bold ? self::bold( $html ) : $html ) . '</p>';
		}
		return implode( "\n", $out );
	}

	/**
	 * The discussion questions as the live lessons write them: a table, one
	 * row per question, each with the category bullet image.
	 *
	 * @param string[] $items  HTML per question (parse_lines()).
	 * @param string   $bullet Bullet image URL.
	 * @return string
	 */
	public static function questions_table( array $items, $bullet ) {
		$img  = '<img src="' . htmlspecialchars( (string) $bullet, ENT_QUOTES, 'UTF-8' ) . '" width="25" height="25" alt="Bullet" />';
		$html = '<table border="1" style="border-collapse: collapse; width: 100%;">' . "\n<tbody>\n";
		foreach ( $items as $item ) {
			$html .= "<tr>\n"
				. '<td style="width: 40px; text-align: center;">' . $img . "</td>\n"
				. '<td style="width: calc(100% - 40px);">' . $item . "</td>\n"
				. "</tr>\n";
		}
		return $html . "</tbody>\n</table>";
	}

	/* ---------- Video ---------- */

	/**
	 * The video ID from a youtu.be / watch / embed / shorts link, or from a
	 * whole `<iframe>` embed code.
	 *
	 * @param string $value Link or embed code.
	 * @return string|null
	 */
	public static function youtube_id( $value ) {
		$re = '~(?:youtu\.be/|youtube(?:-nocookie)?\.com/(?:embed/|shorts/|live/|v/|watch\?(?:[^"\'\s<>]*?&(?:amp;)?)?v=))([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])~i';
		return preg_match( $re, (string) $value, $m ) ? $m[1] : null;
	}

	/**
	 * The embed code for one part (no `si=`).
	 *
	 * @param string $id    Video ID.
	 * @param int    $start Start in seconds.
	 * @param int    $end   End in seconds.
	 * @return string
	 */
	public static function embed_iframe( $id, $start, $end ) {
		$id = preg_match( '/^[A-Za-z0-9_-]{11}$/', (string) $id ) ? $id : '';
		return '<iframe width="1080" height="615" src="https://www.youtube.com/embed/' . $id . '?start=' . (int) $start . '&end=' . (int) $end . '" title="YouTube video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>';
	}

	/* ---------- Comprehension table (port of TBT Comprehension) ---------- */

	/** escapeHtml(): & < > " only — not ', exactly like admin.js. */
	private static function js_escape_html( $str ) {
		return str_replace( array( '&', '<', '>', '"' ), array( '&amp;', '&lt;', '&gt;', '&quot;' ), (string) $str );
	}

	/** applyInlineFormatting(). */
	private static function apply_inline_formatting( $text ) {
		$safe = self::js_escape_html( $text );
		$safe = preg_replace( '/\*([^*]+)\*/', '<i>$1</i>', $safe );
		return preg_replace( '/_([^_]+)_/', '<i>$1</i>', $safe );
	}

	/** normalizeTime(). */
	private static function normalize_time( $t ) {
		if ( ! preg_match( '/^(\d{1,3}):(\d{2})$/', self::js_trim( $t ), $m ) ) {
			return null;
		}
		return (int) $m[1] . ':' . $m[2];
	}

	/** wrapQuestion(). */
	private static function wrap_question( $question_html ) {
		$parts = preg_split( '/(<i>[^<]+<\/i>)/', $question_html, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out   = '';
		foreach ( $parts as $p ) {
			if ( 0 === strpos( $p, '<i>' ) ) {
				$inner = preg_replace( '/<i>([^<]+)<\/i>/', '$1', $p, 1 );
				$out  .= '<i><span style="font-weight: 400;">' . $inner . '</span></i>';
			} elseif ( '' !== $p ) {
				$out .= '<span style="font-weight: 400;">' . $p . '</span>';
			}
		}
		return $out;
	}

	/** buildTooltipHtml(). */
	private static function tooltip_html( $answer ) {
		return '<span class="tbt-tt-wrap" tabindex="0" aria-label="Hint">'
			. '<span class="tbt-tt-icon" aria-hidden="true">?</span>'
			. '<span class="tbt-tt-bubble" role="tooltip">' . self::js_escape_html( $answer ) . '</span>'
			. '</span>';
	}

	/**
	 * buildHtml(): the comprehension table. Hints are looked up by time, as
	 * in TBT Comprehension (a later non-empty answer for the same time wins).
	 *
	 * @param array[] $rows  { time, question, answer }, time as normalizeTime() shows it.
	 * @param string  $color Number circle colour.
	 * @return string
	 */
	public static function comprehension_html( array $rows, $color ) {
		if ( ! $rows ) {
			return '';
		}

		$map = array();
		foreach ( $rows as $row ) {
			$key = self::normalize_time( $row['time'] );
			if ( null !== $key && '' !== (string) $row['answer'] ) {
				$map[ $key ] = (string) $row['answer'];
			}
		}

		$hints = 0;
		$body  = "<table class=\"qa-table\">\n<tbody>\n";
		foreach ( array_values( $rows ) as $i => $row ) {
			$wrapped = self::wrap_question( self::apply_inline_formatting( $row['question'] ) );
			$key     = self::normalize_time( $row['time'] );
			$tooltip = '';
			if ( null !== $key && isset( $map[ $key ] ) ) {
				$tooltip = self::tooltip_html( $map[ $key ] );
				$hints++;
			}

			$body .= "<tr>\n";
			$body .= '<td class="col-num"><span class="circle_pd" style="background-color: ' . $color . ';">' . ( $i + 1 ) . "</span></td>\n";
			$body .= '<td class="col-time">' . self::js_escape_html( $row['time'] ) . "</td>\n";
			$body .= '<td class="col-q">' . $wrapped . $tooltip . "</td>\n";
			$body .= "</tr>\n";
		}
		$body .= "</tbody>\n</table>";

		return $hints > 0 ? self::TOOLTIP_STYLE . "\n" . $body : $body;
	}

	/* ---------- Names ---------- */

	/**
	 * first … tenth.
	 *
	 * @param int $n 1–10.
	 * @return string
	 */
	public static function ordinal( $n ) {
		$n = (int) $n;
		return isset( TBTLB_Config::ORDINALS[ $n - 1 ] ) ? TBTLB_Config::ORDINALS[ $n - 1 ] : '';
	}

	/**
	 * The text before the first `|`, lowercased, accents removed,
	 * non-alphanumerics → `-`.
	 *
	 * @param string $text Title.
	 * @return string
	 */
	public static function slug( $text ) {
		$parts = explode( '|', (string) $text, 2 );
		$s     = mb_strtolower( $parts[0], 'UTF-8' );
		$s     = strtr(
			$s,
			array(
				'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
				'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe', 'ø' => 'o', 'đ' => 'd', 'ð' => 'd', 'þ' => 'th',
			)
		);
		if ( class_exists( 'Normalizer' ) ) {
			$n = Normalizer::normalize( $s, Normalizer::FORM_D );
			if ( false !== $n && null !== $n ) {
				$s = preg_replace( '/\p{Mn}+/u', '', $n );
			}
		} else {
			$s = strtr(
				$s,
				array(
					'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ç' => 'c', 'č' => 'c',
					'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ě' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
					'ñ' => 'n', 'ň' => 'n', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ř' => 'r', 'š' => 's',
					'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ů' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ž' => 'z',
				)
			);
		}
		$s = preg_replace( '/[^a-z0-9]+/', '-', $s );
		return trim( $s, '-' );
	}
}
