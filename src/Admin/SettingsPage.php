<?php
/**
 * Settings screen for the ifthenpay integration.
 *
 * @package Ifthenpay\SureCart\Admin
 */

declare(strict_types=1);

namespace Ifthenpay\SureCart\Admin;

defined( 'ABSPATH' ) || exit;

use Ifthenpay\SureCart\Api\GatewayCatalogClient;
use Ifthenpay\SureCart\Api\IfmbAccountsClient;
use Ifthenpay\SureCart\Api\IfthenpayClient;
use Ifthenpay\SureCart\Api\IfthenpayHelper;
use Ifthenpay\SureCart\Mail\IfthenpayEmailHelper;
use Ifthenpay\SureCart\Repository\DTO\PendingPayment;
use Ifthenpay\SureCart\Repository\PendingPaymentRepository;
use Ifthenpay\SureCart\Setup\ManualPaymentMethodProvisioner;

/**
 * Settings screen, registered as a SureCart submenu (parent slug
 * `sc-dashboard`) so it appears alongside Orders/Products/Settings in
 * SureCart's own admin sidebar, rather than as a standalone top-level menu.
 *
 * Four tabs, navigated via a left-hand `sc-tab` list mirroring
 * `admin.php?page=sc-settings`'s own `#sc-nav`:
 * - **Methods**: activates/deactivates each method at checkout (independent
 *   of its configuration — a method can only be activated once connected).
 * - **ifthenpay Gateway**: the Backoffice-Key-connect + Gateway-Key + methods-table
 *   flow used by `ifthenpay-payments-for-contactform7`.
 * - **MB WAY** / **Multibanco**: each a standalone key, no Backoffice Key
 *   involved (mirrors `multibanco-ifthen-software-gateway-for-woocommerce`).
 *
 * SureCart's own Settings screen (`views/admin/settings-page.php`) is a
 * client-rendered SPA (`#sc-settings-app`) with no extension point for
 * classic form fields, so this page is server-rendered PHP — but it enqueues
 * SureCart's own `surecart-components` bundle and design-token stylesheet so
 * it matches SureCart's real look instead of plain wp-admin chrome.
 */
class SettingsPage {

	private const PAGE_SLUG      = 'iftp-sc-settings';
	private const PARENT_SLUG    = 'sc-dashboard';
	private const TAB_METHODS    = 'methods';
	private const TAB_PBL        = 'pbl';
	private const TAB_MBWAY      = 'mbway';
	private const TAB_MULTIBANCO = 'multibanco';
	private const TAB_ENTRIES    = 'entries';
	private const NONCE_ACTION   = 'iftp_sc_admin_action';
	private const ENTRIES_PER_PAGE = 20;

	/**
	 * Fetches the ifthenpay Gateway method catalog.
	 *
	 * @var GatewayCatalogClient
	 */
	private GatewayCatalogClient $catalog;

	/**
	 * Fetches the Backoffice's named Multibanco/MB WAY accounts.
	 *
	 * @var IfmbAccountsClient
	 */
	private IfmbAccountsClient $accounts;

	/**
	 * HTTP client for the ifthenpay API.
	 *
	 * @var IfthenpayClient
	 */
	private IfthenpayClient $client;

	/**
	 * Creates/verifies/(un)archives the three Manual Payment Methods.
	 *
	 * @var ManualPaymentMethodProvisioner
	 */
	private ManualPaymentMethodProvisioner $provisioner;

	/**
	 * Reads payment rows for the Entries tab.
	 *
	 * @var PendingPaymentRepository
	 */
	private PendingPaymentRepository $entries;

	/**
	 * Constructor.
	 *
	 * @param GatewayCatalogClient|null           $catalog     Injected for testability.
	 * @param IfmbAccountsClient|null             $accounts    Injected for testability.
	 * @param IfthenpayClient|null                $client      Injected for testability.
	 * @param ManualPaymentMethodProvisioner|null $provisioner Injected for testability.
	 * @param PendingPaymentRepository|null       $entries     Injected for testability.
	 */
	public function __construct( ?GatewayCatalogClient $catalog = null, ?IfmbAccountsClient $accounts = null, ?IfthenpayClient $client = null, ?ManualPaymentMethodProvisioner $provisioner = null, ?PendingPaymentRepository $entries = null ) {
		$this->catalog     = $catalog ?? new GatewayCatalogClient();
		$this->accounts    = $accounts ?? new IfmbAccountsClient();
		$this->client      = $client ?? new IfthenpayClient();
		$this->provisioner = $provisioner ?? new ManualPaymentMethodProvisioner();
		$this->entries     = $entries ?? new PendingPaymentRepository();
	}

	/**
	 * Registers all hooks this collaborator owns.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'ensure_secret_key' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_order_back_button_script' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_action( 'admin_post_iftp_sc_save_methods', array( $this, 'handle_save_methods' ) );
		add_action( 'admin_post_iftp_sc_save_mbway', array( $this, 'handle_save_mbway' ) );
		add_action( 'admin_post_iftp_sc_save_multibanco', array( $this, 'handle_save_multibanco' ) );
		add_action( 'admin_post_iftp_sc_connect_backoffice', array( $this, 'handle_connect_backoffice' ) );
		add_action( 'admin_post_iftp_sc_disconnect_backoffice', array( $this, 'handle_disconnect_backoffice' ) );
		add_action( 'admin_post_iftp_sc_save_pbl_config', array( $this, 'handle_save_pbl_config' ) );
		add_action( 'admin_post_iftp_sc_clear_cache', array( $this, 'handle_clear_cache' ) );
		add_action( 'wp_ajax_iftp_sc_get_methods_table', array( $this, 'ajax_get_methods_table' ) );
		add_action( 'wp_ajax_iftp_sc_activate_method', array( $this, 'ajax_activate_method' ) );
	}

	/**
	 * The Multibanco/MB WAY/Pay-by-Link callbacks are all registered with
	 * ifthenpay (via `IfthenpayClient::activate_callback()`) under the same
	 * random per-site secret — auto-generating it removes an easy-to-skip
	 * manual step and matches the pattern used by
	 * `multibanco-ifthen-software-gateway-for-woocommerce` (`secret_key`,
	 * `wp_generate_password( 32, false )`, auto-created on first activation).
	 *
	 * @return void
	 */
	public function ensure_secret_key(): void {
		if ( '' === (string) get_option( 'iftp_sc_secret_key', '' ) ) {
			update_option( 'iftp_sc_secret_key', wp_generate_password( 32, false, false ), false );
		}
	}

