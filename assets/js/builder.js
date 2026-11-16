/*
 * TBT Lesson Builder — the admin page.
 *
 * Holds the form state, autosaves it to localStorage, mirrors the parsers in
 * includes/class-tbtlb-format.php for the live previews, and calls the REST
 * routes (/fill, /audio, /create). The server re-validates everything.
 * Vanilla JS; wp.media for the image pickers.
 */
(function () {
	'use strict';

	var CFG = window.tbtlbConfig;
	var root = document.getElementById('tbtlb-app');
	if (!CFG || !root) {
		return;
	}

	function $(sel, ctx) { return (ctx || root).querySelector(sel); }
	function $$(sel, ctx) { return Array.prototype.slice.call((ctx || root).querySelectorAll(sel)); }

	/** A DOM element; text only ever goes in through textContent. */
	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'text') { node.textContent = attrs[k]; }
			else if (k === 'className') { node.className = attrs[k]; }
			else if (attrs[k] !== false && attrs[k] != null) { node.setAttribute(k, attrs[k] === true ? '' : attrs[k]); }
		});
		(children || []).forEach(function (c) { if (c) { node.appendChild(c); } });
		return node;
	}

	/* ===================================================================
	 * Parsers — mirror TBTLB_Format. Change both together.
	 * ================================================================= */

	function jsTrim(v) { return String(v == null ? '' : v).trim(); }

	function lines(text) {
		return String(text || '').split(/\r\n|\r|\n/).map(jsTrim).filter(function (l) { return l !== ''; });
	}

	function csvLine(line) {
		var out = [], cur = '', quoted = false;
		for (var i = 0; i < line.length; i++) {
			var ch = line.charAt(i);
			if (quoted) {
				if (ch === '"') {
					if (line.charAt(i + 1) === '"') { cur += '"'; i++; }
					else { quoted = false; }
				} else { cur += ch; }
			} else if (ch === ',') { out.push(cur); cur = ''; }
			else if (ch === '"' && cur === '') { quoted = true; }
			else { cur += ch; }
		}
		out.push(cur);
		return out;
	}

	function timeToSeconds(value) {
		var v = jsTrim(value), m;
		if ((m = v.match(/^(\d{1,3}):([0-5]\d)$/))) { return (+m[1]) * 60 + (+m[2]); }
		if ((m = v.match(/^(\d{1,2}):([0-5]\d):([0-5]\d)$/))) {
			if (m[3] === '00') { return (+m[1]) * 60 + (+m[2]); }
			return (+m[1]) * 3600 + (+m[2]) * 60 + (+m[3]);
		}
		return null;
	}

	/** A part's Start or End box: bare digits as seconds, m.ss, then timeToSeconds(). */
	function partTimeToSeconds(value) {
		var v = jsTrim(value), m;
		if (/^\d{1,4}$/.test(v)) { return +v; }
		if ((m = v.match(/^(\d{1,3})\.([0-5]\d)$/))) { return (+m[1]) * 60 + (+m[2]); }
		return timeToSeconds(v);
	}

	function secondsToTime(s) {
		s = Math.max(0, s | 0);
		return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
	}

	function parseQa(text) {
		var rows = [];
		lines(text).forEach(function (line) {
			var delim, cols;
			if (line.indexOf('\t') !== -1) { delim = '\t'; cols = line.split('\t'); }
			else { delim = ','; cols = csvLine(line); }
			if (cols.length < 2) { return; }
			var t = timeToSeconds(cols[0]);
			if (t === null) { return; }
			var question = jsTrim(cols[1]);
			if (!question) { return; }
			rows.push({ t: t, time: secondsToTime(t), question: question, answer: cols.length >= 3 ? jsTrim(cols.slice(2).join(delim)) : '' });
		});
		return rows.sort(function (a, b) { return a.t - b.t; });
	}

	function resolveParts(parts) {
		var ranges = [], errors = {}, autos = [], prevEnd = null;
		parts.forEach(function (p, i) {
			var startRaw = jsTrim(p.start), endRaw = jsTrim(p.end);
			var auto = i === 0 ? 0 : prevEnd;
			var start = startRaw === '' ? auto : partTimeToSeconds(startRaw);
			var end = endRaw === '' ? null : partTimeToSeconds(endRaw);
			var error = '';
			if (startRaw !== '' && start === null) { error = 'Use minutes:seconds, e.g. 3:08'; }
			else if (start === null) { error = 'Enter the start'; }
			else if (endRaw === '') { error = 'Enter the end'; }
			else if (end === null) { error = 'Use minutes:seconds, e.g. 3:08'; }
			else if (end <= start) { error = 'End must be after the start'; }
			else if (prevEnd !== null && start < prevEnd) { error = 'Overlaps the previous part'; }
			autos.push(auto);
			if (error) { ranges.push(null); errors[i] = error; }
			else { ranges.push({ start: start, end: end }); prevEnd = end; }
		});
		return { ranges: ranges, errors: errors, autos: autos };
	}

	function assignQuestions(ranges, qa) {
		var buckets = ranges.map(function () { return []; }), outside = [];
		qa.forEach(function (row) {
			for (var i = 0; i < ranges.length; i++) {
				var r = ranges[i];
				if (r && row.t >= r.start && row.t <= r.end) { buckets[i].push(row); return; }
			}
			outside.push(row);
		});
		return { buckets: buckets, outside: outside };
	}

	var CARD_HEADER_WORDS = ['word', 'english', 'en', 'front'];

	function parseCards(text) {
		var cards = [], first = true;
		lines(text).forEach(function (line) {
			var tab = line.indexOf('\t') !== -1;
			var delim = tab ? '\t' : ',';
			var raw = tab ? line.split('\t') : csvLine(line);
			if (raw.length > 4) { raw = raw.slice(0, 3).concat([raw.slice(3).join(delim)]); }
			var cols = raw.map(function (cell) {
				cell = jsTrim(cell);
				var m = tab ? cell.match(/^"([\s\S]*)"$/) : null;
				return m ? jsTrim(m[1].replace(/""/g, '"')) : cell;
			});
			if (first) {
				first = false;
				if (CARD_HEADER_WORDS.indexOf(cols[0].toLowerCase()) !== -1) { return; }
			}
			if (!cols[0]) { return; }
			cards.push({ word: cols[0], phonetic: cols[1] || '', translation: cols[2] || '', example: cols[3] || '' });
		});
		return cards;
	}

	function isComplete(c) { return !!(c.phonetic && c.translation && c.example); }
	function incomplete(cards) { return cards.filter(function (c) { return !isComplete(c); }).length; }

	/** tbt_fc_csv_field(): quoted only when it holds a comma, quote or line break. */
	function csvField(v) {
		v = String(v);
		return /[",\r\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
	}
	function cardRow(c) { return [c.word, c.phonetic, c.translation, c.example].map(csvField).join(','); }

	function youtubeId(value) {
		var m = String(value || '').match(/(?:youtu\.be\/|youtube(?:-nocookie)?\.com\/(?:embed\/|shorts\/|live\/|v\/|watch\?(?:[^"'\s<>]*?&(?:amp;)?)?v=))([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])/i);
		return m ? m[1] : null;
	}

	/** TBT Poll's paste format (TBTPL_Format::parse()). */
	function pollCleanLine(line) {
		line = String(line).replace(/ /g, ' ').trim();
		while (line.length >= 4 && line.slice(0, 2) === '**' && line.slice(-2) === '**') { line = line.slice(2, -2).trim(); }
		if (line.slice(0, 2) === '**') { line = line.slice(2).replace(/^\s+/, ''); }
		if (line.length >= 2 && line.slice(-2) === '**') { line = line.slice(0, -2).replace(/\s+$/, ''); }
		return line;
	}
	function parsePoll(text) {
		var qs = [];
		String(text || '').split(/\r\n|\r|\n/).forEach(function (raw) {
			var line = pollCleanLine(raw);
			if (!line) { return; }
			var m = line.match(/^[A-Fa-f][.)]\s*([\s\S]*)$/);
			if (m) {
				if (!qs.length) { qs.push({ q: null, a: [] }); }
				qs[qs.length - 1].a.push(pollCleanLine(m[1]));
				return;
			}
			qs.push({ q: line, a: [] });
		});
		return qs;
	}
	function pollReady(qs) {
		return qs.length >= 1 && qs.length <= 5 && qs.every(function (q) {
			return q.q && q.a.length >= 2 && q.a.length <= 6 && q.a.every(Boolean);
		});
	}

	function mindmapOk(value) {
		try {
			var u = new URL(jsTrim(value));
			var host = u.hostname.toLowerCase();
			return u.protocol === 'https:' && (host === CFG.mindmapHost || host.slice(-(CFG.mindmapHost.length + 1)) === '.' + CFG.mindmapHost);
		} catch (e) {
			return false;
		}
	}

	function audioSource(character, answer) { return character + '\n' + jsTrim(answer); }

	/* ===================================================================
	 * State and autosave
	 * ================================================================= */

	var IMAGE_KEYS = ['featured', 'hero', 'warmup', 'poll'];

	function blank() {
		return {
			title: '', excerpt: '', category: '', level: '', tags: '',
			images: { featured: null, hero: null, warmup: null, poll: null },
			warmup: [0, 1, 2].map(function () { return { question: '', character: CFG.characters[0], answer: '', audio: null }; }),
			youtube: '', parts: [], poll: '', scenario: '', dialogue: '', questions: '', mindmap: ''
		};
	}

	var state = blank();
	var busy = { audio: {}, fill: {}, create: false };
	var audioErr = {};
	var fillErr = {};

	function save() {
		try { window.localStorage.setItem(CFG.storageKey, JSON.stringify(state)); } catch (e) { /* storage off or full */ }
	}
	function forget() {
		try { window.localStorage.removeItem(CFG.storageKey); } catch (e) { /* storage off */ }
	}
	function restore() {
		var saved = null;
		try { saved = JSON.parse(window.localStorage.getItem(CFG.storageKey) || 'null'); } catch (e) { saved = null; }
		if (!saved || typeof saved !== 'object') { return false; }
		var s = blank();
		['title', 'excerpt', 'category', 'level', 'tags', 'youtube', 'poll', 'scenario', 'dialogue', 'questions', 'mindmap'].forEach(function (k) {
			if (typeof saved[k] === 'string') { s[k] = saved[k]; }
		});
		if (saved.images && typeof saved.images === 'object') {
			IMAGE_KEYS.forEach(function (k) {
				var img = saved.images[k];
				if (img && img.id) { s.images[k] = { id: +img.id, name: String(img.name || '') }; }
			});
		}
		if (Array.isArray(saved.warmup)) {
			saved.warmup.slice(0, 3).forEach(function (w, i) {
				if (!w || typeof w !== 'object') { return; }
				s.warmup[i].question = String(w.question || '');
				s.warmup[i].answer = String(w.answer || '');
				if (CFG.characters.indexOf(w.character) !== -1) { s.warmup[i].character = w.character; }
				if (w.audio && w.audio.id) { s.warmup[i].audio = { id: +w.audio.id, url: String(w.audio.url || ''), source: String(w.audio.source || '') }; }
			});
		}
		if (Array.isArray(saved.parts)) {
			s.parts = saved.parts.slice(0, CFG.maxParts).map(function (p) {
				p = p || {};
				return { start: String(p.start || ''), end: String(p.end || ''), cards: String(p.cards || ''), qa: String(p.qa || '') };
			});
		}
		migrateQa(s, saved.qa);
		if (!CFG.categories[s.category]) { s.category = ''; }
		if (CFG.levels.indexOf(s.level) === -1) { s.level = ''; }
		state = s;
		return true;
	}

	/**
	 * 0.1.0 kept one questions paste for the whole video. Sort its lines into
	 * the parts once, by the old rule (assignQuestions: the first part whose
	 * range holds the time); lines that fit no part go to Part 1, where they
	 * show red. Each line moves as it was typed.
	 */
	function migrateQa(s, oldQa) {
		if (typeof oldQa !== 'string' || !jsTrim(oldQa)) { return; }
		if (s.parts.some(function (p) { return jsTrim(p.qa) !== ''; })) { return; }
		if (!s.parts.length) { s.parts.push({ start: '', end: '', cards: '', qa: '' }); }
		var ranges = resolveParts(s.parts).ranges;
		var buckets = s.parts.map(function () { return []; });
		String(oldQa).split(/\r\n|\r|\n/).forEach(function (line) {
			if (!jsTrim(line)) { return; }
			var row = parseQa(line)[0];
			var to = 0;
			if (row) {
				var placed = assignQuestions(ranges, [row]).buckets;
				for (var i = 0; i < placed.length; i++) { if (placed[i].length) { to = i; break; } }
			}
			buckets[to].push(line);
		});
		s.parts.forEach(function (p, i) { p.qa = buckets[i].join('\n'); });
	}

	function changed() { refresh(); save(); }

	/* ===================================================================
	 * REST
	 * ================================================================= */

	function api(path, body) {
		return fetch(CFG.rest + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
			body: JSON.stringify(body)
		}).then(function (res) {
			return res.json().catch(function () { return null; }).then(function (data) {
				if (!res.ok) {
					throw new Error((data && data.message) || ('The server answered ' + res.status + '. Try again.'));
				}
				return data;
			});
		}, function () {
			throw new Error('The server could not be reached. Check your connection and try again.');
		});
	}

	/* ===================================================================
	 * Basics, images
	 * ================================================================= */

	function bindFields() {
		$$('[data-bind]').forEach(function (input) {
			input.addEventListener('input', function () {
				state[input.dataset.bind] = input.value;
				changed();
			});
		});
	}
	function paintFields() {
		$$('[data-bind]').forEach(function (input) { input.value = state[input.dataset.bind] || ''; });
	}

	function bindSeg(id, key) {
		$$('#' + id + ' button').forEach(function (b) {
			b.addEventListener('click', function () { state[key] = b.dataset.v; changed(); });
		});
	}
	function paintSegs() {
		$$('#tbtlb-cat button').forEach(function (b) { b.setAttribute('aria-pressed', String(b.dataset.v === state.category)); });
		$$('#tbtlb-level button').forEach(function (b) { b.setAttribute('aria-pressed', String(b.dataset.v === state.level)); });
		root.dataset.look = state.category === 'pd' ? 'pd' : 'gi';
	}

	var frames = {};
	function bindPickers() {
		$$('.tbtlb-pick').forEach(function (b) {
			b.addEventListener('click', function () {
				var key = b.dataset.img;
				if (!window.wp || !wp.media) { return; }
				if (!frames[key]) {
					frames[key] = wp.media({
						title: b.firstChild.textContent,
						library: { type: 'image' },
						multiple: false,
						button: { text: 'Use this image' }
					});
					frames[key].on('select', function () {
						var a = frames[key].state().get('selection').first().toJSON();
						state.images[key] = { id: a.id, name: a.filename || a.title || ('#' + a.id) };
						paintImages();
						changed();
					});
				}
				frames[key].open();
			});
		});
	}
	function paintImages() {
		$$('.tbtlb-pick').forEach(function (b) {
			var img = state.images[b.dataset.img];
			b.classList.toggle('is-set', !!img);
			$('small', b).textContent = img ? '✓ ' + img.name : 'Choose from Media';
		});
	}

	/* ===================================================================
	 * Warm-up
	 * ================================================================= */

	function renderWarmups() {
		var box = $('#tbtlb-warmups');
		box.textContent = '';
		state.warmup.forEach(function (w, i) {
			var n = i + 1;
			var q = el('input', { type: 'text', className: 'tbtlb-content', 'aria-label': 'Question ' + n, placeholder: 'Question ' + n });
			var sel = el('select', { 'aria-label': 'Who answers question ' + n }, CFG.characters.map(function (c) { return el('option', { value: c, text: c }); }));
			var a = el('textarea', { className: 'tbtlb-content', 'aria-label': 'Answer ' + n, placeholder: 'The character’s answer, which also becomes the transcript' });
			var player = el('audio', { controls: true, preload: 'none', hidden: true });
			var btn = el('button', { type: 'button', className: 'tbtlb-btn tbtlb-small', 'data-audio-btn': '' });
			var msg = el('span', { className: 'tbtlb-audio-msg' });
			var audioBox = el('div', { className: 'tbtlb-audio', 'data-audio': i }, [player, btn, msg]);

			q.value = w.question;
			sel.value = w.character;
			a.value = w.answer;
			q.addEventListener('input', function () { state.warmup[i].question = q.value; changed(); });
			a.addEventListener('input', function () { state.warmup[i].answer = a.value; delete audioErr[i]; changed(); });
			sel.addEventListener('change', function () { state.warmup[i].character = sel.value; delete audioErr[i]; changed(); });
			btn.addEventListener('click', function () { generateAudio(i); });

			box.appendChild(el('div', { className: 'tbtlb-wq' }, [
				el('span', { className: 'tbtlb-num', text: String(n) }),
				el('div', {}, [
					el('div', { className: 'tbtlb-line' }, [q, sel]),
					el('div', { className: 'tbtlb-line2' }, [a, audioBox])
				])
			]));
		});
	}

	function audioStale(w) { return !!w.audio && w.audio.source !== audioSource(w.character, w.answer); }

	function paintWarmups() {
		$$('[data-audio]').forEach(function (box) {
			var i = +box.dataset.audio, w = state.warmup[i];
			var player = $('audio', box), btn = $('[data-audio-btn]', box), msg = $('.tbtlb-audio-msg', box);
			var stale = audioStale(w);
			box.classList.toggle('is-set', !!w.audio && !stale);
			box.classList.toggle('is-stale', stale);
			player.hidden = !w.audio;
			if (w.audio && player.dataset.src !== w.audio.url) {
				player.src = w.audio.url;
				player.dataset.src = w.audio.url;
			}
			btn.disabled = !!busy.audio[i];
			if (busy.audio[i]) {
				btn.textContent = 'Generating in ' + w.character + '’s voice…';
			} else if (w.audio) {
				btn.textContent = stale ? 'Regenerate in ' + w.character + '’s voice' : 'Regenerate';
			} else {
				btn.textContent = 'Generate audio in ' + w.character + '’s voice';
			}
			msg.classList.toggle('is-err', !!audioErr[i] || stale);
			msg.textContent = audioErr[i] || (stale ? 'Out of date' : '');
		});
	}

	function generateAudio(i) {
		var w = state.warmup[i];
		if (busy.audio[i]) { return; }
		if (!jsTrim(w.answer)) { audioErr[i] = 'Write the answer first'; paintWarmups(); return; }
		var character = w.character, answer = w.answer;
		busy.audio[i] = true;
		delete audioErr[i];
		paintWarmups();
		api('audio', { text: answer, character: character, n: i + 1, title: state.title, replace: w.audio ? w.audio.id : 0 })
			.then(function (data) {
				state.warmup[i].audio = { id: data.id, url: data.url, source: audioSource(character, answer) };
				save();
			}, function (err) {
				audioErr[i] = err.message;
			})
			.then(function () {
				busy.audio[i] = false;
				refresh();
			});
	}

	/* ===================================================================
	 * Video parts
	 * ================================================================= */

	var TRASH = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>';
	var CHEV = '<svg width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 7l4 4 4-4"/></svg>';
	var PLAY = '<svg width="14" height="14" viewBox="0 0 14 14" fill="currentColor" aria-hidden="true" focusable="false"><path d="M3 2l9 5-9 5z"/></svg>';
	var STEP_NUM = { time: '1', cards: '2', qa: '3' };

	/** View state only: which part cards are folded. Not autosaved, not sent. */
	var folded = [];

	function rowLabel(key, label, hint, forId) {
		return el('div', { className: 'tbtlb-prow-label' }, [
			el('span', { className: 'tbtlb-step', 'data-step': key, text: STEP_NUM[key] }),
			el('div', {}, [
				el(forId ? 'label' : 'span', { className: 'tbtlb-lbl', for: forId || null, text: label }),
				el('span', { className: 'tbtlb-hint', text: hint })
			])
		]);
	}

	function renderParts() {
		var box = $('#tbtlb-parts');
		box.textContent = '';
		state.parts.forEach(function (p, i) {
			var n = i + 1;
			var start = el('input', { type: 'text', className: 'tbtlb-time', 'data-k': 'start', 'aria-label': 'Part ' + n + ' start', inputmode: 'numeric' });
			var end = el('input', { type: 'text', className: 'tbtlb-time', 'data-k': 'end', 'aria-label': 'Part ' + n + ' end', inputmode: 'numeric', placeholder: 'm:ss' });
			var del = el('button', { type: 'button', className: 'tbtlb-iconbtn', 'aria-label': 'Remove part ' + n, title: 'Remove part' });
			del.innerHTML = TRASH; // static markup
			var chev = el('span', { className: 'tbtlb-chev' });
			chev.innerHTML = CHEV; // static markup
			var watch = el('a', { className: 'tbtlb-watch', 'data-watch': '', target: '_blank', rel: 'noopener' });
			watch.innerHTML = PLAY; // static markup
			watch.appendChild(document.createTextNode('Watch this part'));
			var cards = el('textarea', { id: 'tbtlb-cards-' + i, className: 'tbtlb-mono', 'data-k': 'cards', rows: '6', placeholder: 'word,phonetic,translation,example\nor just the words, one per line' });
			var fill = el('button', { type: 'button', className: 'tbtlb-btn tbtlb-small', 'data-fill': '', text: 'Fill with AI' });
			var qa = el('textarea', { id: 'tbtlb-qa-' + i, className: 'tbtlb-mono', 'data-k': 'qa', rows: '4', placeholder: 'Paste three columns from Excel: time · question · answer\n0:22\tHow does she want to engage the audience?\tShe wants the audience to…' });

			start.value = p.start;
			end.value = p.end;
			cards.value = p.cards;
			qa.value = p.qa;
			[start, end, cards, qa].forEach(function (input) {
				input.addEventListener('input', function () { state.parts[i][input.dataset.k] = input.value; changed(); });
			});
			[start, end].forEach(function (input) {
				input.addEventListener('blur', function () {
					var s = partTimeToSeconds(input.value);
					if (jsTrim(input.value) === '' || s === null) { return; }
					var tidy = secondsToTime(s);
					if (tidy !== input.value) {
						input.value = tidy;
						state.parts[i][input.dataset.k] = tidy;
						changed();
					}
				});
			});
			del.addEventListener('click', function (e) {
				e.stopPropagation();
				state.parts.splice(i, 1);
				folded.splice(i, 1);
				busy.fill = {};
				fillErr = {};
				renderParts();
				changed();
			});
			fill.addEventListener('click', function () { fillCards(i); });

			var card = el('div', { className: 'tbtlb-part' + (folded[i] ? ' is-closed' : ''), 'data-part': i });
			var head = el('div', { className: 'tbtlb-part-head', role: 'button', tabindex: '0', 'aria-expanded': folded[i] ? 'false' : 'true' }, [
				el('span', { className: 'tbtlb-num', text: String(n) }),
				el('span', { className: 'tbtlb-part-title', text: 'Part ' + n }),
				el('span', { className: 'tbtlb-part-range', 'data-range': '' }),
				el('span', { className: 'tbtlb-part-sum', 'data-sum': '' }),
				chev,
				del
			]);
			function toggle() {
				folded[i] = !folded[i];
				card.classList.toggle('is-closed', folded[i]);
				head.setAttribute('aria-expanded', folded[i] ? 'false' : 'true');
			}
			head.addEventListener('click', function (e) { if (!del.contains(e.target)) { toggle(); } });
			head.addEventListener('keydown', function (e) {
				if ((e.key === 'Enter' || e.key === ' ') && e.target === head) { e.preventDefault(); toggle(); }
			});

			card.appendChild(head);
			card.appendChild(el('div', { className: 'tbtlb-part-body' }, [
				el('div', { className: 'tbtlb-prow' }, [
					rowLabel('time', 'Time', 'Where the clip starts and ends'),
					el('div', {}, [
						el('div', { className: 'tbtlb-time-line' }, [
							el('label', { className: 'tbtlb-tfield' }, [el('span', { text: 'Start' }), start]),
							el('span', { className: 'tbtlb-arrow', 'aria-hidden': 'true', text: '→' }),
							el('label', { className: 'tbtlb-tfield' }, [el('span', { text: 'End' }), end]),
							el('div', { className: 'tbtlb-time-meta' }, [el('span', { className: 'tbtlb-chip', 'data-dur': '' }), watch])
						]),
						el('div', { className: 'tbtlb-below' }, [
							el('span', { className: 'tbtlb-hint', 'data-time-hint': '' }),
							el('span', { className: 'tbtlb-err-text', 'data-time-err': '' })
						])
					])
				]),
				el('div', { className: 'tbtlb-prow' }, [
					rowLabel('cards', 'Flashcards', 'Words from this part', 'tbtlb-cards-' + i),
					el('div', {}, [
						cards,
						el('div', { className: 'tbtlb-below' }, [
							el('span', { className: 'tbtlb-chipset', 'data-chips': 'cards' }),
							fill,
							el('span', { className: 'tbtlb-fill-err', 'data-fill-err': '' })
						])
					])
				]),
				el('div', { className: 'tbtlb-prow' }, [
					rowLabel('qa', 'Questions', 'Excel: time, question, answer', 'tbtlb-qa-' + i),
					el('div', {}, [
						qa,
						el('div', { className: 'tbtlb-below' }, [
							el('span', { className: 'tbtlb-chipset', 'data-chips': 'q' }),
							el('span', { className: 'tbtlb-err-text', 'data-qa-err': '' })
						]),
						el('div', { 'data-qtable': '' })
					])
				])
			]));
			box.appendChild(card);
		});
		$('#tbtlb-add-part').disabled = state.parts.length >= CFG.maxParts;
		$('#tbtlb-part-count').textContent = state.parts.length ? state.parts.length + ' of ' + CFG.maxParts + ' parts' : 'Lessons have 1–' + CFG.maxParts + ' parts';
	}

	function chip(text, kind) { return el('span', { className: 'tbtlb-chip' + (kind ? ' is-' + kind : ''), text: text }); }
	function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }

	function fillCards(i) {
		var part = state.parts[i];
		var cards = parseCards(part.cards);
		var todo = cards.filter(function (c) { return !isComplete(c); });
		if (!todo.length || busy.fill[i]) { return; }
		var words = todo.map(function (c) { return c.word; });
		var batches = [];
		for (var k = 0; k < words.length; k += 40) { batches.push(words.slice(k, k + 40)); }

		busy.fill[i] = true;
		delete fillErr[i];
		refresh();

		var filled = [];
		batches.reduce(function (chain, batch) {
			return chain.then(function () {
				return api('fill', { items: batch, context: state.title }).then(function (data) {
					filled = filled.concat(Array.isArray(data.cards) ? data.cards : []);
				});
			});
		}, Promise.resolve()).then(function () {
			var j = 0;
			var merged = cards.map(function (c) {
				if (isComplete(c)) { return c; }
				var f = filled[j++] || {};
				return {
					word: c.word,
					phonetic: c.phonetic || jsTrim(f.phonetic),
					translation: c.translation || jsTrim(f.translation),
					example: c.example || jsTrim(f.example)
				};
			});
			if (state.parts[i] === part) {
				part.cards = merged.map(cardRow).join('\n');
				var box = $('[data-part="' + i + '"] [data-k="cards"]');
				if (box) { box.value = part.cards; }
			}
		}, function (err) {
			fillErr[i] = err.message;
		}).then(function () {
			busy.fill[i] = false;
			changed();
		});
	}

	/** "55 s", "1 min 35 s", "2 min". */
	function duration(s) {
		var m = Math.floor(s / 60), r = s % 60;
		return m ? m + ' min' + (r ? ' ' + r + ' s' : '') : r + ' s';
	}

	/**
	 * A part's questions: the rows parseQa() keeps, and how many pasted lines
	 * it skipped. A first line whose first cell has no digit is a header and
	 * is skipped silently.
	 */
	function partQuestions(text) {
		var skipped = 0;
		lines(text).forEach(function (line, k) {
			if (parseQa(line).length) { return; }
			var first = line.indexOf('\t') !== -1 ? line.split('\t')[0] : csvLine(line)[0];
			if (k === 0 && !/\d/.test(first)) { return; }
			skipped++;
		});
		return { rows: parseQa(text), skipped: skipped };
	}

	function setStep(box, key, status) {
		var s = $('[data-step="' + key + '"]', box);
		s.classList.toggle('is-done', status === 'done');
		s.classList.toggle('is-bad', status === 'bad');
		s.textContent = status === 'done' ? '✓' : STEP_NUM[key];
	}

	function refreshVideo() {
		var raw = jsTrim(state.youtube), id = youtubeId(raw);
		var info = $('#tbtlb-yt-info');
		info.textContent = raw ? (id ? 'Video ID ' + id + (/<iframe/i.test(raw) ? ', taken from the embed code' : '') : 'This doesn’t look like a YouTube link or embed code') : '';
		info.classList.toggle('tbtlb-err-text', !!raw && !id);

		var resolved = resolveParts(state.parts);
		var problems = [];
		var rangesOk = true, cardsOk = true, qaOk = true;

		$$('[data-part]').forEach(function (box) {
			var i = +box.dataset.part, n = i + 1, p = state.parts[i];
			var r = resolved.ranges[i], err = resolved.errors[i] || '';
			var auto = resolved.autos[i];
			var prev = -1;
			for (var j = i - 1; j >= 0; j--) { if (resolved.ranges[j]) { prev = j; break; } }

			/* Time */
			var startIn = $('[data-k="start"]', box), endIn = $('[data-k="end"]', box);
			var startRaw = jsTrim(p.start), endRaw = jsTrim(p.end);
			var startBad = err === 'Enter the start' || err === 'Overlaps the previous part' || (startRaw !== '' && partTimeToSeconds(startRaw) === null);
			var waiting = err === 'Enter the end'; // an empty End is not a problem yet
			var shown = err;
			if (err === 'Overlaps the previous part' && prev !== -1) {
				shown = 'Starts before part ' + (prev + 1) + ' ends (' + secondsToTime(resolved.ranges[prev].end) + ')';
			}
			if (waiting) { shown = ''; }
			startIn.placeholder = auto !== null && auto !== undefined ? secondsToTime(auto) : 'm:ss';
			startIn.classList.toggle('is-invalid', !!shown && startBad);
			endIn.classList.toggle('is-invalid', !!shown && !startBad);
			$('[data-time-err]', box).textContent = shown;
			$('[data-time-hint]', box).textContent = !err && startRaw === '' && i > 0 && prev !== -1 ? 'Starts where part ' + (prev + 1) + ' ends. Type a start to skip a stretch.' : '';
			var dur = $('[data-dur]', box);
			dur.textContent = r ? duration(r.end - r.start) : '';
			dur.hidden = !r;
			var watch = $('[data-watch]', box);
			if (r && id) {
				watch.href = 'https://www.youtube.com/embed/' + id + '?start=' + r.start + '&end=' + r.end + '&autoplay=1';
				watch.removeAttribute('aria-disabled');
			} else {
				watch.removeAttribute('href');
				watch.setAttribute('aria-disabled', 'true');
			}
			$('[data-range]', box).textContent = r ? secondsToTime(r.start) + ' – ' + secondsToTime(r.end) : '';
			setStep(box, 'time', r ? 'done' : (shown ? 'bad' : ''));

			/* Flashcards */
			var cards = parseCards(p.cards);
			var todo = incomplete(cards);
			var cc = $('[data-chips="cards"]', box);
			cc.textContent = '';
			cc.appendChild(chip(cards.length ? plural(cards.length, 'word', 'words') : 'No words yet', cards.length ? 'ok' : ''));
			var fill = $('[data-fill]', box);
			fill.disabled = !todo || !!busy.fill[i];
			fill.textContent = busy.fill[i] ? 'Filling…' : (todo ? 'Fill ' + todo + ' with AI' : (cards.length ? 'All filled' : 'Fill with AI'));
			$('[data-k="cards"]', box).disabled = !!busy.fill[i];
			$('[data-fill-err]', box).textContent = fillErr[i] || '';
			setStep(box, 'cards', cards.length && !todo ? 'done' : '');

			/* Questions */
			var qs = partQuestions(p.qa);
			var rows = qs.rows;
			var outside = r ? rows.filter(function (q) { return q.t < r.start || q.t > r.end; }) : [];
			var qc = $('[data-chips="q"]', box);
			qc.textContent = '';
			qc.appendChild(chip(rows.length ? plural(rows.length, 'question', 'questions') : 'No questions yet', outside.length ? 'err' : (rows.length ? 'ok' : '')));
			var noHint = rows.filter(function (q) { return !q.answer; }).length;
			if (noHint) { qc.appendChild(chip(noHint + ' without a hint', 'warn')); }
			var msgs = [];
			if (outside.length) {
				msgs.push(outside.length + ' outside ' + secondsToTime(r.start) + '–' + secondsToTime(r.end) + ' — move ' + (outside.length === 1 ? 'it' : 'them') + ' to the right part or fix the time');
			}
			if (qs.skipped) { msgs.push(plural(qs.skipped, 'line', 'lines') + ' skipped: no time or no question'); }
			$('[data-qa-err]', box).textContent = msgs.join(' · ');
			var tb = $('[data-qtable]', box);
			tb.textContent = '';
			if (rows.length) {
				tb.appendChild(el('table', { className: 'tbtlb-qtable' }, [
					el('colgroup', {}, [el('col', { className: 'tbtlb-qt-t' }), el('col'), el('col', { className: 'tbtlb-qt-a' })]),
					el('thead', {}, [el('tr', {}, [el('th', { text: 'Time' }), el('th', { text: 'Question' }), el('th', { text: 'Answer' })])]),
					el('tbody', {}, rows.map(function (q) {
						return el('tr', { className: outside.indexOf(q) !== -1 ? 'is-out' : '' }, [
							el('td', { className: 'tbtlb-qt-t', text: q.time }),
							el('td', { className: 'tbtlb-qt-q', text: q.question }),
							el('td', { className: 'tbtlb-qt-a', text: q.answer || '—', title: q.answer || null })
						]);
					}))
				]));
			}
			setStep(box, 'qa', rows.length ? (outside.length ? 'bad' : 'done') : '');

			/* Folded summary */
			var sum = $('[data-sum]', box);
			sum.textContent = '';
			sum.appendChild(chip(plural(cards.length, 'card', 'cards'), cards.length && !todo ? 'ok' : ''));
			sum.appendChild(chip(plural(rows.length, 'question', 'questions'), outside.length ? 'err' : (rows.length ? 'ok' : '')));

			/* Readiness, in the order of the rows */
			if (!r) { rangesOk = false; problems.push('Part ' + n + ': fix the time'); }
			if (!cards.length) { cardsOk = false; problems.push('Part ' + n + ': add the flashcards'); }
			else if (todo) { cardsOk = false; problems.push('Part ' + n + ': ' + todo + (todo === 1 ? ' card still needs AI' : ' cards still need AI')); }
			if (!rows.length) { qaOk = false; problems.push('Part ' + n + ': paste the questions'); }
			else if (outside.length) { qaOk = false; problems.push('Part ' + n + ': ' + plural(outside.length, 'question', 'questions') + ' outside its time'); }
		});

		var hasParts = state.parts.length > 0;
		if (!hasParts) { problems.unshift('Video parts: add at least one part'); }
		if (!id) { problems.unshift('Video parts: paste the YouTube link or embed code'); }
		var checks = [!!id, hasParts, hasParts && rangesOk, hasParts && cardsOk, hasParts && qaOk];
		return { checks: checks, problems: problems };
	}

	/* ===================================================================
	 * Poll, discussion, glossary
	 * ================================================================= */

	function refreshPoll() {
		var qs = parsePoll(state.poll);
		var box = $('#tbtlb-poll-preview');
		box.textContent = '';
		qs.forEach(function (q, i) {
			var ol = el('ol', {}, q.a.map(function (a, j) {
				return el('li', {}, [el('b', { text: 'ABCDEF'.charAt(j) || '?' }), el('span', { text: a || '(answer missing)' })]);
			}));
			var body = el('div', {}, [el('h4', { text: q.q || '(question missing)' }), ol]);
			if (q.a.length !== 4) {
				var bad = q.a.length < 2 || q.a.length > 6;
				body.appendChild(el('div', { className: 'tbtlb-chips' }, [chip(plural(q.a.length, 'answer', 'answers') + ', needs 4', bad ? 'err' : 'warn')]));
			}
			box.appendChild(el('div', { className: 'tbtlb-pollq' }, [el('span', { className: 'tbtlb-num', text: String(i + 1) }), body]));
		});
		var ok = pollReady(qs);
		var count = $('#tbtlb-poll-count');
		count.textContent = qs.length ? (ok ? plural(qs.length, 'question', 'questions') + ', ready' : (qs.length > 5 ? 'At most 5 questions' : plural(qs.length, 'question', 'questions') + ', not ready yet')) : 'Questions, each followed by answers A–D';
		return { ok: ok, any: qs.length > 0, count: qs.length };
	}

	function refreshGlossary() {
		var all = [], seen = {};
		state.parts.forEach(function (p) {
			parseCards(p.cards).forEach(function (c) {
				var k = c.word.trim().toLowerCase();
				if (seen[k]) { return; }
				seen[k] = true;
				all.push(c);
			});
		});
		var box = $('#tbtlb-glossary');
		box.textContent = '';
		if (!all.length) {
			box.appendChild(el('div', { className: 'tbtlb-empty-box', text: 'The glossary appears here once a part has flashcards.' }));
			return 0;
		}
		var tbody = el('tbody', {}, all.map(function (c) {
			return el('tr', {}, [
				el('td', { text: c.word }),
				c.translation ? el('td', { text: c.translation }) : el('td', { className: 'is-missing', text: 'filled by AI' })
			]);
		}));
		box.appendChild(el('div', { className: 'tbtlb-gloss-wrap' }, [
			el('table', { className: 'tbtlb-gloss' }, [
				el('thead', {}, [el('tr', {}, [el('th', { text: 'Expression' }), el('th', { text: 'Polish translation' })])]),
				tbody
			])
		]));
		box.appendChild(el('p', { className: 'tbtlb-hint', text: all.length + ' expressions, in the order they appear in the lesson.' }));
		return all.length;
	}

	/* ===================================================================
	 * Status
	 * ================================================================= */

	var STAGES = [['basics', 'Basics'], ['images', 'Images'], ['warmup', 'Warm-up'], ['video', 'Video parts'], ['poll', 'Poll'], ['discussion', 'Discussion']];
	var ready = false;

	function refresh() {
		var excerptLen = state.excerpt.length;
		$('#tbtlb-excerpt-count').textContent = excerptLen + ' / 160';
		$('#tbtlb-excerpt-count').classList.toggle('tbtlb-err-text', excerptLen > 160);

		var titleBad = /[\[\]"]/.test(state.title);
		$('#tbtlb-title-err').textContent = titleBad ? 'The title cannot contain square brackets [ ] or double quotes ".' : '';
		$('#tbtlb-title').classList.toggle('is-invalid', titleBad);

		paintSegs();
		paintWarmups();
		var video = refreshVideo();
		var poll = refreshPoll();
		var glossary = refreshGlossary();

		var dl = lines(state.dialogue).length, dq = lines(state.questions).length;
		$('#tbtlb-dlg-count').textContent = dl ? plural(dl, 'turn', 'turns') : 'One line per turn: Name: text';
		$('#tbtlb-dq-count').textContent = dq ? plural(dq, 'question', 'questions') : 'One per line';
		var mmRaw = jsTrim(state.mindmap), mmOk = mindmapOk(mmRaw);
		$('#tbtlb-mindmap-err').textContent = mmRaw && !mmOk ? 'Use a mindomo.com link.' : '';
		$('#tbtlb-mindmap').classList.toggle('is-invalid', !!mmRaw && !mmOk);

		var checks = {
			basics: [!!jsTrim(state.title) && !titleBad, !!jsTrim(state.excerpt), !!state.category, !!state.level],
			images: IMAGE_KEYS.map(function (k) { return !!state.images[k]; }),
			warmup: state.warmup.map(function (w) { return !!(jsTrim(w.question) && jsTrim(w.answer) && w.audio && !audioStale(w)); }),
			video: video.checks,
			discussion: [lines(state.scenario).length > 0, dl > 0, dq > 0, mmOk]
		};
		function st(a) { return a.every(Boolean) ? 'done' : (a.some(Boolean) ? 'part' : ''); }
		var S = {
			basics: st(checks.basics), images: st(checks.images), warmup: st(checks.warmup), video: st(checks.video),
			poll: poll.ok ? 'done' : (poll.any ? 'part' : ''), discussion: st(checks.discussion), glossary: glossary ? 'done' : ''
		};
		Object.keys(S).forEach(function (k) {
			var dot = $('.tbtlb-dot[data-for="' + k + '"]');
			dot.classList.toggle('is-done', S[k] === 'done');
			dot.classList.toggle('is-part', S[k] === 'part');
		});

		var ul = $('#tbtlb-checklist');
		ul.textContent = '';
		STAGES.forEach(function (s) {
			var done = S[s[0]] === 'done';
			if (!done && s[0] === 'video' && video.problems.length) {
				video.problems.forEach(function (t) { ul.appendChild(el('li', { text: t })); });
				return;
			}
			ul.appendChild(el('li', { className: done ? 'is-ok' : '', text: done ? s[1] + ' ready' : s[1] + ' — still to fill in' }));
		});
		var anyBusy = busy.create || Object.keys(busy.audio).some(function (k) { return busy.audio[k]; }) || Object.keys(busy.fill).some(function (k) { return busy.fill[k]; });
		ready = STAGES.every(function (s) { return S[s[0]] === 'done'; });
		$('#tbtlb-create').disabled = !ready || anyBusy;
		$('#tbtlb-create').textContent = busy.create ? 'Creating…' : 'Create draft';

		var doneN = Object.keys(S).filter(function (k) { return S[k] === 'done'; }).length;
		var sum = $('#tbtlb-navsum');
		sum.textContent = '';
		sum.appendChild(el('strong', { text: doneN + ' of 7' }));
		sum.appendChild(document.createTextNode(' stages ready'));
	}

	/* ===================================================================
	 * Create draft
	 * ================================================================= */

	function payload() {
		var images = {};
		IMAGE_KEYS.forEach(function (k) { images[k] = state.images[k] ? state.images[k].id : 0; });
		return {
			title: state.title, excerpt: state.excerpt, category: state.category, level: state.level, tags: state.tags,
			images: images,
			warmup: state.warmup.map(function (w) { return { question: w.question, character: w.character, answer: w.answer, audio: w.audio ? w.audio.id : 0 }; }),
			youtube: state.youtube,
			parts: state.parts.map(function (p) { return { start: p.start, end: p.end, cards: p.cards, qa: p.qa }; }),
			poll: state.poll, scenario: state.scenario, dialogue: state.dialogue, questions: state.questions, mindmap: state.mindmap
		};
	}

	function showResult(ok, data, message) {
		var dlg = $('#tbtlb-done');
		$('#tbtlb-done-head').textContent = ok ? 'Draft created' : 'Draft not created';
		$('#tbtlb-done-title').textContent = state.title;
		var list = $('#tbtlb-done-list');
		list.textContent = '';
		if (ok && Array.isArray(data.made)) {
			data.made.forEach(function (t) { list.appendChild(el('li', { text: t })); });
		}
		var err = $('#tbtlb-done-error');
		err.hidden = ok;
		err.textContent = ok ? '' : message + ' The form is as you left it.';
		var divi = $('#tbtlb-open-divi'), edit = $('#tbtlb-edit-post');
		divi.hidden = edit.hidden = !ok;
		if (ok) {
			divi.href = data.builder_url;
			edit.href = data.edit_url;
		}
		if (typeof dlg.showModal === 'function') { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
	}

	function create() {
		if (!ready || busy.create) { return; }
		busy.create = true;
		refresh();
		api('create', payload()).then(function (data) {
			busy.create = false;
			forget(); // the draft exists; nothing to restore
			$('#tbtlb-restored').hidden = true;
			refresh();
			showResult(true, data);
		}, function (err) {
			busy.create = false;
			refresh();
			showResult(false, null, err.message);
		});
	}

	/* ===================================================================
	 * Wiring
	 * ================================================================= */

	function renderAll() {
		paintFields();
		paintImages();
		renderWarmups();
		renderParts();
		refresh();
	}

	function startOver() {
		if (!window.confirm('Start over? This clears the whole form.')) { return; }
		state = blank();
		folded = [];
		audioErr = {};
		fillErr = {};
		forget();
		$('#tbtlb-restored').hidden = true;
		renderAll();
	}

	bindFields();
	bindSeg('tbtlb-cat', 'category');
	bindSeg('tbtlb-level', 'level');
	bindPickers();

	$('#tbtlb-add-part').addEventListener('click', function () {
		if (state.parts.length >= CFG.maxParts) { return; }
		folded = state.parts.map(function () { return true; });
		state.parts.push({ start: '', end: '', cards: '', qa: '' });
		folded.push(false);
		renderParts();
		changed();
		var last = $$('[data-part]').pop();
		if (last) {
			last.scrollIntoView({ behavior: 'smooth', block: 'start' });
			$('[data-k="end"]', last).focus({ preventScroll: true });
		}
	});
	$('#tbtlb-create').addEventListener('click', create);
	$('#tbtlb-clear').addEventListener('click', startOver);
	$('#tbtlb-restart').addEventListener('click', startOver);
	$('#tbtlb-close-done').addEventListener('click', function () {
		var dlg = $('#tbtlb-done');
		if (typeof dlg.close === 'function') { dlg.close(); } else { dlg.removeAttribute('open'); }
	});

	if (restore()) { $('#tbtlb-restored').hidden = false; }
	renderAll();
})();
