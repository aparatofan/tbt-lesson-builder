# TBT Lesson Builder 0.2.0 — Video parts, one part at a time

In 0.1.0 the Video parts stage is hard to read and easy to break. The comprehension questions are pasted once for the whole video and sorted into the parts by time. The parts show the flashcards and the questions side by side. A timeline bar and an embed-code box sit between them. Typing `55` as an end time is rejected (it must be `0:55`), so the part has no range and none of the pasted questions reach it. The part then reads “No questions in this part yet”.

0.2.0 rebuilds that stage only. It has one YouTube field. Below it each part is a card with three rows, one under another: **Time → Flashcards → Questions**. Questions are pasted inside the part they belong to.

The design is `docs/prototypes/video-parts.html` (approved 16 Nov 2026). Where this spec and the prototype disagree, the spec wins. Where the spec is silent, the prototype decides. Its sample data, the fake **Fill with AI** message and the “What changed” note under the stage are prototype-only. Every other stage of the builder stays exactly as it is.

## Decisions (locked)

| Topic | Decision |
|---|---|
| Time formats — Phase 0 decision (16 Nov 2026) | **Overrides the “Time formats” row below and Task 2.** The new formats (bare digits = seconds, `m.ss`) apply **only to the part Start/End boxes**: `resolve_parts()` / `resolveParts()` and the blur rewrite. They live in a separate helper, `part_time_to_seconds()` / `partTimeToSeconds()`, which tries the new formats and then falls back to `time_to_seconds()`. `time_to_seconds()` and `parse_qa()` / `parseQa()` are unchanged, so a numbered line (`1\tWhat…`) in a questions paste is still skipped, and the existing `'80' → null` test stays. The Task 2 cases (`55`, `130`, `1.30`, `14.28`, `1.75`, `1.2.3`, `12345`) test the new helper; instead of `parse_qa('55\tQ\tA')` giving 0:55, a test checks that the line is skipped. |
| Layout | YouTube field, then the part cards, then **Add part** (max 10, unchanged). No global questions box, no timeline bar, no embed-code `<details>`. |
| Part card | Header: number badge, “Part N”, the resolved range (`0:00 – 0:55`), two summary chips (`6 cards`, `3 questions`), fold chevron, delete. Body: three rows, each with a label column (step circle, label, one-line hint) and an input column. |
| Step circles | Grey number when empty. Green ✓ when the row is ready. Red when the row has a problem (bad time, question outside the part). |
| Folding | Clicking the header (or Enter/Space on it) folds or opens the body. **Add part** folds the other parts and opens, scrolls to and focuses the new part's End box. Folding is view state only: not autosaved, not sent. |
| Time row | Start and End boxes, the length chip (`55 s`, `1 min 35 s`) and a **Watch this part** link. An empty start still continues from the previous part's end, shown as the Start placeholder, with the hint “Starts where part N ends. Type a start to skip a stretch.” |
| Time formats | `m:ss`, `mm:ss`, `mmm:ss`, `h:mm:ss` and the Excel `a:bb:00` case as today, **plus** bare digits = seconds (`55` → 0:55, `130` → 2:10) and one dot as the separator (`1.30` → 1:30). On blur a valid time is rewritten as `m:ss`, so `130` visibly becomes `2:10`. The same rules apply to the time column of the questions. |
| Watch this part | `https://www.youtube.com/embed/{id}?start={s}&end={e}&autoplay=1`, `target="_blank" rel="noopener"`. Disabled (muted, not clickable) until the video and the part's range are valid. Replaces the embed-code box; the post's iframe is unchanged (`embed_iframe()`). |
| Flashcards row | The textarea as today (full CSV rows or words only). Below it: a chip (`6 words` / `No words yet`), then the button **Fill 6 with AI** (count = incomplete cards). It reads “All filled” and is disabled when nothing is missing, and “Filling…” while working. Fill errors show beside it, as today. |
| Questions row | A textarea for **this part's** questions: Excel paste, time · question · answer (tab- or comma-separated, as today). Below it: a count chip, an “N without a hint” chip when answers are missing (as today), and a red line for problems. Under that: a preview table, Time · Question · Answer (answer on one line, ellipsis, full text in `title`). |
| Questions outside the part | Each question must sit inside its own part's range, ends included (`start ≤ t ≤ end`). A question outside it gets a red table row and the red line “1 outside 0:00–0:55 — move it to the right part or fix the time”. It blocks Create draft. |
| Skipped lines | A pasted line with no valid time or no question is skipped. The red line says “2 lines skipped: no time or no question”. Lines that look like a header are skipped silently, as today. |
| Required | Every part needs a valid range, at least one flashcard (all complete) and **at least one question**, with none outside the part. |
| Data | Autosave and the `/create` payload gain `parts[i].qa`. The top-level `qa` is no longer written or sent. The Divi output of a part does not change. |
| Old autosave | On restore, if the saved form has a non-empty top-level `qa` and no part has its own `qa`, sort those questions into the parts by time once, the way 0.1.0 does (`assign_questions`, earlier part wins on a shared boundary). Questions that fit no part go to Part 1, where they show red. The pasted lines are moved as they were typed (raw line text, not reformatted). |

