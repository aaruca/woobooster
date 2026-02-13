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

        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            return null;
        }

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
