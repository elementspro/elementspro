<?php
namespace ElementorPro\Core\Updater;

// Prevent loading this file directly and/or if the class is already defined
if (!defined('ABSPATH') || class_exists('Updater'))
	return;
class Updater
{

	/**
	 * @var $config the config for the updater
	 * @access public
	 */
	var $config;

	/**
	 * @var $missing_config any config that is missing from the initialization of this instance
	 * @access public
	 */
	var $missing_config;

	/**
	 * @var $github_data temporiraly store the data fetched from GitHub, allows us to only load the data once per class instance
	 * @access private
	 */
	private $github_data;

	/**
	 * Class Constructor
	 *
	 * @since 1.0
	 * @param array $config the configuration required for the updater to work
	 * @see has_minimum_config()
	 * @return void
	 */
	public function __construct($config = array())
	{

		$defaults = array(
			'slug' => plugin_basename(__FILE__),
			'plugin_basename' => plugin_basename(__FILE__),
			'proper_folder_name' => dirname(plugin_basename(__FILE__)),
			'sslverify' => true,
			'access_token' => '',
		);

		$this->config = wp_parse_args($config, $defaults);

		$this->set_defaults();

		add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'), 100);

		// Hook into the plugin details screen
		add_filter('plugins_api', array($this, 'get_plugin_info'), 10, 3);
		add_filter('upgrader_post_install', array($this, 'upgrader_post_install'), 10, 3);

		// Point WordPress at the actual plugin folder inside the extracted package.
		add_filter('upgrader_source_selection', array($this, 'upgrader_source_selection'), 10, 4);

		// set timeout
		add_filter('http_request_timeout', array($this, 'http_request_timeout'));

		// set sslverify for zip download
		add_filter('http_request_args', array($this, 'http_request_sslverify'), 10, 2);
	}

	/**
	 * Check wether or not the transients need to be overruled and API needs to be called for every single page load
	 *
	 * @return bool overrule or not
	 */
	public function overrule_transients()
	{
		global $pagenow;
		if ('update-core.php' === $pagenow && isset($_GET['force-check'])) {
			return true;
		}
		return (defined('WP_GITHUB_FORCE_UPDATE') && WP_GITHUB_FORCE_UPDATE);
	}

	/**
	 * Set defaults
	 *
	 * @since 1.2
	 * @return void
	 */
	public function set_defaults()
	{
		if (!empty($this->config['access_token'])) {
			$this->config['zip_url'] = add_query_arg(array('access_token' => $this->config['access_token']), $this->config['zip_url']);
		}

		if (!isset($this->config['new_version']))
			$this->config['new_version'] = $this->get_new_version();

		if (!isset($this->config['last_updated']))
			$this->config['last_updated'] = $this->get_date();

		if (!isset($this->config['description']))
			$this->config['description'] = $this->get_description();

		$plugin_data = $this->get_plugin_data();
		if (!isset($this->config['plugin_name']))
			$this->config['plugin_name'] = $plugin_data['Name'];

		if (!isset($this->config['version']))
			$this->config['version'] = $plugin_data['Version'];

		if (!isset($this->config['author']))
			$this->config['author'] = $plugin_data['Author'];

		if (!isset($this->config['homepage']))
			$this->config['homepage'] = $plugin_data['PluginURI'];

		if (!isset($this->config['readme']))
			$this->config['readme'] = 'README.md';

	}

	/**
	 * Callback fn for the http_request_timeout filter
	 *
	 * @since 1.0
	 * @return int timeout value
	 */
	public function http_request_timeout()
	{
		return 2;
	}

	/**
	 * Callback fn for the http_request_args filter
	 *
	 * @param unknown $args
	 * @param unknown $url
	 *
	 * @return mixed
	 */
	public function http_request_sslverify($args, $url)
	{
		if ($this->get_zip_url() == $url)
			$args['sslverify'] = $this->config['sslverify'];

		return $args;
	}

	private function get_zip_url()
	{
		return str_replace('{release_version}', $this->config['new_version'], $this->config['zip_url']);
	}

