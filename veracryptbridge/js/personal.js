/**
 * VeraCrypt settings page: while a volume is being mounted or unmounted the
 * button shows a spinner, what is happening and the seconds elapsed (VeraCrypt
 * can take up to half a minute), and the other buttons wait.
 */
(function () {
	'use strict';

	function start(form, button) {
		var root = document.getElementById('veracryptbridge');
		root.classList.add('vcb-working');
		root.querySelectorAll('button').forEach(function (b) {
			if (b !== button) {
				b.disabled = true;
			}
		});

		var spinner = document.createElement('span');
		spinner.className = 'vcb-spinner';
		spinner.setAttribute('aria-hidden', 'true');
		var label = document.createElement('span');
		label.textContent = form.dataset.busy;
		button.textContent = '';
		button.appendChild(spinner);
		button.appendChild(label);
		button.classList.add('vcb-busy');
		button.setAttribute('aria-busy', 'true');

		var began = Date.now();
		setInterval(function () {
			var seconds = Math.floor((Date.now() - began) / 1000);
			if (seconds >= 3) {
				label.textContent = form.dataset.busy + ' ' + seconds + ' s';
			}
		}, 1000);
		button.setAttribute('aria-disabled', 'true');
	}

	function init() {
		document.querySelectorAll('#veracryptbridge form[data-busy]').forEach(function (form) {
			form.addEventListener('submit', function (event) {
				if (form.dataset.sent) {
					// Already running: do not send it twice
					event.preventDefault();
					return;
				}
				form.dataset.sent = '1';
				start(form, event.submitter || form.querySelector('button[type=submit]'));
			});
		});
	}

	// Nextcloud may load the script after the page is ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	// Back button: the page comes from the cache with the buttons still busy
	window.addEventListener('pageshow', function (event) {
		if (event.persisted) {
			window.location.reload();
		}
	});
})();