## Scope

| 0.2.0 | Not in 0.2.0 |
|---|---|
| Video parts stage: layout, time formats, per-part questions, Watch link, folding | Any other stage |
| `parts[i].qa` in autosave and `/create`; old-autosave migration | A “paste all questions at once” helper |
| `time_to_seconds()` additions + tests; `questions_outside()` helper + tests | Changes to the Divi templates, comprehension HTML or flashcard sets |

## Files

```
tbt-lesson-builder.php               version bump
includes/class-tbtlb-format.php      time_to_seconds() additions, questions_outside()
includes/class-tbtlb-rest.php        /create: per-part questions
includes/class-tbtlb-admin.php       the Video parts stage markup
assets/js/builder.js                 Video parts render/refresh, restore migration, payload
assets/css/builder.css               part card and rows; timeline/embed/qlist rules removed
tests/test-format.php                new cases
docs/prototypes/video-parts.html     the approved prototype (Mariusz adds it)
docs/SPEC-0.2.0.md                   this file (Mariusz adds it)
CLAUDE.md                            “current release” line → SPEC-0.2.0.md
```

---

## Phase 0 — read-only inventory (stop and report)

1. **Version.** Report `TBTLB_VERSION` and the header version on `main`, and any open PRs. 0.2.0 assumes main is at 0.1.x.
2. **Video stage today.** List every place that reads or writes the top-level `qa`, the timeline (`tbtlb-tl-*`), the embed box (`data-embed`), the question list (`data-q`, `tbtlb-qlist`), `assignQuestions` / `assign_questions`, and the video-stage readiness checks (nav dot, finish checklist, Create draft gate). Include `builder.js`, `class-tbtlb-admin.php`, `class-tbtlb-rest.php` and `builder.css`.
3. **Times.** Confirm `time_to_seconds()` (PHP) and `timeToSeconds()` (JS) are the only time parsers. List every caller. Say whether accepting bare digits could change how any existing input is read. In particular: could a header line or a numbered line in a questions paste now be read as a time? Do the comprehension fixtures still pass unchanged?
4. **Finish checklist.** Quote the current Video-parts lines of the checklist and the Create-draft gate, and propose their 0.2.0 wording (see Task 4).
5. **Restore.** Confirm what `restore()` keeps today for a part (`start`, `end`, `cards`), so the migration in Task 4 is additive.

**Hard stop** if anything contradicts this spec. Otherwise end with the report and wait for Mariusz to say go.

---

## Task 1 — Version

