/* Sasipea broneering – päevaplaan. Vanilla JS, ilma build-sammuta. */
(function () {
	'use strict';

	var root = document.getElementById('sb-planner');
	if (!root || !window.SB_PLANNER) return;
	var C = window.SB_PLANNER;

	var SLOT = 15;     // ruudustiku samm minutites
	var ROW = 20;      // ühe 15 min rea kõrgus px
	var state = { date: C.today, view: 'day', staff: null, data: null }; // view: day | week | staff (ühe juuksuriõpilase nädal)
	// Töögraafikust tulles avatakse valitud päev (?day=2026-11-25).
	var wanted = (/[?&]day=(\d{4}-\d{2}-\d{2})(?:&|$)/.exec(window.location.search) || [])[1];
	if (wanted) state.date = wanted;

	/* ---------- abifunktsioonid ---------- */

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

	function api(path, method, body) {
		return fetch(C.rest + path, {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': C.nonce, 'Content-Type': 'application/json', 'Accept': 'application/json' },
			body: body ? JSON.stringify(body) : undefined
		}).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (d) {
				if (!r.ok) throw new Error((d && d.message) || 'Viga');
				return d;
			});
		});
	}

	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function hm(min) { return pad(Math.floor(min / 60)) + ':' + pad(min % 60); }
	function addDays(ds, n) {
		var d = new Date(ds + 'T12:00:00'); d.setDate(d.getDate() + n);
		return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
	}
	function monday(ds) { var d = new Date(ds + 'T12:00:00'); return addDays(ds, -((d.getDay() + 6) % 7)); }

	function toast(msg, isError) {
		var t = h('div', { class: 'sb-toast' + (isError ? ' is-error' : ''), role: isError ? 'alert' : 'status' }, msg);
		document.body.appendChild(t);
		setTimeout(function () { t.remove(); }, isError ? 6000 : 3000);
	}

	/* ---------- raam ---------- */

	var body;
	function frame() {
		root.textContent = '';
		var dateInput = h('input', { type: 'date', value: state.date, 'aria-label': 'Kuupäev', onchange: function (e) { if (e.target.value) { state.date = e.target.value; load(); } } });
		var bar = h('div', { class: 'sb-p-bar' },
			h('div', { class: 'sb-p-nav' },
				h('button', { type: 'button', class: 'button', 'aria-label': state.view === 'day' ? 'Eelmine päev' : 'Eelmine nädal', onclick: function () { state.date = addDays(state.date, state.view === 'day' ? -1 : -7); load(); } }, '‹'),
				h('button', { type: 'button', class: 'button', onclick: function () { state.date = C.today; load(); } }, 'Täna'),
				h('button', { type: 'button', class: 'button', 'aria-label': state.view === 'day' ? 'Järgmine päev' : 'Järgmine nädal', onclick: function () { state.date = addDays(state.date, state.view === 'day' ? 1 : 7); load(); } }, '›'),
				dateInput),
			h('div', { class: 'sb-p-views', role: 'group', 'aria-label': 'Vaade' },
				h('button', { type: 'button', class: 'button' + (state.view === 'day' ? ' button-primary' : ''), 'aria-pressed': state.view === 'day' ? 'true' : 'false', onclick: function () { state.view = 'day'; load(); } }, 'Päev'),
				h('button', { type: 'button', class: 'button' + (state.view === 'week' ? ' button-primary' : ''), 'aria-pressed': state.view === 'week' ? 'true' : 'false', onclick: function () { state.view = 'week'; load(); } }, 'Nädal'),
				state.view === 'staff' ? h('button', { type: 'button', class: 'button button-primary', 'aria-pressed': 'true', title: 'Sulge juuksuriõpilase nädalavaade', onclick: function () { state.view = 'week'; load(); } }, staffName() + ' ×') : null),
			C.canManage ? h('div', { class: 'sb-p-actions' },
				h('button', { type: 'button', class: 'button', onclick: function () { bookingDialog(null, {}); } }, '+ Broneering'),
				h('button', { type: 'button', class: 'button', onclick: function () { blockDialog({}); } }, 'Blokeeri aeg')) : null);
		body = h('div', { class: 'sb-p-body', 'aria-live': 'polite' }, h('p', null, 'Laen…'));
		root.appendChild(bar);
		root.appendChild(body);
	}

	function staffName() {
		var list = (state.data && state.data.staff) || [], i;
		for (i = 0; i < list.length; i++) if (list[i].id === state.staff) return list[i].name;
		return 'Juuksuriõpilane';
	}
	function openStaff(id) { state.staff = id; state.view = 'staff'; load(); }
	function staffLink(s) {
		return h('button', { type: 'button', class: 'sb-link sb-p-stafflink', title: 'Ava ' + s.name + ' nädalavaade', onclick: function () { openStaff(s.id); } }, s.name);
	}

	/** Nimi on teada alles pärast andmete laadimist: uuenda vaatenupu silti. */
	function frameChip() {
		var chip = root.querySelector('.sb-p-views button[title]');
		if (chip) chip.textContent = staffName() + ' ×';
	}

	function load() {
		frame();
		var start = state.view === 'day' ? state.date : monday(state.date);
		api('admin/day?date=' + start + '&days=' + (state.view === 'day' ? 1 : 7)).then(function (d) {
			state.data = d;
			body.textContent = '';
			if (!d.staff.length) {
				body.appendChild(h('p', null, C.canManage ? 'Aktiivseid juuksuriõpilasi pole. Lisa need menüüs Juuksuriõpilased.' : 'Sinu kasutaja pole seotud ühegi juuksuriõpilasega. Palun pöördu administraatori poole.'));
				return;
			}
			if (state.view === 'staff' && !d.staff.some(function (s) { return s.id === state.staff; })) state.view = 'week';
			if (state.view === 'staff') { frameChip(); renderStaffWeek(d); }
			else if (state.view === 'week') renderWeek(d);
			else renderDay(d, d.days[0]);
		}).catch(function (e) { body.textContent = ''; body.appendChild(h('div', { class: 'notice notice-error' }, h('p', null, e.message))); });
	}

	/* ---------- päevavaade ---------- */

	function dayRange(day, staff) {
		var lo = 24 * 60, hi = 0;
		function take(r) { lo = Math.min(lo, r[0]); hi = Math.max(hi, r[1]); }
		day.opening.forEach(take);
		staff.forEach(function (s) { (day.work[s.id] || []).forEach(take); });
		day.bookings.forEach(function (b) { take([b.start_min, b.end_min]); });
		if (lo >= hi) { lo = 10 * 60; hi = 18 * 60; }
		lo = Math.floor(Math.max(0, lo - 30) / 60) * 60;
		hi = Math.ceil(Math.min(1440, hi + 30) / 60) * 60;
		return [lo, hi];
	}

	function renderDay(d, day) {
		var range = dayRange(day, d.staff), lo = range[0], hi = range[1];
		var rows = (hi - lo) / SLOT, height = rows * ROW;
		body.appendChild(h('h2', { class: 'sb-p-title' }, day.label.charAt(0).toUpperCase() + day.label.slice(1)));
		if (!day.opening.length) body.appendChild(h('p', { class: 'sb-p-closed' }, 'Salong on sel päeval suletud.'));
		if (day.draft) body.appendChild(h('p', { class: 'sb-p-draft' }, 'Selle kuu töögraafik on salvestatud, aga klientidele veel avalikuks tegemata.'));

		var grid = h('div', { class: 'sb-p-grid', style: 'grid-template-columns: 56px repeat(' + d.staff.length + ', minmax(150px, 1fr))' });
		// päis
		grid.appendChild(h('div', { class: 'sb-p-corner' }));
		d.staff.forEach(function (s) {
			var sub = [s.level_name, s.discount_pct ? '−' + s.discount_pct + '%' : '', s.extra_min ? '+' + s.extra_min + ' min' : ''].filter(Boolean).join(' · ');
			grid.appendChild(h('div', { class: 'sb-p-head' }, staffLink(s), sub ? h('span', { class: 'sb-p-head-sub' }, sub) : null));
		});
		// kellaajad
		var times = h('div', { class: 'sb-p-times', style: 'height:' + height + 'px' });
		for (var m = lo; m < hi; m += 60) times.appendChild(h('div', { class: 'sb-p-hour', style: 'top:' + ((m - lo) / SLOT * ROW) + 'px' }, hm(m)));
		grid.appendChild(times);

		d.staff.forEach(function (s) {
			var col = h('div', { class: 'sb-p-col', 'data-staff': s.id, style: 'height:' + height + 'px; background-size: 100% ' + (ROW * 4) + 'px' });
			// tööaeg
			(day.work[s.id] || []).forEach(function (r) {
				var open = day.opening.filter(function (o) { return o[0] < r[1] && o[1] > r[0]; });
				open.forEach(function (o) {
					var a = Math.max(r[0], o[0]), b = Math.min(r[1], o[1]);
					col.appendChild(h('div', { class: 'sb-p-work', style: 'top:' + ((a - lo) / SLOT * ROW) + 'px;height:' + ((b - a) / SLOT * ROW) + 'px' }));
				});
			});
			// blokeeringud
			day.blocks.filter(function (b) { return !b.staff_id || b.staff_id === s.id; }).forEach(function (b) {
				var a = Math.max(lo, b.start_min), e = Math.min(hi, b.end_min);
				if (e <= a) return;
				col.appendChild(h('button', {
					type: 'button', class: 'sb-p-block', style: 'top:' + ((a - lo) / SLOT * ROW) + 'px;height:' + ((e - a) / SLOT * ROW) + 'px',
					title: (b.reason || 'Blokeeritud') + ' ' + hm(b.start_min) + '–' + hm(b.end_min),
					onclick: function (ev) { ev.stopPropagation(); if (C.canManage) blockInfo(b); }
				}, h('span', null, (b.staff_id ? '' : 'Salong: ') + (b.reason || 'Blokeeritud'))));
			});
			// broneeringud
			day.bookings.filter(function (b) { return b.staff_id === s.id; }).forEach(function (b) {
				col.appendChild(bookingEl(b, lo));
			});
			// klikk tühjal alal -> uus broneering
			if (C.canManage) {
				col.addEventListener('click', function (ev) {
					if (ev.target !== col && !ev.target.classList.contains('sb-p-work')) return;
					var rect = col.getBoundingClientRect();
					var min = lo + Math.floor((ev.clientY - rect.top) / ROW) * SLOT;
					bookingDialog(null, { staff_id: s.id, date: day.date, time: hm(min) });
				});
			}
			grid.appendChild(col);
		});
		var wrap = h('div', { class: 'sb-p-scroll' }, grid);
		body.appendChild(wrap);
		body.appendChild(legend());
	}

	function bookingEl(b, lo) {
		var cancelled = b.status.indexOf('cancelled') === 0;
		var el = h('button', {
			type: 'button',
			class: 'sb-p-booking is-' + b.status + (cancelled ? ' is-cancelled' : ''),
			style: 'top:' + ((b.start_min - lo) / SLOT * ROW) + 'px;height:' + Math.max(ROW, (b.end_min - b.start_min) / SLOT * ROW - 2) + 'px',
			'aria-label': b.time + ', ' + b.client + ', ' + b.service + ', ' + b.status_label
		},
		h('span', { class: 'sb-p-b-time' }, b.time, ' · ', b.status_label),
		h('strong', null, b.client),
		h('span', null, b.service),
		b.phone ? h('span', null, b.phone) : null);
		el.addEventListener('click', function (ev) { ev.stopPropagation(); if (!el._dragged) openBooking(b.id); el._dragged = false; });
		if (C.canManage && !cancelled) enableDrag(el, b, lo);
		return el;
	}

	function legend() {
		var items = [['booked', 'Broneeritud'], ['arrived', 'Kohal'], ['no_show', 'Ei tulnud'], ['cancelled', 'Tühistatud']];
		return h('div', { class: 'sb-p-legend' }, items.map(function (i) {
			return h('span', null, h('i', { class: 'sb-p-dot is-' + i[0] }), i[1]);
		}), C.canManage ? h('span', { class: 'sb-p-hint' }, 'Lohista broneeringut, et muuta aega või juuksuriõpilast. Klõps tühjal alal loob uue broneeringu.') : null);
	}

	/* ---------- lohistamine ---------- */

	function enableDrag(el, b, lo) {
		var startX, startY, origTop, moving = false, targetCol = null;
		el.addEventListener('pointerdown', function (ev) {
			if (ev.button !== 0) return;
			startX = ev.clientX; startY = ev.clientY; origTop = el.offsetTop; moving = false;
			el.setPointerCapture(ev.pointerId);
		});
		el.addEventListener('pointermove', function (ev) {
			if (!el.hasPointerCapture(ev.pointerId)) return;
			var dx = ev.clientX - startX, dy = ev.clientY - startY;
			if (!moving && Math.abs(dx) + Math.abs(dy) < 6) return;
			moving = true;
			el.classList.add('is-dragging');
			var snapped = Math.round(dy / ROW) * ROW;
			el.style.transform = 'translate(' + dx + 'px,' + snapped + 'px)';
			el.style.pointerEvents = 'none';
			var under = document.elementFromPoint(ev.clientX, ev.clientY);
			el.style.pointerEvents = '';
			targetCol = under && under.closest ? under.closest('.sb-p-col') : null;
			root.querySelectorAll('.sb-p-col.is-target').forEach(function (c) { c.classList.remove('is-target'); });
			if (targetCol) targetCol.classList.add('is-target');
		});
		el.addEventListener('pointerup', function (ev) {
			if (!el.hasPointerCapture(ev.pointerId)) return;
			el.releasePointerCapture(ev.pointerId);
			root.querySelectorAll('.sb-p-col.is-target').forEach(function (c) { c.classList.remove('is-target'); });
			if (!moving) return;
			el._dragged = true;
			var dy = ev.clientY - startY;
			var newStart = b.start_min + Math.round(dy / ROW) * SLOT;
			var staff = targetCol ? +targetCol.getAttribute('data-staff') : b.staff_id;
			el.style.transform = '';
			el.classList.remove('is-dragging');
			if (newStart === b.start_min && staff === b.staff_id) return;
			el.classList.add('is-saving');
			api('admin/bookings/' + b.id, 'PATCH', { date: b.date, time: hm(newStart), staff_id: staff })
				.then(function () { toast('Broneering liigutatud: ' + hm(newStart)); load(); })
				.catch(function (e) { toast(e.message, true); load(); });
		});
		el.addEventListener('pointercancel', function () { el.style.transform = ''; el.classList.remove('is-dragging'); });
	}

	/* ---------- nädalavaade ---------- */

	function renderWeek(d) {
		var table = h('table', { class: 'widefat sb-p-week' });
		table.appendChild(h('thead', null, h('tr', null, h('th', { scope: 'col' }, 'Juuksuriõpilane'), d.days.map(function (day) {
			var dt = new Date(day.date + 'T12:00:00');
			return h('th', { scope: 'col' }, h('button', { type: 'button', class: 'sb-link', onclick: function () { state.date = day.date; state.view = 'day'; load(); } }, ['E', 'T', 'K', 'N', 'R', 'L', 'P'][(dt.getDay() + 6) % 7] + ' ' + dt.getDate() + '.' + (dt.getMonth() + 1)));
		}))));
		var tb = h('tbody');
		d.staff.forEach(function (s) {
			tb.appendChild(h('tr', null, h('th', { scope: 'row' }, staffLink(s)), d.days.map(function (day) {
				var work = day.work[s.id] || [];
				var list = day.bookings.filter(function (b) { return b.staff_id === s.id; });
				return h('td', { class: work.length && day.opening.length ? '' : 'is-off' },
					work.length && day.opening.length ? h('div', { class: 'sb-p-w-hours' }, work.map(function (r) { return hm(r[0]) + '–' + hm(r[1]); }).join(', ')) : h('div', { class: 'sb-p-w-hours' }, 'vaba'),
					list.map(function (b) {
						return h('button', { type: 'button', class: 'sb-p-w-item is-' + b.status, onclick: function () { openBooking(b.id); } },
							h('strong', null, b.time.split('–')[0]), ' ', b.client, h('br'), h('small', null, b.service));
					}));
			})));
		});
		table.appendChild(tb);
		body.appendChild(h('div', { class: 'sb-p-scroll' }, table));
	}

	/* ---------- ühe juuksuriõpilase nädal ---------- */

	function renderStaffWeek(d) {
		var s = d.staff.filter(function (x) { return x.id === state.staff; })[0];
		var names = ['E', 'T', 'K', 'N', 'R', 'L', 'P'];
		function dm(ds) { var dt = new Date(ds + 'T12:00:00'); return dt.getDate() + '.' + pad(dt.getMonth() + 1); }
		var sub = [s.level_name, s.discount_pct ? '−' + s.discount_pct + '%' : '', s.extra_min ? '+' + s.extra_min + ' min' : ''].filter(Boolean).join(' · ');
		body.appendChild(h('h2', { class: 'sb-p-title' }, s.name + ': nädal ' + dm(d.days[0].date) + '–' + dm(d.days[6].date) + '.' + d.days[6].date.slice(0, 4),
			sub ? h('span', { class: 'sb-p-head-sub' }, sub) : null));
		if (d.days.some(function (day) { return day.draft; })) body.appendChild(h('p', { class: 'sb-p-draft' }, 'Märkega "pole avalik" päevade töögraafik on salvestatud, aga klientidele veel avalikuks tegemata.'));
		var table = h('table', { class: 'widefat sb-p-week sb-p-staffweek' });
		table.appendChild(h('thead', null, h('tr', null, d.days.map(function (day, i) {
			return h('th', { scope: 'col', class: day.date === C.today ? 'is-today' : '' },
				h('button', { type: 'button', class: 'sb-link', title: 'Ava päevaplaan', onclick: function () { state.date = day.date; state.view = 'day'; load(); } }, names[i] + ' ' + dm(day.date)),
				day.draft ? h('span', { class: 'sb-p-draft-tag' }, 'pole avalik') : null);
		}))));
		table.appendChild(h('tbody', null, h('tr', null, d.days.map(function (day) {
			var work = day.work[s.id] || [];
			var list = day.bookings.filter(function (b) { return b.staff_id === s.id; });
			return h('td', { class: work.length ? '' : 'is-off' },
				h('div', { class: 'sb-p-w-hours' }, work.length ? work.map(function (r) { return hm(r[0]) + '–' + hm(r[1]); }).join(', ') : 'vaba'),
				list.map(function (b) {
					return h('button', { type: 'button', class: 'sb-p-w-item is-' + b.status, onclick: function () { openBooking(b.id); } },
						h('strong', null, b.time), h('br'), b.client, h('br'), h('small', null, b.service));
				}),
				work.length && !list.length ? h('div', { class: 'sb-p-w-none' }, 'broneeringuid pole') : null);
		}))));
		body.appendChild(h('div', { class: 'sb-p-scroll' }, table));
	}

	/* ---------- dialoogid ---------- */

	function dialog(title, content, onClose) {
		var dlg = h('dialog', { class: 'sb-p-dialog', 'aria-labelledby': 'sb-dlg-title' },
			h('div', { class: 'sb-p-dlg-head' },
				h('h2', { id: 'sb-dlg-title' }, title),
				h('button', { type: 'button', class: 'sb-p-close', 'aria-label': 'Sulge', onclick: function () { dlg.close(); } }, '×')),
			content);
		dlg.addEventListener('close', function () { dlg.remove(); if (onClose) onClose(); });
		document.body.appendChild(dlg);
		dlg.showModal();
		return dlg;
	}

	function serviceSelect(selected) {
		var sel = h('select', { name: 'service_id', id: 'sb-f-service', required: true });
		(state.data.services || []).forEach(function (cat) {
			var og = h('optgroup', { label: cat.name });
			cat.services.forEach(function (s) {
				og.appendChild(h('option', { value: s.id, selected: s.id === selected }, s.name + ' (' + s.duration_label + ', ' + s.price_label + ')' + (s.hidden ? ' – peidetud' : '')));
			});
			sel.appendChild(og);
		});
		return sel;
	}

	function staffSelect(selected) {
		return h('select', { name: 'staff_id', id: 'sb-f-staff' }, state.data.staff.map(function (s) {
			return h('option', { value: s.id, selected: s.id === selected }, s.name);
		}));
	}

	function row(label, input, id) {
		return h('p', { class: 'sb-p-row' }, h('label', { for: id }, label), input);
	}

	/** Modelli valik modellide andmebaasist: teenusele sobivad, kõige kauem kutsumata ees. */
	function modelPicker(form) {
		var q = h('input', { type: 'search', id: 'sb-f-model', placeholder: 'Otsi modelli nime järgi', autocomplete: 'off' });
		var all = h('input', { type: 'checkbox', id: 'sb-f-model-all' });
		var info = h('span', { class: 'sb-p-models-info' });
		var list = h('ul', { class: 'sb-p-models' });
		var seq = 0, timer;
		function load() {
			var my = ++seq;
			var sid = form.elements.service_id ? form.elements.service_id.value : '';
			api('admin/models?service_id=' + encodeURIComponent(sid) + '&q=' + encodeURIComponent(q.value.trim()) + (all.checked ? '&all=1' : '')).then(function (r) {
				if (my !== seq) return;
				list.textContent = '';
				info.textContent = all.checked || !r.filter.length ? 'Kõik modellid' : 'Sobivad: ' + r.filter.join(', ');
				if (!r.models.length) { list.appendChild(h('li', { class: 'sb-p-models-empty' }, 'Sobivaid modelle ei leitud. Proovi linnukest "näita kõiki".')); return; }
				r.models.forEach(function (m) {
					var meta = [m.services, m.hair, m.availability ? 'sobib: ' + m.availability : '', m.last_contact ? 'viimati ühendust ' + m.last_contact : 'pole veel ühendust võetud'].filter(Boolean).join(' · ');
					list.appendChild(h('li', null, h('button', { type: 'button', onclick: function () {
						var parts = m.name.trim().split(/\s+/);
						form.elements.first_name.value = parts.shift() || '';
						form.elements.last_name.value = parts.join(' ');
						form.elements.phone.value = m.phone; form.elements.email.value = m.email;
						Array.prototype.forEach.call(list.querySelectorAll('button'), function (x) { x.classList.remove('is-picked'); });
						this.classList.add('is-picked');
					} }, h('strong', null, m.name), m.phone ? ' · ' + m.phone : '', m.status === 'Uus' ? h('span', { class: 'sb-p-tag' }, 'uus') : null, h('small', null, meta))));
				});
			}).catch(function () { if (my === seq) info.textContent = 'Modellide laadimine ebaõnnestus.'; });
		}
		q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(load, 250); });
		all.addEventListener('change', load);
		form.addEventListener('change', function (ev) { if (ev.target && ev.target.name === 'service_id') load(); });
		setTimeout(load, 0);
		return h('details', { class: 'sb-p-modelbox', open: true },
			h('summary', null, 'Vali modell andmebaasist'),
			h('p', { class: 'sb-p-models-bar' }, q, h('label', { for: 'sb-f-model-all' }, all, ' näita kõiki'), info),
			list);
	}

	/** b = olemasolev broneering või null; pre = eeltäidetud väljad. */
	function bookingDialog(b, pre) {
		var isNew = !b;
		var form = h('form', { class: 'sb-p-form' });
		form.appendChild(row('Teenus', serviceSelect(b ? b.service_id : null), 'sb-f-service'));
		form.appendChild(row('Juuksuriõpilane', staffSelect(b ? b.staff_id : pre.staff_id), 'sb-f-staff'));
		form.appendChild(h('div', { class: 'sb-p-2col' },
			row('Kuupäev', h('input', { type: 'date', name: 'date', id: 'sb-f-date', required: true, value: b ? b.date : (pre.date || state.date) }), 'sb-f-date'),
			row('Kellaaeg', h('input', { type: 'time', name: 'time', id: 'sb-f-time', required: true, step: 300, value: b ? b.time.split('–')[0] : (pre.time || '') }), 'sb-f-time')));
		var results = h('ul', { class: 'sb-p-clients' });
		if (isNew) {
			var search = h('input', { type: 'search', id: 'sb-f-search', placeholder: 'Otsi olemasolevat klienti (nimi, telefon)', autocomplete: 'off' });
			var timer;
			search.addEventListener('input', function () {
				clearTimeout(timer);
				timer = setTimeout(function () {
					results.textContent = '';
					if (search.value.trim().length < 2) return;
					api('admin/clients?q=' + encodeURIComponent(search.value.trim())).then(function (list) {
						list.forEach(function (c) {
							results.appendChild(h('li', null, h('button', { type: 'button', class: 'sb-link', onclick: function () {
								form.elements.first_name.value = c.first_name; form.elements.last_name.value = c.last_name;
								form.elements.phone.value = c.phone; form.elements.email.value = c.email; results.textContent = '';
							} }, c.first_name + ' ' + c.last_name + ' · ' + c.phone)));
						});
					});
				}, 250);
			});
			if (C.models) form.appendChild(modelPicker(form));
			form.appendChild(row('Klient', search, 'sb-f-search'));
			form.appendChild(results);
			form.appendChild(h('div', { class: 'sb-p-2col' },
				row('Eesnimi *', h('input', { name: 'first_name', id: 'sb-f-fn', required: true }), 'sb-f-fn'),
				row('Perekonnanimi', h('input', { name: 'last_name', id: 'sb-f-ln' }), 'sb-f-ln')));
			form.appendChild(h('div', { class: 'sb-p-2col' },
				row('Telefon', h('input', { name: 'phone', id: 'sb-f-ph', type: 'tel' }), 'sb-f-ph'),
				row('E-post', h('input', { name: 'email', id: 'sb-f-em', type: 'email' }), 'sb-f-em')));
		}
		form.appendChild(row('Kommentaar', h('textarea', { name: 'comment', id: 'sb-f-cm', rows: 2 }, b ? b.comment : ''), 'sb-f-cm'));
		if (isNew) form.appendChild(h('p', { class: 'description' }, 'Kui kliendil on e-post, saadetakse talle kinnitus.'));
		var err = h('div', { class: 'sb-p-err', role: 'alert' });
		form.appendChild(err);
		form.appendChild(h('p', null, h('button', { type: 'submit', class: 'button button-primary' }, isNew ? 'Loo broneering' : 'Salvesta muudatused')));
		var dlg = dialog(isNew ? 'Uus broneering' : 'Muuda broneeringut', form);
		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			err.textContent = '';
			var f = form.elements;
			var data = { service_id: +f.service_id.value, staff_id: +f.staff_id.value, date: f.date.value, time: f.time.value, comment: f.comment.value };
			if (isNew) { data.first_name = f.first_name.value; data.last_name = f.last_name.value; data.phone = f.phone.value; data.email = f.email.value; }
			var btn = form.querySelector('[type=submit]'); btn.disabled = true;
			api(isNew ? 'admin/bookings' : 'admin/bookings/' + b.id, isNew ? 'POST' : 'PATCH', data)
				.then(function () { dlg.close(); toast(isNew ? 'Broneering loodud' : 'Salvestatud'); state.date = data.date; load(); })
				.catch(function (e) { btn.disabled = false; err.textContent = e.message; });
		});
	}

	function openBooking(id) {
		api('admin/bookings/' + id).then(function (b) {
			var content = h('div', { class: 'sb-p-details' },
				h('dl', null,
					h('dt', null, 'Aeg'), h('dd', null, b.date.split('-').reverse().join('.') + ' ' + b.time),
					h('dt', null, 'Teenus'), h('dd', null, b.service, b.price ? ' · ' + b.price + (b.material ? ' + materjal' : '') : ''),
					h('dt', null, 'Klient'), h('dd', null, b.client || '—', C.canManage && C.clientUrl && b.client_id ? [' ', h('a', { href: C.clientUrl + b.client_id }, 'Kliendikaart')] : null, b.model_id ? (b.model_url ? h('a', { class: 'sb-p-tag', href: b.model_url, target: '_blank', rel: 'noopener' }, 'modell') : h('span', { class: 'sb-p-tag' }, 'modell')) : null),
					b.phone ? [h('dt', null, 'Telefon'), h('dd', null, h('a', { href: 'tel:' + b.phone }, b.phone))] : null,
					b.email ? [h('dt', null, 'E-post'), h('dd', null, h('a', { href: 'mailto:' + b.email }, b.email))] : null,
					b.comment ? [h('dt', null, 'Kommentaar'), h('dd', null, b.comment)] : null,
					h('dt', null, 'Staatus'), h('dd', null, b.status_label),
					h('dt', null, 'Allikas'), h('dd', null, b.source === 'online' ? 'online' : 'admin')));
			var dlg;
			if (C.canManage) {
				var statusRow = h('div', { class: 'sb-p-status', role: 'group', 'aria-label': 'Muuda staatust' });
				[['booked', 'Broneeritud'], ['arrived', 'Kohal'], ['no_show', 'Ei tulnud'], ['cancelled_client', 'Tühistas klient'], ['cancelled_salon', 'Tühistas salong']].forEach(function (s) {
					statusRow.appendChild(h('button', {
						type: 'button', class: 'button' + (b.status === s[0] ? ' button-primary' : ''), 'aria-pressed': b.status === s[0] ? 'true' : 'false',
						onclick: function () {
							api('admin/bookings/' + b.id, 'PATCH', { status: s[0] }).then(function () { dlg.close(); toast('Staatus: ' + s[1]); load(); }).catch(function (e) { toast(e.message, true); });
						}
					}, s[1]));
				});
				if (C.kassaUrl && ['booked', 'arrived'].indexOf(b.status) !== -1) content.appendChild(h('p', null, h('a', { class: 'button button-primary sb-p-kassa', href: C.kassaUrl + b.id }, 'Kassasse')));
				content.appendChild(h('h3', null, 'Staatus'));
				content.appendChild(statusRow);
				content.appendChild(h('p', null, h('button', { type: 'button', class: 'button', onclick: function () { dlg.close(); bookingDialog(b, {}); } }, 'Muuda aega, juuksuriõpilast või teenust')));
				content.appendChild(salesBox(b));
				if (b.history && b.history.length > 1) {
					content.appendChild(h('h3', null, 'Kliendi varasemad külastused'));
					content.appendChild(h('ul', { class: 'sb-p-history' }, b.history.map(function (x) { return h('li', null, x.date + ' · ' + x.service + ' · ' + x.staff + ' · ' + x.status); })));
				}
			}
			dlg = dialog(b.client || 'Broneering', content);
		}).catch(function (e) { toast(e.message, true); });
	}

	/* Materjal ja tooted broneeringu juures (lähevad juuksuriõpilase tasu arvestusse). */
	function salesBox(b) {
		var items = state.data.sale_items || { material: [], product: [] };
		var box = h('div', { class: 'sb-p-sales' });
		var list = h('ul', { class: 'sb-p-sales-list' });
		var err = h('div', { class: 'sb-p-err', role: 'alert' });
		function draw(sales) {
			list.textContent = '';
			var sum = 0;
			sales.forEach(function (s) {
				sum += s.total_cents;
				list.appendChild(h('li', null, s.label + ' · ' + s.qty_label + ' · ' + s.total_label + ' ',
					h('button', { type: 'button', class: 'sb-link', 'aria-label': 'Eemalda ' + s.label, onclick: function () {
						api('admin/sales/' + s.id, 'DELETE').then(function () { draw(sales.filter(function (x) { return x.id !== s.id; })); }).catch(function (e) { err.textContent = e.message; });
					} }, 'eemalda')));
			});
			if (!sales.length) list.appendChild(h('li', { class: 'sb-p-hint' }, 'Midagi pole veel lisatud.'));
			else list.appendChild(h('li', null, h('strong', null, 'Kokku: ' + (sum / 100).toFixed(2).replace('.', ',') + ' €')));
		}
		var sel = h('select', { id: 'sb-sale-item', 'aria-label': 'Materjal või toode' },
			h('optgroup', { label: 'Materjal (grammides)' }, items.material.map(function (m) { return h('option', { value: m.key }, m.name); })),
			items.product.length ? h('optgroup', { label: 'Tooted (tükkides)' }, items.product.map(function (p) { return h('option', { value: p.key }, p.name); })) : null);
		var qty = h('input', { id: 'sb-sale-qty', type: 'text', inputmode: 'decimal', placeholder: 'kogus', 'aria-label': 'Kogus (grammi või tükki)', style: 'width:90px' });
		var add = h('button', { type: 'button', class: 'button', onclick: function () {
			err.textContent = '';
			api('admin/sales', 'POST', { booking_id: b.id, item: sel.value, qty: qty.value })
				.then(function (r) { qty.value = ''; draw(r.sales); })
				.catch(function (e) { err.textContent = e.message; });
		} }, 'Lisa');
		box.appendChild(h('h3', null, 'Materjal ja tooted'));
		box.appendChild(list);
		box.appendChild(h('div', { class: 'sb-p-sales-add' }, sel, qty, add));
		if (items.shop) box.appendChild(shopSearch(b, draw, err));
		box.appendChild(err);
		draw(b.sales || []);
		return box;
	}

	/* Toote otsing veebipoest: nimi, tootekood või skännitud triipkood. Müük arvatakse poe laost maha. */
	function shopSearch(b, draw, err) {
		var results = h('ul', { class: 'sb-p-shop-results', 'aria-live': 'polite' });
		var input = h('input', { id: 'sb-shop-q', type: 'search', placeholder: 'nimi, triipkood või tootekood', autocomplete: 'off' });
		function sell(item, n) {
			err.textContent = '';
			results.textContent = '';
			results.appendChild(h('li', { class: 'sb-p-hint' }, 'Salvestan müüki…'));
			api('admin/sales', 'POST', { booking_id: b.id, item: item.key, qty: n })
				.then(function (r) { results.textContent = ''; input.value = ''; input.focus(); draw(r.sales); toast('Müüdud: ' + item.name); })
				.catch(function (e) { results.textContent = ''; err.textContent = e.message; });
		}
		function search() {
			var q = input.value.trim();
			err.textContent = '';
			results.textContent = '';
			if (q.length < 2) return;
			results.appendChild(h('li', { class: 'sb-p-hint' }, 'Otsin poest…'));
			api('admin/shop-search?q=' + encodeURIComponent(q)).then(function (list) {
				results.textContent = '';
				if (!list.length) { results.appendChild(h('li', { class: 'sb-p-hint' }, 'Poest ei leitud midagi.')); return; }
				list.forEach(function (it) {
					var n = h('input', { type: 'number', min: 1, value: 1, 'aria-label': 'Kogus: ' + it.name, style: 'width:60px' });
					results.appendChild(h('li', null,
						h('span', { class: 'sb-p-shop-name' }, it.name, h('span', { class: 'sb-p-hint' }, ' ' + [it.gtin || it.sku, it.price_label, it.stock_label].filter(Boolean).join(' · '))),
						n,
						h('button', { type: 'button', class: 'button', disabled: !it.in_stock, onclick: function () { sell(it, Math.max(1, parseInt(n.value, 10) || 1)); } }, it.in_stock ? 'Müü' : 'Otsas')));
				});
			}).catch(function (e) { results.textContent = ''; err.textContent = e.message; });
		}
		// Skänner saadab koodi järel Enteri; see ei tohi dialoogi sulgeda ega vormi saata.
		input.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); search(); } });
		return h('div', { class: 'sb-p-shop' },
			h('label', { for: 'sb-shop-q' }, 'Toode veebipoe laost'),
			h('div', { class: 'sb-p-sales-add' }, input, h('button', { type: 'button', class: 'button', onclick: search }, 'Otsi')),
			results);
	}

	function blockDialog(pre) {
		var form = h('form', { class: 'sb-p-form' });
		var sel = h('select', { name: 'staff_id', id: 'sb-b-staff' }, h('option', { value: 0 }, 'Kogu salong'), state.data.staff.map(function (s) { return h('option', { value: s.id, selected: s.id === pre.staff_id }, s.name); }));
		form.appendChild(row('Kellele', sel, 'sb-b-staff'));
		form.appendChild(h('div', { class: 'sb-p-2col' },
			row('Alates kuupäev', h('input', { type: 'date', name: 'date', id: 'sb-b-date', required: true, value: state.date }), 'sb-b-date'),
			row('Kell', h('input', { type: 'time', name: 'from', id: 'sb-b-from', required: true, value: '10:00' }), 'sb-b-from')));
		form.appendChild(h('div', { class: 'sb-p-2col' },
			row('Kuni kuupäev', h('input', { type: 'date', name: 'to_date', id: 'sb-b-todate', required: true, value: state.date }), 'sb-b-todate'),
			row('Kell', h('input', { type: 'time', name: 'to', id: 'sb-b-to', required: true, value: '19:00' }), 'sb-b-to')));
		form.appendChild(row('Põhjus', h('input', { name: 'reason', id: 'sb-b-reason', placeholder: 'nt koolitus, puhkus' }), 'sb-b-reason'));
		var err = h('div', { class: 'sb-p-err', role: 'alert' });
		form.appendChild(err);
		form.appendChild(h('p', { class: 'description' }, 'Blokeeritud ajale online-broneeringuid ei tule. Olemasolevaid broneeringuid see ei muuda.'));
		form.appendChild(h('p', null, h('button', { type: 'submit', class: 'button button-primary' }, 'Blokeeri')));
		var dlg = dialog('Blokeeri aeg', form);
		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			var f = form.elements;
			api('admin/blocks', 'POST', { staff_id: +f.staff_id.value, date: f.date.value, from: f.from.value, to_date: f.to_date.value, to: f.to.value, reason: f.reason.value })
				.then(function () { dlg.close(); toast('Aeg blokeeritud'); load(); })
				.catch(function (e) { err.textContent = e.message; });
		});
	}

	function blockInfo(b) {
		var dlg = dialog('Blokeeritud aeg', h('div', null,
			h('p', null, hm(b.start_min) + '–' + hm(b.end_min) + (b.reason ? ' · ' + b.reason : '')),
			h('p', null, h('button', { type: 'button', class: 'button', onclick: function () {
				api('admin/blocks/' + b.id, 'DELETE').then(function () { dlg.close(); toast('Blokeering eemaldatud'); load(); }).catch(function (e) { toast(e.message, true); });
			} }, 'Eemalda blokeering'))));
	}

	load();
})();
