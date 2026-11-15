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
			var start = startRaw === '' ? auto : timeToSeconds(startRaw);
			var end = endRaw === '' ? null : timeToSeconds(endRaw);
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

	function embedIframe(id, start, end) {
		return '<iframe width="1080" height="615" src="https://www.youtube.com/embed/' + id + '?start=' + start + '&end=' + end + '" title="YouTube video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>';
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
			youtube: '', qa: '', parts: [], poll: '', scenario: '', dialogue: '', questions: '', mindmap: ''
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
		['title', 'excerpt', 'category', 'level', 'tags', 'youtube', 'qa', 'poll', 'scenario', 'dialogue', 'questions', 'mindmap'].forEach(function (k) {
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
				return { start: String(p.start || ''), end: String(p.end || ''), cards: String(p.cards || '') };
			});
		}
		if (!CFG.categories[s.category]) { s.category = ''; }
		if (CFG.levels.indexOf(s.level) === -1) { s.level = ''; }
		state = s;
		return true;
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

	function renderParts() {
		var box = $('#tbtlb-parts');
		box.textContent = '';
		state.parts.forEach(function (p, i) {
			var n = i + 1;
			var start = el('input', { type: 'text', className: 'tbtlb-time', 'data-k': 'start', 'aria-label': 'Part ' + n + ' start', inputmode: 'numeric' });
			var end = el('input', { type: 'text', className: 'tbtlb-time', 'data-k': 'end', 'aria-label': 'Part ' + n + ' end', placeholder: 'end' });
			var del = el('button', { type: 'button', className: 'tbtlb-iconbtn', 'aria-label': 'Remove part ' + n, title: 'Remove part' });
			del.innerHTML = TRASH; // static markup
			var cards = el('textarea', { id: 'tbtlb-cards-' + i, className: 'tbtlb-mono', 'data-k': 'cards', rows: '5', placeholder: 'word,phonetic,translation,example\nor just the words, one per line' });
			var fill = el('button', { type: 'button', className: 'tbtlb-btn tbtlb-small', 'data-fill': '', text: 'Fill with AI' });
			var fillMsg = el('span', { className: 'tbtlb-fill-err', 'data-fill-err': '' });

			start.value = p.start;
			end.value = p.end;
			cards.value = p.cards;
			[start, end, cards].forEach(function (input) {
				input.addEventListener('input', function () { state.parts[i][input.dataset.k] = input.value; changed(); });
			});
			del.addEventListener('click', function () {
				state.parts.splice(i, 1);
				busy.fill = {};
				fillErr = {};
				renderParts();
				changed();
			});
			fill.addEventListener('click', function () { fillCards(i); });

			box.appendChild(el('div', { className: 'tbtlb-part', 'data-part': i }, [
				el('div', { className: 'tbtlb-part-head' }, [
					el('span', { className: 'tbtlb-num', text: String(n) }),
					start,
					el('span', { className: 'tbtlb-dash', text: '–' }),
					end,
					el('span'),
					del
				]),
				el('div', { className: 'tbtlb-part-body' }, [
					el('div', {}, [
						el('label', { className: 'tbtlb-lbl', for: 'tbtlb-cards-' + i, text: 'Flashcards' }),
						cards,
						el('div', { className: 'tbtlb-chips', 'data-chips': 'cards' }),
						el('div', { className: 'tbtlb-fill-row' }, [fill, fillMsg])
					]),
					el('div', {}, [
						el('span', { className: 'tbtlb-lbl', text: 'Comprehension questions' }),
						el('ul', { className: 'tbtlb-qlist', 'data-q': '' }),
						el('div', { className: 'tbtlb-chips', 'data-chips': 'q' })
					]),
					el('details', { className: 'tbtlb-embed' }, [
						el('summary', { text: 'Embed code the builder will write' }),
						el('pre', { 'data-embed': '' })
					])
				])
			]));
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

	function refreshVideo() {
		var raw = jsTrim(state.youtube), id = youtubeId(raw);
		var info = $('#tbtlb-yt-info');
		info.textContent = raw ? (id ? 'Video ID ' + id + (/<iframe/i.test(raw) ? ', taken from the embed code' : '') : 'This doesn’t look like a YouTube link or embed code') : '';
		info.classList.toggle('tbtlb-err-text', !!raw && !id);

		var resolved = resolveParts(state.parts);
		var qa = parseQa(state.qa);
		var assigned = assignQuestions(resolved.ranges, qa);
		var good = resolved.ranges.filter(Boolean);
		var startAll = good.length ? Math.min.apply(null, good.map(function (r) { return r.start; })) : 0;
		var endAll = good.length ? Math.max.apply(null, good.map(function (r) { return r.end; })) : null;
		var cardsOk = true;

		$$('[data-part]').forEach(function (box) {
			var i = +box.dataset.part, r = resolved.ranges[i], err = resolved.errors[i];
			var auto = resolved.autos[i];
			$('[data-k="start"]', box).placeholder = auto !== null && auto !== undefined ? secondsToTime(auto) : 'start';
			$('[data-k="end"]', box).classList.toggle('is-invalid', !!err);
			$('[data-embed]', box).textContent = id && r ? embedIframe(id, r.start, r.end) : 'Appears once the video and this part’s times are in.';

			var ql = $('[data-q]', box);
			ql.textContent = '';
			var rows = assigned.buckets[i] || [];
			if (!rows.length) { ql.appendChild(el('li', {}, [el('span', { className: 'tbtlb-empty', text: 'No questions in this part yet' })])); }
			rows.forEach(function (q) {
				ql.appendChild(el('li', {}, [el('span', { className: 'tbtlb-t', text: q.time }), el('span', { text: q.question })]));
			});
			var qc = $('[data-chips="q"]', box);
			qc.textContent = '';
			qc.appendChild(chip(rows.length ? plural(rows.length, 'question', 'questions') : 'No questions', rows.length ? 'ok' : 'warn'));
			var noHint = rows.filter(function (q) { return !q.answer; }).length;
			if (noHint) { qc.appendChild(chip(noHint + ' without a hint', 'warn')); }

			var cards = parseCards(state.parts[i].cards);
			var todo = incomplete(cards);
			if (!cards.length || todo) { cardsOk = false; }
			var cc = $('[data-chips="cards"]', box);
			cc.textContent = '';
			cc.appendChild(chip(cards.length ? plural(cards.length, 'card', 'cards') : 'No cards', cards.length ? 'ok' : 'warn'));
			if (todo) { cc.appendChild(chip(todo + ' to fill with AI', 'warn')); }
			if (err) { cc.appendChild(chip(err, 'err')); }

			var fill = $('[data-fill]', box);
			fill.disabled = !todo || !!busy.fill[i];
			fill.textContent = busy.fill[i] ? 'Filling…' : 'Fill with AI';
			$('[data-k="cards"]', box).disabled = !!busy.fill[i];
			$('[data-fill-err]', box).textContent = fillErr[i] || '';
		});

		// Timeline: parts as proportional segments, a tick per question, gaps for skipped stretches.
		var bar = $('#tbtlb-tl-bar');
		bar.textContent = '';
		if (!state.parts.length || !good.length) {
			bar.appendChild(el('span', { className: 'tbtlb-tl-empty', text: state.parts.length ? 'Enter where each part ends' : 'Add a part to see the timeline' }));
		} else {
			var total = Math.max(1, endAll - startAll);
			resolved.ranges.forEach(function (r, i) {
				if (!r) { return; }
				var seg = el('div', { className: 'tbtlb-tl-seg ' + (i % 2 ? 'is-even' : 'is-odd'), text: String(i + 1) });
				seg.style.left = ((r.start - startAll) / total * 100) + '%';
				seg.style.width = ((r.end - r.start) / total * 100) + '%';
				assigned.buckets[i].forEach(function (q) {
					var tick = el('span', { className: 'tbtlb-tl-tick' });
					tick.style.left = ((q.t - r.start) / (r.end - r.start) * 100) + '%';
					seg.appendChild(tick);
				});
				bar.appendChild(seg);
			});
		}
		$('#tbtlb-tl-start').textContent = secondsToTime(startAll);
		$('#tbtlb-tl-end').textContent = endAll !== null ? secondsToTime(endAll) : '';

		var notice = $('#tbtlb-video-notice');
		notice.textContent = '';
		if (assigned.outside.length && state.parts.length) {
			var n = assigned.outside.length;
			var times = assigned.outside.slice(0, 3).map(function (q) { return q.time; }).join(', ') + (n > 3 ? '…' : '');
			notice.appendChild(el('div', {
				className: 'tbtlb-notice tbtlb-err',
				text: n + (n > 1 ? ' questions fall' : ' question falls') + ' outside every part (' + times + '). Check the part times, or remove questions from a skipped stretch.'
			}));
		}

		var hasParts = state.parts.length > 0;
		var checks = [!!id, hasParts, hasParts && Object.keys(resolved.errors).length === 0, qa.length > 0 && !assigned.outside.length, hasParts && cardsOk];
		return { checks: checks, qa: qa };
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
			youtube: state.youtube, qa: state.qa,
			parts: state.parts.map(function (p) { return { start: p.start, end: p.end, cards: p.cards }; }),
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
		state.parts.push({ start: '', end: '', cards: '' });
		renderParts();
		changed();
		var last = $$('[data-part]').pop();
		if (last) { $('[data-k="end"]', last).focus(); }
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
