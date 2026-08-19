<?php
/**
 * Plugin bootstrap.
 *
 * @package Ifthenpay\SureCart
 */

declare(strict_types=1);

namespace Ifthenpay\SureCart;

defined( 'ABSPATH' ) || exit;

use Ifthenpay\SureCart\Admin\ProcessorsTabCard;
use Ifthenpay\SureCart\Admin\SettingsPage;
use Ifthenpay\SureCart\Checkout\CheckoutAssets;
use Ifthenpay\SureCart\Checkout\PaymentModalController;
use Ifthenpay\SureCart\Frontend\ConfirmationInstructions;
use Ifthenpay\SureCart\Frontend\OrderPaymentDetailsBlock;
use Ifthenpay\SureCart\Frontend\StatusController;
use Ifthenpay\SureCart\Repository\PendingPaymentRepository;
use Ifthenpay\SureCart\Setup\ExpiredPaymentsCron;
use Ifthenpay\SureCart\Setup\ManualPaymentMethodProvisioner;
use Ifthenpay\SureCart\Webhook\CallbackController;

/**
 * Boots every part of the integration. Kept intentionally free of business
 * logic — each collaborator owns its own hooks.
 */
class Plugin {

	/**
	 * Boots every collaborator.
	 *
	 * @return void
	 */
	public function boot(): void {
		( new PendingPaymentRepository() )->maybe_upgrade();
		( new ManualPaymentMethodProvisioner() )->boot();
		( new ExpiredPaymentsCron() )->boot();
		( new SettingsPage() )->boot();
		( new ProcessorsTabCard() )->boot();
		( new CheckoutAssets() )->boot();
		( new PaymentModalController() )->boot();
		( new ConfirmationInstructions() )->boot();
		( new OrderPaymentDetailsBlock() )->boot();
		( new StatusController() )->boot();
		( new CallbackController() )->boot();
	}
}
