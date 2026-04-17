<?php
namespace ElementorPro\Core\Updater;

if (!defined('ABSPATH') || class_exists('Updater'))
	return;
class Updater
{
	var $config;
	var $missing_config;
	private $github_data;

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
		add_filter('plugins_api', array($this, 'get_plugin_info'), 10, 3);
		add_filter('upgrader_post_install', array($this, 'upgrader_post_install'), 10, 3);
		add_filter('upgrader_source_selection', array($this, 'upgrader_source_selection'), 10, 4);
		add_filter('http_request_timeout', array($this, 'http_request_timeout'));
		add_filter('http_request_args', array($this, 'http_request_sslverify'), 10, 2);
	}

	public function overrule_transients()
	{
		global $pagenow;
		if ('update-core.php' === $pagenow && isset($_GET['force-check'])) {
			return true;
		}
		return (defined('WP_GITHUB_FORCE_UPDATE') && WP_GITHUB_FORCE_UPDATE);
	}

	public function set_defaults()
	{
		if (!empty($this->config['access_token'])) {
			$this->config['zip_url'] = add_query_arg(array('access_token' => $this->config['access_token']), $this->config['zip_url']);
		}

		if (!isset($this->config['new_version'])) {
			$this->config['new_version'] = $this->get_new_version();
		}
		if (!isset($this->config['last_updated'])) {
			$this->config['last_updated'] = $this->get_date();
		}
		if (!isset($this->config['description'])) {
			$this->config['description'] = $this->get_description();
		}

		$plugin_data = $this->get_plugin_data();
		if (!isset($this->config['plugin_name'])) {
			$this->config['plugin_name'] = $plugin_data['Name'];
		}
		if (!isset($this->config['version'])) {
			$this->config['version'] = $plugin_data['Version'];
		}
		if (!isset($this->config['author'])) {
			$this->config['author'] = $plugin_data['Author'];
		}
		if (!isset($this->config['homepage'])) {
			$this->config['homepage'] = $plugin_data['PluginURI'];
		}
		if (!isset($this->config['readme'])) {
			$this->config['readme'] = 'README.md';
		}
	}

	public function http_request_timeout()
	{
		return 2;
	}

	public function http_request_sslverify($args, $url)
	{
		if ($this->get_zip_url() == $url) {
			$args['sslverify'] = $this->config['sslverify'];
		}

		return $args;
	}

	private function get_zip_url()
	{
		return str_replace('{release_version}', $this->config['new_version'], $this->config['zip_url']);
	}

	public function get_new_version()
	{
		$version = get_site_transient(md5($this->config['slug']) . '_new_version');

		if ($this->overrule_transients() || (!isset($version) || !$version || '' == $version)) {
			$raw_response = $this->remote_get(trailingslashit($this->config['raw_url']) . basename($this->config['slug']));

			if (is_wp_error($raw_response)) {
				$version = false;
			}

			if (is_array($raw_response) && !empty($raw_response['body'])) {
				preg_match('/.*Version\:\s*(.*)$/mi', $raw_response['body'], $matches);
			}

			if (empty($matches[1])) {
				$version = false;
			} else {
				$version = $matches[1];
			}

			if (false === $version) {
				$raw_response = $this->remote_get(trailingslashit($this->config['raw_url']) . $this->config['readme']);

				if (is_wp_error($raw_response)) {
					return $version;
				}

				preg_match('#^\s*`*~Current Version\:\s*([^~]*)~#im', $raw_response['body'], $__version);

				if (isset($__version[1])) {
					$version_readme = $__version[1];
					if (-1 == version_compare($version, $version_readme)) {
						$version = $version_readme;
					}
				}
			}

			if (false !== $version) {
				set_site_transient(md5($this->config['slug']) . '_new_version', $version, 60 * 60 * 6);
			}
		}

		return $version;
	}

	public function remote_get($query)
	{
		if (!empty($this->config['access_token'])) {
			$query = add_query_arg(array('access_token' => $this->config['access_token']), $query);
		}

		return wp_remote_get($query, array(
			'sslverify' => $this->config['sslverify']
		));
	}

	public function get_github_data()
	{
		if (isset($this->github_data) && !empty($this->github_data)) {
			$github_data = $this->github_data;
		} else {
			$github_data = get_site_transient(md5($this->config['slug']) . '_github_data');

			if ($this->overrule_transients() || (!isset($github_data) || !$github_data || '' == $github_data)) {
				$github_data = $this->remote_get($this->config['api_url']);

				if (is_wp_error($github_data)) {
					return false;
				}

				$github_data = json_decode($github_data['body']);
				set_site_transient(md5($this->config['slug']) . '_github_data', $github_data, 60 * 60 * 6);
			}

			$this->github_data = $github_data;
		}

		return $github_data;
	}

	public function get_date()
	{
		$_date = $this->get_github_data();
		return (!empty($_date->updated_at)) ? date('Y-m-d', strtotime($_date->updated_at)) : false;
	}

	public function get_description()
	{
		$_description = $this->get_github_data();
		return (!empty($_description->description)) ? $_description->description : false;
	}

	public function get_plugin_data()
	{
		if (!function_exists('get_plugin_data')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugin_file = rtrim(WP_PLUGIN_DIR, '/') . '/' . $this->config['proper_folder_name'] . '/' . $this->config['slug'];
		if (!is_file($plugin_file)) {
			return false;
		}
		return get_plugin_data($plugin_file, false, false);
	}

	public function check_update($transient)
	{
		global $pagenow;

		if (!is_object($transient)) {
			$transient = new \stdClass();
		}

		if ('plugins.php' === $pagenow && is_multisite()) {
			return $transient;
		}

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

			if (false !== $response) {
				$transient->response[$this->config['plugin_basename']] = $response;
			}
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

	public function get_plugin_info($data, $action, $args)
	{
		if (
			!is_object($args) ||
			!isset($args->slug) ||
			(
				$args->slug !== $this->config['slug'] &&
				$args->slug !== $this->config['proper_folder_name']
			)
		) {
			return $data;
		}

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

	public function upgrader_post_install($true, $hook_extra, $result)
	{
		if (!$this->is_target_plugin($hook_extra)) {
			return $result;
		}

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

		$fail = __('The plugin has been updated, but could not be reactivated. Please reactivate it manually.', 'elementor-pro');
		$success = __('Plugin reactivated successfully.', 'elementor-pro');
		if ($was_active || is_wp_error($activate)) {
			echo is_wp_error($activate) ? $fail : $success;
		}
		return $result;
	}

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
			}

			return new \WP_Error(
				'rename_failed',
				__('The update could not rename the plugin folder.', 'elementor-pro')
			);
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

			if (null !== $plugin_info_name && $plugin_info_name === $this->config['plugin_name']) {
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