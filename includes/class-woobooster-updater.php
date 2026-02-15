<?php
/**
 * WooBooster GitHub Updater.
 *
 * Checks GitHub Releases for new versions and enables one-click updates
 * from the WordPress admin Plugins page — just like plugins from wordpress.org.
 *
 * How it works:
 * 1. On the update check transient, queries the GitHub API for the latest release tag.
 * 2. Compares the tag (e.g. "1.0.1") against WOOBOOSTER_VERSION.
 * 3. If newer, injects the update info into WordPress's update transient.
 * 4. WordPress handles the download + install from the release .zip asset.
 *
 * Release workflow:
 * 1. Update WOOBOOSTER_VERSION in woobooster.php
 * 2. Commit + push to main
 * 3. Create a GitHub Release with tag matching the version (e.g. "1.0.1")
 * 4. Attach a .zip of the plugin folder as a release asset (or let GitHub auto-zip)
 * 5. WordPress sites will detect and offer the update automatically
 *
 * @package WooBooster
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Updater
{

    /**
     * GitHub repository owner.
     *
     * @var string
     */
    private $github_user;

    /**
     * GitHub repository name.
     *
     * @var string
     */
    private $github_repo;

    /**
     * Plugin basename (e.g. "woobooster/woobooster.php").
     *
     * @var string
     */
    private $plugin_basename;

    /**
     * Current plugin version.
     *
     * @var string
     */
    private $current_version;

    /**
     * Plugin slug.
     *
     * @var string
     */
    private $plugin_slug;

    /**
     * Cached GitHub API response.
     *
     * @var object|null
     */
    private $github_response = null;

    /**
     * Constructor.
     *
     * @param string $github_user     GitHub username or org.
     * @param string $github_repo     Repository name.
     * @param string $plugin_basename Plugin basename.
     * @param string $current_version Current version string.
     */
    public function __construct($github_user, $github_repo, $plugin_basename, $current_version)
    {
        $this->github_user = $github_user;
        $this->github_repo = $github_repo;
        $this->plugin_basename = $plugin_basename;
        $this->current_version = $current_version;
        $this->plugin_slug = dirname($plugin_basename);
    }

    /**
     * Initialize update hooks.
     */
    public function init()
    {
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'post_install'), 10, 3);

        // Add "Check for updates" link on the Plugins list page.
        add_filter('plugin_action_links_' . $this->plugin_basename, array($this, 'add_check_update_link'));

        // Show inline update notice row if update available.
        add_action('after_plugin_row_' . $this->plugin_basename, array($this, 'show_update_notice'), 10, 2);

        // Admin notice when GitHub API fails due to missing token.
        add_action('admin_notices', array($this, 'maybe_show_token_notice'));

        // Handle force-check request from plugins page link.
        add_action('admin_init', array($this, 'handle_force_check'));
    }

    /**
     * Add "Check for updates" action link to plugins list.
     *
     * @param array $links Existing action links.
     * @return array Modified links.
     */
    public function add_check_update_link($links)
    {
        $check_url = wp_nonce_url(
            admin_url('plugins.php?woobooster_force_check=1'),
            'woobooster_force_check'
        );
        $links['check_update'] = '<a href="' . esc_url($check_url) . '">' . esc_html__('Check for updates', 'woobooster') . '</a>';
        return $links;
    }

    /**
     * Handle force-check request from the plugins page link.
     * Called early via admin_init.
     */
    public function handle_force_check()
    {
        if (empty($_GET['woobooster_force_check'])) {
            return;
        }

        check_admin_referer('woobooster_force_check');

        if (!current_user_can('update_plugins')) {
            return;
        }

        $this->force_check();

        // Redirect back to plugins page with result message.
        $update_transient = get_site_transient('update_plugins');
        $has_update = isset($update_transient->response[$this->plugin_basename]);

        wp_safe_redirect(add_query_arg(
            'woobooster_checked',
            $has_update ? 'update_available' : 'up_to_date',
            admin_url('plugins.php')
        ));
        exit;
    }

    /**
     * Force a fresh update check — clears all caches.
     */
    public function force_check()
    {
        $this->github_response = null;
        delete_transient('woobooster_github_release');
        delete_transient('woobooster_github_api_error');
        delete_site_transient('update_plugins');
        wp_update_plugins();
    }

    /**
     * Show inline update notice below the plugin row.
     *
     * @param string $file   Plugin basename.
     * @param array  $plugin Plugin data.
     */
    public function show_update_notice($file, $plugin)
    {
        // Show feedback message after force-check.
        if (!empty($_GET['woobooster_checked'])) {
            $msg = ('update_available' === $_GET['woobooster_checked'])
                ? __('Update found! Click "update now" above.', 'woobooster')
                : sprintf(__('You are running the latest version (v%s).', 'woobooster'), $this->current_version);

            echo '<tr class="plugin-update-tr"><td colspan="4" class="plugin-update colspanchange">';
            echo '<div class="notice inline notice-info"><p>' . esc_html($msg) . '</p></div>';
            echo '</td></tr>';
        }
    }

    /**
     * Show admin notice if GitHub API failed due to missing auth token.
     */
    public function maybe_show_token_notice()
    {
        $error = get_transient('woobooster_github_api_error');
        if (!$error || !current_user_can('manage_options')) {
            return;
        }

        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p><strong>WooBooster:</strong> ' . esc_html($error) . '</p>';
        echo '</div>';
    }

    /**
     * Fetch the latest release from GitHub API.
     *
     * @return object|null Release data or null on failure.
     */
    private function get_github_release()
    {
        if (null !== $this->github_response) {
            return $this->github_response;
        }

        // Check transient cache first (avoid hammering GitHub API).
        $cache_key = 'woobooster_github_release';
        $cached = get_transient($cache_key);

        if (false !== $cached) {
            $this->github_response = $cached;
            return $cached;
        }

        $url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            $this->github_user,
            $this->github_repo
        );

        $headers = array(
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'WooBooster-Updater/' . $this->current_version,
        );

        // Support private repos via token defined in wp-config.php:
        // define( 'WOOBOOSTER_GITHUB_TOKEN', 'ghp_xxxxx' );
        if (defined('WOOBOOSTER_GITHUB_TOKEN') && WOOBOOSTER_GITHUB_TOKEN) {
            $headers['Authorization'] = 'token ' . WOOBOOSTER_GITHUB_TOKEN;
        }

        $response = wp_remote_get($url, array(
            'headers' => $headers,
            'timeout' => 10,
        ));

        if (is_wp_error($response)) {
            set_transient('woobooster_github_api_error', __('Could not connect to GitHub. Check your server\'s outbound connectivity.', 'woobooster'), HOUR_IN_SECONDS);
            return null;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if (200 !== $status_code) {
            // Detect private repo without token.
            if (404 === $status_code && (!defined('WOOBOOSTER_GITHUB_TOKEN') || !WOOBOOSTER_GITHUB_TOKEN)) {
                set_transient(
                    'woobooster_github_api_error',
                    sprintf(
                        /* translators: %s: constant name */
                        __('Auto-updates disabled — the GitHub repo is private. Add %s to wp-config.php with a valid Personal Access Token.', 'woobooster'),
                        'WOOBOOSTER_GITHUB_TOKEN'
                    ),
                    DAY_IN_SECONDS
                );
            } elseif (403 === $status_code) {
                set_transient('woobooster_github_api_error', __('GitHub API rate limit exceeded. Updates will retry in 1 hour.', 'woobooster'), HOUR_IN_SECONDS);
            } elseif (401 === $status_code) {
                set_transient('woobooster_github_api_error', __('GitHub token is invalid or expired. Please update WOOBOOSTER_GITHUB_TOKEN in wp-config.php.', 'woobooster'), DAY_IN_SECONDS);
            }
            return null;
        }

        // Clear any previous error on success.
        delete_transient('woobooster_github_api_error');

        $body = json_decode(wp_remote_retrieve_body($response));

        if (empty($body) || empty($body->tag_name)) {
            return null;
        }

        $this->github_response = $body;

        // Cache for 6 hours.
        set_transient($cache_key, $body, 6 * HOUR_IN_SECONDS);

        return $body;
    }

    /**
     * Check for updates and inject into WordPress update transient.
     *
     * @param object $transient Update transient data.
     * @return object Modified transient.
     */
    public function check_update($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_github_release();

        if (!$release) {
            return $transient;
        }

        // Strip leading "v" from tag if present (e.g. "v1.0.1" → "1.0.1").
        $latest_version = ltrim($release->tag_name, 'v');

        if (version_compare($latest_version, $this->current_version, '>')) {
            $download_url = $this->get_download_url($release);

            if ($download_url) {
                $plugin_data = new stdClass();
                $plugin_data->slug = $this->plugin_slug;
                $plugin_data->plugin = $this->plugin_basename;
                $plugin_data->new_version = $latest_version;
                $plugin_data->url = $release->html_url;
                $plugin_data->package = $download_url;
                $plugin_data->icons = array();
                $plugin_data->banners = array();
                $plugin_data->tested = '';
                $plugin_data->requires = '6.0';
                $plugin_data->requires_php = '7.4';

                $transient->response[$this->plugin_basename] = $plugin_data;
            }
        }

        return $transient;
    }

    /**
     * Provide plugin information for the "View details" popup.
     *
     * @param false|object|array $result Plugin info result.
     * @param string             $action API action.
     * @param object             $args   API args.
     * @return false|object
     */
    public function plugin_info($result, $action, $args)
    {
        if ('plugin_information' !== $action) {
            return $result;
        }

        if (empty($args->slug) || $args->slug !== $this->plugin_slug) {
            return $result;
        }

        $release = $this->get_github_release();

        if (!$release) {
            return $result;
        }

        $latest_version = ltrim($release->tag_name, 'v');

        $info = new stdClass();
        $info->name = 'WooBooster';
        $info->slug = $this->plugin_slug;
        $info->version = $latest_version;
        $info->author = '<a href="https://github.com/' . esc_attr($this->github_user) . '">Ale Aruca, Muhammad Adeel</a>';
        $info->homepage = 'https://github.com/' . $this->github_user . '/' . $this->github_repo;
        $info->requires = '6.0';
        $info->tested = '';
        $info->requires_php = '7.4';
        $info->downloaded = 0;
        $info->last_updated = $release->published_at;
        $info->download_link = $this->get_download_url($release);

        // Use release body as changelog (Markdown → HTML).
        if (!empty($release->body)) {
            $info->sections = array(
                'description' => 'Rule-based product recommendation engine for WooCommerce with Bricks Builder Query Loop integration.',
                'changelog' => nl2br(esc_html($release->body)),
            );
        }

        return $info;
    }

    /**
     * Fix directory name after update.
     *
     * GitHub zips use "repo-tag" as folder name. This renames it to our plugin slug.
     *
     * @param bool  $response   Install response.
     * @param array $hook_extra Extra data.
     * @param array $result     Install result.
     * @return array Modified result.
     */
    public function post_install($response, $hook_extra, $result)
    {
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_basename) {
            return $result;
        }

        global $wp_filesystem;

        $proper_destination = WP_PLUGIN_DIR . '/' . $this->plugin_slug;

        // Move from GitHub's folder name to our expected plugin slug.
        $wp_filesystem->move($result['destination'], $proper_destination);
        $result['destination'] = $proper_destination;
        $result['destination_name'] = $this->plugin_slug;

        // Re-activate the plugin.
        activate_plugin($this->plugin_basename);

        return $result;
    }

    /**
     * Get the download URL from a release.
     *
     * Prefers a .zip release asset. Falls back to GitHub's auto-generated zipball.
     *
     * @param object $release GitHub release object.
     * @return string Download URL.
     */
    private function get_download_url($release)
    {
        // Check for a .zip asset attached to the release.
        if (!empty($release->assets) && is_array($release->assets)) {
            foreach ($release->assets as $asset) {
                if (isset($asset->content_type) && 'application/zip' === $asset->content_type) {
                    return $asset->browser_download_url;
                }
                if (isset($asset->name) && substr($asset->name, -4) === '.zip') {
                    return $asset->browser_download_url;
                }
            }
        }

        // Fallback: GitHub's auto-generated source zipball.
        return $release->zipball_url;
    }
}
