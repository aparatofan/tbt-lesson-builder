# TBT Lesson Builder 0.3.0 — Flashcards from the Media library, Personal productivity, comments open

The first real lesson was built with 0.2.1 on 16 Nov 2026. Three fixes came out of it:

1. **Flashcards from the Media library.** Madzia uploads each part's CSV to the Media library before the lesson is built. A part can now link to that file. The lesson then shows `[tbt_flashcards url="https://thebluetree.pl/wp-content/uploads/2026/11/five-free-habits-7.csv"]` and no flashcard set is created for it. The glossary reads the same files (TBT Flashcards 2.2.0, `urls`).
2. **Personal productivity, not Personal Development.** On the live site Personal Development is a parent category with no posts of its own. The lessons belong in its subcategory **Personal productivity** (category ID **669**). That subcategory's icon, the logo on the left of the banner next to the title, is the global module **30091**, not 30087. The first draft went into the parent category with the wrong icon.
3. **Comments open.** Every draft is created with comments allowed.

Everything else stays exactly as in 0.2.1 (`docs/SPEC-0.2.0.md`, `docs/SPEC-0.2.1.md`). The empty middle column of the banner stays empty. The lesson title is added by hand in Divi.

## Depends on

| Plugin | Version | New in this release |
|---|---|---|
| TBT Flashcards | **2.2.0** | `[tbt_flashcards_glossary urls="…"]`; `tbt_fc_read_csv_text()`, `tbt_fc_parse_csv()` used for the server check |
| TBT Poll | 0.1.0 | — |

The dependency line names Flashcards 2.2.0. The builder checks `defined( 'TBT_FC_VERSION' ) && version_compare( TBT_FC_VERSION, '2.2.0', '>=' )` as well as the functions.

## Decisions (locked)

