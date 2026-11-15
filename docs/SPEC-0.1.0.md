# TBT Lesson Builder 0.1.0 — Fill in a lesson once, get the Divi draft

Today a Man and Society or Personal Development lesson is written in Word and then rebuilt by hand in Divi: seven flashcard CSVs, seven YouTube embeds, seven comprehension tables, a YOP poll, a wpDataTables glossary, three MP3s. The structure never changes; only the content does.

The Lesson Builder is a wp-admin page where an admin fills in one form. **Create draft** then makes the flashcard sets, the poll, the warm-up recordings and a complete Divi draft post with the right global headers. From then on the lesson is edited in Divi as usual (one-shot: the builder never reopens or rebuilds a lesson).

The design is `docs/prototypes/builder.html` (approved 15 Nov 2026). Where this spec and the prototype disagree, the spec wins; where the spec is silent, the prototype decides. The prototype's **Load sample lesson** button, fake pickers and fake AI/audio are prototype-only.

The reference lesson is `docs/reference/sample-lesson.json`: the Divi export of *How to Make Stress Your Friend*. Every piece of Divi markup the builder writes comes from it.

## Depends on

| Plugin | Version | Used for |
|---|---|---|
| TBT Flashcards | 2.1.0 | `tbt_fc_create_set()`, `tbt_fc_fill_cards()`, `tbt_fc_tts_mp3()`, `[tbt_flashcards id]`, `[tbt_flashcards_glossary]` |
| TBT Poll | 0.1.0 | `tbt_poll_parse()`, `tbt_poll_create()`, `tbt_poll_shortcode()` |
| Divi | as live | the layout and the global library items |

If either plugin (or one of its functions) is missing, the page shows one line naming what is missing and the form is not rendered. Checked with `function_exists()`, never fatal.

## Decisions (locked)

| Topic | Decision |
|---|---|
| Who | Administrators only (`manage_options`). wp-admin page **Lesson Builder** under the TBT Hub menu. |
| One-shot | Create draft makes a new draft post. Nothing links back; no rebuild, no edit mode. |
| Category | One per lesson: **Man and Society** or **Personal Development**. It sets everything domain-related (table below). Collections such as Learn from TED are added by hand later. |
| Level | A1–C2. Sets the level icon in the header; also stored as post meta `_tbtlb_level`. |
| Parts | 1–10. One YouTube video per lesson; each part has a start and an end. An empty start continues from the previous part's end; a typed start may skip a stretch. No part titles anywhere in the lesson. |
| Comprehension | Pasted once for the whole video (Excel: time, question, answer). Each question goes to the part whose range holds its time (start ≤ t ≤ end; a time on a shared boundary goes to the earlier part). A question in no part blocks Create draft. |
| Flashcards | Per part. Full CSV rows or just words. **Fill with AI** on each part writes the missing phonetics, translations and examples into the box for review. Create draft needs every card complete. |
| Warm-up | Three questions, each with a character (Alita, Pete, Sven, Kat) and their answer. **Generate audio** under each answer makes the MP3 in that character's voice and plays it. Changing the answer or the character marks the audio as out of date. The answer is also the transcript. |
| Poll | Pasted in the Word format (question lines, then A–D lines). Made with TBT Poll; the poll's title is the lesson title. |
| Glossary | `[tbt_flashcards_glossary ids="…" domain="…"]` over the lesson's flashcard sets. No wpDataTables. |
| Images | Featured, hero, warm-up and poll images chosen from the Media library. |
| Post | Draft, the chosen category, tags, excerpt, featured image, current user as author. |
| Unsaved work | The form autosaves to the browser (localStorage, per user) and offers it back on the next visit; cleared after a successful Create draft. |

### What the category decides

| | Man and Society | Personal Development |
|---|---|---|
| WordPress category (looked up by name) | Man and Society | Personal Development |
| Header family | General Interest (`gi`) | Personal Development (`pd`) |
| Category icon (global module) | 30080 | 30091 |
| Comprehension number colour | `#663366` | `#006600` |
| Poll / glossary `domain` | `gi` | `pd` |
| Warm-up header row | 365603 | 365564 |
| Part 1…10 header rows | 365477, 365476, 365475, 365474, 365473, 365472, 365471, 365470, 365469, 365468 | 365565, 365566, 365567, 365568, 365569, 365570, 365571, 365572, 367473, 370906 |
| Poll header row | 365605 | 365573 |
| Discussion header row | 365607 | 365575 |
| Glossary header row | 365609 | 365576 |
| Comments header row | 365611 | 365577 |

