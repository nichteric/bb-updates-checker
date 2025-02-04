<?php
/*
Plugin Name: BB Update Checker
Description: Adds the update functionality to themes and plugins.
Version: 1.2
Author: Eric Leclercq <eric@curious.care>
Text Domain: bb-updates-checker
*/

defined('ABSPATH') || exit();

// URL of the server where themes and plugins are published
define('BB_UPDATE_CHECKER_URL', 'https://your.update.server/path/');

define('BB_UPDATE_CHECKER_FREQUENCY', 4 * HOUR_IN_SECONDS);

// Regexp to detect token in plugin and theme headers
define('BB_UPDATE_CHECKER_TOKEN', 'BB (Update Checker|Updates):\s+enabled');

if (! function_exists('get_plugins')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

class BBUpdateChecker
{
    public $plugins = [];
    public $themes = [];

    public function __construct()
    {

    // Find plugins which use BBUpdateChecker
        foreach (get_plugins() as $plugin => $data) {
            $file = ABSPATH . 'wp-content/plugins/' . $plugin;
            if (preg_match("/" . BB_UPDATE_CHECKER_TOKEN. "/", file_get_contents($file))) {
                $this->plugins[] = new BBUpdateCheckerPlugin(dirname($plugin));
            }
        }

        // Find themes which use BBUpdateChecker
        foreach (glob(get_theme_root() . "/*", GLOB_ONLYDIR) as $theme_dir) {
            if (preg_match("/" . BB_UPDATE_CHECKER_TOKEN. "/", file_get_contents("{$theme_dir}/style.css"))) {
                $slug = basename($theme_dir);
                $this->themes[] = new BBUpdateCheckerTheme($slug);
            }
        }

        $this->add_admin_scripts();

        // Register with WP update API
        add_filter('site_transient_update_plugins', [$this, 'add_plugins_info']);

        // Check if we should clear the cache
        if (!empty($_GET['action']) &&  $_GET['action'] == 'clear_cache') {
            foreach ($this->plugins as $p) {
                delete_transient($p->cache_key);
            }
            foreach ($this->themes as $p) {
                delete_transient($p->cache_key);
            }
            $action = 'admin_notices';
            if (is_multisite()) {
                $action = "network_{$action}";
            }
            add_action($action, function () {
                echo "<div class=\"notice notice-success\"><p>" . __('Update cache cleared', 'bb-updates-checker') . "</p></div>";
            });
        }
    }

    public function add_admin_scripts()
    {
        // Add "Check for updates" button
        add_action('admin_enqueue_scripts', function () {
            wp_register_script('bb-updates-checker', '');
            wp_enqueue_script('bb-updates-checker', '', [], false, [ 'in_footer'=> true ]);
            $check_for_update = __('Check for updates', 'bb-updates-checker');
            $url  = is_multisite() ? network_admin_url() : admin_url();
            $script = <<<EOF
  var button_plugins = jQuery("<a class=\"button\" href=\"{$url}plugins.php?action=clear_cache\">$check_for_update</a>");
  let button_themes = jQuery("<a class=\"hide-if-no-js page-title-action\" href=\"{$url}themes.php?action=clear_cache\">$check_for_update</a>");
  jQuery('.wp-admin.plugins-php .tablenav.top .actions.bulkactions').after(button_plugins);
  jQuery('.wp-admin.themes-php .wrap a.page-title-action').after(button_themes);
  EOF;
            wp_add_inline_script('bb-updates-checker', $script);
        });
    }

    public function add_plugins_info($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }
        foreach ($this->plugins as $p) {
            $res = $p->add_plugin_info();
            if ($res) {
                $transient->response[$res->plugin] = $res;
            }
        }
        return $transient;
    }
}

class BBUpdateCheckerPlugin
{
    public $update_url = BB_UPDATE_CHECKER_URL;
    public $cache_allowed = true;
    public $slug;
    public $name;
    public $version;
    public $cache_key;

    public function __construct($slug)
    {
        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . "/{$slug}/{$slug}.php");
        $this->slug = $slug;
        $this->name = $plugin_data['Name'];
        $this->version = $plugin_data['Version'];
        $this->cache_key = "plugin_{$slug}_upd";