| Topic | Decision |
|---|---|
| Button | In each part's Flashcards row, **Choose from Media library** replaces 0.2.1's **Choose CSV file**. The Media window has its own Upload tab, so a file from the computer still works and lands in Media too. |
| Media window | `wp.media`, single choice. Title “Flashcards for part N”, button “Use this file”, library limited to CSV/text files (MIME types agreed in Phase 0). |
| On choose | The file is fetched (same origin) and its text goes into the box with 0.2.1's normalising (BOM dropped, `\n`, trailing blanks trimmed). The part stores `media: { id, url, name }`. If the box already has text, ask first, as in 0.2.1. Message: “Linked five-free-habits-7.csv: 24 cards.” |
| Linked part | The box is read-only. It shows the file's cards so the chips, the glossary preview and the checks still work. Under it is one line: file icon · **five-free-habits-7.csv** · “from Media library” · **Unlink**. **Fill with AI** is hidden. |
| Unlink | Keeps the text in the box, makes it editable again and removes `media`. The part then makes a flashcard set at Create draft, as in 0.2.1. |
| Incomplete file | If some of the file's cards miss a phonetic, translation or example, a red line reads “3 cards in five-free-habits-7.csv are incomplete. Fix the file in Media, or Unlink and use Fill with AI.” This blocks Create draft. |
| Lesson output | A linked part writes `[tbt_flashcards url="{url}"]` (the attachment's URL, `esc_url`) and creates no set. An unlinked part works as today (`tbt_fc_create_set`, `[tbt_flashcards id]`). Parts may mix. |
| Glossary | `[tbt_flashcards_glossary ids="{sets}" urls="{linked files, part order}" domain="…"]`. Omit an attribute when it would be empty. |
| Server check | `/create` takes `parts[i].media` = attachment ID. It must be an attachment whose file ends in `.csv`, `.tsv` or `.txt` and whose URL is in uploads. The server reads it with `tbt_fc_read_csv_text()` and parses it with `tbt_fc_parse_csv()`. Not the box text: the file is what the lesson will show. It needs ≥ 1 card and every card complete. Messages: “Part 2: the flashcards file is missing from Media.”, “Part 2: five-free-habits-7.csv has no flashcards.”, “Part 2: 3 cards in five-free-habits-7.csv are incomplete.” |
| Data | Additive: autosave keeps `media` per part; the payload sends `parts[i].media` (ID or 0). `cards` is still sent (unused by the server for a linked part). |
| PD category | `class-tbtlb-config.php`, `pd`: `'label' => 'Personal productivity'` (the Basics button), `'wp_category' => 'Personal productivity'`, new `'wp_category_id' => 669`, `'icon' => 30091`. Everything else in `pd` (family, colours, header rows, `domain` `pd`) is unchanged. |
| Category lookup | By ID when the config has `wp_category_id` (`get_term( $id, 'category' )`), otherwise by name as today. If the term is missing: “Category ‘Personal productivity’ (ID 669) was not found.” GI keeps its name lookup. |
| Comments | `wp_insert_post` gets `'comment_status' => 'open'`. Pings are left to the site default. |

## Files

```
tbt-lesson-builder.php               version 0.3.0
includes/class-tbtlb-config.php      pd: Personal productivity, ID 669, icon 30091
includes/class-tbtlb-rest.php        dependency check; category by ID; per-part media; comment_status
includes/class-tbtlb-layout.php      url shortcode for linked parts; glossary urls
assets/js/builder.js                 Media button, linked state, restore, payload
assets/css/builder.css               the linked-file line (existing tokens and classes)
docs/SPEC-0.3.0.md                   this file (Mariusz adds it)
CLAUDE.md                            “current release” line → SPEC-0.3.0.md
```

---

## Phase 0 — read-only inventory (stop and report)

1. **Version.** Confirm `main` is at 0.2.1 and list any open PRs. Confirm Flashcards 2.2.0's `urls` attribute (if not merged yet, say so and continue).
2. **0.2.1's CSV button.** List the code for **Choose CSV file** (`csvFile`, `csvBtn`, `csvMsg`, the reader) and say what is reused for the Media file (normalising, replace prompt, messages) and what is removed.
3. **MIME types.** Report how WordPress stores an uploaded `.csv` (usually `text/csv`), and propose the `library.type` list for the Media window. Mariusz will check one of Madzia's files in Media against it.
4. **Layout.** Quote where `{{FLASHCARDS}}` and `{{GLOSSARY}}` are filled (`class-tbtlb-layout.php` ~102 and ~134). Confirm a linked part needs no change to `templates/part.txt`.
5. **Undo.** Confirm `undo()` only deletes what this request created, so a run with linked parts (fewer sets) still cleans up correctly.
6. **Category.** Quote the category lookup (`get_term_by( 'name', … )`, rest ~428) and where `label` is shown, and confirm the change in Decisions touches nothing else.
7. **Comments.** Confirm `wp_insert_post` sets no `comment_status` today, so drafts get the site default (closed on the live site).

**Hard stop** if anything contradicts this spec. Otherwise end with the report and wait for Mariusz to say go.

## Task 1 — Version and config

`Version: 0.3.0`, `TBTLB_VERSION` `0.3.0`, `CLAUDE.md` line. `pd` as in Decisions (label, category name, ID 669, icon 30091).

## Task 2 — REST (`class-tbtlb-rest.php`)

- Category: look up by `wp_category_id` when set, as in Decisions.
- Dependency: Flashcards 2.2.0 (version check + `tbt_fc_read_csv_text`, `tbt_fc_parse_csv`, plus the existing functions).
- Per part, flashcards check: if `media` > 0, check the file and its cards as in Decisions; keep `'media_url' => wp_get_attachment_url( $id )` on the part. Else the 0.2.1 checks on `cards`.
- Create: skip `tbt_fc_create_set` for linked parts.
- `wp_insert_post`: `'comment_status' => 'open'`.

## Task 3 — Layout (`class-tbtlb-layout.php`)

- `{{FLASHCARDS}}`: `[tbt_flashcards url="…"]` for a linked part, `[tbt_flashcards id="…"]` otherwise.
- `{{GLOSSARY}}`: `ids` from the created sets, `urls` from the linked parts in part order, each attribute only when non-empty, then `domain`.

## Task 4 — The page (`builder.js`, `builder.css`)

- Replace **Choose CSV file** with **Choose from Media library** (same place, same button classes). One `wp.media` frame per click, as the Images stage's pickers do.
- Linked state as in Decisions: read-only box, the file line with **Unlink**, Fill hidden, the incomplete-file red line. The Flashcards step circle is ✓ only when the file's cards are all complete.
- `restore()` keeps `media` (`id` > 0, `url`, `name` strings). Payload sends `media: id || 0`.
- The glossary preview stage needs no change (it reads the box).

## Validation

```bash
for f in tbt-lesson-builder.php includes/*.php; do php -l "$f"; done
node --check assets/js/builder.js
php tests/test-format.php
```

## Acceptance checklist (live site, after Flashcards 2.2.0 and this release)

- [ ] **Choose from Media library** opens Media showing CSV files. Picking `five-free-habits-7.csv` fills the box (read-only) and shows the file line.
- [ ] Uploading a new CSV in the Media window's Upload tab and choosing it works the same way.
- [ ] A file with incomplete cards shows the red line and blocks Create draft; **Unlink** → **Fill with AI** fixes it as before.
- [ ] Create draft with all parts linked: no new flashcard sets; each part's code module holds `[tbt_flashcards url="…"]`; the glossary shortcode has `urls` in part order and lists all words, left-aligned.
- [ ] A mixed lesson (one part typed, one linked) makes one set and one url shortcode; the glossary has both.
- [ ] The Basics category buttons read **Man and Society** and **Personal productivity**.
- [ ] A Personal productivity draft is filed under Personal Development → Personal productivity (ID 669), not the parent, and shows icon 30091 on the left of the banner. Its colours and headers are still the green PD ones.
- [ ] A Man and Society draft still shows icon 30080.
- [ ] The new draft has **Allow comments** ticked, and the comments section works once published.
