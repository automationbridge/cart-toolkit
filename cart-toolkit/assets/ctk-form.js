/**
 * Cart Toolkit form: the price picker above FluentCart's checkout.
 *
 * Picking a price asks the server to swap it into this form's cart, through
 * FluentCart's own checkout route and nonce, then reloads the page so the
 * summary and the payment box are rebuilt for the new price.
 */
(function () {
	'use strict';

	var cfg = window.CTK_FORM;
	if (!cfg) {
		return;
	}

	function showError(picker, message) {
		var box = picker.querySelector('.ctk-picker-error');
		if (box) {
			box.textContent = message;
		}
	}

	document.addEventListener('change', function (event) {
		var input = event.target;
		if (!input.matches || !input.matches('[data-ctk-option]')) {
			return;
		}
		var picker = input.closest('.ctk-picker');
		var previous = picker.getAttribute('data-ctk-current') || '';
		picker.classList.add('is-busy');

		var url = new URL(cfg.ajax, window.location.href);
		url.searchParams.set('action', 'fluent_cart_checkout_routes');
		url.searchParams.set('fc_checkout_action', 'ctk_switch_option');
		url.searchParams.set('fct_cart_hash', cfg.hash);

		var body = new URLSearchParams();
		body.set('form', cfg.form);
		body.set('option', input.value);

		fetch(url.toString(), {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': cfg.nonce,
				'Content-Type': 'application/x-www-form-urlencoded'
			},
			body: body.toString()
		})
			.then(function (response) {
				return response.json().then(function (json) {
					return { ok: response.ok, json: json };
				});
			})
			.then(function (result) {
				if (result.ok && result.json && result.json.switched) {
					window.location.reload();
					return;
				}
				throw new Error((result.json && result.json.message) || cfg.error);
			})
			.catch(function (error) {
				picker.classList.remove('is-busy');
				if (previous) {
					var back = picker.querySelector('[data-ctk-option][value="' + previous + '"]');
					if (back) {
						back.checked = true;
					}
				}
				showError(picker, error && error.message ? error.message : cfg.error);
			});
	});

	// Remember the price in the cart, so a failed switch can put it back.
	document.querySelectorAll('.ctk-picker').forEach(function (picker) {
		var checked = picker.querySelector('[data-ctk-option]:checked');
		if (checked) {
			picker.setAttribute('data-ctk-current', checked.value);
		}
	});
})();
