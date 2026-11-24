/* Sasipea broneering – avalik vorm. Vanilla JS, ilma build-sammuta. */
(function () {
	'use strict';

	var root = document.querySelector('.sb-app');
	if (!root) return;
	var cfg = JSON.parse(root.getAttribute('data-sb-config'));

	var MONTHS = ['jaanuar', 'veebruar', 'märts', 'aprill', 'mai', 'juuni', 'juuli', 'august', 'september', 'oktoober', 'november', 'detsember'];
	var DAYS_SHORT = ['E', 'T', 'K', 'N', 'R', 'L', 'P'];
	var DAYS_LONG = ['esmaspäev', 'teisipäev', 'kolmapäev', 'neljapäev', 'reede', 'laupäev', 'pühapäev'];

	var state = {
		nonce: null,
		catalog: [],
		service: null,
		staffList: [],
		staff: null, // {id:0,name:'Ükskõik kes'} või praktikant
		month: null, // 'YYYY-MM'
		days: {},
		date: null,
		slots: [],
		slot: null,
		startedAt: Date.now(),
		manage: null
	};

	/* ---------- abifunktsioonid ---------- */

	function h(tag, attrs) {
		var el = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (k) {
				var v = attrs[k];
				if (v === null || v === undefined || v === false) return;
				if (k === 'class') el.className = v;
				else if (k === 'text') el.textContent = v;
				else if (k.slice(0, 2) === 'on') el.addEventListener(k.slice(2), v);
				else el.setAttribute(k, v === true ? '' : v);
			});
		}
		for (var i = 2; i < arguments.length; i++) {
			var c = arguments[i];
			if (c === null || c === undefined || c === false) continue;
			if (Array.isArray(c)) c.forEach(function (x) { if (x) el.appendChild(typeof x === 'string' ? document.createTextNode(x) : x); });
			else el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		}
		return el;
	}

	function api(path, opts) {
		opts = opts || {};
		var headers = { 'Accept': 'application/json' };
		if (opts.body) headers['Content-Type'] = 'application/json';
		if (state.nonce) headers['X-WP-Nonce'] = state.nonce;
		return fetch(cfg.rest + path, {
			method: opts.method || 'GET',
			headers: headers,
			// Avalik broneerimine käib alati külastajana. Sisseloginud kasutaja küpsisega ei klapiks
			// külastaja turvakood ja WordPress vastaks "Küpsiste kontroll ebaõnnestus".
			credentials: 'omit',
			body: opts.body ? JSON.stringify(opts.body) : undefined
		}).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (data) {
				if (!r.ok) {
					var err = new Error((data && data.message) || 'Viga. Proovi uuesti.');
					err.data = data && data.data;
					err.code = data && data.code;
					throw err;
				}
				return data;
			});
		});
	}

	function pad(n) { return (n < 10 ? '0' : '') + n; }

	function todayStr() {
		// Kohalik kuupäev Tallinnas.
		var p = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Tallinn', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
		return p;
	}

	function dateLabel(d) {
		var dt = new Date(d + 'T12:00:00');
		var wd = (dt.getDay() + 6) % 7;
		return DAYS_LONG[wd] + ', ' + dt.getDate() + '. ' + MONTHS[dt.getMonth()];
	}

	var firstRender = true;
	function focusHeading(el) {
		if (firstRender) { firstRender = false; return; } // ära keri lehte esimesel laadimisel
		var hd = el.querySelector('h2, h3');
		if (hd) { hd.setAttribute('tabindex', '-1'); hd.focus({ preventScroll: false }); }
	}

	function live(msg) {
		var region = root.querySelector('.sb-live');
		if (region) { region.textContent = ''; setTimeout(function () { region.textContent = msg; }, 50); }
	}

	function errorBox(msg) {
		return h('div', { class: 'sb-error', role: 'alert' }, msg);
	}

	/* ---------- raam ---------- */

	var main, summaryEl;

	function frame() {
		root.textContent = '';
		summaryEl = h('div', { class: 'sb-summary', 'aria-label': 'Sinu valikud' });
		main = h('div', { class: 'sb-step' });
		root.appendChild(h('div', { class: 'sb-live sb-visually-hidden', 'aria-live': 'polite' }));
		root.appendChild(summaryEl);
		root.appendChild(main);
	}

	function renderSummary(step) {
		summaryEl.textContent = '';
		var rows = [];
		// Kestus ja hind sõltuvad valitud praktikandist (tase: soodustus ja lisaaeg).
		var terms = (state.staff && step > 2 && state.staff.duration_label) ? state.staff : state.service;
		if (state.service && step > 1) rows.push(['Teenus', state.service.name + ' · ' + terms.duration_label + ' · ' + terms.price_label + (state.service.material_extra ? ' + materjal' : ''), 1]);
		if (state.staff && step > 2) rows.push(['Praktikant', state.staff.name, 2]);
		if (state.slot && step > 3) rows.push(['Aeg', dateLabel(state.date) + ' kell ' + state.slot.time, 3]);
		if (!rows.length) return;
		summaryEl.appendChild(h('dl', null, rows.map(function (r) {
			return h('div', { class: 'sb-summary-row' },
				h('dt', null, r[0]),
				h('dd', null, r[1], ' ', h('button', { type: 'button', class: 'sb-link', onclick: function () { goto(r[2]); } }, 'Muuda', h('span', { class: 'sb-visually-hidden' }, ' ' + r[0].toLowerCase()))));
		})));
	}

	function goto(step) {
		if (step <= 1) { state.service = null; }
		if (step <= 2) { state.staff = null; }
		if (step <= 3) { state.slot = null; }
		[null, stepService, stepStaff, stepTime, stepContact][step]();
	}

	function stepHeader(n, title) {
		return h('div', { class: 'sb-step-head' },
			h('p', { class: 'sb-step-no' }, 'Samm ' + n + '/4'),
			h('h2', null, title));
	}

	/* ---------- 1. teenus ---------- */

	function stepService() {
		renderSummary(1);
		main.textContent = '';
		main.appendChild(stepHeader(1, 'Vali teenus'));
		state.catalog.forEach(function (cat) {
			var list = h('ul', { class: 'sb-options' });
			cat.services.forEach(function (s) {
				list.appendChild(h('li', null, h('button', {
					type: 'button', class: 'sb-option',
					onclick: function () { state.service = s; stepStaff(); }
				},
				h('span', { class: 'sb-option-title' }, s.name),
				h('span', { class: 'sb-option-meta' }, s.duration_label + ' · ' + s.price_label + (s.material_extra ? ' + materjal' : '')),
				s.note ? h('span', { class: 'sb-option-note' }, s.note) : null)));
			});
			main.appendChild(h('section', { class: 'sb-cat' }, h('h3', null, cat.name), list));
		});
		focusHeading(main);
	}

	/* ---------- 2. praktikant ---------- */

	function stepStaff() {
		renderSummary(2);
		main.textContent = '';
		main.appendChild(stepHeader(2, 'Vali praktikant'));
		var list = h('ul', { class: 'sb-options sb-staff' });
		main.appendChild(list);
		var anyStaff = { id: 0, name: 'Ükskõik kes' };
		var anyMeta = h('span', { class: 'sb-option-meta' }, 'Näitame kõige rohkem vabu aegu');
		list.appendChild(h('li', null, h('button', {
			type: 'button', class: 'sb-option',
			onclick: function () { state.staff = anyStaff; stepTime(); }
		}, h('span', { class: 'sb-option-title' }, 'Ükskõik kes'), anyMeta)));
		api('staff?service=' + state.service.id).then(function (res) {
			var staff = res.staff || [];
			state.staffList = staff;
			if (res.any) {
				anyStaff.duration_label = res.any.duration_label;
				anyStaff.price_label = res.any.price_label;
				anyMeta.textContent = 'Näitame kõige rohkem vabu aegu · ' + res.any.duration_label + ' · ' + res.any.price_label;
			}
			staff.forEach(function (p) {
				var meta = (p.level ? p.level + ' · ' : '') + p.duration_label + ' · ' + p.price_label + (p.discount_pct ? ' (−' + p.discount_pct + '%)' : '') + (state.service.material_extra ? ' + materjal' : '');
				list.appendChild(h('li', null, h('button', {
					type: 'button', class: 'sb-option sb-option-staff',
					onclick: function () { state.staff = p; stepTime(); }
				},
				p.photo_url ? h('img', { src: p.photo_url, alt: '', width: 48, height: 48, loading: 'lazy' }) : h('span', { class: 'sb-avatar', 'aria-hidden': 'true' }, p.name.replace(/^Näidis:\s*/, '').charAt(0)),
				h('span', { class: 'sb-option-text' }, h('span', { class: 'sb-option-title' }, p.name), h('span', { class: 'sb-option-meta' }, meta)))));
			});
		}).catch(function (e) { main.appendChild(errorBox(e.message)); });
		focusHeading(main);
	}

	/* ---------- 3. aeg ---------- */

	function stepTime(opts) {
		opts = opts || {};
		renderSummary(3);
		main.textContent = '';
		main.appendChild(opts.header || stepHeader(3, 'Vali aeg'));
		if (!state.month) state.month = todayStr().slice(0, 7);
		var cal = h('div', { class: 'sb-cal' });
		var slotsEl = h('div', { class: 'sb-slots' });
		main.appendChild(cal);
		main.appendChild(slotsEl);
		loadMonth(cal, slotsEl, opts);
		focusHeading(main);
	}

	function staffParam() { return state.staff && state.staff.id ? '&staff=' + state.staff.id : ''; }

	function loadMonth(cal, slotsEl, opts) {
		cal.textContent = '';
		cal.appendChild(h('p', { class: 'sb-loading' }, 'Otsin vabu aegu…'));
		api('availability/days?service=' + state.service.id + staffParam() + '&month=' + state.month).then(function (days) {
			state.days = days;
			drawMonth(cal, slotsEl, opts);
			// Kui kuus pole ühtki vaba aega ja oleme jooksvas kuus, liigu automaatselt järgmisse.
			var any = Object.keys(days).some(function (k) { return days[k] > 0; });
			if (!any && !opts.triedNext && state.month === todayStr().slice(0, 7)) {
				opts.triedNext = true;
				shiftMonth(1); loadMonth(cal, slotsEl, opts);
				return;
			}
			if (state.date && days[state.date] > 0) loadSlots(slotsEl, opts);
		}).catch(function (e) { cal.textContent = ''; cal.appendChild(errorBox(e.message)); });
	}

	function shiftMonth(delta) {
		var y = +state.month.slice(0, 4), m = +state.month.slice(5, 7) - 1 + delta;
		y += Math.floor(m / 12); m = ((m % 12) + 12) % 12;
		state.month = y + '-' + pad(m + 1);
	}

	function drawMonth(cal, slotsEl, opts) {
		cal.textContent = '';
		var y = +state.month.slice(0, 4), m = +state.month.slice(5, 7) - 1;
		var thisMonth = todayStr().slice(0, 7);
		var nav = h('div', { class: 'sb-cal-nav' },
			h('button', { type: 'button', class: 'sb-btn-icon', 'aria-label': 'Eelmine kuu', disabled: state.month <= thisMonth, onclick: function () { shiftMonth(-1); state.date = null; slotsEl.textContent = ''; loadMonth(cal, slotsEl, opts); } }, '‹'),
			h('h3', { 'aria-live': 'polite' }, MONTHS[m].charAt(0).toUpperCase() + MONTHS[m].slice(1) + ' ' + y),
			h('button', { type: 'button', class: 'sb-btn-icon', 'aria-label': 'Järgmine kuu', onclick: function () { shiftMonth(1); state.date = null; slotsEl.textContent = ''; loadMonth(cal, slotsEl, opts); } }, '›'));
		cal.appendChild(nav);

		var table = h('table', { class: 'sb-cal-grid', role: 'grid' });
		table.appendChild(h('thead', null, h('tr', null, DAYS_SHORT.map(function (d, i) { return h('th', { scope: 'col', abbr: DAYS_LONG[i] }, d); }))));
		var tbody = h('tbody');
		var first = new Date(y, m, 1);
		var offset = (first.getDay() + 6) % 7;
		var daysInMonth = new Date(y, m + 1, 0).getDate();
		var tr = h('tr');
		for (var i = 0; i < offset; i++) tr.appendChild(h('td'));
		for (var d = 1; d <= daysInMonth; d++) {
			var ds = y + '-' + pad(m + 1) + '-' + pad(d);
			var n = state.days[ds] || 0;
			var label = dateLabel(ds) + (n ? ', ' + n + ' vaba aega' : ', vabu aegu pole');
			var btn = h('button', {
				type: 'button', class: 'sb-day' + (n ? ' sb-day-free' : '') + (ds === state.date ? ' is-selected' : ''),
				disabled: !n, 'aria-label': label, 'aria-pressed': ds === state.date ? 'true' : 'false',
				'data-date': ds
			}, String(d));
			btn.addEventListener('click', (function (ds) { return function () {
				state.date = ds;
				cal.querySelectorAll('.sb-day').forEach(function (b) { var on = b.getAttribute('data-date') === ds; b.classList.toggle('is-selected', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
				loadSlots(slotsEl, opts);
			}; })(ds));
			tr.appendChild(h('td', null, btn));
			if ((offset + d) % 7 === 0) { tbody.appendChild(tr); tr = h('tr'); }
		}
		if (tr.childNodes.length) tbody.appendChild(tr);
		table.appendChild(tbody);
		cal.appendChild(table);
		var free = Object.keys(state.days).filter(function (k) { return state.days[k] > 0; }).length;
		if (!free) cal.appendChild(h('p', { class: 'sb-muted' }, 'Selles kuus vabu aegu pole. Vaata järgmist kuud.'));
	}

	function loadSlots(slotsEl, opts) {
		slotsEl.textContent = '';
		slotsEl.appendChild(h('p', { class: 'sb-loading' }, 'Laen kellaaegu…'));
		var q = 'availability/slots?service=' + state.service.id + staffParam() + '&date=' + state.date + (opts.exclude ? '&exclude=' + opts.exclude : '');
		api(q).then(function (slots) {
			state.slots = slots;
			slotsEl.textContent = '';
			slotsEl.appendChild(h('h3', null, dateLabel(state.date)));
			if (!slots.length) { slotsEl.appendChild(h('p', { class: 'sb-muted' }, 'Sellel päeval vabu aegu enam pole.')); return; }
			var ul = h('ul', { class: 'sb-times' });
			slots.forEach(function (s) {
				ul.appendChild(h('li', null, h('button', {
					type: 'button', class: 'sb-time',
					onclick: function () { state.slot = s; (opts.onPick || stepContact)(s); }
				}, s.time)));
			});
			slotsEl.appendChild(ul);
			live(slots.length + ' vaba aega');
		}).catch(function (e) { slotsEl.textContent = ''; slotsEl.appendChild(errorBox(e.message)); });
	}

	/* ---------- 4. kontakt ---------- */

	function field(name, label, type, extra) {
		extra = extra || {};
		var id = 'sb-' + name;
		var input = h(type === 'textarea' ? 'textarea' : 'input', Object.assign({
			id: id, name: name, type: type === 'textarea' ? null : type,
			'aria-describedby': id + '-err' + (extra.hint ? ' ' + id + '-hint' : '')
		}, extra.attrs || {}));
		return h('div', { class: 'sb-field' },
			h('label', { for: id }, label, extra.required ? h('span', { class: 'sb-req', 'aria-hidden': 'true' }, ' *') : null),
			extra.hint ? h('p', { class: 'sb-hint', id: id + '-hint' }, extra.hint) : null,
			input,
			h('p', { class: 'sb-field-err', id: id + '-err' }));
	}

	function check(name, label, required) {
		var id = 'sb-' + name;
		return h('div', { class: 'sb-check' },
			h('input', { type: 'checkbox', id: id, name: name, required: required, 'aria-describedby': id + '-err' }),
			h('label', { for: id }, label),
			h('p', { class: 'sb-field-err', id: id + '-err' }));
	}

	function stepContact() {
		renderSummary(4);
		main.textContent = '';
		main.appendChild(stepHeader(4, 'Sinu andmed'));
		var privacyLabel = h('span', null, cfg.consents.privacy + ' ', h('a', { href: cfg.privacyUrl, target: '_blank', rel: 'noopener' }, 'Privaatsuspoliitika'), h('span', { class: 'sb-req', 'aria-hidden': 'true' }, ' *'));
		var form = h('form', { class: 'sb-form', novalidate: true },
			h('div', { class: 'sb-row' },
				field('first_name', 'Eesnimi', 'text', { required: true, attrs: { autocomplete: 'given-name', required: true } }),
				field('last_name', 'Perekonnanimi', 'text', { required: true, attrs: { autocomplete: 'family-name', required: true } })),
			field('phone', 'Telefon', 'tel', { required: true, hint: 'Eesti number, näiteks 5123 4567. Välismaa number koos suunakoodiga.', attrs: { autocomplete: 'tel', inputmode: 'tel', required: true } }),
			field('email', 'E-post', 'email', { required: true, hint: 'Saadame sinna kinnituse ja meeldetuletuse.', attrs: { autocomplete: 'email', required: true } }),
			field('comment', 'Kommentaar (vabatahtlik)', 'textarea', { attrs: { rows: 3, maxlength: 1000 } }),
			h('div', { class: 'sb-hp', 'aria-hidden': 'true' }, h('label', { for: 'sb-website' }, 'Veebileht'), h('input', { id: 'sb-website', name: 'website', type: 'text', tabindex: '-1', autocomplete: 'off' })),
			(function () { var c = check('privacy', '', true); c.querySelector('label').appendChild(privacyLabel); return c; })(),
			check('models', cfg.consents.models, false),
			check('social', cfg.consents.social, false),
			h('div', { class: 'sb-form-error' }),
			h('button', { type: 'submit', class: 'sb-btn sb-btn-primary' }, 'Kinnita broneering'));
		form.addEventListener('submit', submit);
		main.appendChild(form);
		focusHeading(main);
	}

	function submit(ev) {
		ev.preventDefault();
		var form = ev.currentTarget;
		var btn = form.querySelector('button[type=submit]');
		form.querySelectorAll('.sb-field-err').forEach(function (e) { e.textContent = ''; });
		form.querySelectorAll('[aria-invalid]').forEach(function (e) { e.removeAttribute('aria-invalid'); });
		var errBox = form.querySelector('.sb-form-error');
		errBox.textContent = '';
		var v = function (n) { return form.elements[n].value.trim(); };
		var data = {
			service: state.service.id,
			staff: state.staff.id,
			start: state.slot.start,
			first_name: v('first_name'), last_name: v('last_name'),
			phone: v('phone'), email: v('email'), comment: v('comment'),
			website: form.elements.website.value,
			privacy: form.elements.privacy.checked,
			models: form.elements.models.checked,
			social: form.elements.social.checked,
			elapsed: Math.round((Date.now() - state.startedAt) / 1000)
		};
		// Kliendipoolne kontroll (server kontrollib uuesti).
		var errs = {};
		if (!data.first_name) errs.first_name = 'Sisesta eesnimi.';
		if (!data.last_name) errs.last_name = 'Sisesta perekonnanimi.';
		if (data.phone.replace(/\D/g, '').length < 7) errs.phone = 'Sisesta telefoninumber, näiteks 5123 4567.';
		if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.email)) errs.email = 'Sisesta korrektne e-posti aadress.';
		if (!data.privacy) errs.privacy = 'Broneerimiseks pead nõustuma privaatsuspoliitikaga.';
		if (showErrors(form, errs)) return;

		btn.disabled = true; btn.textContent = 'Salvestan…';
		api('bookings', { method: 'POST', body: data }).then(function (res) {
			confirmation(res, 'Broneering on kinnitatud!');
		}).catch(function (e) {
			btn.disabled = false; btn.textContent = 'Kinnita broneering';
			if (e.data && e.data.fields) { showErrors(form, e.data.fields); return; }
			if (e.code === 'sb_taken') {
				errBox.appendChild(errorBox(e.message));
				errBox.appendChild(h('button', { type: 'button', class: 'sb-btn', onclick: function () { goto(3); } }, 'Vali uus aeg'));
			} else if (e.code === 'rest_forbidden' || e.code === 'rest_cookie_invalid_nonce') {
				errBox.appendChild(errorBox('Leht oli liiga kaua avatud. Värskendan, proovi uuesti.'));
				getNonce();
			} else {
				errBox.appendChild(errorBox(e.message));
			}
			errBox.focus && errBox.setAttribute('tabindex', '-1'); errBox.focus();
		});
	}

	function showErrors(form, errs) {
		var keys = Object.keys(errs);
		keys.forEach(function (k) {
			var el = form.querySelector('#sb-' + k + '-err');
			if (el) el.textContent = errs[k];
			var inp = form.elements[k];
			if (inp) inp.setAttribute('aria-invalid', 'true');
		});
		if (keys.length && form.elements[keys[0]]) form.elements[keys[0]].focus();
		return keys.length > 0;
	}

	/* ---------- kinnitus / haldus ---------- */

	function confirmation(b, title) {
		summaryEl.textContent = '';
		main.textContent = '';
		var cancelled = b.status.indexOf('cancelled') === 0;
		main.appendChild(h('div', { class: 'sb-confirm' + (cancelled ? ' is-cancelled' : '') },
			h('h2', null, title),
			h('dl', { class: 'sb-confirm-list' },
				h('div', null, h('dt', null, 'Teenus'), h('dd', null, b.service)),
				h('div', null, h('dt', null, 'Aeg'), h('dd', null, b.date_label + ' kell ' + b.time + (b.end_time ? '–' + b.end_time : ''))),
				h('div', null, h('dt', null, 'Praktikant'), h('dd', null, b.staff)),
				h('div', null, h('dt', null, 'Hind'), h('dd', null, b.price + (b.material_extra ? ' + materjal' : ''))),
				h('div', null, h('dt', null, 'Aadress'), h('dd', null, 'Sasipea Akadeemia, ' + b.address)),
				cancelled ? h('div', null, h('dt', null, 'Staatus'), h('dd', null, b.status_label)) : null),
			!cancelled && b.note ? h('p', { class: 'sb-muted' }, b.note) : null,
			!cancelled ? h('p', null, 'Saatsime kinnituse e-postile.') : null,
			h('div', { class: 'sb-actions' },
				!cancelled ? h('a', { class: 'sb-btn', href: b.ics_url }, 'Lisa kalendrisse') : null,
				!cancelled ? h('a', { class: 'sb-btn', href: b.manage_url }, 'Tühista või muuda aega') : null,
				cancelled ? h('a', { class: 'sb-btn sb-btn-primary', href: cfg.bookUrl }, 'Broneeri uus aeg') : null)));
		focusHeading(main);
	}

	function manage(token) {
		main.textContent = '';
		main.appendChild(h('p', { class: 'sb-loading' }, 'Laen broneeringut…'));
		api('manage/' + token).then(function (b) { state.manage = b; manageView(b, token); })
			.catch(function (e) { main.textContent = ''; main.appendChild(errorBox(e.message)); });
	}

	function manageView(b, token, msg) {
		confirmation(b, msg || 'Sinu broneering');
		var box = main.querySelector('.sb-actions');
		box.textContent = '';
		if (b.status.indexOf('cancelled') === 0) {
			box.appendChild(h('a', { class: 'sb-btn sb-btn-primary', href: cfg.bookUrl }, 'Broneeri uus aeg'));
			return;
		}
		box.appendChild(h('a', { class: 'sb-btn', href: b.ics_url }, 'Lisa kalendrisse'));
		if (!b.can_change) {
			main.querySelector('.sb-confirm').appendChild(h('p', null, 'Muuta või tühistada saab kuni ' + b.cancel_hours + ' tundi enne algust. Palun helista: ' + cfg.phone + '.'));
			return;
		}
		box.appendChild(h('button', { type: 'button', class: 'sb-btn', onclick: function () { reschedule(b, token); } }, 'Muuda aega'));
		var cancelBtn = h('button', { type: 'button', class: 'sb-btn sb-btn-danger', onclick: function () {
			cancelBtn.replaceWith(h('div', { class: 'sb-confirm-cancel', role: 'group', 'aria-label': 'Kinnita tühistamine' },
				h('p', null, 'Kas tühistad broneeringu?'),
				h('button', { type: 'button', class: 'sb-btn sb-btn-danger', onclick: function (ev) {
					ev.currentTarget.disabled = true;
					api('manage/' + token, { method: 'POST', body: { action: 'cancel' } })
						.then(function (nb) { manageView(nb, token, 'Broneering on tühistatud'); })
						.catch(function (e) { main.appendChild(errorBox(e.message)); });
				} }, 'Jah, tühista'),
				h('button', { type: 'button', class: 'sb-btn', onclick: function () { manageView(b, token); } }, 'Ei')));
			main.querySelector('.sb-confirm-cancel button').focus();
		} }, 'Tühista broneering');
		box.appendChild(cancelBtn);
	}

	function reschedule(b, token) {
		state.service = { id: b.service_id, name: b.service };
		state.staff = { id: b.staff_id, name: b.staff };
		state.month = b.date.slice(0, 7);
		state.date = null;
		summaryEl.textContent = '';
		stepTime({
			header: h('div', { class: 'sb-step-head' }, h('h2', null, 'Vali uus aeg'), h('p', { class: 'sb-muted' }, b.service + ' · ' + b.staff),
				h('button', { type: 'button', class: 'sb-link', onclick: function () { manageView(b, token); } }, 'Tagasi')),
			exclude: token,
			onPick: function (slot) {
				main.querySelectorAll('.sb-time').forEach(function (x) { x.disabled = true; });
				api('manage/' + token, { method: 'POST', body: { action: 'move', start: slot.start, staff: b.staff_id } })
					.then(function (nb) { manageView(nb, token, 'Aeg on muudetud'); })
					.catch(function (e) {
						main.querySelectorAll('.sb-time').forEach(function (x) { x.disabled = false; });
						main.appendChild(errorBox(e.message));
					});
			}
		});
		summaryEl.textContent = '';
	}

	/* ---------- start ---------- */

	function getNonce() {
		return api('nonce').then(function (r) { state.nonce = r.nonce; });
	}

	frame();
	getNonce().then(function () {
		if (cfg.manageToken) { manage(cfg.manageToken); return; }
		return api('services').then(function (cat) { state.catalog = cat; stepService(); });
	}).catch(function (e) { main.textContent = ''; main.appendChild(errorBox(e.message || 'Laadimine ebaõnnestus.')); });
})();