	/**
	 * Get New Version from GitHub
	 *
	 * @since 1.0
	 * @return int $version the version number
	 */
	public function get_new_version()
	{
		$version = get_site_transient(md5($this->config['slug']) . '_new_version');

		if ($this->overrule_transients() || (!isset($version) || !$version || '' == $version)) {

			$raw_response = $this->remote_get(trailingslashit($this->config['raw_url']) . basename($this->config['slug']));

			if (is_wp_error($raw_response))
				$version = false;

			if (is_array($raw_response)) {
				if (!empty($raw_response['body']))
					preg_match('/.*Version\:\s*(.*)$/mi', $raw_response['body'], $matches);
			}

			if (empty($matches[1]))
				$version = false;
			else
				$version = $matches[1];

			// back compat for older readme version handling
			// only done when there is no version found in file name
			if (false === $version) {
				$raw_response = $this->remote_get(trailingslashit($this->config['raw_url']) . $this->config['readme']);

				if (is_wp_error($raw_response))
					return $version;

				preg_match('#^\s*`*~Current Version\:\s*([^~]*)~#im', $raw_response['body'], $__version);

				if (isset($__version[1])) {
					$version_readme = $__version[1];
					if (-1 == version_compare($version, $version_readme))
						$version = $version_readme;
				}
			}

			// refresh every 6 hours
			if (false !== $version)
				set_site_transient(md5($this->config['slug']) . '_new_version', $version, 60 * 60 * 6);
		}

		return $version;
	}

	/**
	 * Interact with GitHub
	 *
	 * @param string $query
	 *
	 * @since 1.6
	 * @return mixed
	 */
	public function remote_get($query)
	{
		if (!empty($this->config['access_token']))
			$query = add_query_arg(array('access_token' => $this->config['access_token']), $query);

		$raw_response = wp_remote_get($query, array(
			'sslverify' => $this->config['sslverify']
		));

		return $raw_response;
	}

	/**
	 * Get GitHub Data from the specified repository
	 *
	 * @since 1.0
	 * @return array $github_data the data
	 */
	public function get_github_data()
	{
		if (isset($this->github_data) && !empty($this->github_data)) {
			$github_data = $this->github_data;
		} else {
			$github_data = get_site_transient(md5($this->config['slug']) . '_github_data');

			if ($this->overrule_transients() || (!isset($github_data) || !$github_data || '' == $github_data)) {
				$github_data = $this->remote_get($this->config['api_url']);

				if (is_wp_error($github_data))
					return false;

				$github_data = json_decode($github_data['body']);

				// refresh every 6 hours
				set_site_transient(md5($this->config['slug']) . '_github_data', $github_data, 60 * 60 * 6);
			}

			// Store the data in this class instance for future calls
			$this->github_data = $github_data;
		}

		return $github_data;
	}

	/**
	 * Get update date
	 *
	 * @since 1.0
	 * @return string $date the date
	 */
	public function get_date()
	{
		$_date = $this->get_github_data();
		return (!empty($_date->updated_at)) ? date('Y-m-d', strtotime($_date->updated_at)) : false;
	}

	/**
	 * Get plugin description
	 *
	 * @since 1.0
	 * @return string $description the description
	 */
	public function get_description()
	{
		$_description = $this->get_github_data();
		return (!empty($_description->description)) ? $_description->description : false;
	}

