<?php
namespace ElementorPro\Core\Admin;

use Elementor\Core\Base\App;
use Elementor\Settings;
use Elementor\Tools;
use Elementor\Utils;
use ElementorPro\Core\Utils as ProUtils;
use ElementorPro\License\API;
use ElementorPro\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Admin extends App {

	/**
	 * Get module name.
	 *
	 * Retrieve the module name.
	 *
	 * @since 2.3.0
	 * @access public
	 *
	 * @return string Module name.
	 */
	public function get_name() {
		return 'admin';
	}

	/**
	 * Enqueue admin styles.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function enqueue_styles() {
		$suffix = Utils::is_script_debug() ? '' : '.min';

		wp_register_style(
			'elementor-pro-admin',
			ELEMENTOR_PRO_URL . 'assets/css/admin' . $suffix . '.css',
			[],
			ELEMENTOR_PRO_VERSION
		);

		wp_enqueue_style( 'elementor-pro-admin' );
	}

	public function enqueue_scripts() {
		$suffix = Utils::is_script_debug() ? '' : '.min';

		wp_enqueue_script(
			'elementor-pro-admin',
			ELEMENTOR_PRO_URL . 'assets/js/admin' . $suffix . '.js',
			[
				'elementor-admin',
			],
			ELEMENTOR_PRO_VERSION,
			true
		);

		$locale_settings = [];

		/**
		 * Localized admin settings.
		 *
		 * Filters the localized settings used in the admin as JavaScript variables.
		 *
		 * By default Elementor Pro passes some admin settings to be consumed as JavaScript
		 * variables. This hook allows developers to add extra settings values to be consumed
		 * using JavaScript in WordPress admin.
		 *
		 * @since 1.0.0
		 *
		 * @param array $locale_settings Localized settings.
		 */
		$locale_settings = apply_filters( 'elementor_pro/admin/localize_settings', $locale_settings );

		Utils::print_js_config(
			'elementor-pro-admin',
			'ElementorProConfig',
			$locale_settings
		);
	}

	public function remove_go_pro_menu() {
		remove_action( 'admin_menu', [ Plugin::elementor()->settings, 'register_pro_menu' ], Settings::MENU_PRIORITY_GO_PRO );
	}
	public function plugin_row_meta( $plugin_meta, $plugin_file ) {
		if ( ELEMENTOR_PRO_PLUGIN_BASE === $plugin_file ) {
			$row_meta = [
				'changelog' => '<a href="https://go.elementor.com/pro-changelog/" title="' . esc_attr( esc_html__( 'View Elementor Pro Changelog', 'elementor-pro' ) ) . '" target="_blank">' . esc_html__( 'Changelog', 'elementor-pro' ) . '</a>',
			];

			$plugin_meta = array_merge( $plugin_meta, $row_meta );
		}

		return $plugin_meta;
	}

	public function add_finder_items( array $categories ) {
		$categories['settings']['items']['integrations'] = [
			'title' => esc_html__( 'Integrations', 'elementor-pro' ),
			'icon' => 'integration',
			'url' => Settings::get_settings_tab_url( 'integrations' ),
			'keywords' => [ 'integrations', 'settings', 'typekit', 'facebook', 'recaptcha', 'mailchimp', 'drip', 'activecampaign', 'getresponse', 'convertkit', 'elementor' ],
		];

		return $categories;
	}

	public function register_ajax_actions( $ajax_manager ) {
		$ajax_manager->register_ajax_action( 'elementor_site_mailer_campaign', [ $this, 'handle_hints_cta' ] );
	}

	public function handle_hints_cta( $request ) {
		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_send_json_error();
		}

		if ( empty( $request['source'] ) ) {
			return;
		}

		$campaign_data = [
			'source' => sanitize_key( $request['source'] ),
			'campaign' => 'sm-plg-v' . ProUtils\Abtest::get_variation( 'plg_site_mailer_submission' ),
			'medium' => 'wp-dash',
		];

		set_transient( 'elementor_site_mailer_campaign', $campaign_data, 30 * DAY_IN_SECONDS );
	}

	/**
	 * Admin constructor.
	 */
	public function __construct() {
		$this->add_component( 'canary-deployment', new Canary_Deployment() );

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_styles' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_action( 'admin_menu', [ $this, 'remove_go_pro_menu' ], 0 );

		add_filter( 'plugin_action_links_' . ELEMENTOR_PLUGIN_BASE, function ( $links ) {
			return Action_Links::get_links( $links );
		}, 50 );
		add_filter( 'plugin_action_links_' . ELEMENTOR_PRO_PLUGIN_BASE, function ( $links ) {
			return Action_Links::get_pro_links( $links );
		}, 50 );

		add_filter( 'plugin_row_meta', [ $this, 'plugin_row_meta' ], 10, 2 );

		add_filter( 'elementor/finder/categories', [ $this, 'add_finder_items' ] );
		add_action( 'in_plugin_update_message-' . ELEMENTOR_PRO_PLUGIN_BASE, function( $plugin_data ) {
			Plugin::elementor()->admin->version_update_warning( ELEMENTOR_PRO_VERSION, $plugin_data['new_version'] );
		} );

		add_action( 'elementor/ajax/register_actions', [ $this, 'register_ajax_actions' ] );
	}
}