	/**
	 * Registers the ifthenpay submenu under SureCart's own admin menu.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'ifthenpay', 'ifthenpay-payments-for-surecart' ),
			__( 'ifthenpay', 'ifthenpay-payments-for-surecart' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueues admin assets only on our own settings screen.
	 *
	 * @return void
	 */
	public function maybe_enqueue_assets(): void {
		if ( ! $this->is_our_screen() ) {
			return;
		}


		wp_enqueue_style( 'surecart-themes-default' );
		wp_enqueue_style( 'iftp-sc-admin', IFTP_SC_CSS_URL . '/admin.css', array( 'surecart-themes-default' ), ifthenpay_sc_asset_version( 'css/admin.css' ) );


		wp_enqueue_script( 'surecart-components' );

		if ( self::TAB_ENTRIES === $this->current_tab() ) {
			wp_enqueue_script( 'iftp-sc-entries-row-click', IFTP_SC_JS_URL . '/entries-row-click.js', array(), ifthenpay_sc_asset_version( 'js/entries-row-click.js' ), true );


			wp_enqueue_script( 'iftp-sc-entries-restore', IFTP_SC_JS_URL . '/entries-restore.js', array(), ifthenpay_sc_asset_version( 'js/entries-restore.js' ), false );
		}

		if ( self::TAB_PBL === $this->current_tab() ) {
			wp_enqueue_script( 'iftp-sc-pbl-admin', IFTP_SC_JS_URL . '/pbl-admin.js', array( 'jquery' ), ifthenpay_sc_asset_version( 'js/pbl-admin.js' ), true );
			wp_localize_script(
				'iftp-sc-pbl-admin',
				'iftpScPblAdmin',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
					'i18n'    => array(
						'loading'         => __( 'Loading payment methods…', 'ifthenpay-payments-for-surecart' ),
						'error'           => __( 'Something went wrong. Please try again.', 'ifthenpay-payments-for-surecart' ),
						'activating'      => __( 'Sending…', 'ifthenpay-payments-for-surecart' ),
						'activationSent'  => __( 'Request sent to ifthenpay support.', 'ifthenpay-payments-for-surecart' ),
						'activateMethod'  => __( 'Activate Method', 'ifthenpay-payments-for-surecart' ),
					),
				)
			);
		}
	}

	/**
	 * Whether the current admin screen is our settings page.
	 *
	 * @return bool
	 */
	private function is_our_screen(): bool {
		return isset( $_GET['page'] ) && self::PAGE_SLUG === sanitize_text_field( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen-context check, no state changes.
	}

	/**
	 * On SureCart's own order edit screen, when reached via the `iftp_from=orders`
	 * link built by `render_entry_payment_id()`, enqueues a tiny script that
	 * repoints the screen's native back button (`<sc-button href="admin.php?page=sc-orders">`)
	 * at our Entries tab instead of SureCart's order list — so someone drilling
	 * into an order from ifthenpay's own list lands back where they started.
	 *
	 * @return void
	 */
	public function maybe_enqueue_order_back_button_script(): void {
		if ( ! $this->is_surecart_order_screen_from_entries() ) {
			return;
		}

		wp_enqueue_script(
			'iftp-sc-order-back-button',
			IFTP_SC_JS_URL . '/order-back-button.js',
			array(),
			ifthenpay_sc_asset_version( 'js/order-back-button.js' ),
			true
		);

		wp_localize_script(
			'iftp-sc-order-back-button',
			'iftpScOrderBack',
			array(
				'entriesUrl' => $this->tab_url( self::TAB_ENTRIES ),
			)
		);
	}

	/**
	 * Whether the current screen is SureCart's order edit screen, reached from
	 * our Entries tab (`admin.php?page=sc-orders&action=edit&id=...&iftp_from=orders`).
	 *
	 * @return bool
	 */
	private function is_surecart_order_screen_from_entries(): bool {
		if ( ! isset( $_GET['page'], $_GET['action'], $_GET['iftp_from'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen-context check, no state changes.
			return false;
		}

		return 'sc-orders' === sanitize_text_field( wp_unslash( $_GET['page'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen-context check, no state changes.
			&& 'edit' === sanitize_text_field( wp_unslash( $_GET['action'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen-context check, no state changes.
			&& 'orders' === sanitize_text_field( wp_unslash( $_GET['iftp_from'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen-context check, no state changes.
	}

	/**
	 * The active tab, defaulting to Methods.
	 *
	 * @return string
	 */
	private function current_tab(): string {
		$valid = array( self::TAB_METHODS, self::TAB_PBL, self::TAB_MBWAY, self::TAB_MULTIBANCO, self::TAB_ENTRIES );
		$tab   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : self::TAB_METHODS; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection, no state changes.
		return in_array( $tab, $valid, true ) ? $tab : self::TAB_METHODS;
	}

	/**
	 * Renders a payment-method icon — SureCart's own `<sc-icon>` component for
	 * every real icon name, except `link`, which every call site here only
	 * ever passes as a stand-in for "no specific method known yet" (the PBL
	 * nav/tab icon before a sub-method is chosen, and `entry_method_badge()`'s
	 * fallback for an unpaid/pre-upgrade PBL row). ifthenpay's own icon font
	 * (`.iftp-sc-icon-logo` above) has a real glyph for exactly that, so it
	 * replaces SureCart's generic chain-link glyph everywhere `link` shows up
	 * instead of just at the one call site that prompted this.
	 *
	 * @param string $icon        `sc-icon` name, or `link` for the ifthenpay glyph.
	 * @param int    $size        Icon box size in px, or `0` to leave it unset (inherits the caller's own default sizing, e.g. `render_page_header()`'s title).
	 * @param string $slot        Optional `slot` attribute (e.g. `prefix`, for slotting into `<sc-tab>`/`<sc-button>`).
	 * @param string $extra_class Extra class(es) appended to the ifthenpay glyph only (e.g. the Orders table's brand-blue override) — has no effect on real `sc-icon` names.
	 *
	 * @return void
	 */
	private function render_method_icon( string $icon, int $size = 0, string $slot = '', string $extra_class = '' ): void {
		$slot_attr = '' !== $slot ? sprintf( ' slot="%s"', esc_attr( $slot ) ) : '';

		if ( 'link' === $icon ) {
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- built entirely from esc_attr()'d parts above.
			printf(
				'<i class="iftp-sc-icon--ifthenpay%1$s"%2$s%3$s></i>',
				'' !== $extra_class ? ' ' . esc_attr( $extra_class ) : '',
				$slot_attr,
				$size > 0 ? sprintf( ' style="font-size: %dpx;"', $size ) : ''
			);
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- built entirely from esc_attr()'d parts above.
		printf(
			'<sc-icon name="%1$s"%2$s%3$s></sc-icon>',
			esc_attr( $icon ),
			$slot_attr,
			$size > 0 ? sprintf( ' style="width: %1$dpx; height: %1$dpx;"', $size ) : ''
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * The "Account cache cleared" flash, shown once after `iftp_sc_clear_cache`
	 * redirects back with `?iftp_notice=cache_cleared`.
	 *
	 * Rendered inside `.iftp-sc-tab-content`/`.iftp-sc-admin-card` (the flex
	 * row's content column), not as a sibling above `.iftp-sc-layout` — the
	 * left nav (`#sc-nav`) shares that same row via `align-items: stretch`, so
	 * anything added above the row pushes the nav down with it. Keeping the
	 * notice inside the content column only grows that column.
	 *
	 * @return void
	 */
	private function render_cache_cleared_notice(): void {
		if ( ! isset( $_GET['iftp_notice'] ) || 'cache_cleared' !== sanitize_key( wp_unslash( $_GET['iftp_notice'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only UX flash, no state changes.
			return;
		}
		?>
		<sc-alert type="success" open closable style="margin-bottom: 20px;">
			<?php esc_html_e( 'Account cache cleared.', 'ifthenpay-payments-for-surecart' ); ?>
		</sc-alert>
		<?php
	}

	/**
	 * The left-nav items, in display order.
	 *
	 * @return array<string, array{label: string, icon: string}>
	 */
	private function nav_items(): array {
		return array(
			self::TAB_METHODS    => array(
				'label' => __( 'Settings', 'ifthenpay-payments-for-surecart' ),
				'icon'  => 'settings',
			),
			self::TAB_PBL        => array(
				'label' => __( 'ifthenpay Gateway', 'ifthenpay-payments-for-surecart' ),
				'icon'  => 'link',
			),
			self::TAB_MBWAY      => array(
				'label' => __( 'MB WAY', 'ifthenpay-payments-for-surecart' ),
				'icon'  => 'smartphone',
			),
			self::TAB_MULTIBANCO => array(
				'label' => __( 'Multibanco', 'ifthenpay-payments-for-surecart' ),
				'icon'  => 'hash',
			),
			self::TAB_ENTRIES    => array(
				'label' => __( 'ifthenpay Orders', 'ifthenpay-payments-for-surecart' ),
				'icon'  => 'list',
			),
		);
	}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab               = $this->current_tab();
		$backoffice_key    = (string) get_option( 'iftp_sc_backoffice_key', '' );
		$backoffice_linked = '' !== $backoffice_key;


		if ( $backoffice_linked && false === get_option( GatewayCatalogClient::METHODS_CACHE_OPTION, false ) ) {
			$this->catalog->refresh_available_methods_cache();
		}
		?>
		<div class="wrap iftp-sc-settings">
			<div class="sc-settings-header-container">
				<div id="sc-settings-header">
					<sc-breadcrumbs style="font-size: 16px">
						<sc-breadcrumb>
							<img style="display: block" src="<?php echo esc_url( plugins_url( 'surecart/images/logo.svg' ) ); ?>" alt="SureCart" width="125" />
						</sc-breadcrumb>
						<sc-breadcrumb>
							<img src="<?php echo esc_url( IFTP_SC_IMAGES_URL . '/logo-color.svg' ); ?>" alt="<?php esc_attr_e( 'ifthenpay', 'ifthenpay-payments-for-surecart' ); ?>" class="iftp-settings-header__logo-navbar" height="18" />
						</sc-breadcrumb>
					</sc-breadcrumbs>

					<sc-flex>
						<?php if ( $backoffice_linked ) : ?>
							<sc-button
								type="text"
								size="small"
								href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'iftp_sc_clear_cache' ), admin_url( 'admin-post.php' ) ), self::NONCE_ACTION ) ); ?>"
							>
								<sc-icon slot="prefix" name="refresh-cw" style="width: 14px; height: 14px;"></sc-icon>
								<?php esc_html_e( 'Clear Account Cache', 'ifthenpay-payments-for-surecart' ); ?>
							</sc-button>
						<?php endif; ?>
						<sc-button type="text" size="small" href="https://ifthenpay.com/" target="_blank">
							<?php esc_html_e( 'ifthenpay', 'ifthenpay-payments-for-surecart' ); ?>
							<sc-icon name="external-link" slot="suffix"></sc-icon>
						</sc-button>
						<sc-tag type="default" size="medium">
							<?php
							printf(
								/* translators: %s: plugin version */
								esc_html__( 'Version %s', 'ifthenpay-payments-for-surecart' ),
								esc_html( IFTP_SC_VERSION )
							);
							?>
						</sc-tag>
					</sc-flex>
				</div>
			</div>

			<?php if ( ! $backoffice_linked ) : ?>
				<div class="iftp-sc-layout iftp-sc-layout--gated">
					<sc-card class="iftp-sc-admin-card">
						<?php $this->render_cache_cleared_notice(); ?>
						<?php $this->render_backoffice_connect_form(); ?>
						<sc-alert type="warning" open>
							<?php esc_html_e( "You'll need to connect your ifthenpay Backoffice before you can connect and use any payment method.", 'ifthenpay-payments-for-surecart' ); ?>
						</sc-alert>
					</sc-card>
				</div>
			<?php else : ?>
				<div class="iftp-sc-layout">
					<div id="sc-nav" style="--sc-tabs-min-width: 0;">
						<?php foreach ( $this->nav_items() as $key => $item ) : ?>
							<sc-tab href="<?php echo esc_url( $this->tab_url( $key ) ); ?>" panel="" <?php echo $tab === $key ? 'active' : ''; ?>>
								<?php $this->render_method_icon( $item['icon'], 18, 'prefix', 'iftp-sc-nav-icon--ifthenpay' ); ?>
								<?php echo esc_html( $item['label'] ); ?>
							</sc-tab>
						<?php endforeach; ?>
					</div>

					<div class="iftp-sc-tab-content<?php echo self::TAB_ENTRIES === $tab ? ' iftp-sc-tab-content--wide' : ''; ?>">
						<?php $this->render_cache_cleared_notice(); ?>
						<?php
						switch ( $tab ) {
							case self::TAB_PBL:
								$this->render_pbl_tab();
								break;
							case self::TAB_MBWAY:
								$this->render_mbway_tab();
								break;
							case self::TAB_MULTIBANCO:
								$this->render_multibanco_tab();
								break;
							case self::TAB_ENTRIES:
								$this->render_entries_tab();
								break;
							default:
								$this->render_methods_tab();
								break;
						}
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Builds the URL for a given tab.
	 *
	 * @param string $tab Tab slug.
	 *
	 * @return string
	 */
	private function tab_url( string $tab ): string {
		return add_query_arg(
			array(
				'page' => self::PAGE_SLUG,
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Renders a text field using SureCart's own `.form-control`/`.input`
	 * markup and classes (`dist/components/collection/components/ui/form-control/sc-form-control.css`,
	 * `.../input/sc-input.css`) instead of plain wp-admin `form-table` rows —
	 * `<sc-input>` itself has no `formAssociated`/`ElementInternals` support,
	 * so it wouldn't submit its value via a native `<form>`; a real
	 * `<input>` styled with SureCart's classes gets the same look with a
	 * guaranteed-working submit.
	 *
	 * @param string                $id     Field id/name.
	 * @param string                $label  Field label.
	 * @param string                $value  Current value.
	 * @param string                $help   Help text shown under the field.
	 * @param array<string, string> $attrs  Extra HTML attributes (e.g. `type`, `placeholder`, `maxlength`).
	 * @param bool                  $narrow Caps the field's width instead of stretching full-width — for short values like a day count.
	 *
	 * @return void
	 */
	private function render_text_field( string $id, string $label, string $value, string $help = '', array $attrs = array(), bool $narrow = false ): void {
		$type        = $attrs['type'] ?? 'text';
		$extra_attrs = '';
		foreach ( $attrs as $attr_name => $attr_value ) {
			if ( 'type' === $attr_name ) {
				continue;
			}
			$extra_attrs .= sprintf( ' %s="%s"', esc_attr( $attr_name ), esc_attr( $attr_value ) );
		}
		?>
		<div class="form-control form-control--medium form-control--has-label<?php echo '' !== $help ? ' form-control--has-help-text' : ''; ?><?php echo $narrow ? ' form-control--narrow' : ''; ?>">
			<label class="form-control__label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			<div class="input input--medium">
				<input
					type="<?php echo esc_attr( $type ); ?>"
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $id ); ?>"
					class="input__control"
					autocomplete="off"
					value="<?php echo esc_attr( $value ); ?>"
					<?php echo $extra_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built entirely from esc_attr()'d parts above. ?>
				/>
			</div>
			<?php if ( '' !== $help ) : ?>
				<div class="form-control__help-text"><?php echo esc_html( $help ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders a select field the same way — a real native `<select>` (for
	 * the same form-participation reason as `render_text_field()`) styled to
	 * match SureCart's `.select .trigger` box (`sc-select.css`).
	 *
	 * @param string               $id       Field id, used verbatim as the field `name` too.
	 * @param string               $label    Field label.
	 * @param array<string,string> $options  Map of option value => label.
	 * @param string               $selected Currently-selected value.
	 * @param string               $help     Help text shown under the field.
	 *
	 * @return void
	 */
	private function render_select_field( string $id, string $label, array $options, string $selected, string $help = '' ): void {
		?>
		<div class="form-control form-control--medium form-control--has-label<?php echo '' !== $help ? ' form-control--has-help-text' : ''; ?>">
			<label class="form-control__label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			<div class="iftp-sc-select-wrap">
				<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $id ); ?>" class="iftp-sc-select">
					<?php foreach ( $options as $option_value => $option_label ) : ?>
						<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $selected, $option_value ); ?>>
							<?php echo esc_html( $option_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<sc-icon name="chevron-down" class="iftp-sc-select__caret" style="width: 16px; height: 16px;"></sc-icon>
			</div>
			<?php if ( '' !== $help ) : ?>
				<div class="form-control__help-text"><?php echo esc_html( $help ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders a toggle switch — a real native checkbox visually presented as
	 * a switch (`.iftp-sc-switch`), used for "is this method active at
	 * checkout" controls where a plain checkbox reads as too easy to miss in
	 * a data table.
	 *
	 * @param string $name     Field name (table-array syntax, e.g. `methods[pbl]`).
	 * @param bool   $checked  Whether the switch is on.
	 * @param bool   $disabled Whether the switch is disabled.
	 *
	 * @return void
	 */
	private function render_switch_field( string $name, bool $checked, bool $disabled = false ): void {
		?>
		<label class="iftp-sc-switch<?php echo $disabled ? ' iftp-sc-switch--disabled' : ''; ?>">
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $checked ); ?> <?php disabled( $disabled ); ?> />
			<span class="iftp-sc-switch__track"><span class="iftp-sc-switch__thumb"></span></span>
		</label>
		<?php
	}

	/**
	 * Renders the Methods tab: per-method activation toggles, only selectable
	 * once that method is connected on its own tab. This is the default
	 * landing tab.
	 *
	 * @return void
	 */
	private function render_methods_tab(): void {

		$backoffice_key = (string) get_option( 'iftp_sc_backoffice_key', '' );

		$statuses = $this->provisioner->get_all_statuses();

		$rows = array(
			'pbl'        => array(
				'label'      => __( 'ifthenpay Gateway', 'ifthenpay-payments-for-surecart' ),
				'configured' => '' !== (string) get_option( 'iftp_sc_gateway_key', '' ),
				'tab'        => self::TAB_PBL,
				'icon'       => 'link',
			),
			'mbway'      => array(
				'label'      => __( 'MB WAY', 'ifthenpay-payments-for-surecart' ),
				'configured' => '' !== (string) get_option( 'iftp_sc_mbway_key', '' ),
				'tab'        => self::TAB_MBWAY,
				'icon'       => 'smartphone',
			),
			'multibanco' => array(
				'label'      => __( 'Multibanco', 'ifthenpay-payments-for-surecart' ),
				'configured' => $this->is_multibanco_configured(),
				'tab'        => self::TAB_MULTIBANCO,
				'icon'       => 'hash',
			),
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="iftp_sc_save_methods" />
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<?php $this->render_page_header( 'settings', __( 'Settings', 'ifthenpay-payments-for-surecart' ) ); ?>

			<div class="iftp-sc-modules">
				<?php
				$this->render_module_start(
					__( 'Backoffice Key', 'ifthenpay-payments-for-surecart' ),
					__( 'Your ifthenpay Backoffice connection. Every payment method below depends on it.', 'ifthenpay-payments-for-surecart' )
				);
				$this->render_connection_card(
					'link',
					__( 'Backoffice Key', 'ifthenpay-payments-for-surecart' ),
					$this->mask_key( $backoffice_key ),
					'iftp_sc_disconnect_backoffice',
					__( 'Disconnect your ifthenpay Backoffice? All payment methods will stop working until you reconnect.', 'ifthenpay-payments-for-surecart' )
				);
				$this->render_module_end( false );

				$this->render_module_start(
					__( 'Payment methods', 'ifthenpay-payments-for-surecart' ),
					__( 'Turn a method on or off at checkout without losing its setup. Connect a method on its own tab first, then activate it here.', 'ifthenpay-payments-for-surecart' )
				);
				?>

				<?php if ( is_wp_error( $statuses ) ) : ?>
					<sc-alert type="danger" open><?php echo esc_html( $statuses->get_error_message() ); ?></sc-alert>
				<?php else : ?>
					<table class="iftp-sc-methods-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Active', 'ifthenpay-payments-for-surecart' ); ?></th>
								<th><?php esc_html_e( 'Method', 'ifthenpay-payments-for-surecart' ); ?></th>
								<th><?php esc_html_e( 'Status', 'ifthenpay-payments-for-surecart' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $key => $row ) : ?>
								<?php
								$status    = $statuses[ $key ] ?? array(
									'exists'   => false,
									'archived' => true,
								);
								$connected = $row['configured'] && $status['exists'];
								$checked   = $connected && ! $status['archived'];
								?>
								<tr>
									<td><?php $this->render_switch_field( 'methods[' . $key . ']', $checked, ! $connected ); ?></td>
									<td>
										<span class="iftp-sc-methods-table__method">
											<span class="iftp-sc-methods-table__icon">
												<?php $this->render_method_icon( $row['icon'], 'link' === $row['icon'] ? 13 : 16 ); ?>
											</span>
											<span class="iftp-sc-methods-table__method-label"><?php echo esc_html( $row['label'] ); ?></span>
										</span>
									</td>
									<td>
										<span class="iftp-sc-methods-table__status">
											<sc-tag type="<?php echo $connected ? 'success' : 'warning'; ?>" size="medium">
												<?php echo $connected ? esc_html__( 'Connected', 'ifthenpay-payments-for-surecart' ) : esc_html__( 'Not connected', 'ifthenpay-payments-for-surecart' ); ?>
											</sc-tag>
											<sc-button type="default" size="small" outline href="<?php echo esc_url( $this->tab_url( $row['tab'] ) ); ?>">
												<sc-icon slot="prefix" name="settings" style="width: 14px; height: 14px;"></sc-icon>
												<?php esc_html_e( 'Configure', 'ifthenpay-payments-for-surecart' ); ?>
											</sc-button>
										</span>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<?php $this->render_module_end(); ?>
			</div>
		</form>
		<?php
	}

	/**
	 * Renders a tab's page-level header: icon + title outside any box, and a
	 * Save button — mirroring SureCart's own Store Settings screen (its
	 * title/icon row plus top `sc-button[submit]`, both outside the
	 * `sc-dashboard-module` boxes below and separated from them by a rule
	 * line, styled via `.iftp-sc-page-header`). Must be called from *inside*
	 * the tab's `<form>` (see `render_methods_tab()` etc.), as the first
	 * element — a plain HTML `<form>` only picks up a submit button through
	 * real DOM descendance, not visual position, so rendering this before
	 * the `<form>` tag opens leaves the Save button submitting nothing.
	 *
	 * @param string $icon      `sc-icon` name for the title.
	 * @param string $title     Tab title.
	 * @param bool   $show_save Whether to show the Save button — pass `false` when called outside a `<form>` (e.g. an error state with nothing to submit).
	 *
	 * @return void
	 */
	private function render_page_header( string $icon, string $title, bool $show_save = true ): void {
		?>
		<div class="iftp-sc-page-header">
			<h3 class="iftp-sc-page-header__title">
				<?php $this->render_method_icon( $icon ); ?>
				<span><?php echo esc_html( $title ); ?></span>
			</h3>
			<?php if ( $show_save ) : ?>
				<sc-button type="primary" size="medium" submit><?php esc_html_e( 'Save', 'ifthenpay-payments-for-surecart' ); ?></sc-button>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Opens an `sc-dashboard-module` section: heading + description outside
	 * a box, followed by the `sc-card` box itself — mirroring SureCart's own
	 * "Store Details"/"Notification Settings" sections on
	 * `admin.php?page=sc-settings`. Pair with `render_module_end()`.
	 *
	 * @param string $heading     Section heading, shown outside the box.
	 * @param string $description Description text, shown outside the box under the heading.
	 *
	 * @return void
	 */
	private function render_module_start( string $heading, string $description ): void {
		?>
		<sc-dashboard-module heading="<?php echo esc_attr( $heading ); ?>" style="--sc-dashboard-module-spacing: var(--sc-spacing-large); --sc-dashbaord-module-heading-size: 1.1em; --sc-card-padding: var(--sc-spacing-x-large);">
			<span slot="description"><?php echo esc_html( $description ); ?></span>
			<sc-card>
			<?php
	}

	/**
	 * Closes an `sc-dashboard-module` section opened by `render_module_start()`,
	 * optionally with a Save button and/or help text underneath the box.
	 *
	 * @param bool   $with_save Whether to show a Save button under the box.
	 * @param string $help      Optional help text shown under the box (next to the Save button, if any).
	 *
	 * @return void
	 */
	private function render_module_end( bool $with_save = true, string $help = '' ): void {
		?>
			</sc-card>
			<?php if ( $with_save || '' !== $help ) : ?>
				<div class="iftp-sc-form__actions">
					<?php if ( $with_save ) : ?>
						<sc-button type="primary" size="medium" submit><?php esc_html_e( 'Save', 'ifthenpay-payments-for-surecart' ); ?></sc-button>
					<?php endif; ?>
					<?php if ( '' !== $help ) : ?>
						<p class="iftp-sc-field__help"><?php echo esc_html( $help ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</sc-dashboard-module>
		<?php
	}

	/**
	 * Renders the MB WAY tab: a single standalone key, chosen from the
	 * merchant's named accounts. The account picker and Save button stay
	 * visible whether or not an account is already connected — switching
	 * accounts later is just "pick a different one + Save", not a separate
	 * disconnect step.
	 *
	 * @return void
	 */
	private function render_mbway_tab(): void {
		$mbway_key      = (string) get_option( 'iftp_sc_mbway_key', '' );
		$backoffice_key = (string) get_option( 'iftp_sc_backoffice_key', '' );
		$buckets        = $this->accounts->get_classified_accounts( $backoffice_key );

		if ( is_wp_error( $buckets ) ) {
			$this->render_page_header( 'smartphone', __( 'MB WAY', 'ifthenpay-payments-for-surecart' ), false );
			?>
			<sc-alert type="danger" open><?php echo esc_html( $buckets->get_error_message() ); ?></sc-alert>
			<?php
			return;
		}

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="iftp_sc_save_mbway" />
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<?php
			$this->render_page_header( 'smartphone', __( 'MB WAY', 'ifthenpay-payments-for-surecart' ) );
			$this->render_module_start(
				__( 'MB WAY', 'ifthenpay-payments-for-surecart' ),
				__( 'Pick which account on your ifthenpay Backoffice to use. Lets customers pay with the MB WAY app.', 'ifthenpay-payments-for-surecart' )
			);
			$this->render_account_picker(
				'iftp_sc_mbway_account',
				$buckets['mbway'],
				$this->selected_conta( $buckets['mbway'], $mbway_key ),
				__( 'No MB WAY account found on your ifthenpay Backoffice.', 'ifthenpay-payments-for-surecart' ),
				IFTP_SC_IMAGES_URL . '/mbway_only_icon.svg'
			);
			$this->render_module_end(
				! empty( $buckets['mbway'] ),
				! empty( $buckets['mbway'] ) ? __( 'Connecting registers the notify-URL callback with ifthenpay automatically, and activates this method once you turn it on in Settings.', 'ifthenpay-payments-for-surecart' ) : ''
			);
			?>
		</form>
		<?php
	}

	/**
	 * Renders the Multibanco tab: a single standalone account, chosen from
	 * the merchant's named accounts — either a modern MB Key account or a
	 * legacy Entity/Sub-entity account (whose reference is computed entirely
	 * offline by `MultibancoOfflineReferenceGenerator`, since it has no MB Key
	 * to call ifthenpay's reference API with). The account picker and Save
	 * button stay visible whether or not an account is already connected —
	 * switching accounts later is just "pick a different one + Save", not a
	 * separate disconnect step.
	 *
	 * @return void
	 */
	private function render_multibanco_tab(): void {
		$backoffice_key = (string) get_option( 'iftp_sc_backoffice_key', '' );
		$buckets        = $this->accounts->get_classified_accounts( $backoffice_key );

		if ( is_wp_error( $buckets ) ) {
			$this->render_page_header( 'hash', __( 'Multibanco', 'ifthenpay-payments-for-surecart' ), false );
			?>
			<sc-alert type="danger" open><?php echo esc_html( $buckets->get_error_message() ); ?></sc-alert>
			<?php
			return;
		}


		$mb_accounts   = $buckets['modern_mb'] + $buckets['legacy_mb'];
		$selected      = $this->selected_mb_conta( $mb_accounts );
		$selected_type = '' !== $selected ? ( IfmbAccountsClient::classify( $selected )['type'] ?? '' ) : '';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="iftp_sc_save_multibanco" />
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<?php
			$this->render_page_header( 'hash', __( 'Multibanco', 'ifthenpay-payments-for-surecart' ) );
			?>
			<div class="iftp-sc-modules">
				<?php
				$this->render_module_start(
					__( 'Multibanco', 'ifthenpay-payments-for-surecart' ),
					__( 'Pick which account on your ifthenpay Backoffice to use. Lets customers pay by Multibanco reference at any ATM or via home banking.', 'ifthenpay-payments-for-surecart' )
				);
				$this->render_account_picker(
					'iftp_sc_mb_account',
					$mb_accounts,
					$selected,
					__( 'No Multibanco account found on your ifthenpay Backoffice.', 'ifthenpay-payments-for-surecart' ),
					IFTP_SC_IMAGES_URL . '/multibanco_only_icon.svg'
				);
				$this->render_module_end(
					! empty( $mb_accounts ),
					! empty( $mb_accounts ) ? __( 'Connecting registers the notify-URL callback with ifthenpay automatically, and activates this method once you turn it on in Settings.', 'ifthenpay-payments-for-surecart' ) : ''
				);
				if ( 'legacy_mb' === $selected_type ) :
					$this->render_module_start(
						__( 'Expiry', 'ifthenpay-payments-for-surecart' ),
						__( 'Controls how long a Multibanco reference stays valid before the customer can no longer pay it.', 'ifthenpay-payments-for-surecart' )
					);
					?>
					<sc-alert type="info" open><?php esc_html_e( 'This is a legacy Entity/Sub-entity account. Its references never expire, so there is no expiry to configure.', 'ifthenpay-payments-for-surecart' ); ?></sc-alert>
					<?php
					$this->render_module_end( false );
				else :
					$this->render_module_start(
						__( 'Expiry', 'ifthenpay-payments-for-surecart' ),
						__( 'Controls how long a Multibanco reference stays valid before the customer can no longer pay it.', 'ifthenpay-payments-for-surecart' )
					);
					$this->render_text_field(
						'mb_expire_days',
						__( 'Expiry days', 'ifthenpay-payments-for-surecart' ),
						(string) max( 1, (int) get_option( 'iftp_sc_mb_expire_days', 3 ) ),
						__( 'Sent to ifthenpay as the reference\'s expiry. A payment session still unpaid after this many days is also marked expired.', 'ifthenpay-payments-for-surecart' ),
						array(
							'type' => 'number',
							'min'  => '1',
							'max'  => '90',
						),
						true
					);
					$this->render_module_end( true );
				endif;
				?>
			</div>
		</form>
		<?php
	}

	/**
	 * Renders the Entries tab: a plain, read-only list of every Multibanco,
	 * MB WAY and ifthenpay Gateway payment attempt recorded in
	 * `{$wpdb->prefix}ifthenpay_sc_payments`, most recent first.
	 *
	 * @return void
	 */
	private function render_entries_tab(): void {
		$this->render_page_header( 'list', __( 'ifthenpay Orders', 'ifthenpay-payments-for-surecart' ), false );

		$paged         = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination, no state changes.
		$state_filter  = $this->entries_state_filter();
		$method_filter = $this->entries_method_filter();
		$search        = $this->entries_search();
		$has_filters   = '' !== $state_filter || '' !== $method_filter || '' !== $search;

		$total = $this->entries->count( $state_filter, $method_filter, $search );

		$paged = min( $paged, max( 1, (int) ceil( $total / self::ENTRIES_PER_PAGE ) ) );

		$offset = ( $paged - 1 ) * self::ENTRIES_PER_PAGE;
		$rows   = $this->entries->find_all( self::ENTRIES_PER_PAGE, $offset, $state_filter, $method_filter, $search );

		$this->render_module_start(
			__( 'Payment entries', 'ifthenpay-payments-for-surecart' ),
			__( 'Every Multibanco, MB WAY and ifthenpay Gateway payment attempt received through ifthenpay.', 'ifthenpay-payments-for-surecart' )
		);

		$this->render_entries_filter_bar( $state_filter, $method_filter, $search );

		if ( empty( $rows ) ) :
			?>
			<sc-alert type="info" open>
				<?php
				if ( $has_filters ) {
					esc_html_e( 'No payments match your filters.', 'ifthenpay-payments-for-surecart' );
				} else {
					esc_html_e( 'No payments yet.', 'ifthenpay-payments-for-surecart' );
				}
				?>
			</sc-alert>
			<?php
		else :
			?>
			<div class="iftp-sc-table-scroll">
				<table class="iftp-sc-methods-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Payment ID', 'ifthenpay-payments-for-surecart' ); ?></th>
							<th><?php esc_html_e( 'Method', 'ifthenpay-payments-for-surecart' ); ?></th>
							<th><?php esc_html_e( 'Request ID', 'ifthenpay-payments-for-surecart' ); ?></th>
							<th><?php esc_html_e( 'Amount', 'ifthenpay-payments-for-surecart' ); ?></th>
							<th><?php esc_html_e( 'Entity & Reference', 'ifthenpay-payments-for-surecart' ); ?></th>
							<th><?php esc_html_e( 'Phone', 'ifthenpay-payments-for-surecart' ); ?></th>
							<th><?php esc_html_e( 'Payment Link', 'ifthenpay-payments-for-surecart' ); ?></th>
							<th><?php esc_html_e( 'State', 'ifthenpay-payments-for-surecart' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<?php $order_edit_url = $this->entry_order_edit_url( $row ); ?>
							<tr<?php echo '' !== $order_edit_url ? ' class="iftp-sc-methods-table__row--clickable" data-href="' . esc_url( $order_edit_url ) . '"' : ''; ?>>
								<td><?php $this->render_entry_payment_id( $row ); ?></td>
								<td><?php $this->render_entry_method_badge( $row ); ?></td>
								<td><?php echo '' !== $row->request_id ? esc_html( $row->request_id ) : '—'; ?></td>
								<td><?php echo esc_html( number_format( (float) $row->amount, 2 ) ); ?></td>
								<td><?php echo esc_html( $this->entry_reference_display( $row ) ); ?></td>
								<td><?php echo '' !== $row->phone ? esc_html( $row->phone ) : '—'; ?></td>
								<td><?php $this->render_entry_payment_link( $row ); ?></td>
								<td><?php $this->render_entry_state_tag( $row->state ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php
			$this->render_entries_pagination( $total, $paged, $state_filter, $method_filter, $search );
		endif;

		$this->render_module_end( false );
	}

	/**
	 * Renders the Payment ID column. It always reads as link-styled text (see
	 * `.iftp-sc-methods-table__payment-id`) for a consistent look across every
	 * state, but only becomes an actual `<a>` once a matching SureCart order
	 * edit screen is known — the row itself is also made clickable to the same
	 * destination, see `render_entries_tab()`.
	 *
	 * @param PendingPayment $entry Payment row.
	 *
	 * @return void
	 */
	private function render_entry_payment_id( PendingPayment $entry ): void {
		$url = $this->entry_order_edit_url( $entry );

		if ( '' === $url ) {
			printf( '<span class="iftp-sc-methods-table__payment-id">%s</span>', esc_html( $entry->ref ) );
			return;
		}

		printf(
			'<a class="iftp-sc-methods-table__payment-id" href="%1$s">%2$s</a>',
			esc_url( $url ),
			esc_html( $entry->ref )
		);
	}

	/**
	 * Resolves a payment row to its matching SureCart order edit screen URL
	 * (`admin.php?page=sc-orders&action=edit&id=...`), or `''` if the row
	 * never became an order. `order_id` is only ever populated once a row
	 * reaches `PAID` (see `PaymentConfirmationService::store_order_id()`), so
	 * PENDING/FAILED/EXPIRED rows correctly resolve to no URL.
	 *
	 * The `iftp_from=orders` marker lets `maybe_enqueue_order_back_button_script()`
	 * detect that the order screen was reached from here, so it can repoint
	 * SureCart's own back button at this Entries tab instead of `sc-orders`.
	 *
	 * @param PendingPayment $entry Payment row.
	 *
	 * @return string
	 */
	private function entry_order_edit_url( PendingPayment $entry ): string {
		if ( '' === $entry->order_id ) {
			return '';
		}

		return (string) add_query_arg(
			'iftp_from',
			'orders',
			admin_url( 'admin.php?page=sc-orders&action=edit&id=' . $entry->order_id )
		);
	}

	/**
	 * Renders the Payment Link column — only ever populated for a `pbl` row
	 * still `PENDING` with a saved payment link (see
	 * `PaymentInitiator::initiate_pbl()`), so the merchant can hand the
	 * customer that same link again instead of generating a fresh ifthenpay
	 * Gateway session. Blank for every other method/state — a paid, failed,
	 * cancelled or expired link isn't something anyone should still be
	 * clicking.
	 *
	 * @param PendingPayment $entry Payment row.
	 *
	 * @return void
	 */
	private function render_entry_payment_link( PendingPayment $entry ): void {
		if ( 'pbl' !== $entry->method || 'PENDING' !== $entry->state || '' === $entry->redirect_url ) {
			echo '—';
			return;
		}

		printf(
			'<a class="iftp-sc-methods-table__payment-id" href="%1$s" target="_blank" rel="noopener">%2$s</a>',
			esc_url( $entry->redirect_url ),
			esc_html__( 'Open link', 'ifthenpay-payments-for-surecart' )
		);
	}

	/**
	 * Renders the Method column's icon + text badge. A ifthenpay Gateway row is
	 * always labelled "ifthenpay Gateway" (the channel actually used to pay), with
	 * the icon narrowed to the actual sub-method once the webhook has echoed
	 * back `mtd` (see `Webhook\CallbackController::handle_pbl()`) — an unpaid
	 * or pre-upgrade PBL row falls back to a generic link icon.
	 *
	 * @param PendingPayment $entry Payment row.
	 *
	 * @return void
	 */
	private function render_entry_method_badge( PendingPayment $entry ): void {
		$badge = $this->entry_method_badge( $entry );
		$wide  = ! empty( $badge['wide'] );
		?>
		<span class="iftp-sc-methods-table__method">
			<?php if ( '' !== $badge['icon_url'] ) : ?>
				<span class="iftp-sc-methods-table__icon<?php echo $wide ? ' iftp-sc-methods-table__icon--wide' : ''; ?>" title="<?php echo esc_attr( $badge['title'] ); ?>">
					<img src="<?php echo esc_url( $badge['icon_url'] ); ?>" alt="" class="<?php echo $wide ? 'iftp-sc-methods-table__icon-img--wide' : 'iftp-sc-methods-table__icon-img'; ?>" />
				</span>
			<?php else : ?>
				<span class="iftp-sc-methods-table__icon" title="<?php echo esc_attr( $badge['title'] ); ?>">
					<?php $this->render_method_icon( $badge['icon'], 28, '', 'iftp-sc-entries-icon-link' ); ?>
				</span>
			<?php endif; ?>
			<span class="iftp-sc-methods-table__method-label"><?php echo esc_html( $badge['label'] ); ?></span>
		</span>
		<?php
	}

	/**
	 * Resolves a payment row to its Method column icon + label.
	 *
	 * @param PendingPayment $entry Payment row.
	 *
	 * @return array{icon: string, icon_url: string, label: string, title: string}
	 */
	private function entry_method_badge( PendingPayment $entry ): array {
		if ( 'multibanco' === $entry->method ) {
			return array(
				'icon'     => '',
				'icon_url' => IFTP_SC_IMAGES_URL . '/multibanco_only_icon.svg',
				'label'    => __( 'Multibanco', 'ifthenpay-payments-for-surecart' ),
				'title'    => '',
			);
		}

		if ( 'mbway' === $entry->method ) {
			return array(
				'icon'     => '',
				'icon_url' => IFTP_SC_IMAGES_URL . '/mbway_only_icon.svg',
				'label'    => __( 'MB WAY', 'ifthenpay-payments-for-surecart' ),
				'title'    => '',
			);
		}


		$mtd      = strtoupper( $entry->mtd );
		$resolved = IfthenpayHelper::resolve_payment_method( $entry );

		if ( '' !== $resolved['icon_url'] ) {
			return array(
				'icon'     => '',
				'icon_url' => $resolved['icon_url'],
				'label'    => __( 'ifthenpay Gateway', 'ifthenpay-payments-for-surecart' ),
				'title'    => $resolved['label'],

				'wide'     => 'CCARD' === $mtd,
			);
		}


		$local_icons = array(
			'MB'    => IFTP_SC_IMAGES_URL . '/multibanco_only_icon.svg',
			'MBWAY' => IFTP_SC_IMAGES_URL . '/mbway_only_icon.svg',
		);

		if ( isset( $local_icons[ $mtd ] ) ) {
			return array(
				'icon'     => '',
				'icon_url' => $local_icons[ $mtd ],
				'label'    => __( 'ifthenpay Gateway', 'ifthenpay-payments-for-surecart' ),
				'title'    => $resolved['label'],
			);
		}


		$fallback_glyphs = array(
			'CCARD'   => 'credit-card',
			'COFIDIS' => 'credit-card',
			'APPLE'   => 'applepay',
			'GOOGLE'  => 'smartphone',
			'PIX'     => 'zap',
		);

		return array(
			'icon'     => $fallback_glyphs[ $mtd ] ?? 'link',
			'icon_url' => '',
			'label'    => __( 'ifthenpay Gateway', 'ifthenpay-payments-for-surecart' ),
			'title'    => '' !== $entry->mtd ? $resolved['label'] : '',
		);
	}

	/**
	 * Formats the Entity & Reference column — Multibanco only; blank for
	 * MB WAY/ifthenpay Gateway rows.
	 *
	 * @param PendingPayment $entry Payment row.
	 *
	 * @return string
	 */
	private function entry_reference_display( PendingPayment $entry ): string {
		if ( '' === $entry->entity && '' === $entry->reference ) {
			return '—';
		}

		return trim( $entry->entity . ' ' . $entry->reference );
	}

	/**
	 * Renders the State column's tag.
	 *
	 * @param string $state Raw state column value.
	 *
	 * @return void
	 */
	private function render_entry_state_tag( string $state ): void {
		$types = array(
			'PAID'      => 'success',
			'PENDING'   => 'warning',
			'FAILED'    => 'danger',
			'CANCELLED' => 'default',
			'EXPIRED'   => 'default',
		);
		$labels = $this->entry_state_labels();
		?>
		<sc-tag type="<?php echo esc_attr( $types[ $state ] ?? 'default' ); ?>" size="medium">
			<?php echo esc_html( $labels[ $state ] ?? $state ); ?>
		</sc-tag>
		<?php
	}

	/**
	 * State labels, in filter-dropdown/display order. `''` (All) is only
	 * meaningful as the Entries tab's state filter — a row's own `state`
	 * column is never empty, so `render_entry_state_tag()` never looks it up.
	 *
	 * @return array<string, string> State => label.
	 */
	private function entry_state_labels(): array {
		return array(
			''          => __( 'All statuses', 'ifthenpay-payments-for-surecart' ),
			'PAID'      => __( 'Paid', 'ifthenpay-payments-for-surecart' ),
			'PENDING'   => __( 'Pending', 'ifthenpay-payments-for-surecart' ),
			'FAILED'    => __( 'Failed', 'ifthenpay-payments-for-surecart' ),
			'CANCELLED' => __( 'Cancelled', 'ifthenpay-payments-for-surecart' ),
			'EXPIRED'   => __( 'Expired', 'ifthenpay-payments-for-surecart' ),
		);
	}

	/**
	 * Method labels, in filter-dropdown order. Unlike the raw `method`
	 * column (only ever `multibanco`/`mbway`/`pbl`), this also splits `pbl`
	 * rows out by their resolved sub-method (`mtd`) — a paid ifthenpay
	 * Gateway payment reads far more usefully filtered as "Card"/"Apple
	 * Pay"/etc. than lumped under the generic "ifthenpay Gateway" bucket,
	 * which `entries_method_filter()`/`PendingPaymentRepository::method_filter_condition()`
	 * now reserve for `pbl` rows whose `mtd` isn't resolved yet (still
	 * pending). A `pbl` row that resolves to Multibanco/MB WAY (`mtd`
	 * `MB`/`MBWAY`) is folded into the same `multibanco`/`mbway` filter as a
	 * direct payment through that method — from the merchant's perspective
	 * it's the same real-world payment method regardless of which internal
	 * channel it came through.
	 *
	 * Labels for the `pbl_*` keys mirror `IfthenpayHelper::fallback_label()`
	 * verbatim so the same sub-method always reads with the same name
	 * whether it's shown in this dropdown or in the Method column itself.
	 *
	 * @return array<string, string> Method => label.
	 */
	private function entry_method_labels(): array {
		return array(
			''            => __( 'All methods', 'ifthenpay-payments-for-surecart' ),
			'multibanco'  => __( 'Multibanco', 'ifthenpay-payments-for-surecart' ),
			'mbway'       => __( 'MB WAY', 'ifthenpay-payments-for-surecart' ),
			'pbl'         => __( 'ifthenpay Gateway', 'ifthenpay-payments-for-surecart' ),
			'pbl_ccard'   => __( 'Card', 'ifthenpay-payments-for-surecart' ),
			'pbl_apple'   => __( 'Apple Pay', 'ifthenpay-payments-for-surecart' ),
			'pbl_google'  => __( 'Google Pay', 'ifthenpay-payments-for-surecart' ),
			'pbl_pix'     => __( 'Pix', 'ifthenpay-payments-for-surecart' ),
		);
	}

	/**
	 * Reads and validates the Entries tab's `state` filter from the URL.
	 * `entries-restore.js` is what persists the chosen value across visits
	 * (in `localStorage`) and reapplies it via a URL redirect — this method
	 * only ever reads what's on the current request.
	 *
	 * @return string One of the keys from `entry_state_labels()`, `''` for All.
	 */
	private function entries_state_filter(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter selection, no state changes.
		$state = isset( $_GET['state'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['state'] ) ) ) : '';

		return isset( $this->entry_state_labels()[ $state ] ) ? $state : '';
	}

	/**
	 * Reads and validates the Entries tab's `method` filter from the URL —
	 * same persistence model as `entries_state_filter()`.
	 *
	 * @return string One of the keys from `entry_method_labels()`, `''` for All.
	 */
	private function entries_method_filter(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter selection, no state changes.
		$method = isset( $_GET['method'] ) ? sanitize_key( wp_unslash( $_GET['method'] ) ) : '';

		return isset( $this->entry_method_labels()[ $method ] ) ? $method : '';
	}

	/**
	 * Reads the Entries tab's free-text search from the URL. Unlike
	 * state/method, never persisted to `localStorage` — a lingering search
	 * term silently re-filtering a later, unrelated visit would be more
	 * surprising than helpful.
	 *
	 * @return string
	 */
	private function entries_search(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter selection, no state changes.
		return isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
	}

	/**
	 * Renders the Entries tab's filter bar — a status dropdown, a method
	 * dropdown and a free-text search box (Payment ID/Request ID/phone/
	 * method), all outside the table, applied together on submit. The
	 * state/method choices persist across visits via `entries-restore.js`
	 * (localStorage); the search box is URL-only, cleared on the next
	 * unrelated visit.
	 *
	 * @param string $state  Currently selected state, `''` for All.
	 * @param string $method Currently selected method, `''` for All.
	 * @param string $search Currently entered search text.
	 *
	 * @return void
	 */
	private function render_entries_filter_bar( string $state, string $method, string $search ): void {
		$has_filters = '' !== $state || '' !== $method || '' !== $search;
		?>
		<form class="iftp-sc-entries-filter-bar" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<input type="hidden" name="tab" value="<?php echo esc_attr( self::TAB_ENTRIES ); ?>" />

			<?php $this->render_select_field( 'state', __( 'Status', 'ifthenpay-payments-for-surecart' ), $this->entry_state_labels(), $state ); ?>
			<?php $this->render_select_field( 'method', __( 'Method', 'ifthenpay-payments-for-surecart' ), $this->entry_method_labels(), $method ); ?>
			<?php
			$this->render_text_field(
				'search',
				__( 'Search', 'ifthenpay-payments-for-surecart' ),
				$search,
				'',
				array(
					'type'        => 'search',
					'placeholder' => __( 'Payment ID, Request ID, phone…', 'ifthenpay-payments-for-surecart' ),
				)
			);
			?>

			<div class="iftp-sc-entries-filter-bar__actions">
				<sc-button type="primary" size="medium" submit>
					<?php esc_html_e( 'Filter', 'ifthenpay-payments-for-surecart' ); ?>
				</sc-button>
				<?php if ( $has_filters ) : ?>
					<?php

					?>
					<a href="<?php echo esc_url( $this->tab_url( self::TAB_ENTRIES ) ); ?>" class="iftp-sc-entries-clear">
						<?php esc_html_e( 'Clear', 'ifthenpay-payments-for-surecart' ); ?>
					</a>
				<?php endif; ?>
			</div>
		</form>
		<?php
	}

	/**
	 * Renders pagination links for the Entries tab.
	 *
	 * @param int    $total         Total row count across all pages (for the current filters).
	 * @param int    $paged         Current page number.
	 * @param string $state_filter  Active state filter, `''` for All — carried through the "Go to" jump form.
	 * @param string $method_filter Active method filter, `''` for All — carried through the "Go to" jump form.
	 * @param string $search        Active search text — carried through the "Go to" jump form.
	 *
	 * @return void
	 */
	private function render_entries_pagination( int $total, int $paged, string $state_filter = '', string $method_filter = '', string $search = '' ): void {
		$total_pages = (int) ceil( $total / self::ENTRIES_PER_PAGE );
		if ( $total_pages <= 1 ) {
			return;
		}
		?>
		<div class="iftp-sc-entries-pagination">
			<?php

			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'current'   => $paged,
						'total'     => $total_pages,
						'prev_text' => '‹',
						'next_text' => '›',
					)
				)
			);
			?>
			<form class="iftp-sc-pagination-jump" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<input type="hidden" name="tab" value="<?php echo esc_attr( self::TAB_ENTRIES ); ?>" />
				<?php if ( '' !== $state_filter ) : ?>
					<input type="hidden" name="state" value="<?php echo esc_attr( $state_filter ); ?>" />
				<?php endif; ?>
				<?php if ( '' !== $method_filter ) : ?>
					<input type="hidden" name="method" value="<?php echo esc_attr( $method_filter ); ?>" />
				<?php endif; ?>
				<?php if ( '' !== $search ) : ?>
					<input type="hidden" name="search" value="<?php echo esc_attr( $search ); ?>" />
				<?php endif; ?>
				<label for="iftp-sc-jump-page" class="iftp-sc-pagination-jump__label"><?php esc_html_e( 'Go to', 'ifthenpay-payments-for-surecart' ); ?></label>
				<input
					type="number"
					id="iftp-sc-jump-page"
					name="paged"
					class="iftp-sc-pagination-jump__input"
					min="1"
					max="<?php echo esc_attr( (string) $total_pages ); ?>"
					placeholder="<?php echo esc_attr( (string) $paged ); ?>"
				/>
			</form>
		</div>
		<?php
	}

	/**
	 * Resolves the ifthenpay Gateway methods table for a chosen Gateway Key: every
	 * globally visible method flagged with whether it's provisioned and, if
	 * so, its available named account(s). Unprovisioned methods are kept
	 * (not filtered out) so the settings screen can show them disabled.
	 *
	 * @param string $backoffice_key The ifthenpay Backoffice Key.
	 * @param string $gateway_key    The ifthenpay Gateway Key to look up.
	 *
	 * @return array<string, array{label: string, accounts: array<string,string>, provisioned: bool, logo: string, position: int}>|\WP_Error
	 */
	private function resolve_pbl_methods_table( string $backoffice_key, string $gateway_key ) {
		$rows = $this->catalog->get_gateway_keys( $backoffice_key );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$found = false;
		foreach ( (array) $rows as $candidate ) {
			if ( isset( $candidate['GatewayKey'] ) && $candidate['GatewayKey'] === $gateway_key ) {
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			return new \WP_Error( 'iftp_sc_gateway_key_not_found', __( 'This Gateway Key was not found on your ifthenpay Backoffice.', 'ifthenpay-payments-for-surecart' ) );
		}

		$available = $this->catalog->get_available_methods();
		if ( is_wp_error( $available ) ) {
			return $available;
		}

		$accounts_by_entity = $this->accounts->get_accounts_by_gateway_key( $backoffice_key, $gateway_key );
		if ( is_wp_error( $accounts_by_entity ) ) {
			return $accounts_by_entity;
		}

		$methods = array();
		foreach ( (array) $available as $method ) {
			$entity = strtoupper( (string) ( $method['Entity'] ?? '' ) );
			if ( '' === $entity || empty( $method['IsVisible'] ) ) {
				continue;
			}

			$methods[ $entity ] = array(
				'label'       => (string) ( $method['Method'] ?? $entity ),
				'accounts'    => $accounts_by_entity[ $entity ] ?? array(),
				'provisioned' => ! empty( $accounts_by_entity[ $entity ] ),
				'logo'        => (string) ( $method['SmallImageUrl'] ?? '' ),
				'position'    => (int) ( $method['Position'] ?? 0 ),
			);
		}

		return $methods;
	}

	/**
	 * Decides which method should be pre-selected as "Default payment
	 * method" when the methods table (re)renders — CCARD if it's
	 * provisioned, else whichever provisioned method comes first. Only
	 * kicks in the first time a Gateway Key is configured (nothing saved
	 * yet) or right after switching to a *different* Gateway Key than the
	 * one currently saved; once the merchant has saved a default for the
	 * Gateway Key in view, that explicit choice is always respected instead
	 * — this never silently overwrites it.
	 *
	 * @param string                                                                                              $gateway_key   The Gateway Key currently in view (may not be saved yet).
	 * @param array<string, array{label: string, accounts: array<string,string>, provisioned: bool, logo: string, position: int}> $methods       Result of `resolve_pbl_methods_table()`.
	 * @param array<string, array{enabled: bool, account: string}>                                                $saved_methods The persisted `iftp_sc_pbl_methods` option.
	 *
	 * @return string Entity code, or '' if nothing is provisioned to default to.
	 */
	private function effective_default_method( string $gateway_key, array $methods, array $saved_methods ): string {
		$provisioned_keys = array();
		foreach ( $methods as $entity => $method ) {
			if ( ! empty( $method['provisioned'] ) ) {
				$provisioned_keys[] = $entity;
			}
		}
		if ( empty( $provisioned_keys ) ) {
			return '';
		}

		$saved_gateway_key = (string) get_option( 'iftp_sc_gateway_key', '' );
		$saved_default     = (string) get_option( 'iftp_sc_pbl_default_method', '' );
		$is_same_gateway   = '' !== $saved_gateway_key && $saved_gateway_key === $gateway_key;

		if ( $is_same_gateway && in_array( $saved_default, $provisioned_keys, true ) && ! empty( $saved_methods[ $saved_default ]['enabled'] ) ) {
			return $saved_default;
		}

		if ( in_array( 'CCARD', $provisioned_keys, true ) ) {
			return 'CCARD';
		}

		return $provisioned_keys[0];
	}

	/**
	 * Renders the methods `<table>` (or the "nothing available" alert) as a
	 * string — the single template shared by the initial page load and the
	 * `iftp_sc_get_methods_table` AJAX handler, so the two can never drift.
	 *
	 * @param array<string, array{label: string, accounts: array<string,string>, provisioned: bool, logo: string, position: int}> $methods       Result of `resolve_pbl_methods_table()`.
	 * @param array<string, array{enabled: bool, account: string}>                                                $saved_methods The persisted `iftp_sc_pbl_methods` option.
	 *
	 * @return string
	 */
	private function render_methods_table_html( array $methods, array $saved_methods ): string {
		if ( empty( $methods ) ) {
			ob_start();
			?>
			<sc-alert type="info" open><?php esc_html_e( 'No methods are available for this Gateway Key yet. Contact ifthenpay support to enable some.', 'ifthenpay-payments-for-surecart' ); ?></sc-alert>
			<?php
			return (string) ob_get_clean();
		}

		ob_start();
		?>
		<table class="iftp-sc-methods-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Enabled', 'ifthenpay-payments-for-surecart' ); ?></th>
					<th><?php esc_html_e( 'Method', 'ifthenpay-payments-for-surecart' ); ?></th>
					<th><?php esc_html_e( 'Account', 'ifthenpay-payments-for-surecart' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $methods as $entity => $method ) : ?>
					<?php
					$provisioned      = ! empty( $method['provisioned'] );
					$checked          = $provisioned && ( $saved_methods[ $entity ]['enabled'] ?? true );
					$accounts         = is_array( $method['accounts'] ?? null ) ? $method['accounts'] : array();
					$saved_account    = (string) ( $saved_methods[ $entity ]['account'] ?? '' );
					$first_account    = reset( $accounts );
					$selected_account = in_array( $saved_account, $accounts, true ) ? $saved_account : ( false !== $first_account ? (string) $first_account : '' );
					?>
					<tr class="iftp-sc-methods-table__row<?php echo $provisioned ? '' : ' iftp-sc-methods-table__row--unprovisioned'; ?>">
						<td><?php $this->render_switch_field( 'methods[' . $entity . '][enabled]', (bool) $checked, ! $provisioned ); ?></td>
						<td>
							<span class="iftp-sc-methods-table__method">
								<?php if ( ! empty( $method['logo'] ) ) : ?>
									<span class="iftp-sc-methods-table__logo-wrap">
										<img src="<?php echo esc_url( $method['logo'] ); ?>" alt="" class="iftp-sc-methods-table__logo" />
									</span>
								<?php endif; ?>
								<span class="iftp-sc-methods-table__method-label"><?php echo esc_html( $method['label'] ?? $entity ); ?></span>
							</span>
						</td>
						<td>
							<?php if ( $provisioned ) : ?>
								<div class="iftp-sc-select-wrap">
									<select name="methods[<?php echo esc_attr( $entity ); ?>][account]" class="iftp-sc-select">
										<?php foreach ( $accounts as $alias => $conta ) : ?>
											<option value="<?php echo esc_attr( $conta ); ?>" <?php selected( $selected_account, $conta ); ?>><?php echo esc_html( $alias ); ?></option>
										<?php endforeach; ?>
									</select>
									<sc-icon name="chevron-down" class="iftp-sc-select__caret" style="width: 16px; height: 16px;"></sc-icon>
								</div>
							<?php else : ?>
								<span class="iftp-sc-methods-table__not-activated"><?php esc_html_e( 'Not activated', 'ifthenpay-payments-for-surecart' ); ?></span>
								<sc-button type="default" size="small" outline class="iftp-sc-activate-method-btn" data-entity="<?php echo esc_attr( $entity ); ?>">
									<?php esc_html_e( 'Activate Method', 'ifthenpay-payments-for-surecart' ); ?>
								</sc-button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Renders the "Default payment method" select as a string — options
	 * limited to provisioned methods only, since there's nothing to default
	 * to on a method with no account. Shared by the initial page load and
	 * the `iftp_sc_get_methods_table` AJAX handler.
	 *
	 * @param array<string, array{label: string, accounts: array<string,string>, provisioned: bool, logo: string, position: int}> $methods  Result of `resolve_pbl_methods_table()`.
	 * @param string                                                                                              $selected Entity code to preselect.
	 *
	 * @return string
	 */
	private function render_default_method_field_html( array $methods, string $selected ): string {
		$options = array( '' => __( '— None —', 'ifthenpay-payments-for-surecart' ) );
		foreach ( $methods as $entity => $method ) {
			if ( ! empty( $method['provisioned'] ) ) {
				$options[ $entity ] = $method['label'] ?? $entity;
			}
		}

		ob_start();
		$this->render_select_field(
			'default_method',
			__( 'Default payment method', 'ifthenpay-payments-for-surecart' ),
			$options,
			$selected,
			__( "Which method is pre-selected on ifthenpay's hosted payment page.", 'ifthenpay-payments-for-surecart' )
		);
		return (string) ob_get_clean();
	}

	/**
	 * Renders the ifthenpay Gateway tab: Backoffice Key connect, then Gateway Key +
	 * methods table, mirroring `ifthenpay-payments-for-contactform7`.
	 *
	 * @return void
	 */
	private function render_pbl_tab(): void {

		$backoffice_key = (string) get_option( 'iftp_sc_backoffice_key', '' );

		$rows = $this->catalog->get_gateway_keys( $backoffice_key );
		if ( is_wp_error( $rows ) || empty( $rows ) ) {
			?>
			<sc-alert type="danger" open><?php esc_html_e( "We couldn't fetch any Gateway Keys for this Backoffice Key.", 'ifthenpay-payments-for-surecart' ); ?></sc-alert>
			<?php
			$this->render_disconnect_link(
				'iftp_sc_disconnect_backoffice',
				__( 'Disconnect ifthenpay ifthenpay Gateway? Your Gateway Key and methods selection will be cleared.', 'ifthenpay-payments-for-surecart' )
			);
			return;
		}

		$saved_gateway_key = (string) get_option( 'iftp_sc_gateway_key', '' );
		$effective_gk      = '';
		foreach ( $rows as $row ) {
			if ( isset( $row['GatewayKey'] ) && $row['GatewayKey'] === $saved_gateway_key ) {
				$effective_gk = $saved_gateway_key;
				break;
			}
		}
		if ( '' === $effective_gk && isset( $rows[0]['GatewayKey'] ) ) {
			$effective_gk = (string) $rows[0]['GatewayKey'];
		}

		$methods_result = '' !== $effective_gk ? $this->resolve_pbl_methods_table( $backoffice_key, $effective_gk ) : array();
		$methods_error  = is_wp_error( $methods_result ) ? $methods_result->get_error_message() : '';
		$methods        = is_wp_error( $methods_result ) ? array() : $methods_result;

		$saved_methods = get_option( 'iftp_sc_pbl_methods', array() );
		$saved_methods = is_array( $saved_methods ) ? $saved_methods : array();

		$effective_default = $this->effective_default_method( $effective_gk, $methods, $saved_methods );

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="iftp_sc_save_pbl_config" />
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<?php $this->render_page_header( 'link', __( 'ifthenpay Gateway', 'ifthenpay-payments-for-surecart' ) ); ?>

			<div class="iftp-sc-modules">
				<?php
				$this->render_module_start(
					__( 'Gateway & methods', 'ifthenpay-payments-for-surecart' ),
					__( 'Every method provisioned on this Gateway Key is listed here, including Multibanco and MB WAY if enabled on it — separate from the standalone keys on their own tabs.', 'ifthenpay-payments-for-surecart' )
				);

				$gateway_options = array();
				foreach ( $rows as $row ) {
					$gk                     = (string) ( $row['GatewayKey'] ?? '' );
					$alias                  = (string) ( $row['Alias'] ?? '' );
					$gateway_options[ $gk ] = '' !== $alias ? $alias . ' (' . $gk . ')' : $gk;
				}
				$this->render_select_field(
					'gateway_key',
					__( 'Gateway Key', 'ifthenpay-payments-for-surecart' ),
					$gateway_options,
					$effective_gk,
					__( 'Selecting a different key and saving refreshes the methods below.', 'ifthenpay-payments-for-surecart' )
				);
				?>

				<div id="iftp-sc-methods-wrapper">
					<?php if ( '' !== $methods_error ) : ?>
						<sc-alert type="danger" open><?php echo esc_html( $methods_error ); ?></sc-alert>
					<?php else : ?>
						<?php echo $this->render_methods_table_html( $methods, $saved_methods ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped internally, shared with the AJAX handler. ?>
					<?php endif; ?>
				</div>

				<div id="iftp-sc-default-method-wrapper">
					<?php echo $this->render_default_method_field_html( $methods, $effective_default ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped internally, shared with the AJAX handler. ?>
				</div>

				<?php $this->render_module_end(); ?>

				<?php
				$this->render_module_start(
					__( 'Hosted page', 'ifthenpay-payments-for-surecart' ),
					__( 'Customize the checkout page customers land on to complete a ifthenpay Gateway payment.', 'ifthenpay-payments-for-surecart' )
				);
				$this->render_text_field(
					'description',
					__( 'Description', 'ifthenpay-payments-for-surecart' ),
					(string) get_option( 'iftp_sc_pbl_description', '' ),
					__( 'Shown to the customer on the hosted payment page. Leave blank to use the default.', 'ifthenpay-payments-for-surecart' ),
					array(
						'maxlength'   => '200',
						'placeholder' => sprintf( /* translators: %s: site name */ __( 'Order at %s', 'ifthenpay-payments-for-surecart' ), get_bloginfo( 'name' ) ),
					)
				);
				$this->render_text_field(
					'expire_days',
					__( 'Expiry days', 'ifthenpay-payments-for-surecart' ),
					(string) max( 1, (int) get_option( 'iftp_sc_pbl_expire_days', 3 ) ),
					__( 'Payment sessions still unpaid after this many days are marked expired.', 'ifthenpay-payments-for-surecart' ),
					array(
						'type' => 'number',
						'min'  => '1',
						'max'  => '90',
					),
					true
				);
				$this->render_module_end(
					true,
					__( 'Saving registers the ifthenpay Gateway callback with ifthenpay automatically, and activates this method once you turn it on in Methods.', 'ifthenpay-payments-for-surecart' )
				);
				?>
			</div>
		</form>
		<?php
	}

	/**
	 * Renders the Backoffice Key connect form — the front door of this
	 * settings screen; see `render()`'s gate branch.
	 *
	 * @return void
	 */
	private function render_backoffice_connect_form(): void {
		?>
		<h3 class="iftp-sc-section-title iftp-sc-section-title--tight"><?php esc_html_e( 'Connect your ifthenpay account', 'ifthenpay-payments-for-surecart' ); ?></h3>
		<?php if ( isset( $_GET['iftp_notice'] ) && 'invalid_key' === sanitize_key( wp_unslash( $_GET['iftp_notice'] ) ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only UX flash, no state changes. ?>
			<sc-alert type="danger" open><?php esc_html_e( "We couldn't verify this Backoffice Key. Double check it and try again.", 'ifthenpay-payments-for-surecart' ); ?></sc-alert>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="iftp_sc_connect_backoffice" />
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<?php
			$this->render_text_field(
				'backoffice_key',
				__( 'Backoffice Key', 'ifthenpay-payments-for-surecart' ),
				'',
				__( 'Found in your ifthenpay Backoffice, under Account.', 'ifthenpay-payments-for-surecart' ),
				array( 'required' => 'required' )
			);
			?>
			<div class="iftp-sc-form__actions">
				<sc-button type="primary" size="medium" submit><?php esc_html_e( 'Connect', 'ifthenpay-payments-for-surecart' ); ?></sc-button>
			</div>
		</form>
		<?php
	}

	/**
	 * Renders the "Disconnect" button for a connected key, shared by the Pay
	 * by Link, MB WAY, and Multibanco tabs.
	 *
	 * @param string $action          The `admin-post.php` action to POST to (e.g. `iftp_sc_disconnect_mbway`).
	 * @param string $confirm_message Confirmation prompt shown before disconnecting.
	 *
	 * @return void
	 */
	private function render_disconnect_link( string $action, string $confirm_message ): void {
		$url = wp_nonce_url(
			add_query_arg( array( 'action' => $action ), admin_url( 'admin-post.php' ) ),
			self::NONCE_ACTION
		);
		?>
		<sc-button
			type="danger"
			size="small"
			outline
			href="<?php echo esc_url( $url ); ?>"
			onclick="return confirm('<?php echo esc_js( $confirm_message ); ?>');"
		>
			<sc-icon slot="prefix" name="log-out" style="width: 14px; height: 14px;"></sc-icon>
			<?php esc_html_e( 'Disconnect', 'ifthenpay-payments-for-surecart' ); ?>
		</sc-button>
		<?php
	}

	/**
	 * Renders a connected-key summary card: icon, masked value, a "Connected"
	 * tag, and a Disconnect button — shared by the ifthenpay Gateway, MB WAY, and
	 * Multibanco tabs so each looks and behaves the same once a key is saved.
	 *
	 * @param string $icon              `sc-icon` name for the leading badge.
	 * @param string $eyebrow           Small label above the masked value (e.g. `Backoffice Key`).
	 * @param string $masked_value      The masked key, from `mask_key()`.
	 * @param string $disconnect_action The `admin-post.php` action to disconnect this key.
	 * @param string $confirm_message   Confirmation prompt shown before disconnecting.
	 *
	 * @return void
	 */
	private function render_connection_card( string $icon, string $eyebrow, string $masked_value, string $disconnect_action, string $confirm_message ): void {
		?>
		<div class="iftp-sc-connection-card">
			<span class="iftp-sc-connection-card__icon">
				<?php $this->render_method_icon( $icon, 18 ); ?>
			</span>
			<span class="iftp-sc-connection-card__body">
				<span class="iftp-sc-connection-card__eyebrow"><?php echo esc_html( $eyebrow ); ?></span>
				<span class="iftp-sc-connection-card__value"><?php echo esc_html( $masked_value ); ?></span>
			</span>
			<span class="iftp-sc-connection-card__status">
				<sc-tag type="success" size="medium"><?php esc_html_e( 'Connected', 'ifthenpay-payments-for-surecart' ); ?></sc-tag>
				<?php $this->render_disconnect_link( $disconnect_action, $confirm_message ); ?>
			</span>
		</div>
		<?php
	}

	/**
	 * Renders the "pick which account to use" step shared by the MB WAY and
	 * Multibanco tabs' modules: a dropdown of the merchant's named accounts
	 * (value = raw `Conta` string, label = `Alias`, mirroring the Pay by
	 * Link Gateway Key dropdown), collapsed to a one-line confirmation when
	 * there's only one account to pick from, or an info notice when there
	 * are none. The caller owns the surrounding `<form>`/nonce/Save button
	 * (see `render_mbway_tab()`/`render_multibanco_tab()`), so this only
	 * renders the field itself — always shown, whether or not an account is
	 * already connected, so switching accounts is just "pick + Save" rather
	 * than a separate disconnect step.
	 *
	 * @param string               $post_field     Field name the chosen `Conta` value is posted under.
	 * @param array<string,string> $accounts       Map of raw `Conta` string => `Alias`.
	 * @param string               $selected_conta The currently-connected `Conta` string, if any, to preselect.
	 * @param string               $empty_message  Shown when `$accounts` is empty.
	 * @param string               $icon_url       Method symbol (`mbway_only_icon.svg`/`multibanco_only_icon.svg`) shown next to the field, `''` for none.
	 *
	 * @return void
	 */
	private function render_account_picker( string $post_field, array $accounts, string $selected_conta, string $empty_message, string $icon_url = '' ): void {
		if ( empty( $accounts ) ) {
			?>
			<sc-alert type="info" open><?php echo esc_html( $empty_message ); ?></sc-alert>
			<?php
			return;
		}
		?>
		<div class="iftp-sc-account-picker">
			<?php if ( '' !== $icon_url ) : ?>
				<img src="<?php echo esc_url( $icon_url ); ?>" alt="" class="iftp-sc-account-picker__icon" />
			<?php endif; ?>
			<div class="iftp-sc-account-picker__field">
				<?php if ( 1 === count( $accounts ) ) : ?>
					<?php
					$conta = (string) array_key_first( $accounts );
					$alias = (string) $accounts[ $conta ];
					?>
					<p class="iftp-sc-field__help">
						<?php
						printf(
							/* translators: %s: account alias/name from the ifthenpay Backoffice */
							esc_html__( 'We found one account on your ifthenpay Backoffice: %s', 'ifthenpay-payments-for-surecart' ),
							'<strong>' . esc_html( $alias ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built entirely from esc_html()'d parts above.
						);
						?>
					</p>
					<input type="hidden" name="<?php echo esc_attr( $post_field ); ?>" value="<?php echo esc_attr( $conta ); ?>" />
				<?php else : ?>
					<?php

					$options = $accounts;
					if ( '' === $selected_conta ) {
						$options = array( '' => __( '— Select an account —', 'ifthenpay-payments-for-surecart' ) ) + $options;
					}
					$this->render_select_field(
						$post_field,
						__( 'Account', 'ifthenpay-payments-for-surecart' ),
						$options,
						$selected_conta
					);
					?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Finds which raw `Conta` string (dropdown value) in `$accounts`
	 * classifies to the given saved key, so the account picker can preselect
	 * the currently-connected account instead of always defaulting to blank.
	 *
	 * @param array<string,string> $accounts Map of raw `Conta` string => `Alias`, as returned by `IfmbAccountsClient::get_classified_accounts()`.
	 * @param string                $key      The saved key (`iftp_sc_mbway_key`/`iftp_sc_mb_key`) to match.
	 *
	 * @return string The matching `Conta` string, or `''` if none matches (e.g. the saved key is blank, or no longer listed).
	 */
	private function selected_conta( array $accounts, string $key ): string {
		if ( '' === $key ) {
			return '';
		}
		foreach ( array_keys( $accounts ) as $conta ) {
			$classified = IfmbAccountsClient::classify( $conta );
			if ( null !== $classified && ( $classified['key'] ?? '' ) === $key ) {
				return $conta;
			}
		}
		return '';
	}

	/**
	 * `selected_conta()`'s Multibanco-specific counterpart — the Multibanco
	 * tab's account list mixes modern MB Key accounts (matched by `key`, like
	 * `selected_conta()` already does) and legacy Entity/Sub-entity accounts
	 * (which have no `key`, so need matching on the `entity`/`subentity` pair
	 * saved by `handle_save_multibanco()` instead).
	 *
	 * @param array<string,string> $accounts Map of raw `Conta` string => `Alias`, as returned by `IfmbAccountsClient::get_classified_accounts()`.
	 *
	 * @return string The matching `Conta` string, or `''` if none matches.
	 */
	private function selected_mb_conta( array $accounts ): string {
		$mb_key = (string) get_option( 'iftp_sc_mb_key', '' );
		if ( '' !== $mb_key ) {
			return $this->selected_conta( $accounts, $mb_key );
		}

		$entity    = (string) get_option( 'iftp_sc_mb_legacy_entity', '' );
		$subentity = (string) get_option( 'iftp_sc_mb_legacy_subentity', '' );
		if ( '' === $entity || '' === $subentity ) {
			return '';
		}

		foreach ( array_keys( $accounts ) as $conta ) {
			$classified = IfmbAccountsClient::classify( $conta );
			if ( null !== $classified
				&& 'legacy_mb' === $classified['type']
				&& ( $classified['entity'] ?? '' ) === $entity
				&& ( $classified['subentity'] ?? '' ) === $subentity
			) {
				return $conta;
			}
		}
		return '';
	}

	/**
	 * Whether Multibanco has an account configured, of either type — mirrors
	 * the check `PaymentInitiator::initiate_multibanco()` itself uses to pick
	 * a branch, so "configured" here always means a payment can actually be
	 * created.
	 *
	 * @return bool
	 */
	private function is_multibanco_configured(): bool {
		if ( '' !== (string) get_option( 'iftp_sc_mb_key', '' ) ) {
			return true;
		}
		return '' !== (string) get_option( 'iftp_sc_mb_legacy_entity', '' ) && '' !== (string) get_option( 'iftp_sc_mb_legacy_subentity', '' );
	}

	/**
	 * Masks a key for display — only the last 4 characters are shown,
	 * preceded by one bullet per hidden character (rather than the key's own
	 * leading characters and asterisks, which reveal more of the real value
	 * and read as dated).
	 *
	 * @param string $key The full key.
	 *
	 * @return string
	 */
	private function mask_key( string $key ): string {
		$visible = substr( $key, -4 );
		$hidden  = max( 0, strlen( $key ) - strlen( $visible ) );
		return str_repeat( "\u{2022}", $hidden ) . $visible;
	}

	/**
	 * Defense in depth for the method-config admin-post handlers: `render()`
	 * already hides every tab until the Backoffice Key is connected, but
	 * these handlers are independently reachable via `admin-post.php`, so a
	 * stale bookmark or replayed POST shouldn't be able to configure a method
	 * while disconnected.
	 *
	 * @return void
	 */
	private function require_backoffice_connected(): void {
		if ( '' === (string) get_option( 'iftp_sc_backoffice_key', '' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
			exit;
		}
	}

	/**
	 * Saves per-method activation toggles — turns a method on/off at
	 * checkout by archiving/unarchiving it on SureCart's side.
	 *
	 * @return void
	 */
	public function handle_save_methods(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ifthenpay-payments-for-surecart' ), 403 );
		}
		check_admin_referer( self::NONCE_ACTION );
		$this->require_backoffice_connected();

		$submitted = isset( $_POST['methods'] ) && is_array( $_POST['methods'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['methods'] ) )
			: array();

		$configured = array(
			'pbl'        => '' !== (string) get_option( 'iftp_sc_gateway_key', '' ),
			'mbway'      => '' !== (string) get_option( 'iftp_sc_mbway_key', '' ),
			'multibanco' => $this->is_multibanco_configured(),
		);

		foreach ( $configured as $key => $is_configured ) {
			$want_active = isset( $submitted[ $key ] );


			if ( $want_active && ! $is_configured ) {
				continue;
			}

			$this->provisioner->set_archived( $key, ! $want_active );
		}

		wp_safe_redirect( $this->tab_url( self::TAB_METHODS ) );
		exit;
	}

	/**
	 * Saves the MB WAY key and registers its notify-URL callback.
	 *
	 * @return void
	 */
	public function handle_save_mbway(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ifthenpay-payments-for-surecart' ), 403 );
		}
		check_admin_referer( self::NONCE_ACTION );
		$this->require_backoffice_connected();

		$conta      = isset( $_POST['iftp_sc_mbway_account'] ) ? sanitize_text_field( wp_unslash( $_POST['iftp_sc_mbway_account'] ) ) : '';
		$classified = IfmbAccountsClient::classify( $conta );
		$mbway_key  = ( null !== $classified && 'mbway' === $classified['type'] ) ? $classified['key'] : '';

		if ( '' !== $mbway_key ) {
			update_option( 'iftp_sc_mbway_key', $mbway_key, false );
			$this->client->activate_callback( $mbway_key, $this->mbway_notify_url(), (string) get_option( 'iftp_sc_secret_key', '' ) );
		}

		wp_safe_redirect( $this->tab_url( self::TAB_MBWAY ) );
		exit;
	}

	/**
	 * Saves the Multibanco account — either a modern MB Key or a legacy
	 * Entity/Sub-entity account — and registers its notify-URL callback. The
	 * two are mutually exclusive: whichever type is picked has its option(s)
	 * saved and the other type's cleared, so `PaymentInitiator::initiate_multibanco()`
	 * can tell which one is active from `iftp_sc_mb_key` alone.
	 *
	 * A legacy account has no MB Key to register a callback against, so its
	 * callback is registered using the Entity code as the `chave` instead —
	 * the same generic mechanism `activate_callback()` already uses for
	 * Gateway/MB WAY/MB keys.
	 *
	 * @return void
	 */
	public function handle_save_multibanco(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ifthenpay-payments-for-surecart' ), 403 );
		}
		check_admin_referer( self::NONCE_ACTION );
		$this->require_backoffice_connected();

		$conta      = isset( $_POST['iftp_sc_mb_account'] ) ? sanitize_text_field( wp_unslash( $_POST['iftp_sc_mb_account'] ) ) : '';
		$classified = IfmbAccountsClient::classify( $conta );

		if ( null !== $classified && 'modern_mb' === $classified['type'] ) {
			update_option( 'iftp_sc_mb_key', $classified['key'], false );
			delete_option( 'iftp_sc_mb_legacy_entity' );
			delete_option( 'iftp_sc_mb_legacy_subentity' );
			$this->client->activate_callback( $classified['key'], $this->multibanco_notify_url(), (string) get_option( 'iftp_sc_secret_key', '' ) );
		} elseif ( null !== $classified && 'legacy_mb' === $classified['type'] ) {
			update_option( 'iftp_sc_mb_legacy_entity', $classified['entity'], false );
			update_option( 'iftp_sc_mb_legacy_subentity', $classified['subentity'], false );
			delete_option( 'iftp_sc_mb_key' );
			$this->client->activate_callback( $classified['entity'], $this->multibanco_notify_url(), (string) get_option( 'iftp_sc_secret_key', '' ) );
		}

		$mb_expire_days = isset( $_POST['mb_expire_days'] ) ? max( 1, absint( $_POST['mb_expire_days'] ) ) : 3;
		update_option( 'iftp_sc_mb_expire_days', $mb_expire_days, false );

		wp_safe_redirect( $this->tab_url( self::TAB_MULTIBANCO ) );
		exit;
	}

	/**
	 * Verifies a Backoffice Key against ifthenpay and saves it (Stage 1).
	 *
	 * @return void
	 */
	public function handle_connect_backoffice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ifthenpay-payments-for-surecart' ), 403 );
		}
		check_admin_referer( self::NONCE_ACTION );

		$backoffice_key = isset( $_POST['backoffice_key'] ) ? sanitize_text_field( wp_unslash( $_POST['backoffice_key'] ) ) : '';
		$rows           = '' !== $backoffice_key ? $this->catalog->get_gateway_keys( $backoffice_key ) : array();

		if ( '' === $backoffice_key || is_wp_error( $rows ) || empty( $rows ) ) {
			wp_safe_redirect( add_query_arg( array( 'iftp_notice' => 'invalid_key' ), $this->tab_url( self::TAB_METHODS ) ) );
			exit;
		}

		update_option( 'iftp_sc_backoffice_key', $backoffice_key, false );


		$this->catalog->refresh_available_methods_cache();

		wp_safe_redirect( $this->tab_url( self::TAB_METHODS ) );
		exit;
	}

	/**
	 * Clears and re-fetches the cached ifthenpay methods catalog (the real
	 * brand icons/labels `entry_method_badge()` and `instructions.php` read via
	 * `IfthenpayHelper::resolve_payment_method()`) — our equivalent of
	 * SureCart's own "Clear Account Cache" (`CacheSettings::clear()`), exposed
	 * here too so a merchant never has to leave this screen to unstick a stale
	 * icon/label after ifthenpay adds or renames a method. Re-fetches
	 * immediately rather than just deleting, so the very next page load
	 * already has fresh data instead of falling back to generic icons until
	 * something else happens to repopulate it (mirrors `render()`'s own
	 * backfill guard).
	 *
	 * @return void
	 */
	public function handle_clear_cache(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ifthenpay-payments-for-surecart' ), 403 );
		}
		check_admin_referer( self::NONCE_ACTION );

		delete_option( GatewayCatalogClient::METHODS_CACHE_OPTION );
		$this->catalog->refresh_available_methods_cache();

		$redirect = wp_get_referer();
		wp_safe_redirect( add_query_arg( array( 'iftp_notice' => 'cache_cleared' ), $redirect ? $redirect : $this->tab_url( self::TAB_METHODS ) ) );
		exit;
	}

	/**
	 * Clears the ifthenpay Gateway connection (Backoffice Key, Gateway Key, methods).
	 *
	 * @return void
	 */
	public function handle_disconnect_backoffice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ifthenpay-payments-for-surecart' ), 403 );
		}
		check_admin_referer( self::NONCE_ACTION );

		delete_option( 'iftp_sc_backoffice_key' );
		delete_option( 'iftp_sc_gateway_key' );
		delete_option( 'iftp_sc_pbl_methods' );
		delete_option( 'iftp_sc_pbl_default_method' );
		delete_option( GatewayCatalogClient::METHODS_CACHE_OPTION );

		delete_option( 'iftp_sc_mb_key' );
		delete_option( 'iftp_sc_mb_legacy_entity' );
		delete_option( 'iftp_sc_mb_legacy_subentity' );
		delete_option( 'iftp_sc_mbway_key' );

		wp_safe_redirect( $this->tab_url( self::TAB_METHODS ) );
		exit;
	}

	/**
	 * Saves the selected Gateway Key + enabled methods (Stage 2) and
	 * registers the ifthenpay Gateway callback.
	 *
	 * @return void
	 */
	public function handle_save_pbl_config(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ifthenpay-payments-for-surecart' ), 403 );
		}
		check_admin_referer( self::NONCE_ACTION );

		$backoffice_key = (string) get_option( 'iftp_sc_backoffice_key', '' );
		$gateway_key    = isset( $_POST['gateway_key'] ) ? sanitize_text_field( wp_unslash( $_POST['gateway_key'] ) ) : '';

		if ( '' === $backoffice_key || '' === $gateway_key ) {
			wp_safe_redirect( $this->tab_url( self::TAB_PBL ) );
			exit;
		}

		update_option( 'iftp_sc_gateway_key', $gateway_key, false );

		$description = isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( $_POST['description'] ) ) : '';
		$expire_days = isset( $_POST['expire_days'] ) ? max( 1, absint( $_POST['expire_days'] ) ) : 3;
		update_option( 'iftp_sc_pbl_description', $description, false );
		update_option( 'iftp_sc_pbl_expire_days', $expire_days, false );

		$methods = $this->resolve_pbl_methods_table( $backoffice_key, $gateway_key );
		if ( ! is_wp_error( $methods ) ) {
			$submitted = isset( $_POST['methods'] ) && is_array( $_POST['methods'] )
				? map_deep( wp_unslash( $_POST['methods'] ), 'sanitize_text_field' )
				: array();

			foreach ( $methods as $entity => $method ) {
				$entry   = is_array( $submitted[ $entity ] ?? null ) ? $submitted[ $entity ] : array();
				$account = isset( $entry['account'] ) ? sanitize_text_field( (string) $entry['account'] ) : '';

				$method['enabled'] = isset( $entry['enabled'] );

				$first_account      = reset( $method['accounts'] );
				$method['account']  = in_array( $account, $method['accounts'], true ) ? $account : ( false !== $first_account ? (string) $first_account : '' );
				$methods[ $entity ] = $method;
			}
			update_option( 'iftp_sc_pbl_methods', $methods, false );

			$default_method = isset( $_POST['default_method'] ) ? sanitize_text_field( wp_unslash( $_POST['default_method'] ) ) : '';
			update_option( 'iftp_sc_pbl_default_method', ( '' !== $default_method && ! empty( $methods[ $default_method ]['enabled'] ) ) ? $default_method : '', false );

			$this->client->activate_callback( $gateway_key, $this->pbl_notify_url(), (string) get_option( 'iftp_sc_secret_key', '' ) );
		}

		wp_safe_redirect( $this->tab_url( self::TAB_PBL ) );
		exit;
	}

	/**
	 * Re-resolves and re-renders the methods table + default-method field for
	 * a Gateway Key the merchant just picked in the dropdown — before they've
	 * saved anything — so the ifthenpay Gateway tab updates live instead of
	 * requiring a full page save + reload.
	 *
	 * @return void
	 */
	public function ajax_get_methods_table(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ifthenpay-payments-for-surecart' ) ), 403 );
		}

		$backoffice_key = (string) get_option( 'iftp_sc_backoffice_key', '' );
		$gateway_key    = isset( $_POST['gateway_key'] ) ? sanitize_text_field( wp_unslash( $_POST['gateway_key'] ) ) : '';

		if ( '' === $backoffice_key || '' === $gateway_key ) {
			wp_send_json_error( array( 'message' => __( 'Missing Gateway Key.', 'ifthenpay-payments-for-surecart' ) ), 400 );
		}

		$methods_result = $this->resolve_pbl_methods_table( $backoffice_key, $gateway_key );
		if ( is_wp_error( $methods_result ) ) {
			wp_send_json_error( array( 'message' => $methods_result->get_error_message() ) );
		}

		$saved_methods = get_option( 'iftp_sc_pbl_methods', array() );
		$saved_methods = is_array( $saved_methods ) ? $saved_methods : array();

		$effective_default = $this->effective_default_method( $gateway_key, $methods_result, $saved_methods );

		wp_send_json_success(
			array(
				'table_html'   => $this->render_methods_table_html( $methods_result, $saved_methods ),
				'default_html' => $this->render_default_method_field_html( $methods_result, $effective_default ),
			)
		);
	}

	/**
	 * Sends an activation request email to ifthenpay support for a method
	 * that's visible in the global catalog but not yet provisioned on the
	 * selected Gateway Key. Enforces a 24-hour cooldown per (gateway, entity)
	 * pair via a transient, mirroring
	 * `ifthenpay-payments-for-gravityforms`'s `ajax_activate_payment_method()`.
	 *
	 * @return void
	 */
	public function ajax_activate_method(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array(), 403 );
		}

		$entity      = strtoupper( sanitize_text_field( wp_unslash( (string) ( $_POST['entity'] ?? '' ) ) ) );
		$gateway_key = isset( $_POST['gateway_key'] ) ? sanitize_text_field( wp_unslash( $_POST['gateway_key'] ) ) : '';

		if ( '' === $entity || '' === $gateway_key ) {
			wp_send_json_error( array( 'message' => __( 'Missing method or Gateway Key.', 'ifthenpay-payments-for-surecart' ) ), 400 );
		}

		$cooldown_key = 'iftp_sc_activation_' . md5( $gateway_key . '_' . $entity );
		if ( get_transient( $cooldown_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Activation request already sent. Please wait 24 hours before requesting again.', 'ifthenpay-payments-for-surecart' ) ), 429 );
		}

		$current_user = wp_get_current_user();

		IfthenpayEmailHelper::send_activation_email(
			array(
				'gateway_key'      => $gateway_key,
				'entity'           => $entity,
				'backoffice_key'   => (string) get_option( 'iftp_sc_backoffice_key', '' ),
				'customer_email'   => $current_user->user_email,
				'site_url'         => home_url( '/' ),
				'site_name'        => get_bloginfo( 'name' ),
				'wp_version'       => get_bloginfo( 'version' ),
				'surecart_version' => $this->surecart_plugin_version(),
				'plugin_version'   => IFTP_SC_VERSION,
			)
		);

		set_transient( $cooldown_key, 1, DAY_IN_SECONDS );

		wp_send_json_success( array( 'message' => __( 'Activation request sent to ifthenpay support.', 'ifthenpay-payments-for-surecart' ) ) );
	}

	/**
	 * Reads SureCart core's own `Version:` header off its plugin file — no
	 * `SURECART_VERSION`-style constant exists to read directly.
	 *
	 * @return string
	 */
	private function surecart_plugin_version(): string {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_file = WP_PLUGIN_DIR . '/surecart/surecart.php';
		if ( ! file_exists( $plugin_file ) ) {
			return '';
		}

		$data = get_plugin_data( $plugin_file, false, false );
		return (string) ( $data['Version'] ?? '' );
	}

	/**
	 * Notify URLs use ifthenpay's literal `[PLACEHOLDER]` templating, so they
	 * are built by concatenation rather than `add_query_arg()` (which would
	 * urlencode the brackets ifthenpay needs to match verbatim).
	 *
	 * @return string
	 */
	private function multibanco_notify_url(): string {
		return rest_url( 'ifthenpay-surecart/v1/callback/multibanco' )
			. '?order_id=[ORDER_ID]&apk=[ANTI_PHISHING_KEY]&amount=[AMOUNT]&request_id=[REQUEST_ID]&entity=[ENTITY]&reference=[REFERENCE]&paid_at=[PAYMENT_DATETIME]';
	}

	/**
	 * Builds the MB WAY notify URL template.
	 *
	 * @return string
	 */
	private function mbway_notify_url(): string {
		return rest_url( 'ifthenpay-surecart/v1/callback/mbway' )
			. '?order_id=[ORDER_ID]&apk=[ANTI_PHISHING_KEY]&amount=[AMOUNT]&request_id=[REQUEST_ID]&paid_at=[PAYMENT_DATETIME]';
	}

	/**
	 * Builds the ifthenpay Gateway notify URL template.
	 *
	 * @return string
	 */
	private function pbl_notify_url(): string {
		return rest_url( 'ifthenpay-surecart/v1/callback/pbl' )
			. '?ref=[ORDER_ID]&apk=[ANTI_PHISHING_KEY]&val=[AMOUNT]&mtd=[PAYMENT_METHOD]&req=[REQUEST_ID]';
	}

	/**
	 * Renders admin notices for provisioning state.
	 *
	 * @return void
	 */
	public function render_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen      = get_current_screen();
		$on_our_page = $screen && false !== strpos( (string) $screen->id, self::PAGE_SLUG );

		if ( get_option( ManualPaymentMethodProvisioner::OPTION_DEFERRED ) ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				wp_kses_post(
					sprintf(
						/* translators: %s: link to SureCart's API settings */
						__( "ifthenpay payment methods weren't created yet. SureCart's API token isn't configured, so we couldn't add the Multibanco, MB WAY, and ifthenpay Gateway methods automatically. Add the token, then revisit this page to finish setup. %s", 'ifthenpay-payments-for-surecart' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=sc-settings' ) ) . '">' . esc_html__( 'SureCart API settings', 'ifthenpay-payments-for-surecart' ) . '</a>'
					)
				)
			);
			return;
		}

		if ( $on_our_page && get_transient( 'iftp_sc_provisioned_notice' ) ) {
			delete_transient( 'iftp_sc_provisioned_notice' );
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( "ifthenpay payment methods added. We've created Multibanco, MB WAY, and ifthenpay Gateway as Manual Payment Methods in SureCart. Add your ifthenpay keys below, then activate them in Methods.", 'ifthenpay-payments-for-surecart' )
			);
		}
	}
}
