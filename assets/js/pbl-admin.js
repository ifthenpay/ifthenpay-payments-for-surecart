/**
 * ifthenpay | Payments for SureCart — ifthenpay Gateway admin JS.
 *
 * Two responsibilities only:
 *   1. Re-fetch and re-render the methods table + default-method field the
 *      moment the Gateway Key dropdown changes, so the merchant sees the new
 *      Gateway Key's methods live instead of having to Save + reload.
 *   2. Send an ifthenpay support activation request when "Activate Method"
 *      is clicked on a method that isn't provisioned yet on this key.
 * @param $
 */
(function ($) {
	'use strict';

	const cfg = typeof iftpScPblAdmin !== 'undefined' ? iftpScPblAdmin : {};
	const ajaxUrl =
		cfg.ajaxUrl || (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
	const nonce = cfg.nonce || '';
	const i18n = cfg.i18n || {};

	function methodsWrapper() {
		return $('#iftp-sc-methods-wrapper');
	}

	function defaultMethodWrapper() {
		return $('#iftp-sc-default-method-wrapper');
	}

	function loadMethodsTable(gatewayKey) {
		if (!gatewayKey) {
			return;
		}

		methodsWrapper().html(
			'<sc-alert type="info" open>' +
				(i18n.loading || 'Loading payment methods…') +
				'</sc-alert>'
		);

		$.post(
			ajaxUrl,
			{
				action: 'iftp_sc_get_methods_table',
				nonce: nonce,
				gateway_key: gatewayKey,
			},
			null,
			'json'
		)
			.done(function (res) {
				if (res && res.success) {
					methodsWrapper().html(res.data.table_html || '');
					defaultMethodWrapper().html(res.data.default_html || '');
					return;
				}
				const msg =
					(res && res.data && res.data.message) ||
					i18n.error ||
					'Something went wrong. Please try again.';
				methodsWrapper().html(
					'<sc-alert type="danger" open>' + msg + '</sc-alert>'
				);
			})
			.fail(function () {
				methodsWrapper().html(
					'<sc-alert type="danger" open>' +
						(i18n.error || 'Something went wrong. Please try again.') +
						'</sc-alert>'
				);
			});
	}

	$(document).on('change', '#gateway_key', function () {
		loadMethodsTable($(this).val() || '');
	});

	$(document).on('click', '.iftp-sc-activate-method-btn', function (e) {
		e.preventDefault();

		const $btn = $(this);
		const entity = $btn.data('entity');
		const gatewayKey = $('#gateway_key').val() || '';

		$btn.attr('disabled', 'disabled').text(i18n.activating || 'Sending…');

		$.post(
			ajaxUrl,
			{
				action: 'iftp_sc_activate_method',
				nonce: nonce,
				entity: entity,
				gateway_key: gatewayKey,
			},
			null,
			'json'
		)
			.done(function (res) {
				const msg =
					(res && res.data && res.data.message) ||
					(res && res.success
						? i18n.activationSent
						: i18n.error) ||
					'Request sent.';
				$btn.replaceWith(
					'<em class="iftp-sc-methods-table__activation-note">' +
						msg +
						'</em>'
				);
			})
			.fail(function () {
				$btn
					.removeAttr('disabled')
					.text(i18n.activateMethod || 'Activate Method');
			});
	});
})(jQuery);
