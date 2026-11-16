# TBT Lesson Builder 0.2.1 — Choose a CSV file for a part's flashcards

Mariusz makes each part's flashcards as a CSV in an outside AI tool (`word,phonetic,translation,example`). In 0.2.0 he opens the file, copies everything and pastes it into the part's Flashcards box. 0.2.1 adds a **Choose CSV file** button that does the same in one step, with no copy-and-paste mistakes and no OpenAI key needed.

Everything else stays exactly as in 0.2.0 (`docs/SPEC-0.2.0.md`).

## Decisions (locked)

| Topic | Decision |
|---|---|
| Where | In each part's Flashcards row, under the textarea: the words chip, then **Choose CSV file**, then **Fill N with AI**, then the message. |
| What it does | Opens the file picker (`.csv`, `.tsv`, `.txt`). The chosen file is read **in the browser** and its text goes into that part's Flashcards box, exactly as if pasted. Nothing is uploaded to WordPress, nothing goes to the media library, no new REST route. |
| Replace | If the box already has text, ask first: “Replace the flashcards in part N with words.csv?” Cancel leaves the box as it was. |
| Text | Read as UTF-8 (phonetics keep their IPA). A leading byte-order mark is dropped, line endings become `\n`, trailing blank lines are trimmed. Nothing else is changed: the same parser as a paste reads it, so a header row starting with `word` is still skipped. |
| Messages | After loading: “Loaded words.csv: 24 cards.” in the hint colour. Errors in red, in the same place as the Fill error: “words.csv is empty.”, “No flashcards found in words.csv.”, “words.csv is larger than 1 MB.”, “words.csv could not be read.” A new file or typing in the box clears the message. |
| While filling | The button is disabled while **Fill with AI** is working on that part (the box is locked then too). |
| Data | No change. The loaded text is the part's `cards` string, autosaved and sent as today. The file name is not stored. |

## Not in 0.2.1

- Separators other than comma and tab (for example semicolons from a Polish-locale Excel “CSV”). Such a file loads, but every line reads as one word, so the chip shows the cards as still needing AI.
- Loading questions from a file.

## Files

```
tbt-lesson-builder.php     version 0.2.1
assets/js/builder.js       the button, file reading, messages
assets/css/builder.css     nothing new expected (existing button and hint classes)
docs/SPEC-0.2.1.md         this file
CLAUDE.md                  “current release” line → SPEC-0.2.1.md
```

## Validation

```bash
for f in tbt-lesson-builder.php includes/*.php; do php -l "$f"; done
node --check assets/js/builder.js
php tests/test-format.php
```

## Acceptance checklist (live site)

- [ ] Each part's Flashcards row has **Choose CSV file**.
- [ ] Choosing an AI-made CSV fills the box; the chip shows the word count and the Fill button reads “All filled”.
- [ ] Choosing a file when the box has text asks first; Cancel keeps the old text.
- [ ] Phonetic symbols come through unchanged.
- [ ] Reloading the page keeps the loaded cards.
- [ ] **Create draft** makes the flashcard sets from the loaded cards.