`Version: 0.2.0` in the header and `TBTLB_VERSION` `0.2.0`. Update the “current release” line in `CLAUDE.md` to `docs/SPEC-0.2.0.md`. No DB version, no new options or meta.

## Task 2 — Pure helpers (`class-tbtlb-format.php`) and tests

- `time_to_seconds()`: after trimming, also accept
  - 1–4 digits only → that many seconds (`55` → 55, `130` → 130, `0` → 0);
  - `m.ss` / `mm.ss` / `mmm.ss` with exactly one dot → as `m:ss` (`1.30` → 90). Seconds must still be `00`–`59` (`1.75` → null).

  Everything that works today keeps its result.
- `questions_outside( array $range, array $rows ): array`: the rows (from `parse_qa()`) with `t < start` or `t > end`, in order.
- Keep `assign_questions()` and its tests. The JS restore migration uses the same rule.
- New tests in `tests/test-format.php`:
  - `55` → 55, `130` → 130, `1.30` → 90, `14.28` → 868, `1.75` → null, `1.2.3` → null, `12345` → null.
  - `parse_qa()` on `55\tQ\tA` gives `t` 55 and `time` `0:55`.
  - `questions_outside()` with both ends inclusive (a question at the start and one at the end are inside).
  - Every existing case and the comprehension fixtures still pass.

Mirror the same parsing in `timeToSeconds()` in `builder.js` so the form and the server agree.

## Task 3 — REST `/create` (`class-tbtlb-rest.php`)

Replace the global questions block with per-part questions:

- Drop the top-level `qa` read, the “paste the comprehension questions” failure and the `assign_questions` call.
- For each part, after its range and flashcard checks: `$rows = TBTLB_Format::parse_qa( $str( 'qa', $part ) )`.
  - None → “Part 3: paste the comprehension questions (time, question, answer).”
  - `questions_outside( $range, $rows )` not empty → “Part 2: 1 question is outside 0:55–2:30 (2:41).” / “Part 2: 3 questions are outside 0:55–2:30 (0:50, 2:41, 2:59).” Use `_n()`, show at most 5 times plus “, …”.
  - Otherwise `'rows' => $rows` for that part (the same shape `layout` already consumes).
- Order of checks per part: range → flashcards → questions, so messages come in the order of the rows on screen.
- The success summary (“N comprehension questions in N tables”) counts the per-part rows.

Nothing else in `/create` changes.

## Task 4 — The stage (`class-tbtlb-admin.php`, `builder.js`, `builder.css`)

Build it as the prototype shows. Use the builder's own components wherever one exists: `.tbtlb-btn` / `.tbtlb-small`, `.tbtlb-iconbtn`, `.tbtlb-chip` (`is-ok`, `is-warn`, `is-err`), `.tbtlb-num`, inputs, `.tbtlb-mono`, the `--tbtlb-look` number colour, and tokens only. Do not add the prototype's own CSS variables or a new font link.

**Markup (`class-tbtlb-admin.php`)**
- Lead text: “Paste the video once. Then fill in each part: its time, its flashcards, its questions.”
- YouTube field as today. The info line stays: “Video ID … taken from the embed code”, or the error.
- Remove the global Comprehension questions field, the timeline block and `#tbtlb-video-notice`.

