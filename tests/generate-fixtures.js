/*
 * Regenerates tests/fixtures/comprehension-*.html by running the ORIGINAL
 * buildHtml() from TBT Comprehension's assets/admin.js on the questions of
 * the two live lessons (tests/fixtures/comprehension-input.json, cut from
 * docs/reference/sample-lesson.json and the PD lesson export) and on an
 * "edge" set of hand-written rows (italics, entities, no hints, equal times).
 *
 *   node tests/generate-fixtures.js /path/to/tbt-comprehension/assets/admin.js
 *
 * The PHP port must reproduce these files byte for byte (tests/test-format.php).
 */
'use strict';
const fs = require('fs');
const path = require('path');

const adminJs = process.argv[2];
if (!adminJs) {
	console.error('Usage: node tests/generate-fixtures.js /path/to/admin.js');
	process.exit(1);
}

// Everything from TOOLTIP_STYLE up to setStatus(): the pure functions only.
const src = fs.readFileSync(adminJs, 'utf8');
const from = src.indexOf('var TOOLTIP_STYLE');
const to = src.indexOf('function setStatus');
if (from < 0 || to < 0) {
	console.error('admin.js does not look like TBT Comprehension\'s.');
	process.exit(1);
}
const lib = new Function(src.slice(from, to) + '\nreturn { buildHtml: buildHtml, normalizeTime: normalizeTime };')();

const dir = path.join(__dirname, 'fixtures');
const input = JSON.parse(fs.readFileSync(path.join(dir, 'comprehension-input.json'), 'utf8'));
const runs = [['gi', '#663366'], ['gi', '#006600'], ['pd', '#006600'], ['edge', '#663366']];
let n = 0;
runs.forEach(([lesson, color]) => {
	input[lesson].forEach((rows, i) => {
		// generate() in admin.js: rows from the Q&A box, answers keyed by time.
		const map = {};
		rows.forEach(r => { const k = lib.normalizeTime(r.time); if (k && r.answer) map[k] = r.answer; });
		const html = lib.buildHtml(rows.map(r => ({ time: lib.normalizeTime(r.time), question: r.question })), map, color).html;
		fs.writeFileSync(path.join(dir, `comprehension-${lesson}-${i + 1}-${color.slice(1)}.html`), html);
		n++;
	});
});
console.log(`Wrote ${n} fixtures.`);
