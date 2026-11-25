/* Kellaaja valik kirjutamise asemel: tekstiväli "12:00-19:00" saab kaks valikut (algus ja lõpp).
 * data-sb-range="inline": valikud välja asemel (seaded, nädalapõhi).
 * data-sb-range="pop":    väli jääb kompaktseks, klõps avab väikese valikuakna (töögraafiku tabel).
 * Pausiga päeva ("10:00-13:00, 13:30-16:00") saab endiselt käsitsi kirjutada. */
(function () {
	'use strict';
	var LO = 6 * 60, HI = 23 * 60;

	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function hm(m) { return pad(Math.floor(m / 60)) + ':' + pad(m % 60); }
	function parse(v) {
		var out = [], re = /(\d{1,2})(?:[:.](\d{2}))?\s*(?:-|–|—)\s*(\d{1,2})(?:[:.](\d{2}))?/g, m;
		while ((m = re.exec(v || ''))) {
			var a = +m[1] * 60 + (+m[2] || 0), b = +m[3] * 60 + (+m[4] || 0);
			if (b > a && b <= 1440) out.push([a, b]);
		}
		return out;
	}
	function el(tag, attrs, text) {
		var e = document.createElement(tag), k;
		for (k in attrs || {}) e.setAttribute(k, attrs[k]);
		if (text != null) e.textContent = text;
		return e;
	}
	function stepOf(input) { return parseInt(input.getAttribute('data-step'), 10) || 30; }

	/** Täidab valiku kellaaegadega; olemasolev väärtus lisatakse ka siis, kui see ei ole sammu peal. */
	function fill(sel, step, value, emptyLabel, min) {
		var vals = [], m;
		for (m = LO; m <= HI; m += step) vals.push(m);
		if (value != null && vals.indexOf(value) < 0) vals.push(value);
		vals.sort(function (a, b) { return a - b; });
		sel.innerHTML = '';
		if (emptyLabel != null) sel.appendChild(el('option', { value: '' }, emptyLabel));
		vals.forEach(function (v) {
			if (min != null && v <= min && v !== value) return;
			sel.appendChild(el('option', { value: String(v) }, hm(v)));
		});
		sel.value = value == null ? '' : String(value);
	}

	/** Algus ja lõpp ühe välja jaoks. onChange saab uue teksti ("" = tühi). */
	function pair(input, emptyLabel, onChange) {
		var step = stepOf(input), cur = parse(input.value)[0] || null;
		var name = input.getAttribute('aria-label') || '';
		var s = el('select', { 'class': 'sb-tr-start', 'aria-label': (name ? name + ': ' : '') + 'algus' });
		var e = el('select', { 'class': 'sb-tr-end', 'aria-label': (name ? name + ': ' : '') + 'lõpp' });
		function fallback() { return parse(input.getAttribute('data-default') || input.getAttribute('placeholder') || '')[0] || [12 * 60, 19 * 60]; }
		function refresh(a, b) {
			fill(s, step, a, emptyLabel);
			fill(e, step, a == null ? null : b, a == null ? '–' : null, a);
			e.disabled = a == null;
		}
		function emit() {
			var a = s.value === '' ? null : +s.value, b = e.value === '' ? null : +e.value;
			if (a == null) { refresh(null, null); onChange(''); return; }
			if (b == null || b <= a) { var d = fallback(); b = d[1] > a ? d[1] : Math.min(1440, a + 60); }
			refresh(a, b);
			onChange(hm(a) + '-' + hm(b));
		}
		s.addEventListener('change', emit);
		e.addEventListener('change', emit);
		if (cur) refresh(cur[0], cur[1]);
		else if (emptyLabel != null) refresh(null, null);
		else { var d = fallback(); refresh(d[0], d[1]); }
		return { start: s, end: e };
	}

	function inline(input) {
		if (parse(input.value).length > 1) return; // pausiga päev jääb tekstiks
		var wrap = el('span', { 'class': 'sb-tr' });
		var p = pair(input, input.getAttribute('data-empty') || '—', function (v) { input.value = v; });
		var manual = el('button', { type: 'button', 'class': 'sb-tr-manual' }, 'kirjuta käsitsi');
		manual.title = 'Pausiga päeva jaoks, näiteks 10:00-13:00, 13:30-16:00';
		manual.addEventListener('click', function () { wrap.remove(); input.hidden = false; input.focus(); });
		wrap.appendChild(p.start);
		wrap.appendChild(el('span', { 'class': 'sb-tr-dash', 'aria-hidden': 'true' }, '–'));
		wrap.appendChild(p.end);
		wrap.appendChild(manual);
		input.hidden = true;
		input.parentNode.insertBefore(wrap, input.nextSibling);
	}

	var pop = null, popFor = null;
	function closePop() { if (pop) { pop.remove(); pop = null; popFor = null; } }
	function openPop(input) {
		if (popFor === input) return;
		closePop();
		popFor = input;
		pop = el('div', { 'class': 'sb-tr-pop', role: 'dialog', 'aria-label': input.getAttribute('aria-label') || 'Kellaaeg' });
		var p = pair(input, null, function (v) { input.value = v; input.title = v; });
		var row = el('div', { 'class': 'sb-tr-row' });
		row.appendChild(p.start);
		row.appendChild(el('span', { 'class': 'sb-tr-dash', 'aria-hidden': 'true' }, '–'));
		row.appendChild(p.end);
		var done = el('button', { type: 'button', 'class': 'button sb-tr-done' }, 'Valmis');
		done.addEventListener('click', function () { closePop(); input.focus(); });
		var manual = el('button', { type: 'button', 'class': 'sb-tr-manual' }, 'Pausiga päev: kirjuta käsitsi');
		manual.addEventListener('click', function () { closePop(); input.readOnly = false; input.removeAttribute('data-sb-range'); input.focus(); input.select(); });
		pop.appendChild(el('strong', null, input.getAttribute('aria-label') || 'Kellaaeg'));
		pop.appendChild(row);
		pop.appendChild(done);
		pop.appendChild(manual);
		document.body.appendChild(pop);
		var r = input.getBoundingClientRect(), w = pop.offsetWidth, hgt = pop.offsetHeight;
		var left = Math.max(8, Math.min(r.left, window.innerWidth - w - 8));
		var top = r.bottom + 6 + hgt > window.innerHeight ? Math.max(8, r.top - hgt - 6) : r.bottom + 6;
		pop.style.left = left + 'px';
		pop.style.top = top + 'px';
		p.start.focus();
	}

	function setup(root) {
		(root || document).querySelectorAll('input[data-sb-range="inline"]').forEach(inline);
		(root || document).querySelectorAll('input[data-sb-range="pop"]').forEach(function (input) {
			if (parse(input.value).length > 1) { input.removeAttribute('data-sb-range'); return; }
			input.readOnly = true;
		});
	}

	document.addEventListener('click', function (ev) {
		var t = ev.target;
		if (t.matches && t.matches('input[data-sb-range="pop"]') && !t.disabled) { openPop(t); return; }
		if (pop && !pop.contains(t)) closePop();
	});
	document.addEventListener('keydown', function (ev) {
		if (ev.key === 'Escape' && pop) { var i = popFor; closePop(); if (i) i.focus(); return; }
		var t = ev.target;
		if ((ev.key === 'Enter' || ev.key === ' ') && t.matches && t.matches('input[data-sb-range="pop"]') && !t.disabled) { ev.preventDefault(); openPop(t); }
	});
	window.addEventListener('scroll', function (ev) { if (pop && !(ev.target && pop.contains(ev.target))) closePop(); }, true);
	window.addEventListener('resize', closePop);

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setup(); });
	else setup();
})();
