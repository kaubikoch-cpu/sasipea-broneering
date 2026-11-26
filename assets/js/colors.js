/* Sasipea broneering – värvide ja vesiniku lisamine broneeringule (päevaplaani aken ja kassa arve). Vanilla JS. */
(function () {
	'use strict';

	function h(tag, attrs) {
		var el = document.createElement(tag);
		if (attrs) Object.keys(attrs).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) return;
			if (k === 'class') el.className = v;
			else if (k === 'style') el.style.cssText = v;
			else if (k.slice(0, 2) === 'on') el.addEventListener(k.slice(2), v);
			else el.setAttribute(k, v === true ? '' : v);
		});
		for (var i = 2; i < arguments.length; i++) {
			var c = arguments[i];
			if (c === null || c === undefined || c === false) continue;
			(Array.isArray(c) ? c : [c]).forEach(function (x) { if (x !== null && x !== undefined && x !== false) el.appendChild(typeof x === 'string' || typeof x === 'number' ? document.createTextNode(String(x)) : x); });
		}
		return el;
	}

	/* Värvid broneeringu juures: otsi nime või koodi järgi, lisa grammid. Kulu läheb kliendikaardile ja arvatakse värvilaost maha. */
	function colorMatches(colors, q) {
		// Kõik otsisõnad peavad nimes või koodis olemas olema ("cream 7.1", "shine clear"). Täpne kood tuleb ette.
		var words = q.trim().toLowerCase().split(/\s+/).filter(Boolean);
		if (!words.length) return [];
		var hits = colors.filter(function (c) {
			var t = (c.title + ' ' + (c.sku || '')).toLowerCase();
			return words.every(function (w) { return t.indexOf(w) !== -1; });
		});
		var code = q.trim().toLowerCase();
		hits.sort(function (a, b) { return (b.code.toLowerCase() === code) - (a.code.toLowerCase() === code); });
		return hits;
	}

	function colorBox(b, opts) {
		var api = opts.api, drawSales = opts.onChange, C = { colorsUrl: opts.colorsUrl };
		var colors = b.colors || [];
		var chosen = null;
		var box = h('div', { class: 'sb-p-colors' });
		var list = h('ul', { class: 'sb-p-sales-list' });
		var err = h('div', { class: 'sb-p-err', role: 'alert' });
		var results = h('ul', { class: 'sb-p-color-results', role: 'listbox', 'aria-label': 'Leitud värvid' });
		var pick = h('input', { id: 'sb-color-q', type: 'search', autocomplete: 'off', placeholder: 'otsi: nimi või kood, nt cream 7.1', 'aria-label': 'Otsi värvi nime või koodi järgi' });
		var grams = h('input', { id: 'sb-color-g', type: 'text', inputmode: 'decimal', placeholder: 'grammid', 'aria-label': 'Grammid', style: 'width:90px' });
		// Vesinik: suhe tuleb värvi vaikimisi suhtest, grammid arvutatakse ise; mõlemat saab selle kliendi juures muuta.
		var RATIOS = [[0, 'ilma'], [1, '1:1'], [1.5, '1:1,5'], [2, '1:2']];
		var ratio = 0, oxManual = false, oxLast = '';
		var oxSel = h('select', { id: 'sb-color-ox', 'aria-label': 'Vesinik' });
		var oxG = h('input', { id: 'sb-color-oxg', type: 'text', inputmode: 'decimal', placeholder: 'g', 'aria-label': 'Vesiniku grammid', style: 'width:70px' });
		var ratioRow = h('span', { class: 'sb-p-ratio', role: 'group', 'aria-label': 'Segusuhe värv : vesinik' });
		var oxRow = h('div', { class: 'sb-p-ox', hidden: true }, h('label', { for: 'sb-color-ox' }, 'Vesinik'), oxSel, ratioRow, oxG, h('span', { class: 'sb-p-hint' }, 'g'));
		function num(v) { var n = parseFloat(String(v).replace(',', '.')); return isNaN(n) ? 0 : n; }
		function oxList() { return colors.filter(function (c) { return c.is_ox; }); }
		function fillOx() {
			oxSel.textContent = '';
			oxSel.appendChild(h('option', { value: '' }, '— vali vesinik —'));
			oxList().forEach(function (o) { oxSel.appendChild(h('option', { value: o.id, selected: String(o.id) === oxLast }, o.title + ' (laos ' + o.stock + ')')); });
		}
		function drawRatio() {
			ratioRow.textContent = '';
			RATIOS.forEach(function (r) {
				ratioRow.appendChild(h('button', { type: 'button', class: 'button' + (!oxManual && ratio === r[0] ? ' button-primary' : ''), 'aria-pressed': !oxManual && ratio === r[0] ? 'true' : 'false', onclick: function () { ratio = r[0]; oxManual = false; calcOx(); drawRatio(); } }, r[1]));
			});
			oxSel.disabled = oxG.disabled = !oxManual && ratio === 0;
		}
		function calcOx() { if (!oxManual) oxG.value = ratio > 0 && num(grams.value) > 0 ? String(Math.round(num(grams.value) * ratio)) : ''; }
		function showOx(c) {
			var on = !!c && !c.is_ox && oxList().length > 0;
			oxRow.hidden = !on;
			if (!on) return;
			ratio = c.ratio || 0; oxManual = false;
			if (RATIOS.every(function (r) { return r[0] !== ratio; })) RATIOS.push([ratio, '1:' + String(ratio).replace('.', ',')]);
			fillOx(); calcOx(); drawRatio();
		}
		oxG.addEventListener('input', function () { oxManual = true; drawRatio(); });
		oxSel.addEventListener('change', function () { oxLast = oxSel.value; });
		oxG.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); add(); } });
		function choose(c) {
			chosen = c;
			pick.value = c.title;
			showOx(c);
			results.textContent = '';
			results.appendChild(h('li', { class: 'sb-p-hint' + (c.level !== 'ok' ? ' sb-p-warn' : '') }, 'Laos ' + c.stock + ' (pakend ' + c.pack + ')' + (c.level === 'out' ? '. Otsas, võta uus pakend lattu.' : '')));
			grams.focus();
		}
		function search() {
			chosen = null;
			showOx(null);
			results.textContent = '';
			var hits = colorMatches(colors, pick.value);
			if (!pick.value.trim()) return hits;
			if (!hits.length) { results.appendChild(h('li', { class: 'sb-p-hint' }, 'Sellist värvi värvilaos ei ole.')); return hits; }
			hits.slice(0, 8).forEach(function (c) {
				results.appendChild(h('li', null, h('button', { type: 'button', class: 'sb-p-color-hit', role: 'option', onclick: function () { choose(c); } },
					h('span', null, h('strong', null, c.code), c.code ? ' ' : '', c.name),
					h('small', { class: c.level !== 'ok' ? 'sb-p-warn' : '' }, 'laos ' + c.stock))));
			});
			if (hits.length > 8) results.appendChild(h('li', { class: 'sb-p-hint' }, 'Veel ' + (hits.length - 8) + ' vastet, täpsusta otsingut.'));
			return hits;
		}
		function apply(r) {
			colors = r.colors || colors;
			draw(r.color_uses || []);
			if (drawSales) drawSales(r.sales || []);
		}
		function draw(uses) {
			list.textContent = '';
			var sum = 0;
			uses.forEach(function (u) {
				sum += u.grams;
				list.appendChild(h('li', null, h('strong', null, u.code || u.name), u.code ? ' ' + u.name : '', ' · ' + String(u.grams).replace('.', ',') + ' g ',
					h('button', { type: 'button', class: 'sb-link', 'aria-label': 'Eemalda ' + u.label, onclick: function () {
						api('admin/color-uses/' + u.id, 'DELETE').then(apply).catch(function (e) { err.textContent = e.message; });
					} }, 'eemalda')));
			});
			if (!uses.length) list.appendChild(h('li', { class: 'sb-p-hint' }, 'Värve pole veel märgitud.'));
			else if (uses.length > 1) list.appendChild(h('li', null, h('strong', null, 'Kokku: ' + String(Math.round(sum * 100) / 100).replace('.', ',') + ' g')));
		}
		function add() {
			err.textContent = '';
			if (!chosen) { err.textContent = 'Otsi värv ja vali see loendist.'; pick.focus(); return; }
			var data = { booking_id: b.id, color_id: chosen.id, grams: grams.value };
			if (!oxRow.hidden && (oxManual || ratio > 0) && oxG.value.trim() !== '') {
				if (!oxSel.value) { err.textContent = 'Vali, millist vesinikku kasutasid, või vali suhteks "ilma".'; oxSel.focus(); return; }
				data.ox_id = +oxSel.value; data.ox_grams = oxG.value;
			}
			api('admin/color-uses', 'POST', data)
				.then(function (r) { chosen = null; pick.value = ''; grams.value = ''; results.textContent = ''; showOx(null); apply(r); pick.focus(); })
				.catch(function (e) { err.textContent = e.message; });
		}
		pick.addEventListener('input', search);
		// Enter ei tohi dialoogi sulgeda: ainus vaste (või täpne kood) valitakse, grammidelt lisatakse rida.
		pick.addEventListener('keydown', function (ev) {
			if (ev.key !== 'Enter') return;
			ev.preventDefault();
			if (chosen) { grams.focus(); return; }
			var hits = search(), code = pick.value.trim().toLowerCase();
			if (hits.length === 1 || (hits.length && hits[0].code.toLowerCase() === code && (hits.length < 2 || hits[1].code.toLowerCase() !== code))) choose(hits[0]);
		});
		grams.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); add(); } });
		grams.addEventListener('input', calcOx);
		box.appendChild(h('h3', null, 'Värvid ja vesinik'));
		if (b.last_formula) box.appendChild(h('p', { class: 'sb-p-formula' }, h('strong', null, 'Eelmine kord '), b.last_formula));
		box.appendChild(list);
		if (colors.length) {
			box.appendChild(h('div', { class: 'sb-p-sales-add' }, pick, grams, h('button', { type: 'button', class: 'button', onclick: add }, 'Lisa')));
			box.appendChild(oxRow);
			box.appendChild(results);
		} else {
			box.appendChild(h('p', { class: 'sb-p-hint' }, 'Värviladu on tühi. ', C.colorsUrl ? h('a', { href: C.colorsUrl }, 'Lisa värvid värvilattu') : 'Lisa värvid värvilattu.'));
		}
		box.appendChild(err);
		draw(b.color_uses || []);
		return box;
	}

	/* REST-i abiline lehtedele, kus päevaplaani skripti pole (kassa). */
	function makeApi(rest, nonce) {
		return function (path, method, body) {
			return fetch(rest + path, {
				method: method || 'GET',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json', 'Accept': 'application/json' },
				body: body ? JSON.stringify(body) : undefined
			}).then(function (r) {
				return r.json().catch(function () { return {}; }).then(function (d) {
					if (!r.ok) throw new Error((d && d.message) || 'Viga');
					return d;
				});
			});
		};
	}

	window.SBColors = { box: colorBox, api: makeApi };

	/* Kassa arve: <div id="sb-k-colors" data-rest data-nonce data-booking data-colors-url>. Pärast muudatust laetakse arve uuesti, et read ja summa oleksid õiged. */
	var el = document.getElementById('sb-k-colors');
	if (el) {
		var api = makeApi(el.getAttribute('data-rest'), el.getAttribute('data-nonce'));
		api('admin/bookings/' + el.getAttribute('data-booking')).then(function (b) {
			el.textContent = '';
			el.appendChild(colorBox(b, { api: api, colorsUrl: el.getAttribute('data-colors-url'), onChange: function () { window.location.reload(); } }));
		}).catch(function (e) { el.textContent = e.message; });
	}
})();