	/**
	 * Get Plugin data
	 *
	 * @since 1.0
	 * @return object $data the data
	 */
	public function get_plugin_data()
	{
		if (!function_exists('get_plugin_data')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugin_file = rtrim(WP_PLUGIN_DIR, '/') . '/' . $this->config['proper_folder_name'] . '/' . $this->config['slug'];
		if (!is_file($plugin_file)) {
			return false;
		}
		$data = get_plugin_data($plugin_file, false, false);
		return $data;
	}

	/**
	 * Hook into the plugin update check and connect to GitHub
	 *
	 * @since 1.0
	 * @param object  $transient the plugin data transient
	 * @return object $transient updated plugin data transient
	 */
	public function check_update($transient)
	{

		// Check if the transient contains the 'checked' information
		// If not, just return its value without hacking it
		global $pagenow;

		if (!is_object($transient)) {
			$transient = new \stdClass();
		}

		if ('plugins.php' === $pagenow && is_multisite()) {
			return $transient;
		}
		// check the version and decide if it's new
		$update = version_compare($this->config['new_version'], $this->config['version']);

		if (1 === $update) {
			if (!empty($transient->checked)) {
				$transient->last_checked = current_time('timestamp');
				$transient->checked[$this->config['plugin_basename']] = $this->config['new_version'];
			}

			$response = new \stdClass();
			$response->new_version = $this->config['new_version'];
			$response->plugin = $this->config['plugin_basename'];
			$response->slug = $this->config['proper_folder_name'];
			$response->url = add_query_arg(array('access_token' => $this->config['access_token']), $this->config['github_url']);
			$response->package = $this->get_zip_url();

			// If response is false, don't alter the transient
			if (false !== $response)
				$transient->response[$this->config['plugin_basename']] = $response;
		} else {
			if (!isset($transient->checked) || !is_array($transient->checked)) {
				$transient->checked = array();
			}

			$transient->last_checked = current_time('timestamp');
			$transient->checked[$this->config['plugin_basename']] = $this->config['version'];

			if (isset($transient->response[$this->config['plugin_basename']])) {
				unset($transient->response[$this->config['plugin_basename']]);
			}

			if (!isset($transient->no_update) || !is_array($transient->no_update)) {
				$transient->no_update = array();
			}

			$no_update = new \stdClass();
			$no_update->id = $this->config['plugin_basename'];
			$no_update->slug = $this->config['proper_folder_name'];
			$no_update->plugin = $this->config['plugin_basename'];
			$no_update->new_version = $this->config['version'];
			$no_update->url = add_query_arg(array('access_token' => $this->config['access_token']), $this->config['github_url']);
			$no_update->package = '';
			$transient->no_update[$this->config['plugin_basename']] = $no_update;
		}

		return $transient;
	}

	/**
	 * Get Plugin info
	 *
	 * @since 1.0
	 * @param bool    $false  always false
	 * @param string  $action the API function being performed
	 * @param object  $args   plugin arguments
	 * @return object $response the plugin info
	 */
	public function get_plugin_info($data, $action, $args)
	{
		// Check if this call API is for the right plugin
		if (
			!is_object($args) ||
			!isset($args->slug) ||
			(
				$args->slug !== $this->config['slug'] &&
				$args->slug !== $this->config['proper_folder_name']
			)
		)
			return $data;

		if (!is_object($data)) {
			$data = new \stdClass();
		}

		$data->slug = $this->config['slug'];
		$data->plugin_name = $this->config['plugin_name'];
		$data->version = $this->config['new_version'];
		$data->author = $this->config['author'];
		$data->homepage = $this->config['homepage'];
		$data->requires = $this->config['requires'];
		$data->tested = $this->config['tested'];
		$data->downloaded = 0;
		$data->last_updated = $this->config['last_updated'];
		$data->sections = array('description' => $this->config['description']);
		$data->download_link = $this->get_zip_url();

		return $data;
	}

	/**
	 * Upgrader/Updater
	 * Move & activate the plugin, echo the update message
	 *
	 * @since 1.0
	 * @param boolean $true       always true
	 * @param mixed   $hook_extra not used
	 * @param array   $result     the result of the move
	 * @return array $result the result of the move
	 */
	public function upgrader_post_install($true, $hook_extra, $result)
	{
		if (!$this->is_target_plugin($hook_extra)) {
			return $result;
		}

		// WordPress has already installed the plugin into the final folder at this point.
		$proper_destination = WP_PLUGIN_DIR . '/' . $this->config['proper_folder_name'];
		$installed_destination = isset($result['destination']) ? untrailingslashit($result['destination']) : '';

		if (
			$installed_destination &&
			basename($installed_destination) !== $this->config['proper_folder_name'] &&
			$installed_destination !== untrailingslashit($proper_destination)
		) {
			return new \WP_Error(
				'install_move_failed',
				__('The update installed into an unexpected plugin folder.', 'elementor-pro')
			);
		}

		$result['destination'] = $proper_destination;
		$was_active = is_plugin_active($this->config['plugin_basename']);
		$activate = true;

		if ($was_active) {
			$activate = activate_plugin($this->config['plugin_basename']);
		}

		$this->clear_update_state();

		// Output the update message
		$fail = __('The plugin has been updated, but could not be reactivated. Please reactivate it manually.', 'elementor-pro');
		$success = __('Plugin reactivated successfully.', 'elementor-pro');
		if ($was_active || is_wp_error($activate)) {
			echo is_wp_error($activate) ? $fail : $success;
		}
		return $result;

	}

	/**
	 * Point WordPress at the actual plugin folder inside the extracted package.
	 * This allows release zips with wrapper folders or extra metadata directories
	 * to install as long as they contain a valid plugin root somewhere inside.
	 *
	 * @param string $source        Path to the source folder
	 * @param string $remote_source Path to the temp extraction directory
	 * @param object $upgrader      The upgrader instance
	 * @return string|WP_Error Corrected source path or error
	 */
	public function upgrader_source_selection($source, $remote_source, $upgrader, $hook_extra = array())
	{
		global $wp_filesystem;

		if (!$this->is_target_plugin($hook_extra, $upgrader)) {
			return $source;
		}

		$plugin_source = $this->locate_plugin_source($source);

		if (is_wp_error($plugin_source)) {
			return $plugin_source;
		}

		$corrected_source = trailingslashit($remote_source) . $this->config['proper_folder_name'];
		$normalized_plugin_source = untrailingslashit($plugin_source);
		$normalized_corrected_source = untrailingslashit($corrected_source);

		if (basename($normalized_plugin_source) === $this->config['proper_folder_name']) {
			return trailingslashit($normalized_plugin_source);
		}

		if ($normalized_plugin_source !== $normalized_corrected_source) {
			if ($wp_filesystem->move($normalized_plugin_source, $normalized_corrected_source)) {
				return trailingslashit($normalized_corrected_source);
			} else {
				return new \WP_Error(
					'rename_failed',
					__('The update could not rename the plugin folder.', 'elementor-pro')
				);
			}
		}

		return trailingslashit($normalized_plugin_source);
	}

	private function locate_plugin_source($source)
	{
		if ($this->directory_has_plugin_file($source)) {
			return $source;
		}

		$expected_directory = trailingslashit($source) . $this->config['proper_folder_name'];
		if (is_dir($expected_directory) && $this->directory_has_plugin_file($expected_directory)) {
			return $expected_directory;
		}

		$subdirectories = glob(trailingslashit($source) . '*', GLOB_ONLYDIR);
		if (!is_array($subdirectories)) {
			$subdirectories = array();
		}

		foreach ($subdirectories as $subdirectory) {
			if ($this->directory_has_plugin_file($subdirectory)) {
				return $subdirectory;
			}
		}

		return new \WP_Error(
			'incompatible_archive_no_plugins',
			__('The package could not be installed.', 'elementor-pro'),
			__('No valid plugins were found.', 'elementor-pro')
		);
	}

	private function directory_has_plugin_file($directory)
	{
		$plugin_files = glob(trailingslashit($directory) . '*.php');

		if (!is_array($plugin_files) || empty($plugin_files)) {
			return false;
		}

		if (!function_exists('get_plugin_data')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ($plugin_files as $plugin_file) {
			$plugin_data = get_plugin_data($plugin_file, false, false);

			if (!empty($plugin_data['Name'])) {
				return true;
			}
		}

		return false;
	}

	private function is_target_plugin($hook_extra = array(), $upgrader = null)
	{
		if (isset($hook_extra['plugin']) && $hook_extra['plugin'] === $this->config['plugin_basename']) {
			return true;
		}

		if (
			is_object($upgrader) &&
			isset($upgrader->skin) &&
			is_object($upgrader->skin)
		) {
			if (isset($upgrader->skin->plugin) && $upgrader->skin->plugin === $this->config['plugin_basename']) {
				return true;
			}

			$plugin_info_name = null;

			if (isset($upgrader->skin->plugin_info)) {
				if (is_array($upgrader->skin->plugin_info) && isset($upgrader->skin->plugin_info['Name'])) {
					$plugin_info_name = $upgrader->skin->plugin_info['Name'];
				} elseif (is_object($upgrader->skin->plugin_info) && isset($upgrader->skin->plugin_info->Name)) {
					$plugin_info_name = $upgrader->skin->plugin_info->Name;
				}
			}

			if (
				null !== $plugin_info_name &&
				$plugin_info_name === $this->config['plugin_name']
			) {
				return true;
			}
		}

		return false;
	}

	private function clear_update_state()
	{
		delete_site_transient(md5($this->config['slug']) . '_new_version');
		delete_site_transient(md5($this->config['slug']) . '_github_data');
		delete_site_transient('update_plugins');

		if (function_exists('wp_clean_plugins_cache')) {
			wp_clean_plugins_cache(true);
		}
	}
}
