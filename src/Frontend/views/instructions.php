<?php
/**
 * Payment instructions module. Shared by two callers: appended after
 * SureCart's own order-confirmation blocks by
 * `ConfirmationInstructions::append_instructions()`, and rendered inside the
 * `ifthenpay-order-payment-details` block by `OrderPaymentDetailsBlock::render()`
 * on the customer's single-order page. `$pending` (Repository\DTO\PendingPayment)
 * is provided by whichever of the two requires this file.
 *
 * @package Ifthenpay\SureCart\Frontend
 *
 * @var \Ifthenpay\SureCart\Repository\DTO\PendingPayment $pending
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$iftp_sc_method = \Ifthenpay\SureCart\Api\IfthenpayHelper::resolve_payment_method( $pending );
?>
<sc-dashboard-module heading="<?php esc_attr_e( 'ifthenpay Order', 'ifthenpay-payments-for-surecart' ); ?>" class="iftp-sc-instructions iftp-sc-instructions--<?php echo esc_attr( $pending->method ); ?>">
	<sc-card>
		<div class="iftp-sc-instructions__method">
			<span class="iftp-sc-instructions__method-icon-wrap">
				<?php if ( '' !== $iftp_sc_method['icon_url'] ) : ?>
					<img src="<?php echo esc_url( $iftp_sc_method['icon_url'] ); ?>" alt="" class="iftp-sc-instructions__method-icon" />
				<?php else : ?>
					<?php ?>
					<i class="iftp-sc-instructions__method-icon--fallback" aria-hidden="true"></i>
				<?php endif; ?>
			</span>
			<span class="iftp-sc-instructions__method-label"><?php echo esc_html( $iftp_sc_method['label'] ); ?></span>
			<?php if ( 'PAID' === $pending->state ) : ?>
				<sc-tag type="success" class="iftp-sc-instructions__method-tag"><?php esc_html_e( 'Paid', 'ifthenpay-payments-for-surecart' ); ?></sc-tag>
			<?php endif; ?>
		</div>

		<?php if ( 'PAID' !== $pending->state ) : ?>

			<?php if ( 'multibanco' === $pending->method ) : ?>
				<?php if ( in_array( $pending->state, array( 'FAILED', 'CANCELLED', 'EXPIRED' ), true ) ) : ?>
					<sc-alert type="danger" open>
						<?php esc_html_e( 'This Multibanco reference has expired. Please place a new order to complete your payment.', 'ifthenpay-payments-for-surecart' ); ?>
					</sc-alert>
				<?php elseif ( '' === $pending->reference ) : ?>
					<sc-alert type="danger" open>
						<?php esc_html_e( "We couldn't generate your Multibanco reference. Please contact us for assistance.", 'ifthenpay-payments-for-surecart' ); ?>
					</sc-alert>
				<?php else : ?>
					<dl class="iftp-sc-instructions__list">
						<div class="iftp-sc-instructions__row">
							<dt><?php esc_html_e( 'Entity', 'ifthenpay-payments-for-surecart' ); ?></dt>
							<dd><?php echo esc_html( $pending->entity ); ?></dd>
						</div>
						<div class="iftp-sc-instructions__row">
							<dt><?php esc_html_e( 'Reference', 'ifthenpay-payments-for-surecart' ); ?></dt>
							<dd><?php echo esc_html( $pending->reference ); ?></dd>
						</div>
						<div class="iftp-sc-instructions__row">
							<dt><?php esc_html_e( 'Amount', 'ifthenpay-payments-for-surecart' ); ?></dt>
							<dd><?php echo esc_html( $pending->amount ); ?> &euro;</dd>
						</div>
						<?php if ( $pending->expires_at ) : ?>
							<div class="iftp-sc-instructions__row">
								<dt><?php esc_html_e( 'Expires', 'ifthenpay-payments-for-surecart' ); ?></dt>
								<dd><?php echo esc_html( (string) mysql2date( 'j M Y, H:i', $pending->expires_at ) ); ?></dd>
							</div>
						<?php endif; ?>
					</dl>
					<sc-alert type="info" open class="iftp-sc-instructions__note">
						<?php esc_html_e( 'Pay at any ATM or by home banking app. Your order updates automatically once we receive payment.', 'ifthenpay-payments-for-surecart' ); ?>
					</sc-alert>
				<?php endif; ?>

			<?php elseif ( 'mbway' === $pending->method ) : ?>
				<div
					class="iftp-sc-mbway-status iftp-sc-mbway-status--<?php echo esc_attr( strtolower( $pending->state ) ); ?>"
					data-iftp-sc-ref="<?php echo esc_attr( $pending->ref ); ?>"
					role="status"
					aria-live="polite"
				>
					<?php if ( '' === $pending->request_id ) : ?>
						<sc-tag type="danger" class="iftp-sc-mbway-status__pill"><?php esc_html_e( 'Request failed', 'ifthenpay-payments-for-surecart' ); ?></sc-tag>
						<p class="iftp-sc-mbway-status__message"><?php esc_html_e( "We couldn't send the MB WAY request. Please contact us for assistance.", 'ifthenpay-payments-for-surecart' ); ?></p>
					<?php elseif ( in_array( $pending->state, array( 'FAILED', 'CANCELLED', 'EXPIRED' ), true ) ) : ?>
						<sc-tag type="danger" class="iftp-sc-mbway-status__pill"><?php esc_html_e( 'Payment not completed', 'ifthenpay-payments-for-surecart' ); ?></sc-tag>
						<p class="iftp-sc-mbway-status__message"><?php esc_html_e( 'You didn\'t approve the MB WAY request in time. Create a new payment to retry again!', 'ifthenpay-payments-for-surecart' ); ?></p>
					<?php else : ?>
						<sc-tag type="warning" class="iftp-sc-mbway-status__pill"><?php esc_html_e( 'Waiting for confirmation', 'ifthenpay-payments-for-surecart' ); ?></sc-tag>
						<p class="iftp-sc-mbway-status__message"><?php esc_html_e( 'Check your phone and approve the MB WAY payment request.', 'ifthenpay-payments-for-surecart' ); ?></p>
						<sc-spinner class="iftp-sc-mbway-status__spinner"></sc-spinner>
					<?php endif; ?>
				</div>

			<?php elseif ( 'pbl' === $pending->method ) : ?>
				<div
					class="iftp-sc-mbway-status iftp-sc-mbway-status--<?php echo esc_attr( strtolower( $pending->state ) ); ?>"
					data-iftp-sc-ref="<?php echo esc_attr( $pending->ref ); ?>"
					role="status"
					aria-live="polite"
				>
					<?php if ( in_array( $pending->state, array( 'FAILED', 'CANCELLED', 'EXPIRED' ), true ) ) : ?>
						<sc-tag type="danger" class="iftp-sc-mbway-status__pill"><?php esc_html_e( 'Payment not completed', 'ifthenpay-payments-for-surecart' ); ?></sc-tag>
						<p class="iftp-sc-mbway-status__message"><?php esc_html_e( "Your payment was cancelled or didn't complete. Contact us if you'd still like to complete this payment.", 'ifthenpay-payments-for-surecart' ); ?></p>
					<?php else : ?>
						<sc-tag type="warning" class="iftp-sc-mbway-status__pill"><?php esc_html_e( 'Processing', 'ifthenpay-payments-for-surecart' ); ?></sc-tag>
						<p class="iftp-sc-mbway-status__message"><?php esc_html_e( 'Your payment is still pending! It will be marked as paid as soon as we receive it.', 'ifthenpay-payments-for-surecart' ); ?></p>
						<?php if ( '' !== $pending->redirect_url ) : ?>
							<p class="iftp-sc-mbway-status__message">
								<?php esc_html_e( "Haven't paid yet? You can still complete it below.", 'ifthenpay-payments-for-surecart' ); ?>
							</p>
							<sc-button type="primary" size="medium" href="<?php echo esc_url( $pending->redirect_url ); ?>" target="_blank" rel="noopener" class="iftp-sc-mbway-status__action">
								<?php esc_html_e( 'Complete your payment', 'ifthenpay-payments-for-surecart' ); ?>
								<sc-icon slot="suffix" name="external-link"></sc-icon>
							</sc-button>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>

		<?php endif; ?>
	</sc-card>
</sc-dashboard-module>
<?php if ( in_array( $pending->method, array( 'mbway', 'pbl' ), true ) && ! in_array( $pending->state, array( 'PAID', 'CANCELLED', 'EXPIRED', 'FAILED' ), true ) ) : ?>
	<script type="application/json" id="iftp-sc-status-data">
	<?php
	echo wp_json_encode(
		array(
			'ref'        => $pending->ref,
			'checkoutId' => $pending->checkout_id,
			'restUrl'    => esc_url_raw( rest_url( 'ifthenpay-surecart/v1' ) ),
		)
	);
	?>
															</script>
<?php endif; ?>