Level icons (global modules): A1 30105, A2 30113, B1 30115, B2 30116, C1 30117, C2 30118. Mobile-only notice section: 232953.

Voices (ElevenLabs IDs): Alita `cgSgspJ2msm6clMCkdW9`, Pete `UaYTS0wayjmO9KD1LR4R`, Sven `L0Dsvb3SLTyegXwtm47J`, Kat `hpp4J3VqNfWAUOO0d1Us`.

All of these live in one place, `includes/class-tbtlb-config.php`, as constants/arrays. No settings page in 0.1.0.

## Scope

| 0.1.0 | Later |
|---|---|
| Admin page with the form, autosave, Media pickers | Other lesson types or categories |
| Fill with AI, Generate audio | Settings page for IDs and voices |
| Create draft: sets, poll, MP3s already made, Divi post | Moving old lessons across |
| Comprehension tables (PHP port of TBT Comprehension's generator) | Mindomo map creation |
| Hub item | |

## Files

```
tbt-lesson-builder.php
includes/class-tbtlb-config.php      every ID, colour, voice and ordinal (above)
includes/class-tbtlb-format.php      pure helpers — unit-tested (Task 2)
includes/class-tbtlb-layout.php      Divi content from templates + library items (Task 4)
includes/class-tbtlb-rest.php        /fill, /audio, /create (Task 5)
includes/class-tbtlb-admin.php       the page, assets, Hub item (Task 6)
templates/*.txt                      Divi fragments cut from the sample (Task 3)
assets/css/tbt-tokens.css            vendored copy of Hub's tokens (byte-identical to Results')
assets/css/builder.css
assets/js/builder.js
tests/test-format.php
tests/fixtures/comprehension-*.html  outputs of TBT Comprehension's admin.js for the sample (Task 2)
docs/prototypes/builder.html         the approved prototype (Mariusz adds it)
docs/reference/sample-lesson.json    the Divi export (Mariusz adds it)
.github/workflows/deploy.yml         copy TBT Poll's; secrets are already in the repo
```

Prefixes: classes and constants `TBTLB_`, functions, options and meta `tbtlb_` / `_tbtlb_`, CSS `tbtlb-`, REST `tbt-lesson-builder/v1`, text domain `tbt-lesson-builder`. Never unprefixed `.tbt-*`.

---

## Phase 0 — read-only inventory (stop and report)

1. **Dependencies.** In `aparatofan/flashcards` and `aparatofan/tbt-poll` (main): confirm the signatures of `tbt_fc_create_set`, `tbt_fc_fill_cards`, `tbt_fc_tts_mp3`, `[tbt_flashcards_glossary]`, `tbt_poll_parse`, `tbt_poll_create`, `tbt_poll_shortcode`. If Flashcards 2.1.0 is not merged yet, say so and continue the inventory.
2. **Comprehension.** In `aparatofan/tbt_comprehension` `assets/admin.js`: confirm `buildHtml()`, `applyInlineFormatting()`, `wrapQuestion()`, `normalizeTime()`, `TOOLTIP_STYLE` and the exact output format, which Task 2 ports to PHP byte for byte.
3. **The sample.** Parse `docs/reference/sample-lesson.json` (`data` holds one post's Divi shortcode content). Report the section/row/module tree (with `admin_label`s and `global_module`s) and confirm it matches this list:
   mobile-only section (global 232953) → top banner (category icon blurb global 30080, empty middle column, level blurb global 30116) → hero image → warm-up (global header row, instruction, three question tables with `[audio mp3]` + TRANSCRIPT toggles, warm-up image) → parts 1–7 (global header row; key-language instruction with number-circle-1; code module with `[tbt_flashcards]`; video instruction with number-circle-2 and “Watch the … part of the video.”; code module with the iframe; comprehension instruction with number-circle-3; text module with the table) → poll (global header, instruction, code module with the shortcode, poll image) → discussion (global header; ROLE-PLAY instruction with the scenario; dialogue text module; OVER TO YOU instruction; discussion questions text module with `fa-icon-list` classes; MIND-MAP instruction; code module with the Mindomo iframe) → glossary (global header, code module) → comments (global header, prompt text, comments module).
4. **Global items.** Report how a global row or module appears in the sample: does the page content carry the item's full markup plus `global_module="ID"`? Propose how the builder gets each global item's markup at create time: read the `et_pb_layout` post `ID` and use its `post_content`, adding `global_module="ID"` to the opening tag if absent. Say if Divi 4 needs anything else (e.g. `saved_tabs`, `template_type`) for the row to stay linked.
5. **Post meta.** List the post meta Divi needs for a post to open in the builder and render as a Divi page (at least `_et_pb_use_builder`, `_et_pb_page_layout`, `_et_pb_side_nav`, `_et_builder_version`, `_et_pb_post_hide_nav`, `_et_pb_show_title`). Mariusz will compare your list against an existing lesson in phpMyAdmin.
6. **Content escaping.** Report how the sample stores text in `et_pb_text` (HTML), in `et_pb_toggle`, and raw HTML/shortcodes in `et_pb_code`, and how a literal `[` or `]` in teacher text must be written so it does not open a shortcode.
7. **Patterns.** From `aparatofan/tbt-poll`: bootstrap, Hub item, tokens, deploy workflow. Report anything that does not transfer.

**Hard stop** if anything contradicts the spec, especially items 3–5. Otherwise end with the report and wait for Mariusz to say go.

---

## Task 1 — Bootstrap (`tbt-lesson-builder.php`, `class-tbtlb-config.php`)

Header `Version: 0.1.0`; `TBTLB_VERSION` `0.1.0`; `TBTLB_DIR`, `TBTLB_URL`. No database table. Config as listed above. Hub item **TBT Lesson Builder**, “Fill in a lesson once and get the Divi draft”, capability `manage_options`, url to the page.

## Task 2 — Pure helpers (`class-tbtlb-format.php`) and tests

No WordPress calls; all unit-tested in `tests/test-format.php`.

- `time_to_seconds( string )`: `m:ss`, `mm:ss`, `mmm:ss`, `h:mm:ss`. Special case: `a:bb:00` is read as `a:bb` minutes:seconds, because Excel shows m:ss times as h:mm:ss when copied. Otherwise null.
- `seconds_to_time( int )`: `m:ss` (`80` → `1:20`, `868` → `14:28`).
- `parse_qa( string )`: tab- or comma-separated time, question, answer; skips a header row and blank lines; sorted by time.
- `resolve_parts( array $parts )`: applies the empty-start rule; returns ranges or the first problem per part: “Use minutes:seconds, e.g. 3:08”, “Enter the start”, “End must be after the start”, “Overlaps the previous part”.
- `assign_questions( $ranges, $qa )`: buckets + the list of questions in no part.
- `parse_cards( string )`: CSV with header optional, or words only, or tab-separated; mirrors Flashcards' CSV rules for quotes; returns `{word, phonetic, translation, example}` with empty strings for missing fields. `incomplete( $cards )`.
- `parse_dialogue( string )`: `Name: text` per line; `**…**` → `<strong>…</strong>`; text escaped first.
- `parse_lines( string )`: non-empty lines (discussion questions), `**…**` → `<strong>`.
- `youtube_id( string )`: from a youtu.be / watch / embed / shorts link **or a whole `<iframe>`**.
- `embed_iframe( $id, $start, $end )`: `<iframe width="1080" height="615" src="https://www.youtube.com/embed/{id}?start={s}&end={e}" title="YouTube video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>` (no `si=`).
- `comprehension_html( array $rows, string $color )`: a byte-for-byte port of `buildHtml()` from TBT Comprehension, with `rows` = `{time, question, answer}`, the time shown as `normalizeTime()` would show it, and the hint tooltip when an answer exists. **Test:** generate fixtures by running the original `admin.js` functions in Node on the sample's seven parts (both colours), commit them under `tests/fixtures/`, and assert the PHP output is identical.
- `ordinal( int )`: first … tenth.
- `slug( string )`: the text before the first `|`, lowercased, accents removed, non-alphanumerics → `-`.
- `escape_shortcode_text( string )`: `[` → `&#91;`, `]` → `&#93;` for text written into Divi modules.

## Task 3 — Templates (`templates/*.txt`)

Cut from the sample, one file per repeated piece, with every lesson-specific value replaced by a `{{PLACEHOLDER}}`:

`mobile.txt`, `banner.txt` ({{CATEGORY_ICON}}, {{LEVEL_ICON}}), `hero.txt` ({{HERO_SRC}}, {{HERO_ALT}}), `warmup.txt` ({{HEADER_ROW}}, {{Q1}}…{{Q3}}, {{MP3_1}}…{{MP3_3}}, {{T1}}…{{T3}}, {{WARMUP_SRC}}), `part.txt` ({{HEADER_ROW}}, {{N}} for the section `module_id` `partN` and label, {{FLASHCARDS}}, {{ORDINAL}}, {{IFRAME}}, {{TABLE}}), `poll.txt`, `discussion.txt` ({{SCENARIO}}, {{DIALOGUE}}, {{QUESTIONS}}, {{MINDMAP_URL}}), `glossary.txt`, `comments.txt`.

- The global items (`{{HEADER_ROW}}`, the icons, the mobile section) are filled at create time from the Divi library (Phase 0 item 4), never copied from the sample, so a later edit of a global item still reaches new lessons.
- Keep every other attribute exactly as in the sample (builder versions, presets, classes, spacing).
- Fix the sample's own mistakes while cutting: the part instruction always says “Watch the {{ORDINAL}} part of the video.”

## Task 4 — Layout (`class-tbtlb-layout.php`)

`TBTLB_Layout::build( array $lesson ): string|WP_Error` — the whole post content, in sample order, with `part.txt` repeated once per part. Every global library item is loaded first; a missing one returns `WP_Error` naming its ID before anything else is created. Teacher text goes through `esc_html()` (or the format helpers) and then `escape_shortcode_text()`; URLs through `esc_url()`; attribute values through `esc_attr()`.

## Task 5 — REST (`class-tbtlb-rest.php`)

Namespace `tbt-lesson-builder/v1`; every route `manage_options`, `wp_rest` nonce, no-cache headers.

- `POST /fill` `{ items: string[], context: string }` → `tbt_fc_fill_cards()` → `{ cards }`. Errors pass through with their message.
- `POST /audio` `{ text, character, n, title, replace? }` → `tbt_fc_tts_mp3( $text, $voice )`; saved to Media as `Q{n}-{slug(title)}.mp3` (`wp_upload_bits()` + `wp_insert_attachment()`), attachment meta `_tbtlb_generated = 1`. `replace` is the previous attachment ID from this form: delete it only if it has `_tbtlb_generated`, the current user is its author, and it is not attached to a post. → `{ id, url }`.
- `POST /create` with the whole form. The server re-validates everything (same helpers as the form), then:
  1. builds the layout once in memory (fails before creating anything if a global item is missing);
  2. creates the flashcard sets, one per part, titled `{title} — part {n}`, context = title;
  3. creates the poll (`tbt_poll_create( title, tbt_poll_parse( text ) )`);
  4. builds the final content with the set IDs, the poll shortcode and the glossary shortcode;
  5. inserts the draft post (title, content, excerpt, category, tags, author), sets the featured image, the Divi meta from Phase 0 item 5, `_tbtlb_level`, and `_tbtlb_built` = `TBTLB_VERSION` + time; attaches the three MP3s to the post.
  If any step after 2 fails, delete what this request created (sets, poll) and return the error. → `{ post_id, edit_url, builder_url }`.
- Validation messages are plain sentences naming the stage: “Warm-up: question 2 has no audio.”, “Video parts: 2 questions fall outside every part (3:21, 3:32).”, “Part 4: 3 cards still need AI.”, “Poll: question 2 needs at least 2 answers.”, “Category ‘Personal Development’ was not found.”

## Task 6 — The page (`class-tbtlb-admin.php`, `builder.js`, `builder.css`)

As the prototype: Tool/Admin world (canvas `--tbt-canvas`, white stage cards, 22px radius, 40px padding), left stage navigation with progress dots, stages Basics · Images · Warm-up · Video parts · Poll · Discussion · Glossary, and the finish card with the checklist and **Create draft** (enabled only when every stage is ready).

- **Basics:** title, excerpt (counter, 160 suggested), category (segmented, sets `--look`), level A1–C2, tags.
- **Images:** four `wp.media` pickers with the chosen file name.
- **Warm-up:** per question: question, character select, answer, **Generate audio in {character}'s voice** → while working “Generating in Sven's voice…” → an `<audio controls>` player plus **Regenerate**. Editing the answer or changing the character marks it “Out of date” and blocks Create draft until regenerated.
- **Video parts:** YouTube field (link or iframe), the Excel paste box, the timeline (parts as proportional segments with a tick per question, gaps for skipped stretches), the part cards (number, start, end, delete; flashcards box with chips and **Fill with AI**; the part's questions; the generated embed code in a `<details>`), **Add part** (max 10).
- **Fill with AI:** sends the part's words (each card's `word`) in batches of at most 40, writes complete CSV rows back into the box, keeps cards that were already complete unchanged. Errors show under the button.
- **Poll:** the paste box with a live preview (number badge, question, A–D), and a chip for a question without exactly four answers.
- **Discussion:** scenario, dialogue (`Name: text` lines, turn count), questions (one per line, count), Mindomo link (must be a `mindomo.com` URL).
- **Glossary:** read-only preview table built from the parts' cards (de-duplicated), with the count.
- **Create draft** → a dialog listing what was made, with **Open in Divi** (`builder_url`) and **Edit post**. On error, the dialog shows the message and the form stays as it was.
- Autosave: every change to localStorage key `tbtlb:{user_id}` (try/catch); on load, if present, the banner “Your unfinished lesson was restored. Start over” with Start over clearing it.
- Assets only on this page; `wp_enqueue_media()`; versioned with `TBTLB_VERSION`.

## Validation

```bash
for f in tbt-lesson-builder.php includes/*.php; do php -l "$f"; done
node --check assets/js/builder.js
php tests/test-format.php
```

State what still needs a live check.

---

## Acceptance checklist (live site, after Flashcards 2.1.0, Poll 0.1.0 and this release are deployed)

Mariusz rebuilds *How to Make Stress Your Friend* through the form from its Word document.

- [ ] Without Flashcards 2.1.0 or Poll active, the page shows one line naming the missing plugin.
- [ ] Pasting the Excel Q&A and the seven end times sorts the questions 4 / 7 / 7 / 12 / 12 / 9 / 3; moving an end time moves questions; a question in a skipped stretch shows the red notice and blocks Create draft.
- [ ] Pasting the YouTube iframe instead of the link gives the same video ID.
- [ ] Words-only flashcards → **Fill with AI** writes full rows; already complete rows are unchanged.
- [ ] **Generate audio** for Sven plays the answer in Sven's voice; changing the character marks it out of date; Regenerate replaces it (the old MP3 is gone from Media).
- [ ] Reloading the page mid-way restores the form.
- [ ] **Create draft** makes one draft post: Man and Society, B2, tags, excerpt, featured image; seven flashcard sets; one poll; no new wpDataTables table.
- [ ] Open in Divi: the layout matches the current lesson section for section, with the General Interest part headers 1–7, the category and B2 icons, and “Watch the fifth/sixth/seventh part” correct.
- [ ] Editing a global part header in the Divi library changes it in the new lesson too (the rows are still global).
- [ ] Preview: flashcards play, each video part starts and stops at its times, comprehension hints show on hover in purple circles, the poll votes, the glossary searches and pages, the mind map loads, comments show.
- [ ] The same lesson built as Personal Development gets the green PD headers, the Personal Productivity icon, green circles, `domain="pd"` poll and glossary.
- [ ] A failure on the post insert (e.g. Divi library item ID changed in config) leaves no orphan sets or polls.
- [ ] Hard refresh shows `?ver=0.1.0` on the builder assets.
