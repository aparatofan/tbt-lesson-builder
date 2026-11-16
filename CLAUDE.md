# TBT Lesson Builder — Claude Code guidance

Keep this file concise. It is loaded at the start of every Claude Code session.

## Start here

- Work from the task, not from a full-repository scan.
- The current release is specified in `docs/SPEC-0.2.1.md`. Follow it in order. Phase 0 is read-only and ends with a report; do not edit anything before Mariusz answers it.
- `docs/prototypes/builder.html` is the approved design. Where the spec and the prototype disagree, the spec wins; where the spec is silent, the prototype decides.
- `docs/reference/sample-lesson.json` is the Divi export of a finished lesson. Every piece of Divi markup the builder writes comes from it. It is large: parse it with a script, never paste it into the conversation.
- Sibling code is the reference for patterns: `aparatofan/tbt-poll` (bootstrap, Hub item, tokens, deploy), `aparatofan/flashcards` (sets, AI fill, ElevenLabs), `aparatofan/tbt_comprehension` (`assets/admin.js`, the comprehension table this plugin ports to PHP).
- Do not change unrelated code, formatting, wording, or styling.

## Project basics

- WordPress 6.x plugin; PHP 8.0+. Pure PHP + vanilla JavaScript (plus `wp.media` on the admin page). No jQuery of our own, build system, CDN, Composer, or npm dependency.
- Bootstrap: `tbt-lesson-builder.php`. Server-side: `includes/`. Divi fragments: `templates/`. Assets: `assets/`. Standalone tests: `tests/`.
- Prefixes: `TBTLB_` (classes, constants), `tbtlb_` (functions, options, meta `_tbtlb_`), `tbtlb-` (CSS), REST namespace `tbt-lesson-builder/v1`. Never use unprefixed `.tbt-*` class names; TBT Hub claims them.
- `assets/css/tbt-tokens.css` is a vendored copy of Hub's tokens. Never edit a value in it. No stylesheet here defines a colour of its own.

## Architecture to preserve

- **One-shot.** The builder creates a draft and never touches it again.
- **Every ID lives in `class-tbtlb-config.php`**: global library items, category icons, level icons, colours, voices. Nowhere else.
- **Global items come from the Divi library at create time**, never copied from the sample, so the rows stay global.
- **Nothing half-made.** Create draft validates everything and loads every library item before creating anything, and removes what it created if a later step fails.
- **The builder owns no content data.** Flashcards owns sets, AI fill and ElevenLabs (the key never leaves it); TBT Poll owns polls. Call their public functions, checked with `function_exists()`.
- `TBTLB_Format` is pure PHP and unit-tested. Its comprehension output must stay byte-identical to TBT Comprehension's `buildHtml()`; the fixtures prove it.
- Desktop and tablet only. Light only (wp-admin).

## Security rules

- Administrators only (`manage_options`) for the page and every route; nonce `wp_rest`; no-cache headers.
- Teacher text is escaped for its context and then has `[` `]` encoded before it goes into Divi content.
- Escape all output. Never expose credentials or real configuration values.

## Validation

```bash
for f in tbt-lesson-builder.php includes/*.php; do php -l "$f"; done
node --check assets/js/builder.js
php tests/test-format.php
```

State clearly what still needs a browser or live-site check.

## Git and deployment

- **Never merge a PR and never push to `main`.** A merge to `main` deploys to the live site; that is Mariusz's decision. Stop at an open PR and report.
- Use a focused feature branch. Keep commits task-focused.
- Every release bumps `TBTLB_VERSION` and the plugin header version; the version is the cache buster.
- **Never rename `tbt-lesson-builder.php` or the `/tbt-lesson-builder/` server folder.** The FTP deploy uploads but never deletes.
- Markdown files, `.github/`, `docs/` and `tests/` are excluded from the upload.

## Context discipline

- Prefer targeted search and narrow reads over broad exploration.
- Summarise completion as: what changed, what was checked, and what still needs live-site verification.