**Part card (`renderParts()`)**
- Header row as in Decisions. The whole header is the fold toggle (`role="button"`, `tabindex="0"`, `aria-expanded`). The delete button stops propagation and keeps its current behaviour.
- Body: three `.tbtlb-prow` rows (grid `190px 1fr`, top border between rows):
  1. **Time** · “Where the clip starts and ends”: Start and End inputs with small uppercase captions START / END, an arrow between them, the length chip and **Watch this part**. Under them, the continue-from hint or the time error.
  2. **Flashcards** · “Words from this part”: textarea (6 rows), then the chip, the Fill button and the fill error.
  3. **Questions** · “Excel: time, question, answer”: textarea (4 rows, mono, the prototype's placeholder), then the chips and the red line, then the preview table.
- Time inputs: on `blur`, if the value parses, rewrite it as `m:ss` and save. Error text as today, plus “Starts before part N ends (m:ss)” for an overlap, which replaces “Overlaps the previous part” in the form only (the server message can stay).
- The step circle per row: ready / problem / empty, as in Decisions.

**Refresh (`refreshVideo()`)**
- Per part: resolve the range (unchanged `resolveParts`). Then parse the part's `qa`, count skipped lines and work out the outside rows against the range. If the range is invalid, nothing is “outside” yet: the table shows plain rows.
- Update the header range and summary chips, the step circles, the length chip, the Watch link, the cards chip and Fill button, and the questions chips, red line and table.
- Remove the timeline code and the embed-box code. `embedIframe()` stays only if something else still uses it; otherwise remove it.
- Readiness for the nav dot and the Create-draft gate: video ID valid, at least one part, every range valid, every part with ≥ 1 card and none incomplete, every part with ≥ 1 question and none outside. The finish checklist shows the problems in that order, naming the part (e.g. “Part 2: 1 question outside its time”), in the wording agreed in Phase 0.

**State, autosave, payload**
- A part is `{ start, end, cards, qa }`. `blank()` drops the top-level `qa`. **Add part** pushes `qa: ''`.
- `restore()` keeps `qa` per part (string). Then the one-time migration in Decisions: a non-empty top-level `saved.qa` and every part's `qa` empty → split the pasted lines by the old rule and append each line's raw text to its part's `qa` (Part 1 for those that fit no part). Do not write the top-level `qa` back.
- The `/create` payload sends `parts: [{ start, end, cards, qa }]` and no top-level `qa`.
- Folding state lives in a separate in-memory array, not in `state`.

**CSS (`builder.css`)**
- Add the part-card rules: header, fold, rows, step circle, time captions, length chip, Watch link, the preview table, the outside row in `--tbt-error-bg` with the time in `--tbt-error`.
- Remove the now-unused `tbtlb-tl-*`, `.tbtlb-embed`, `.tbtlb-qlist` and old `.tbtlb-part-head` / `.tbtlb-part-body` grid rules.
- At ≤ 860px the rows stack to one column (tablet).

## Validation

```bash
for f in tbt-lesson-builder.php includes/*.php; do php -l "$f"; done
node --check assets/js/builder.js
php tests/test-format.php
```

State what still needs a live check.

---

## Acceptance checklist (live site)

Mariusz rebuilds *How to Make Stress Your Friend* through the form.

- [ ] The Video parts stage matches the prototype: YouTube field, part cards with Time → Flashcards → Questions rows. No global questions box, no timeline, no embed box.
- [ ] Typing `55` in End shows `0:55` after leaving the box; `130` becomes `2:10`; `1.30` becomes `1:30`; `abc` shows the error.
- [ ] Part 2 with an empty start shows Part 1's end as its placeholder and the “Starts where part 1 ends” hint; typing a later start skips a stretch.
- [ ] **Watch this part** opens the clip from its start and stops at its end.
- [ ] Pasting Part 1's questions from Excel fills the preview table. A question with a time past the part's end shows red, the step circle turns red and Create draft stays disabled until it is moved or fixed.
- [ ] A part with no questions keeps Create draft disabled and the checklist names that part.
- [ ] **Fill N with AI** fills the part's cards as before; the button then reads “All filled”.
- [ ] Clicking a part's header folds it to one line with its range and chips. **Add part** folds the others and puts the cursor in the new End box.
- [ ] A form autosaved under 0.1.0 (questions in the old global box) reopens with the questions sorted into their parts.
- [ ] Reloading mid-way restores every part's questions.
- [ ] **Create draft** makes the same lesson as 0.1.0: each part's flashcards, iframe and comprehension table with its own questions, in order.