        add_filter('plugins_api', [$this, 'info'], 20, 3);
        add_action('upgrader_process_complete', [$this, 'purge'], 10, 2);
    }

    public function add_plugin_info()
    {
        $remote = $this->request_info();
        if (
      $remote &&
      version_compare($this->version, $remote->version, '<') &&
      version_compare($remote->requires, get_bloginfo('version'), '<=') &&
      version_compare($remote->requires_php, PHP_VERSION, '<')
    ) {
            $res = new stdClass();
            $res->slug = $this->slug;
            $res->plugin = "{$this->slug}/{$this->slug}.php";
            $res->new_version = $remote->version;
            $res->tested = $remote->tested;
            $res->package = $remote->download_url;
            return $res;
        }
    }

    public function info($res, $action, $args)
    {
        // do nothing if you're not getting plugin information right now
        if ('plugin_information' !== $action) {
            return $res;
        }
        // do nothing if it is not our plugin
        if ($this->slug !== $args->slug) {
            return $res;
        }
        // get updates
        $remote = $this->request_info();
        if (!$remote) {
            return $res;
        }
        $res = new stdClass();
        $res->name = $remote->name;
        $res->slug = $remote->slug;
        $res->version = $remote->version;
        $res->tested = $remote->tested;
        $res->requires = $remote->requires;
        $res->author = $remote->author;
        $res->author_profile = $remote->author_profile;
        $res->download_link = $remote->download_url;
        $res->trunk = $remote->download_url;
        $res->requires_php = $remote->requires_php;
        $res->last_updated = $remote->last_updated;
        $res->sections = [
      'description' => $remote->sections->description,
      'installation' => $remote->sections->installation,
      'changelog' => $remote->sections->changelog,
    ];
        // FIXME: not using banners for now
        if (!empty($remote->banners)) {
            $res->banners = [
        'low' => $remote->banners->low,
        'high' => $remote->banners->high,
      ];
        }
        return $res;
    }

    public function request_info()
    {
        $remote = get_transient($this->cache_key);
        if (false === $remote || !$this->cache_allowed) {
            $remote = wp_remote_get("{$this->update_url}/?type=plugin&slug={$this->slug}", [
        'timeout' => 10,
        'headers' => ['Accept' => 'application/json'],
      ]);
            if (is_wp_error($remote) || 200 !== wp_remote_retrieve_response_code($remote) || empty(wp_remote_retrieve_body($remote))) {
                return false;
            }
            set_transient($this->cache_key, $remote, BB_UPDATE_CHECKER_FREQUENCY);
        }
        return json_decode(wp_remote_retrieve_body($remote));
    }

    public function purge($upgrader, $options)
    {
        if ($this->cache_allowed && 'update' === $options['action'] && 'plugin' === $options['type']) {
            // just clean the cache when new plugin version is installed
            delete_transient($this->cache_key);
        }
    }
}

class BBUpdateCheckerTheme
{
    public $update_url = BB_UPDATE_CHECKER_URL;
    public $cache_allowed = true;
    public $slug;
    public $name;
    public $version;
    public $cache_key;

    public function __construct($slug)
    {
        $theme_data = wp_get_theme($slug);
        $this->slug = $slug;
        $this->name = $theme_data->get('Name');
        $this->version = $theme_data->get('Version');
        $this->cache_key = "theme_{$slug}_upd";

        add_filter('site_transient_update_themes', [$this, 'update']);
        add_action('upgrader_process_complete', [$this, 'purge'], 10, 2);
    }

    public function update($transient)
    {
        if (empty($transient)) {
            return $transient;
        }

        $remote = get_transient($this->cache_key);
        if (false == $remote || !$this->cache_allowed) {

      // connect to a remote server where the update information is stored
            $remote = wp_remote_get(BB_UPDATE_CHECKER_URL . '/?type=theme&slug=' . $this->slug, [
         'timeout' => 10,
         'headers' => ['Accept' => 'application/json']
       ]);

            if (is_wp_error($remote) || 200 !== wp_remote_retrieve_response_code($remote) || empty(wp_remote_retrieve_body($remote))) {
                return $transient;
            }

            $remote = json_decode(wp_remote_retrieve_body($remote));
            if (!$remote) {
                return $transient; // who knows, meybe JSON is not valid
            }
            set_transient($this->cache_key, $remote, 1); //HOUR_IN_SECONDS);
        }

        // encode the response body
        $data = [
      'theme' => $this->slug,
      'url' => null,
      'requires' => $remote->requires,
      'requires_php' => $remote->requires_php,
      'new_version' => $remote->version,
      'package' => $remote->download_url,
    ];

        // check all the versions now
        if (
      $remote
      && version_compare($this->version, $remote->version, '<')
      && version_compare($remote->requires, get_bloginfo('version'), '<')
      && version_compare($remote->requires_php, PHP_VERSION, '<')
    ) {
            $transient->response[ $this->slug ] = $data;
        } else {
            $transient->no_update[$this->slug ] = $data;
        }
        return $transient;
    }

    public function purge($upgrader, $options)
    {
        if ($this->cache_allowed && 'update' === $options['action'] && 'theme' === $options['type']) {
            delete_transient($this->cache_key);
        }
    }
}

if (is_admin()) {
    add_action('init', function () {
        new BBUpdateChecker();
    });
}
