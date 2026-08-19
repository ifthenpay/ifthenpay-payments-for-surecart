/**
 * Editor registration for `ifthenpay-surecart/ifthenpay-order-payment-details`.
 *
 * No build step — this plugin doesn't use one anywhere else, so this stays
 * plain JS against WordPress's already-enqueued globals rather than adding a
 * webpack/wp-scripts pipeline for one block. The preview uses core's
 * ServerSideRender so the editor always shows exactly what render_callback
 * (Frontend\OrderPaymentDetailsBlock::render()) produces — no separate "edit"
 * markup to keep in sync with the real front-end output.
 */
( function ( blocks, element, serverSideRender, blockEditor, i18n ) {
	var el = element.createElement;
	var __ = i18n.__;

	blocks.registerBlockType( 'ifthenpay-surecart/ifthenpay-order-payment-details', {
		icon: 'money-alt',
		edit: function ( props ) {
			var blockProps = blockEditor.useBlockProps
				? blockEditor.useBlockProps()
				: {};

			return el(
				'div',
				blockProps,
				el( serverSideRender, {
					block: 'ifthenpay-surecart/ifthenpay-order-payment-details',
					attributes: props.attributes,
					EmptyResponsePlaceholder: function () {
						return el(
							'p',
							{ style: { padding: '1em' } },
							__(
								"Open a single order's page (paid via ifthenpay) as a logged-in customer to preview this block's real content.",
								'ifthenpay-payments-for-surecart'
							)
						);
					},
				} )
			);
		},
		save: function () {

			return null;
		},
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.serverSideRender,
	window.wp.blockEditor,
	window.wp.i18n
);
