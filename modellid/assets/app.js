/* Sasipea Modellid */
(function () {
	'use strict';

	// Kinnitusküsimus ohtlikele tegevustele.
	document.addEventListener('submit', function (e) {
		var f = e.target;
		if (f.matches && f.matches('form[data-confirm]') && !window.confirm(f.getAttribute('data-confirm'))) {
			e.preventDefault();
		}
	});

	// Pildi eelvaade ja max arvu kontroll.
	document.addEventListener('change', function (e) {
		var input = e.target;
		if (!input.matches || !input.matches('input[type=file]')) return;

		var max = parseInt(input.getAttribute('data-max') || '0', 10);
		if (max && input.files.length > max) {
			window.alert('Lisa kuni ' + max + ' pilti.');
			input.value = '';
			return;
		}

		if (input.hasAttribute('data-autosubmit') && input.files.length) {
			var form = input.form;
			var status = form.querySelector('.sam-upload-status');
			if (status) status.textContent = 'Laen üles…';
			form.querySelectorAll('.sam-file-btn').forEach(function (b) { b.style.opacity = '.5'; });
			form.submit();
			return;
		}

		var wrap = input.parentNode.querySelector('.sam-preview');
		if (!wrap) return;
		wrap.innerHTML = '';
		Array.prototype.forEach.call(input.files, function (file) {
			if (!/^image\//.test(file.type)) return;
			var img = document.createElement('img');
			img.alt = '';
			img.src = URL.createObjectURL(file);
			wrap.appendChild(img);
		});
	});

	// Topeltsaatmise vältimine.
	document.addEventListener('submit', function (e) {
		var f = e.target;
		if (!f.classList || !f.closest('.sam') || e.defaultPrevented) return;
		var btn = f.querySelector('button[type=submit]');
		if (btn) {
			setTimeout(function () { btn.disabled = true; }, 0);
		}
	});
})();

// Näita paroole.
document.addEventListener('change', function (e) {
	if (!e.target.matches || !e.target.matches('[data-showpass]')) return;
	var form = e.target.form;
	form.querySelectorAll('input[name=current_pass], input[name=new_pass], input[name=new_pass2]').forEach(function (i) {
		i.type = e.target.checked ? 'text' : 'password';
	});
});
