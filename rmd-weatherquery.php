<?php
/**
 * Plugin Name: RMD WeatherQuery
 * Plugin URI: https://github.com/reicheltmediadesign/wordpress-rmd-weatherquery
 * Description: Ein professionelles WordPress Plugin zur Anzeige von Wetterdaten über die WeatherLink v2 API. Unterstützt multiple Darstellungsmodi (Liste, Tabelle, Widget), Wetter-Icons, deutsche Lokalisierung, Temperatur-/Druckumrechnung und intelligente Caching-Funktionen. Entwickelt unter Mitwirkung von Anthropic Claude.
 * Version: 1.4.0
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Author: reichelt media.design
 * Author URI: https://reicheltmedia.design
 * Author Email: info@reicheltmedia.design
 * License: GPL-2.0-or-later
 * Text Domain: rmd-weatherquery
 * Domain Path: /languages
 * Update URI: https://github.com/reicheltmediadesign/wordpress-rmd-weatherquery
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('RMD_WQ_VERSION', '1.4.0');
define('RMD_WQ_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RMD_WQ_PLUGIN_URL', plugin_dir_url(__FILE__));

// Automatic updates from GitHub releases. plugin-update-checker is only bundled in the release zip (vendor/).
// It reads the update.json attached by the release workflow instead of the rate-limited GitHub API.
if (is_readable(RMD_WQ_PLUGIN_DIR . 'vendor/autoload.php')) {
    require RMD_WQ_PLUGIN_DIR . 'vendor/autoload.php';
    if (class_exists('\YahnisElsts\PluginUpdateChecker\v5\PucFactory')) {
        \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://github.com/reicheltmediadesign/wordpress-rmd-weatherquery/releases/latest/download/update.json',
            __FILE__,
            'rmd-weatherquery'
        );
    }
}

class RMD_WeatherQuery {

    const ARCHIVE_EVENT = 'rmd_wq_archive_event';
    const ARCHIVE_SCHEDULE = 'rmd_wq_five_minutes';

    /**
     * Archive column => [API field, plugin field used for label and formatting].
     * Values are stored in the API's units (°F, mph, inHg, in) like everywhere else in the plugin.
     */
    const ARCHIVE_COLUMNS = array(
        'temp'       => array('temp', 'temp'),
        'hum'        => array('hum', 'humidity'),
        'dew_point'  => array('dew_point', 'dew_point'),
        'bar'        => array('bar_sea_level', 'pressure'),
        'wind_speed' => array('wind_speed_last', 'wind_speed'),
        'wind_avg'   => array('wind_speed_avg_last_10_min', 'wind_speed_avg'),
        'wind_gust'  => array('wind_speed_hi_last_10_min', 'wind_gust'),
        'wind_dir'   => array('wind_dir_last', 'wind_dir'),
        'rain_day'   => array('rainfall_daily_in', 'rainfall'),
        'rain_15'    => array('rainfall_last_15_min_in', 'rain_15_min'),
        'rain_rate'  => array('rain_rate_hi_last_15_min_in', 'rain_rate'),
        'solar_rad'  => array('solar_rad', 'solar_rad'),
        'uv_index'   => array('uv_index', 'uv_index')
    );

    private $options;
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_shortcode('wq', array($this, 'weather_shortcode'));
        add_shortcode('wq_header', array($this, 'weather_header_shortcode'));
        add_shortcode('wq_archive', array($this, 'weather_archive_shortcode'));
        add_shortcode('wq_chart', array($this, 'weather_chart_shortcode'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));

        // Add AJAX handlers
        add_action('wp_ajax_rmd_wq_get_stations', array($this, 'ajax_get_stations'));
        add_action('wp_ajax_rmd_wq_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_rmd_wq_refresh_cache', array($this, 'ajax_refresh_cache'));
        add_action('wp_ajax_rmd_wq_clear_cache', array($this, 'ajax_clear_cache'));
        add_action('wp_ajax_rmd_wq_save_header_station', array($this, 'ajax_save_header_station'));
        add_action('wp_ajax_rmd_wq_archive_now', array($this, 'ajax_archive_now'));

        // Weather archive: WP-Cron polls WeatherLink, readings are stored in our own table
        add_filter('cron_schedules', array($this, 'add_cron_schedule'));
        add_action(self::ARCHIVE_EVENT, array($this, 'run_archive'));

        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        $this->options = get_option('rmd_weatherquery_options');
    }
    
    public function init() {
        load_plugin_textdomain('rmd-weatherquery', false, dirname(plugin_basename(__FILE__)) . '/languages');
        $this->maybe_upgrade();
        $this->register_frontend_styles();
        if (!wp_next_scheduled(self::ARCHIVE_EVENT)) {
            wp_schedule_event(time() + 60, self::ARCHIVE_SCHEDULE, self::ARCHIVE_EVENT);
        }
        register_block_type(RMD_WQ_PLUGIN_DIR . 'blocks/weatherquery', array(
            'render_callback' => array($this, 'render_block')
        ));
        register_block_type(RMD_WQ_PLUGIN_DIR . 'blocks/weatherchart', array(
            'render_callback' => array($this, 'render_chart_block')
        ));
    }

    /**
     * The activation hook does not run on plugin updates, so the cache table is
     * (re)created whenever the stored schema version differs from the plugin version.
     */
    private function maybe_upgrade() {
        if (get_option('rmd_weatherquery_db_version') === RMD_WQ_VERSION) {
            return;
        }
        $this->create_cache_table();
        $this->create_archive_table();
        update_option('rmd_weatherquery_db_version', RMD_WQ_VERSION);
    }
    
    public function activate() {
        // Create default options
        $default_options = array(
            'base_url' => 'https://api.weatherlink.com/v2/',
            'api_key' => '',
            'api_secret' => '',
            'version' => RMD_WQ_VERSION
        );

        add_option('rmd_weatherquery_options', $default_options);

        // Create cache table
        $this->create_cache_table();
        $this->create_archive_table();
    }

    /**
     * One row per station reading (WeatherLink Basic: every 15 minutes). `day` is the station's
     * local date, so daily aggregates match the station's own daily rain counter.
     */
    private function create_archive_table() {
        global $wpdb;

        $table_name = $this->archive_table();
        $charset_collate = $wpdb->get_charset_collate();

        $value_columns = '';
        foreach (array_keys(self::ARCHIVE_COLUMNS) as $column) {
            $value_columns .= "            $column float DEFAULT NULL,\n";
        }

        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            station_id int(10) unsigned NOT NULL,
            ts int(10) unsigned NOT NULL,
            day date NOT NULL,
$value_columns            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY station_ts (station_id,ts),
            KEY station_day (station_id,day)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    private function archive_table() {
        global $wpdb;
        return $wpdb->prefix . 'rmd_weatherquery_archive';
    }

    public function add_cron_schedule($schedules) {
        $schedules[self::ARCHIVE_SCHEDULE] = array(
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => __('Alle 5 Minuten (WeatherQuery)', 'rmd-weatherquery')
        );
        return $schedules;
    }

    private function create_cache_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'rmd_weatherquery_cache';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            cache_key varchar(100) NOT NULL,
            cache_data longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY cache_key (cache_key)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    public function deactivate() {
        wp_clear_scheduled_hook(self::ARCHIVE_EVENT);
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'WeatherQuery',
            'WeatherQuery',
            'manage_options',
            'rmd-weatherquery',
            array($this, 'admin_page'),
            'dashicons-cloud',
            30
        );

        add_submenu_page(
            'rmd-weatherquery',
            __('Stationen', 'rmd-weatherquery'),
            __('Stationen', 'rmd-weatherquery'),
            'manage_options',
            'rmd-weatherquery',
            array($this, 'admin_page')
        );

        add_submenu_page(
            'rmd-weatherquery',
            __('Konfiguration', 'rmd-weatherquery'),
            __('Konfiguration', 'rmd-weatherquery'),
            'manage_options',
            'rmd-weatherquery-config',
            array($this, 'config_page')
        );
    }
    
    public function admin_init() {
        register_setting('rmd_weatherquery_group', 'rmd_weatherquery_options', array(
            'sanitize_callback' => array($this, 'sanitize_options')
        ));
        
        add_settings_section(
            'rmd_weatherquery_section',
            __('API Konfiguration', 'rmd-weatherquery'),
            array($this, 'settings_section_callback'),
            'rmd_weatherquery'
        );
        
        add_settings_field(
            'base_url',
            __('Basis URL', 'rmd-weatherquery'),
            array($this, 'base_url_callback'),
            'rmd_weatherquery',
            'rmd_weatherquery_section'
        );
        
        add_settings_field(
            'api_key',
            __('API Schlüssel', 'rmd-weatherquery'),
            array($this, 'api_key_callback'),
            'rmd_weatherquery',
            'rmd_weatherquery_section'
        );
        
        add_settings_field(
            'api_secret',
            __('API Geheimnis', 'rmd-weatherquery'),
            array($this, 'api_secret_callback'),
            'rmd_weatherquery',
            'rmd_weatherquery_section'
        );

        add_settings_field(
            'debug_mode',
            __('Debug-Modus', 'rmd-weatherquery'),
            array($this, 'debug_mode_callback'),
            'rmd_weatherquery',
            'rmd_weatherquery_section'
        );

        add_settings_field(
            'default_sensor_id',
            __('Archiv-Station', 'rmd-weatherquery'),
            array($this, 'default_sensor_id_callback'),
            'rmd_weatherquery',
            'rmd_weatherquery_section'
        );
    }
    
    /**
     * The settings form only posts the API fields. Values saved elsewhere
     * (header_station_id via AJAX, version) are kept instead of being dropped.
     * An unchecked debug checkbox is not posted at all, so it is reset explicitly.
     */
    public function sanitize_options($input) {
        $existing = get_option('rmd_weatherquery_options', array());
        if (!is_array($existing)) {
            $existing = array();
        }
        if (!is_array($input)) {
            return $existing;
        }
        $merged = array_merge($existing, $input);
        if (!isset($input['debug_mode'])) {
            unset($merged['debug_mode']);
        }
        return $merged;
    }

    public function settings_section_callback() {
        echo '<p>' . __('Konfigurieren Sie hier Ihre WeatherLink v2 API Zugangsdaten.', 'rmd-weatherquery') . '</p>';
    }
    
    public function base_url_callback() {
        $value = isset($this->options['base_url']) ? $this->options['base_url'] : 'https://api.weatherlink.com/v2/';
        echo '<input type="url" name="rmd_weatherquery_options[base_url]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('Basis URL für die WeatherLink v2 API', 'rmd-weatherquery') . '</p>';
    }
    
    public function api_key_callback() {
        $value = isset($this->options['api_key']) ? $this->options['api_key'] : '';
        echo '<input type="text" name="rmd_weatherquery_options[api_key]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('Ihr WeatherLink v2 API Schlüssel', 'rmd-weatherquery') . '</p>';
    }
    
    public function api_secret_callback() {
        $value = isset($this->options['api_secret']) ? $this->options['api_secret'] : '';
        echo '<input type="password" name="rmd_weatherquery_options[api_secret]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('Ihr WeatherLink v2 API Geheimnis', 'rmd-weatherquery') . '</p>';
    }

    public function debug_mode_callback() {
        $value = isset($this->options['debug_mode']) ? $this->options['debug_mode'] : 0;
        echo '<label><input type="checkbox" name="rmd_weatherquery_options[debug_mode]" value="1" ' . checked(1, $value, false) . ' /> ';
        echo __('Debug-Modus aktivieren', 'rmd-weatherquery') . '</label>';
        echo '<p class="description">' . __('Zeigt detaillierte Fehlermeldungen bei API-Problemen an', 'rmd-weatherquery') . '</p>';
    }

    public function default_sensor_id_callback() {
        $value = isset($this->options['default_sensor_id']) ? $this->options['default_sensor_id'] : '';
        echo '<input type="text" name="rmd_weatherquery_options[default_sensor_id]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('Stations-ID, die [wq_archive] ohne station_id anzeigt. Leer lassen, um die Header-Station zu verwenden. Archiviert werden immer alle Stationen des Kontos.', 'rmd-weatherquery') . '</p>';
    }

    public function admin_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('WeatherQuery Einstellungen', 'rmd-weatherquery'); ?></h1>
            <p><?php _e('Version', 'rmd-weatherquery'); ?>: <?php echo RMD_WQ_VERSION; ?></p>
            
            <div id="poststuff">
                <div id="post-body" class="metabox-holder columns-2">
                    <div id="post-body-content">
                        <div class="meta-box-sortables ui-sortable">

                            <?php if (!$this->is_configured()): ?>
                            <!-- Configuration Notice -->
                            <div class="postbox">
                                <h2 class="hndle"><span><?php _e('Erste Schritte', 'rmd-weatherquery'); ?></span></h2>
                                <div class="inside">
                                    <p><?php _e('Um WeatherQuery zu verwenden, müssen Sie zuerst Ihre API-Zugangsdaten konfigurieren.', 'rmd-weatherquery'); ?></p>
                                    <p><a href="<?php echo admin_url('admin.php?page=rmd-weatherquery-config'); ?>" class="button button-primary"><?php _e('Zur Konfiguration', 'rmd-weatherquery'); ?></a></p>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Header Station ID Configuration -->
                            <div class="postbox">
                                <h2 class="hndle"><span><?php _e('Header-Wetter Konfiguration', 'rmd-weatherquery'); ?></span></h2>
                                <div class="inside">
                                    <p><?php _e('Legen Sie hier die Standard-Stations-ID fest, die der [wq_header] Shortcode verwenden soll.', 'rmd-weatherquery'); ?></p>
                                    <table class="form-table">
                                        <tr>
                                            <th scope="row">
                                                <label for="header_station_id"><?php _e('Stations-ID', 'rmd-weatherquery'); ?></label>
                                            </th>
                                            <td>
                                                <input type="text"
                                                       id="header_station_id"
                                                       name="header_station_id"
                                                       value="<?php echo esc_attr(isset($this->options['header_station_id']) ? $this->options['header_station_id'] : ''); ?>"
                                                       class="regular-text"
                                                       placeholder="z.B. 12345" />
                                                <p class="description">
                                                    <?php _e('Diese Station wird für den [wq_header] Shortcode verwendet, wenn keine station_id angegeben wird.', 'rmd-weatherquery'); ?>
                                                </p>
                                            </td>
                                        </tr>
                                    </table>
                                    <p class="submit">
                                        <button type="button" id="save-header-station" class="button button-primary">
                                            <?php _e('Stations-ID speichern', 'rmd-weatherquery'); ?>
                                        </button>
                                    </p>
                                    <div id="header-station-result" style="margin-top: 10px;"></div>
                                </div>
                            </div>

                            <!-- Stations Section -->
                            <?php if ($this->is_configured()): ?>
                            <div class="postbox">
                                <h2 class="hndle"><span><?php _e('Verfügbare Stationen', 'rmd-weatherquery'); ?></span></h2>
                                <div class="inside">
                                    <div id="stations-list">
                                        <button type="button" id="load-stations" class="button button-secondary">
                                            <?php _e('Stationen laden', 'rmd-weatherquery'); ?>
                                        </button>
                                        <div id="stations-container"></div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Shortcode Generator -->
                            <div class="postbox" id="shortcode-generator" style="display: none;">
                                <h2 class="hndle"><span><?php _e('Shortcode Generator', 'rmd-weatherquery'); ?></span></h2>
                                <div class="inside">
                                    <div id="shortcode-form">
                                        <table class="form-table">
                                            <tr>
                                                <th scope="row"><?php _e('Ausgewählte Station', 'rmd-weatherquery'); ?></th>
                                                <td><span id="selected-station-name"></span> (ID: <span id="selected-station-id"></span>)</td>
                                            </tr>
                                            <tr>
                                                <th scope="row"><?php _e('Modus', 'rmd-weatherquery'); ?></th>
                                                <td>
                                                    <select id="shortcode-mode">
                                                        <option value="current"><?php _e('Aktuelle Bedingungen', 'rmd-weatherquery'); ?></option>
                                                        <option value="historic"><?php _e('Historische Daten', 'rmd-weatherquery'); ?></option>
                                                    </select>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th scope="row"><?php _e('Datenfelder', 'rmd-weatherquery'); ?></th>
                                                <td>
                                                    <fieldset>
                                                        <label><input type="radio" name="field-selection" value="specific" class="field-selection-type" checked> <?php _e('Spezifische Felder auswählen:', 'rmd-weatherquery'); ?></label><br>
                                                        <div id="specific-fields" style="margin-left: 20px; margin-top: 5px;">
                                                            <label><input type="checkbox" value="temp" class="shortcode-field"> <?php _e('Temperatur', 'rmd-weatherquery'); ?></label><br>
                                                            <label><input type="checkbox" value="humidity" class="shortcode-field"> <?php _e('Luftfeuchtigkeit', 'rmd-weatherquery'); ?></label><br>
                                                            <label><input type="checkbox" value="wind_speed" class="shortcode-field"> <?php _e('Windgeschwindigkeit', 'rmd-weatherquery'); ?></label><br>
                                                            <label><input type="checkbox" value="wind_dir" class="shortcode-field"> <?php _e('Windrichtung', 'rmd-weatherquery'); ?></label><br>
                                                            <label><input type="checkbox" value="pressure" class="shortcode-field"> <?php _e('Luftdruck', 'rmd-weatherquery'); ?></label><br>
                                                            <label><input type="checkbox" value="rainfall" class="shortcode-field"> <?php _e('Niederschlag', 'rmd-weatherquery'); ?></label><br>
                                                        </div>
                                                        <label><input type="radio" name="field-selection" value="all" class="field-selection-type"> <?php _e('Alle verfügbaren Felder anzeigen', 'rmd-weatherquery'); ?></label>
                                                    </fieldset>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th scope="row"><?php _e('Darstellungsart', 'rmd-weatherquery'); ?></th>
                                                <td>
                                                    <select id="display-mode">
                                                        <option value="list"><?php _e('Liste', 'rmd-weatherquery'); ?></option>
                                                        <option value="table"><?php _e('Tabelle', 'rmd-weatherquery'); ?></option>
                                                        <option value="widget"><?php _e('Widget', 'rmd-weatherquery'); ?></option>
                                                    </select>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th scope="row"><?php _e('Optionen', 'rmd-weatherquery'); ?></th>
                                                <td>
                                                    <label><input type="checkbox" id="show-icons"> <?php _e('Wetter-Icons anzeigen', 'rmd-weatherquery'); ?></label><br>
                                                    <div id="icon-color-container" style="margin: 10px 0; display: none;">
                                                        <label><?php _e('Icon-Farbe', 'rmd-weatherquery'); ?>: <input type="color" id="icon-color" value="#62C626" style="margin-left: 5px;"></label>
                                                    </div>
                                                    <label><input type="checkbox" id="show-timestamp"> <?php _e('Zeitstempel anzeigen', 'rmd-weatherquery'); ?></label>
                                                </td>
                                            </tr>
                                        </table>
                                        
                                        <div id="generated-shortcode-container">
                                            <h4><?php _e('Generierter Shortcode:', 'rmd-weatherquery'); ?></h4>
                                            <input type="text" id="generated-shortcode" class="large-text" readonly />
                                            <button type="button" id="copy-shortcode" class="button button-secondary">
                                                <?php _e('In Zwischenablage kopieren', 'rmd-weatherquery'); ?>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                        </div>
                    </div>
                    
                    <div id="postbox-container-1" class="postbox-container">
                        <div class="meta-box-sortables">
                            <div class="postbox">
                                <h2 class="hndle"><span><?php _e('Plugin Informationen', 'rmd-weatherquery'); ?></span></h2>
                                <div class="inside">
                                    <p><strong><?php _e('Version', 'rmd-weatherquery'); ?>:</strong> <?php echo RMD_WQ_VERSION; ?></p>
                                    <p><strong><?php _e('Autor', 'rmd-weatherquery'); ?>:</strong> reichelt media.design</p>
                                    <p><strong><?php _e('Kontakt', 'rmd-weatherquery'); ?>:</strong> info@reicheltmedia.design</p>
                                </div>
                            </div>
                            
                            <div class="postbox">
                                <h2 class="hndle"><span><?php _e('Verwendung', 'rmd-weatherquery'); ?></span></h2>
                                <div class="inside">
                                    <p><?php _e('Verwenden Sie den Shortcode Generator oben, um Shortcodes für Ihre Wetterdaten zu erstellen.', 'rmd-weatherquery'); ?></p>
                                    <p><strong><?php _e('Beispiel:', 'rmd-weatherquery'); ?></strong></p>
                                    <code>[wq mode="current" station_id="12345" fields="temp,humidity"]</code>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function config_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('WeatherQuery Konfiguration', 'rmd-weatherquery'); ?></h1>
            <p><?php _e('Konfigurieren Sie hier Ihre WeatherLink v2 API Zugangsdaten.', 'rmd-weatherquery'); ?></p>

            <div id="poststuff">
                <div id="post-body" class="metabox-holder columns-1">
                    <div id="post-body-content">
                        <div class="meta-box-sortables ui-sortable">

                            <!-- API Configuration Section -->
                            <div class="postbox">
                                <h2 class="hndle"><span><?php _e('API Konfiguration', 'rmd-weatherquery'); ?></span></h2>
                                <div class="inside">
                                    <form method="post" action="options.php">
                                        <?php
                                        settings_fields('rmd_weatherquery_group');
                                        do_settings_sections('rmd_weatherquery');
                                        submit_button();
                                        ?>
                                    </form>
                                </div>
                            </div>

                            <!-- Connection Test Section -->
                            <?php if ($this->is_configured()): ?>
                            <div class="postbox">
                                <h2 class="hndle"><span><?php _e('Verbindungstest', 'rmd-weatherquery'); ?></span></h2>
                                <div class="inside">
                                    <p><?php _e('Testen Sie Ihre API-Verbindung und sehen Sie Debug-Informationen.', 'rmd-weatherquery'); ?></p>
                                    <button type="button" id="test-connection" class="button button-secondary">
                                        <?php _e('Verbindung testen', 'rmd-weatherquery'); ?>
                                    </button>
                                    <div id="test-results" style="margin-top: 15px;"></div>
                                </div>
                            </div>

                            <!-- Cache Management Section -->
                            <div class="postbox">
                                <h2 class="hndle"><span><?php _e('Cache-Verwaltung', 'rmd-weatherquery'); ?></span></h2>
                                <div class="inside">
                                    <p><?php _e('Der Stations-Cache wird automatisch 24 Stunden gespeichert, um die API-Anfragen zu reduzieren.', 'rmd-weatherquery'); ?></p>
                                    <button type="button" id="refresh-cache" class="button button-secondary">
                                        <?php _e('Cache aktualisieren', 'rmd-weatherquery'); ?>
                                    </button>
                                    <button type="button" id="clear-cache" class="button button-secondary" style="margin-left: 10px;">
                                        <?php _e('Cache leeren', 'rmd-weatherquery'); ?>
                                    </button>
                                    <div id="cache-results" style="margin-top: 15px;"></div>
                                </div>
                            </div>

                            <!-- Weather Archive Section -->
                            <?php $archive = $this->get_archive_summary(); ?>
                            <div class="postbox">
                                <h2 class="hndle"><span><?php _e('Wetterarchiv', 'rmd-weatherquery'); ?></span></h2>
                                <div class="inside">
                                    <p><?php _e('Das Plugin ruft alle 5 Minuten die aktuellen Werte aller Stationen ab und speichert jede neue Messung. Ausgabe mit [wq_archive] oder dem Block im Modus „Archiv“.', 'rmd-weatherquery'); ?></p>
                                    <table class="widefat striped" style="max-width: 720px;">
                                        <tbody>
                                            <tr><th><?php _e('Gespeicherte Messungen', 'rmd-weatherquery'); ?></th><td><?php echo esc_html(number_format_i18n($archive['count'])); ?></td></tr>
                                            <tr><th><?php _e('Zeitraum', 'rmd-weatherquery'); ?></th><td><?php echo $archive['count'] ? esc_html(wp_date('d.m.Y H:i', $archive['first']) . ' – ' . wp_date('d.m.Y H:i', $archive['last'])) : '–'; ?></td></tr>
                                            <tr><th><?php _e('Letzter Abruf', 'rmd-weatherquery'); ?></th><td><?php echo $archive['last_run'] ? esc_html(wp_date('d.m.Y H:i:s', $archive['last_run'])) : esc_html__('noch nie', 'rmd-weatherquery'); ?></td></tr>
                                            <tr><th><?php _e('Nächster geplanter Abruf', 'rmd-weatherquery'); ?></th><td><?php echo $archive['next_run'] ? esc_html(wp_date('d.m.Y H:i:s', $archive['next_run'])) : esc_html__('nicht geplant', 'rmd-weatherquery'); ?></td></tr>
                                            <?php if ($archive['error']): ?>
                                            <tr><th><?php _e('Letzter Fehler', 'rmd-weatherquery'); ?></th><td style="color: #d63638;"><?php echo esc_html($archive['error']); ?></td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                    <p class="description" style="margin-top: 10px;">
                                        <?php _e('WP-Cron läuft nur bei Seitenaufrufen. Für eine lückenlose Aufzeichnung diese Adresse alle 5 Minuten von außen aufrufen lassen:', 'rmd-weatherquery'); ?><br>
                                        <code><?php echo esc_html(site_url('wp-cron.php?doing_wp_cron')); ?></code>
                                    </p>
                                    <p>
                                        <button type="button" id="archive-now" class="button button-secondary"><?php _e('Jetzt abrufen und archivieren', 'rmd-weatherquery'); ?></button>
                                    </p>
                                    <div id="archive-results"></div>
                                </div>
                            </div>
                            <?php endif; ?>

                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function is_configured() {
        return !empty($this->options['api_key']) && !empty($this->options['api_secret']);
    }

    private function get_archive_summary() {
        global $wpdb;
        $table = $this->archive_table();
        $row = $wpdb->get_row("SELECT COUNT(*) AS count, MIN(ts) AS first, MAX(ts) AS last FROM $table", ARRAY_A);
        $status = get_option('rmd_weatherquery_archive_status', array());

        return array(
            'count' => $row ? (int) $row['count'] : 0,
            'first' => $row ? (int) $row['first'] : 0,
            'last' => $row ? (int) $row['last'] : 0,
            'last_run' => isset($status['last_run']) ? (int) $status['last_run'] : 0,
            'error' => isset($status['error']) ? $status['error'] : '',
            'next_run' => wp_next_scheduled(self::ARCHIVE_EVENT)
        );
    }
    
    public function enqueue_scripts() {
        wp_enqueue_style('weather-icons');
    }

    /**
     * Registered on init so the block (block.json "style") can load the same
     * stylesheet in the editor and on the frontend.
     */
    private function register_frontend_styles() {
        // Weather Icons are bundled locally so visitors' browsers do not contact a CDN.
        wp_register_style('weather-icons', RMD_WQ_PLUGIN_URL . 'assets/weather-icons/css/weather-icons.min.css', array(), '2.0.12');

        // Chart.js is bundled as well; both scripts are only enqueued when an archive chart is rendered.
        wp_register_script('rmd-wq-chartjs', RMD_WQ_PLUGIN_URL . 'assets/chartjs/chart.umd.min.js', array(), '4.4.1', true);
        wp_register_script('rmd-wq-archive-chart', RMD_WQ_PLUGIN_URL . 'assets/archive-chart.js', array('rmd-wq-chartjs'), RMD_WQ_VERSION, true);
        wp_register_script('rmd-wq-weather-chart', RMD_WQ_PLUGIN_URL . 'assets/weather-chart.js', array('rmd-wq-chartjs'), RMD_WQ_VERSION, true);

        // Add basic styling for our display modes and admin menu icon
        wp_add_inline_style('weather-icons', '
            /* Admin Menu Icon Styling */
            #toplevel_page_rmd-weatherquery .wp-menu-image::before {
                color: #62C626 !important;
            }
            #toplevel_page_rmd-weatherquery:hover .wp-menu-image::before,
            #toplevel_page_rmd-weatherquery.wp-has-current-submenu .wp-menu-image::before {
                color: #ffffff !important;
            }
            .rmd-wq-widget {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
            }
            .rmd-wq-widget-item {
                background: #fff;
                border: 1px solid #e1e5e9;
                border-radius: 12px;
                padding: 20px;
                display: flex;
                align-items: center;
                gap: 12px;
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            }
            .rmd-wq-widget-icon {
                font-size: 24px;
                color: #62C626;
                width: 32px;
                height: 32px;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
            }
            .rmd-wq-widget-content {
                flex: 1;
            }
            .rmd-wq-widget-label {
                font-size: 13px;
                color: #6c757d;
                margin-bottom: 5px;
                font-weight: 500;
                line-height: 1.2;
            }
            .rmd-wq-widget-value {
                font-size: 20px;
                font-weight: 600;
                color: #212529;
                line-height: 1.1;
            }
            .rmd-wq-weather-table {
                width: 100%;
                border-collapse: collapse;
            }
            .rmd-wq-weather-table th,
            .rmd-wq-weather-table td {
                padding: 8px 12px;
                border: 1px solid #ddd;
                text-align: left;
            }
            .rmd-wq-weather-table th {
                background-color: #f5f5f5;
                font-weight: bold;
            }
            .rmd-wq-weather-table .wi {
                font-size: 18px;
                color: #62C626;
                width: 24px;
                height: 24px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                margin-right: 8px;
            }
            .rmd-wq-list .wi {
                color: #62C626;
                margin-right: 5px;
            }
            .rmd-wq-data .wi.rmd-wq-wind-arrow {
                transform-origin: 50% 50%;
            }
            .rmd-wq-header {
                display: inline-flex;
                align-items: center;
                gap: 15px;
                font-size: 14px;
            }
            .rmd-wq-header-item {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                white-space: nowrap;
            }
            .rmd-wq-header-item .wi {
                font-size: 16px;
                color: #62C626;
            }
            .rmd-wq-header-value {
                font-weight: 600;
            }
            .rmd-wq-archive.rmd-wq-list .rmd-wq-reading {
                margin-bottom: 20px;
                padding: 15px;
                background: #fff;
                border: 1px solid #e1e5e9;
                border-radius: 8px;
            }
            .rmd-wq-archive.rmd-wq-list .rmd-wq-reading h4 {
                margin: 0 0 10px 0;
                color: #212529;
                font-size: 16px;
            }
            .rmd-wq-archive.rmd-wq-widget .rmd-wq-reading-widget {
                margin-bottom: 30px;
            }
            .rmd-wq-archive.rmd-wq-widget .rmd-wq-reading-widget h4 {
                margin: 0 0 15px 0;
                color: #212529;
                font-size: 16px;
            }
            .rmd-wq-archive.rmd-wq-table {
                overflow-x: auto;
            }
            .rmd-wq-archive .rmd-wq-weather-table {
                min-width: 600px;
            }
            .wp-block-rmd-weatherquery-weather .rmd-wq-title {
                margin: 0 0 12px;
            }
            .wp-block-rmd-weatherquery-weather.has-text-color .rmd-wq-widget-label,
            .wp-block-rmd-weatherquery-weather.has-text-color .rmd-wq-widget-value,
            .wp-block-rmd-weatherquery-weather.has-text-color .rmd-wq-timestamp {
                color: inherit;
            }
            .wp-block-rmd-weatherquery-weather .rmd-wq-widget-item {
                background: var(--rmd-wq-card-bg, #fff);
            }
            .wp-block-rmd-weatherquery-weather.rmd-wq-cards-plain .rmd-wq-widget-item {
                border: 0;
                box-shadow: none;
                padding: 8px 0;
            }
            .wp-block-rmd-weatherquery-weather.rmd-wq-hide-labels .rmd-wq-widget-label {
                display: none;
            }
            .rmd-wq-notice {
                padding: 12px;
                border: 1px dashed #c3c4c7;
                color: #50575e;
            }
            .rmd-wq-archive-controls {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 8px 12px;
                margin-bottom: 12px;
            }
            .rmd-wq-archive-controls select,
            .rmd-wq-archive-controls input[type="date"] {
                margin-left: 4px;
            }
            .rmd-wq-archive-nav {
                text-decoration: none;
                font-size: 1.4em;
                line-height: 1;
                padding: 0 6px;
            }
            .rmd-wq-archive-range {
                color: #6c757d;
                font-size: 0.9em;
            }
            .rmd-wq-archive .rmd-wq-weather-table td,
            .rmd-wq-archive .rmd-wq-weather-table th {
                white-space: nowrap;
            }
            .rmd-wq-chart-canvas {
                position: relative;
                width: 100%;
                height: 360px;
            }
            .rmd-wq-chart-toggles {
                display: flex;
                flex-wrap: wrap;
                gap: 6px 8px;
                margin-bottom: 12px;
            }
            .rmd-wq-chart-toggle {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 4px 10px;
                border: 1px solid #d0d5da;
                border-radius: 999px;
                font-size: 0.9em;
                line-height: 1.4;
                cursor: pointer;
                user-select: none;
            }
            .rmd-wq-chart-toggle input {
                margin: 0;
                accent-color: var(--rmd-wq-series-color);
            }
            .rmd-wq-chart-swatch {
                width: 10px;
                height: 10px;
                border-radius: 50%;
                background: var(--rmd-wq-series-color);
            }
            .rmd-wq-chart-toggle:has(input:not(:checked)) {
                opacity: 0.55;
            }
            .rmd-wq-chart-toggle:has(input:focus-visible) {
                outline: 2px solid var(--rmd-wq-series-color);
                outline-offset: 2px;
            }
            .rmd-wq-archive-empty {
                color: #6c757d;
                font-style: italic;
            }
            .rmd-wq-timestamp {
                margin-top: 10px;
                font-size: 12px;
                color: #666;
                font-style: italic;
            }
            .rmd-wq-widget-timestamp {
                grid-column: 1 / -1;
                text-align: center;
                margin-top: 15px;
            }
        ');
    }
    
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'toplevel_page_rmd-weatherquery' && $hook !== 'weatherquery_page_rmd-weatherquery-config') {
            return;
        }
        
        wp_enqueue_style('rmd-weatherquery-admin', RMD_WQ_PLUGIN_URL . 'assets/admin.css', array(), RMD_WQ_VERSION);
        wp_enqueue_script('rmd-weatherquery-admin', RMD_WQ_PLUGIN_URL . 'assets/admin.js', array('jquery'), RMD_WQ_VERSION, true);
        wp_localize_script('rmd-weatherquery-admin', 'rmd_wq_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rmd_wq_nonce'),
            'strings' => array(
                'loading' => __('Lade...', 'rmd-weatherquery'),
                'error' => __('Fehler beim Laden der Daten', 'rmd-weatherquery'),
                'copied' => __('In Zwischenablage kopiert!', 'rmd-weatherquery')
            )
        ));
    }
    
    public function ajax_get_stations() {
        check_ajax_referer('rmd_wq_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Unzureichende Berechtigung', 'rmd-weatherquery'));
        }

        $stations = $this->get_stations_with_models();

        // Handle debug mode error responses
        if (is_array($stations) && isset($stations['error'])) {
            $error_message = $stations['error'];
            if ($this->debug_mode_enabled()) {
                // Add detailed debug information to error message
                $debug_details = '';
                if (isset($stations['debug'])) {
                    $debug_details .= "\n\nRequest Details:\n";
                    $debug_details .= "URL: " . $stations['debug']['url'] . "\n";
                    $debug_details .= "Method: " . $stations['debug']['method'] . "\n";
                }
                if (isset($stations['response_body'])) {
                    $debug_details .= "\nResponse Body:\n" . $stations['response_body'];
                }
                if (isset($stations['response_data'])) {
                    $debug_details .= "\nResponse Data:\n" . print_r($stations['response_data'], true);
                }
                if (isset($stations['json_error'])) {
                    $debug_details .= "\nJSON Error: " . $stations['json_error'];
                }
                $error_message .= $debug_details;
            }
            wp_send_json_error($error_message);
        }

        if ($stations === false) {
            wp_send_json_error(__('Fehler beim Laden der Stationen', 'rmd-weatherquery'));
        }

        wp_send_json_success($stations);
    }

    public function ajax_test_connection() {
        check_ajax_referer('rmd_wq_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Unzureichende Berechtigung', 'rmd-weatherquery'));
        }

        $stations = $this->get_stations_with_models();

        // Always return detailed information for connection test
        if (is_array($stations) && isset($stations['error'])) {
            // Format the debug information nicely
            $response = array(
                'success' => false,
                'error' => $stations['error'],
                'debug_info' => array()
            );

            if (isset($stations['debug'])) {
                $response['debug_info']['request'] = $stations['debug'];
            }
            if (isset($stations['response_body'])) {
                $response['debug_info']['response_body'] = $stations['response_body'];
            }
            if (isset($stations['response_data'])) {
                $response['debug_info']['response_data'] = $stations['response_data'];
            }
            if (isset($stations['json_error'])) {
                $response['debug_info']['json_error'] = $stations['json_error'];
            }

            wp_send_json($response);
        }

        if ($stations === false) {
            wp_send_json(array(
                'success' => false,
                'error' => __('Unbekannter Fehler beim Testen der Verbindung', 'rmd-weatherquery')
            ));
        }

        // Success
        wp_send_json(array(
            'success' => true,
            'message' => sprintf(__('%d Stationen gefunden', 'rmd-weatherquery'), count($stations)),
            'stations' => $stations
        ));
    }

    public function ajax_refresh_cache() {
        check_ajax_referer('rmd_wq_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Unzureichende Berechtigung', 'rmd-weatherquery'));
        }

        // Force refresh the cache
        $stations = $this->get_stations_with_models(true);

        if (is_array($stations) && isset($stations['error'])) {
            wp_send_json_error($stations['error']);
        }

        if ($stations === false) {
            wp_send_json_error(__('Fehler beim Aktualisieren des Cache', 'rmd-weatherquery'));
        }

        wp_send_json_success(array(
            'message' => sprintf(__('Cache erfolgreich aktualisiert. %d Stationen geladen.', 'rmd-weatherquery'), count($stations))
        ));
    }

    public function ajax_clear_cache() {
        check_ajax_referer('rmd_wq_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Unzureichende Berechtigung', 'rmd-weatherquery'));
        }

        $this->clear_all_cache();

        wp_send_json_success(array(
            'message' => __('Cache erfolgreich geleert.', 'rmd-weatherquery')
        ));
    }

    public function ajax_save_header_station() {
        check_ajax_referer('rmd_wq_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Unzureichende Berechtigung', 'rmd-weatherquery'));
        }

        $station_id = isset($_POST['station_id']) ? sanitize_text_field($_POST['station_id']) : '';

        // Get current options
        $options = get_option('rmd_weatherquery_options', array());

        // Update header_station_id
        $options['header_station_id'] = $station_id;

        // Save options
        update_option('rmd_weatherquery_options', $options);

        // Reload options in current instance
        $this->options = $options;

        wp_send_json_success(array(
            'message' => __('Stations-ID erfolgreich gespeichert.', 'rmd-weatherquery')
        ));
    }

    private function get_stations() {
        if (!$this->is_configured()) {
            return $this->debug_mode_enabled() ?
                array('error' => 'Plugin nicht konfiguriert: API Key oder Secret fehlt') :
                false;
        }

        $url = rtrim($this->options['base_url'], '/') . '/stations';

        $args = array(
            'headers' => array(
                'X-API-SECRET' => $this->options['api_secret']
            ),
            'timeout' => 30
        );

        $url .= '?api-key=' . urlencode($this->options['api_key']);

        // Debug info
        if ($this->debug_mode_enabled()) {
            $debug_info = array(
                'url' => $url,
                'headers' => $args['headers'],
                'method' => 'GET'
            );
        }

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            if ($this->debug_mode_enabled()) {
                return array(
                    'error' => 'HTTP Request Fehler: ' . $response->get_error_message(),
                    'debug' => isset($debug_info) ? $debug_info : null
                );
            }
            return false;
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($http_code !== 200) {
            if ($this->debug_mode_enabled()) {
                return array(
                    'error' => 'HTTP Error ' . $http_code,
                    'response_body' => $body,
                    'debug' => isset($debug_info) ? $debug_info : null
                );
            }
            return false;
        }

        if (!$data) {
            if ($this->debug_mode_enabled()) {
                return array(
                    'error' => 'JSON Decode Fehler',
                    'response_body' => $body,
                    'json_error' => json_last_error_msg(),
                    'debug' => isset($debug_info) ? $debug_info : null
                );
            }
            return false;
        }

        if (!isset($data['stations'])) {
            if ($this->debug_mode_enabled()) {
                return array(
                    'error' => 'Keine Stationen im API Response gefunden',
                    'response_data' => $data,
                    'debug' => isset($debug_info) ? $debug_info : null
                );
            }
            return false;
        }

        return $data['stations'];
    }

    private function get_sensors() {
        if (!$this->is_configured()) {
            return $this->debug_mode_enabled() ?
                array('error' => 'Plugin nicht konfiguriert: API Key oder Secret fehlt') :
                false;
        }

        $url = rtrim($this->options['base_url'], '/') . '/sensors';

        $args = array(
            'headers' => array(
                'X-API-SECRET' => $this->options['api_secret']
            ),
            'timeout' => 30
        );

        $url .= '?api-key=' . urlencode($this->options['api_key']);

        // Debug info
        if ($this->debug_mode_enabled()) {
            $debug_info = array(
                'url' => $url,
                'headers' => $args['headers'],
                'method' => 'GET'
            );
        }

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            if ($this->debug_mode_enabled()) {
                return array(
                    'error' => 'HTTP Request Fehler: ' . $response->get_error_message(),
                    'debug' => isset($debug_info) ? $debug_info : null
                );
            }
            return false;
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($http_code !== 200) {
            if ($this->debug_mode_enabled()) {
                return array(
                    'error' => 'HTTP Error ' . $http_code,
                    'response_body' => $body,
                    'debug' => isset($debug_info) ? $debug_info : null
                );
            }
            return false;
        }

        if (!$data) {
            if ($this->debug_mode_enabled()) {
                return array(
                    'error' => 'JSON Decode Fehler',
                    'response_body' => $body,
                    'json_error' => json_last_error_msg(),
                    'debug' => isset($debug_info) ? $debug_info : null
                );
            }
            return false;
        }

        if (!isset($data['sensors'])) {
            if ($this->debug_mode_enabled()) {
                return array(
                    'error' => 'Keine Sensoren im API Response gefunden',
                    'response_data' => $data,
                    'debug' => isset($debug_info) ? $debug_info : null
                );
            }
            return false;
        }

        return $data['sensors'];
    }

    private function get_stations_with_models($force_refresh = false) {
        // Check cache first (unless forced refresh)
        $cache_key = 'stations_with_models';
        if (!$force_refresh) {
            $cached_data = $this->get_cache($cache_key);
            if ($cached_data !== false) {
                return $cached_data;
            }
        }

        $stations = $this->get_stations();
        if (is_array($stations) && isset($stations['error'])) {
            return $stations; // Return error from stations call
        }
        if ($stations === false) {
            return false;
        }

        $sensors = $this->get_sensors();
        if (is_array($sensors) && isset($sensors['error'])) {
            // If sensors call fails, return stations without model info
            if ($this->debug_mode_enabled()) {
                return array(
                    'error' => 'Sensor-Daten konnten nicht geladen werden: ' . $sensors['error'],
                    'stations_data' => $stations,
                    'sensor_error' => $sensors
                );
            }
            // In non-debug mode, just return stations without model info
            return $stations;
        }

        // Sensors reference their station via station_id. The ISS (integrated sensor suite) is the
        // actual weather station model; the station's own product_number belongs to the gateway.
        $sensors_by_station = array();
        if (is_array($sensors)) {
            foreach ($sensors as $sensor) {
                if (isset($sensor['station_id'])) {
                    $sensors_by_station[$sensor['station_id']][] = $sensor;
                }
            }
        }

        foreach ($stations as &$station) {
            $iss_model = '';
            $sensor_models = array();
            $station_sensors = isset($sensors_by_station[$station['station_id']]) ? $sensors_by_station[$station['station_id']] : array();

            foreach ($station_sensors as $sensor) {
                $category = isset($sensor['category']) ? strtoupper($sensor['category']) : '';
                if ($category === 'HEALTH' || (isset($sensor['active']) && !$sensor['active']) || empty($sensor['product_name'])) {
                    continue;
                }
                $name = trim((isset($sensor['manufacturer']) ? $sensor['manufacturer'] . ' ' : '') . $sensor['product_name']);
                if ($category === 'ISS' && $iss_model === '') {
                    $iss_model = $name;
                } else {
                    $sensor_models[] = $sensor['product_name'];
                }
            }

            $gateway = '';
            if (!empty($station['gateway_type'])) {
                $gateway = $station['gateway_type'];
                if (!empty($station['product_number'])) {
                    $gateway .= ' (' . $station['product_number'] . ')';
                }
            }

            if ($iss_model !== '') {
                $station['device_model'] = $iss_model;
            } elseif ($gateway !== '') {
                $station['device_model'] = $gateway;
            } else {
                $station['device_model'] = __('Unbekanntes Modell', 'rmd-weatherquery');
            }
            $station['gateway_model'] = $gateway;
            $station['sensor_models'] = $sensor_models;
        }
        unset($station);

        // Cache the result for 24 hours
        $this->set_cache($cache_key, $stations, 24);

        return $stations;
    }

    private function debug_mode_enabled() {
        return !empty($this->options['debug_mode']);
    }
    
    public function weather_shortcode($atts) {
        $atts = shortcode_atts(array(
            'mode' => 'current',
            'station_id' => '',
            'fields' => 'temp',
            'api_key' => '',
            'format' => 'list',
            'display_mode' => 'list', // list, table, widget
            'show_icons' => 'false', // true to show weather icons
            'icon_color' => '#62C626', // hex color for weather icons
            'show_timestamp' => 'false', // true to show last update timestamp
            'start_timestamp' => '',
            'end_timestamp' => '',
            'temperature_unit' => 'celsius', // celsius or fahrenheit
            'show_all_fields' => 'false' // true to show all available fields
        ), $atts, 'wq');
        
        if (!$this->is_configured() && empty($atts['api_key'])) {
            return '<div class="rmd-wq-error">' . __('WeatherQuery Plugin nicht konfiguriert', 'rmd-weatherquery') . '</div>';
        }
        
        if (empty($atts['station_id'])) {
            return '<div class="rmd-wq-error">' . __('Stations-ID erforderlich', 'rmd-weatherquery') . '</div>';
        }
        
        $data = $this->get_weather_data($atts);
        
        if ($data === false) {
            return '<div class="rmd-wq-error">' . __('Fehler beim Laden der Wetterdaten', 'rmd-weatherquery') . '</div>';
        }
        
        return $this->format_weather_data($data, $atts);
    }

    public function weather_header_shortcode($atts) {
        $atts = shortcode_atts(array(
            'station_id' => '',
            'icon_color' => '#62C626',
            'temperature_unit' => 'celsius',
            'separator' => '|'
        ), $atts, 'wq_header');

        if (!$this->is_configured()) {
            return '<span class="rmd-wq-error">' . __('WeatherQuery Plugin nicht konfiguriert', 'rmd-weatherquery') . '</span>';
        }

        // Use configured header station ID if no station_id is provided
        if (empty($atts['station_id'])) {
            if (!empty($this->options['header_station_id'])) {
                $atts['station_id'] = $this->options['header_station_id'];
            } else {
                return '<span class="rmd-wq-error">' . __('Stations-ID erforderlich. Bitte konfigurieren Sie eine Standard-Stations-ID in den WeatherQuery Einstellungen oder übergeben Sie station_id als Parameter.', 'rmd-weatherquery') . '</span>';
            }
        }

        // Get current weather data
        $data = $this->get_weather_data(array(
            'mode' => 'current',
            'station_id' => $atts['station_id'],
            'api_key' => $this->options['api_key']
        ));

        if ($data === false || !isset($data['sensors'])) {
            return '<span class="rmd-wq-error">' . __('Fehler beim Laden der Wetterdaten', 'rmd-weatherquery') . '</span>';
        }

        // Collect all readings from sensors
        $all_readings = array();
        foreach ($data['sensors'] as $sensor) {
            if (!isset($sensor['data']) || !is_array($sensor['data'])) {
                continue;
            }

            foreach ($sensor['data'] as $reading) {
                foreach ($reading as $field_key => $field_value) {
                    if (!array_key_exists($field_key, $all_readings)) {
                        $all_readings[$field_key] = $field_value;
                    }
                }
                break; // Only first reading per sensor
            }
        }

        // Build header output
        $output = '<div class="rmd-wq-header">';
        $items = array();

        // Temperature
        if (isset($all_readings['temp'])) {
            $temp_value = $this->format_field_value('temp', $all_readings['temp'], $atts['temperature_unit']);
            $icon_style = $atts['icon_color'] !== '#62C626' ? ' style="color: ' . esc_attr($atts['icon_color']) . ';"' : '';
            $items[] = '<span class="rmd-wq-header-item"><i class="wi wi-thermometer"' . $icon_style . '></i><span class="rmd-wq-header-value">' . esc_html($temp_value) . '</span></span>';
        }

        // Rain (daily rainfall)
        if (isset($all_readings['rainfall_daily_in'])) {
            $rain_value = $this->format_field_value('rainfall', $all_readings['rainfall_daily_in'], $atts['temperature_unit']);
            $icon_style = $atts['icon_color'] !== '#62C626' ? ' style="color: ' . esc_attr($atts['icon_color']) . ';"' : '';
            $items[] = '<span class="rmd-wq-header-item"><i class="wi wi-rain"' . $icon_style . '></i><span class="rmd-wq-header-value">' . esc_html($rain_value) . '</span></span>';
        }

        // Wind speed
        if (isset($all_readings['wind_speed_last'])) {
            $wind_value = $this->format_field_value('wind_speed', $all_readings['wind_speed_last'], $atts['temperature_unit']);
            $icon_style = $atts['icon_color'] !== '#62C626' ? ' style="color: ' . esc_attr($atts['icon_color']) . ';"' : '';
            $items[] = '<span class="rmd-wq-header-item"><i class="wi wi-wind-direction"' . $icon_style . '></i><span class="rmd-wq-header-value">' . esc_html($wind_value) . '</span></span>';
        }

        // Join items with separator
        $output .= implode(' <span class="rmd-wq-header-separator">' . esc_html($atts['separator']) . '</span> ', $items);
        $output .= '</div>';

        return $output;
    }

    /**
     * [wq_archive] renders readings stored by the plugin itself (see run_archive()).
     * Views: daily (one row per day), monthly, readings (all readings of one day), chart.
     */
    public function weather_archive_shortcode($atts) {
        $atts = shortcode_atts(array(
            'station_id' => '',
            'sensor_id' => '', // name used before 1.4.0, holds a station ID
            'view' => 'daily',
            'days' => '30',
            'start_date' => '',
            'end_date' => '',
            'date' => '',
            'fields' => '',
            'show_icons' => 'false',
            'icon_color' => '#62C626',
            'temperature_unit' => 'celsius',
            'selectable' => 'true'
        ), $atts, 'wq_archive');

        if (!$this->is_configured()) {
            return '<div class="rmd-wq-error">' . esc_html__('WeatherQuery Plugin nicht konfiguriert', 'rmd-weatherquery') . '</div>';
        }

        return $this->render_archive($atts);
    }

    private function render_archive($atts) {
        $station_id = $this->resolve_archive_station($atts);
        if (!$station_id) {
            return '<div class="rmd-wq-error">' . esc_html__('Keine Station für das Wetterarchiv gefunden. Bitte station_id angeben oder eine Header-Station in den WeatherQuery-Einstellungen festlegen.', 'rmd-weatherquery') . '</div>';
        }

        $view = in_array($atts['view'], array('daily', 'monthly', 'readings', 'chart'), true) ? $atts['view'] : 'daily';
        $selectable = strtolower((string) $atts['selectable']) !== 'false';
        $first_day = $this->get_archive_first_day($station_id);

        if ($first_day === null) {
            return '<div class="rmd-wq-data rmd-wq-archive"><p class="rmd-wq-archive-empty">' . esc_html__('Das Wetterarchiv enthält noch keine Messwerte. Die Aufzeichnung beginnt automatisch, sobald die Zeitsteuerung läuft.', 'rmd-weatherquery') . '</p></div>';
        }

        switch ($view) {
            case 'monthly':
                return $this->render_archive_monthly($station_id, $atts);
            case 'readings':
                return $this->render_archive_readings($station_id, $atts, $selectable, $first_day);
            case 'chart':
                list($from, $to) = $this->archive_range($atts, $selectable);
                return $this->render_archive_chart($station_id, $atts, $from, $to, $selectable);
            default:
                list($from, $to) = $this->archive_range($atts, $selectable);
                return $this->render_archive_daily($station_id, $atts, $from, $to, $selectable);
        }
    }

    /**
     * Station for the archive: attribute, legacy sensor_id, archive setting, header station,
     * or the first station that has archived readings.
     */
    private function resolve_archive_station($atts) {
        global $wpdb;

        foreach (array($atts['station_id'], $atts['sensor_id'],
                       isset($this->options['default_sensor_id']) ? $this->options['default_sensor_id'] : '',
                       isset($this->options['header_station_id']) ? $this->options['header_station_id'] : '') as $candidate) {
            if (absint($candidate) > 0) {
                return absint($candidate);
            }
        }

        $table = $this->archive_table();
        return (int) $wpdb->get_var("SELECT station_id FROM $table ORDER BY id ASC LIMIT 1");
    }

    private function get_archive_first_day($station_id) {
        global $wpdb;
        $table = $this->archive_table();
        $day = $wpdb->get_var($wpdb->prepare("SELECT MIN(day) FROM $table WHERE station_id = %d", $station_id));
        return $day ? $day : null;
    }

    /** Period options visitors can pick in daily/chart views. */
    private function archive_period_options() {
        return array(
            7 => __('7 Tage', 'rmd-weatherquery'),
            30 => __('30 Tage', 'rmd-weatherquery'),
            90 => __('90 Tage', 'rmd-weatherquery'),
            365 => __('1 Jahr', 'rmd-weatherquery')
        );
    }

    /**
     * @return array{0: string, 1: string} First and last day (Y-m-d), inclusive.
     */
    private function archive_range($atts, $selectable) {
        $today = wp_date('Y-m-d');

        if (!empty($atts['start_date'])) {
            $from = $this->sanitize_day($atts['start_date']);
            $to = !empty($atts['end_date']) ? $this->sanitize_day($atts['end_date']) : $today;
            if ($from && $to) {
                return array(min($from, $to), max($from, $to));
            }
        }

        $days = max(1, min(3660, absint($atts['days'])));
        if ($selectable && isset($_GET['wq_days']) && array_key_exists(absint($_GET['wq_days']), $this->archive_period_options())) {
            $days = absint($_GET['wq_days']);
        }

        $from = wp_date('Y-m-d', strtotime($today . ' 12:00:00 UTC') - ($days - 1) * DAY_IN_SECONDS, new DateTimeZone('UTC'));
        return array($from, $today);
    }

    private function sanitize_day($value) {
        $value = substr(sanitize_text_field((string) $value), 0, 10);
        $dt = DateTime::createFromFormat('!Y-m-d', $value);
        return ($dt && $dt->format('Y-m-d') === $value) ? $value : null;
    }

    private function format_archive_day($day, $format = 'D, d.m.Y') {
        return wp_date($format, strtotime($day . ' 12:00:00 UTC'), new DateTimeZone('UTC'));
    }

    private function get_archive_daily_rows($station_id, $from, $to) {
        global $wpdb;
        $table = $this->archive_table();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT day, COUNT(*) AS readings, MIN(temp) AS temp_min, MAX(temp) AS temp_max, AVG(temp) AS temp_avg,
                    AVG(hum) AS hum_avg, AVG(bar) AS bar_avg, AVG(wind_avg) AS wind_avg, MAX(wind_gust) AS wind_gust,
                    MAX(rain_day) AS rain
             FROM $table WHERE station_id = %d AND day BETWEEN %s AND %s
             GROUP BY day ORDER BY day ASC",
            $station_id, $from, $to
        ), ARRAY_A);
    }

    /** Value formatted like the live display, or a dash when nothing was recorded. */
    private function format_archive_value($field, $value, $atts) {
        if ($value === null || $value === '') {
            return '–';
        }
        return $this->format_field_value($field, (float) $value, $atts['temperature_unit']);
    }

    private function archive_header_cell($field, $label, $atts) {
        $item = array('icon' => $this->get_field_icon($field));
        $icon = $this->render_icon($item, $atts);
        return '<th>' . ($icon !== '' ? $icon . ' ' : '') . esc_html($label) . '</th>';
    }

    private function render_archive_daily($station_id, $atts, $from, $to, $selectable) {
        $rows = array_reverse($this->get_archive_daily_rows($station_id, $from, $to));

        $output = '<div class="rmd-wq-data rmd-wq-archive rmd-wq-table" data-view="daily" data-station-id="' . esc_attr($station_id) . '">';
        if ($selectable) {
            $output .= $this->render_archive_period_form($from, $to);
        }

        if (empty($rows)) {
            return $output . '<p class="rmd-wq-archive-empty">' . esc_html__('Für diesen Zeitraum sind keine Messwerte archiviert.', 'rmd-weatherquery') . '</p></div>';
        }

        $output .= '<table class="rmd-wq-weather-table"><thead><tr>';
        $output .= '<th>' . esc_html__('Tag', 'rmd-weatherquery') . '</th>';
        $output .= $this->archive_header_cell('temp', __('Tiefst', 'rmd-weatherquery'), $atts);
        $output .= $this->archive_header_cell('temp', __('Höchst', 'rmd-weatherquery'), $atts);
        $output .= $this->archive_header_cell('temp', __('Mittel', 'rmd-weatherquery'), $atts);
        $output .= $this->archive_header_cell('rainfall', __('Niederschlag', 'rmd-weatherquery'), $atts);
        $output .= $this->archive_header_cell('wind_speed', __('Wind Ø', 'rmd-weatherquery'), $atts);
        $output .= $this->archive_header_cell('wind_gust', __('Böe max.', 'rmd-weatherquery'), $atts);
        $output .= $this->archive_header_cell('humidity', __('Feuchte Ø', 'rmd-weatherquery'), $atts);
        $output .= $this->archive_header_cell('pressure', __('Luftdruck Ø', 'rmd-weatherquery'), $atts);
        $output .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $title = sprintf(_n('%d Messung', '%d Messungen', $row['readings'], 'rmd-weatherquery'), $row['readings']);
            $output .= '<tr>';
            $output .= '<td title="' . esc_attr($title) . '">' . esc_html($this->format_archive_day($row['day'])) . '</td>';
            $output .= '<td>' . esc_html($this->format_archive_value('temp', $row['temp_min'], $atts)) . '</td>';
            $output .= '<td>' . esc_html($this->format_archive_value('temp', $row['temp_max'], $atts)) . '</td>';
            $output .= '<td>' . esc_html($this->format_archive_value('temp', $row['temp_avg'], $atts)) . '</td>';
            $output .= '<td>' . esc_html($this->format_archive_value('rainfall', $row['rain'], $atts)) . '</td>';
            $output .= '<td>' . esc_html($this->format_archive_value('wind_speed_avg', $row['wind_avg'], $atts)) . '</td>';
            $output .= '<td>' . esc_html($this->format_archive_value('wind_gust', $row['wind_gust'], $atts)) . '</td>';
            $output .= '<td>' . esc_html($this->format_archive_value('humidity', $row['hum_avg'], $atts)) . '</td>';
            $output .= '<td>' . esc_html($this->format_archive_value('pressure', $row['bar_avg'], $atts)) . '</td>';
            $output .= '</tr>';
        }

        return $output . '</tbody></table></div>';
    }

    private function render_archive_monthly($station_id, $atts) {
        global $wpdb;
        $table = $this->archive_table();

        // Rain per day is the day's highest value of the station's daily counter; months sum those.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT LEFT(day, 7) AS month, COUNT(*) AS days, MIN(temp_min) AS temp_min, MAX(temp_max) AS temp_max,
                    AVG(temp_avg) AS temp_avg, SUM(rain) AS rain, SUM(rain >= 0.0078) AS rain_days, MAX(wind_gust) AS wind_gust
             FROM (
                SELECT day, MIN(temp) AS temp_min, MAX(temp) AS temp_max, AVG(temp) AS temp_avg,
                       MAX(rain_day) AS rain, MAX(wind_gust) AS wind_gust
                FROM $table WHERE station_id = %d GROUP BY day
             ) AS daily
             GROUP BY month ORDER BY month DESC",
            $station_id
        ), ARRAY_A);

        $output = '<div class="rmd-wq-data rmd-wq-archive rmd-wq-table" data-view="monthly" data-station-id="' . esc_attr($station_id) . '">';
        $output .= '<table class="rmd-wq-weather-table"><thead><tr>';
        $output .= '<th>' . esc_html__('Monat', 'rmd-weatherquery') . '</th>';
        $output .= $this->archive_header_cell('temp', __('Tiefst', 'rmd-weatherquery'), $atts);
        $output .= $this->archive_header_cell('temp', __('Höchst', 'rmd-weatherquery'), $atts);
        $output .= $this->archive_header_cell('temp', __('Mittel', 'rmd-weatherquery'), $atts);
        $output .= $this->archive_header_cell('rainfall', __('Niederschlag', 'rmd-weatherquery'), $atts);
        $output .= '<th>' . esc_html__('Regentage', 'rmd-weatherquery') . '</th>';
        $output .= $this->archive_header_cell('wind_gust', __('Böe max.', 'rmd-weatherquery'), $atts);
        $output .= '<th>' . esc_html__('Tage mit Daten', 'rmd-weatherquery') . '</th>';
        $output .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $output .= '<tr>';
            $output .= '<td>' . esc_html($this->format_archive_day($row['month'] . '-01', 'F Y')) . '</td>';
            $output .= '<td>' . esc_html($this->format_archive_value('temp', $row['temp_min'], $atts)) . '</td>';
            $output .= '<td>' . esc_html($this->format_archive_value('temp', $row['temp_max'], $atts)) . '</td>';
            $output .= '<td>' . esc_html($this->format_archive_value('temp', $row['temp_avg'], $atts)) . '</td>';
            $output .= '<td>' . esc_html($this->format_archive_value('rainfall', $row['rain'], $atts)) . '</td>';
            $output .= '<td>' . esc_html((int) $row['rain_days']) . '</td>';
            $output .= '<td>' . esc_html($this->format_archive_value('wind_gust', $row['wind_gust'], $atts)) . '</td>';
            $output .= '<td>' . esc_html((int) $row['days']) . '</td>';
            $output .= '</tr>';
        }

        return $output . '</tbody></table></div>';
    }

    /** Plugin fields that can be shown per reading, mapped to their archive column. */
    private function archive_reading_fields() {
        $fields = array();
        foreach (self::ARCHIVE_COLUMNS as $column => $map) {
            $fields[$map[1]] = $column;
        }
        return $fields;
    }

    private function render_archive_readings($station_id, $atts, $selectable, $first_day) {
        global $wpdb;
        $table = $this->archive_table();
        $today = wp_date('Y-m-d');
        $day = $this->archive_selected_day($atts, $selectable);

        $available = $this->archive_reading_fields();
        $requested_fields = $atts['fields'] !== ''
            ? array_map('trim', explode(',', $atts['fields']))
            : array('temp', 'humidity', 'pressure', 'wind_speed', 'wind_gust', 'wind_dir', 'rain_15_min');
        $fields = array_values(array_filter($requested_fields, function ($field) use ($available) {
            return isset($available[$field]);
        }));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE station_id = %d AND day = %s ORDER BY ts ASC",
            $station_id, $day
        ), ARRAY_A);

        $output = '<div class="rmd-wq-data rmd-wq-archive rmd-wq-table" data-view="readings" data-station-id="' . esc_attr($station_id) . '">';
        if ($selectable) {
            $output .= $this->render_archive_day_form($day, $first_day, $today);
        }

        if (empty($rows) || empty($fields)) {
            return $output . '<p class="rmd-wq-archive-empty">' . esc_html(sprintf(__('Für den %s sind keine Messwerte archiviert.', 'rmd-weatherquery'), $this->format_archive_day($day, 'd.m.Y'))) . '</p></div>';
        }

        $output .= '<table class="rmd-wq-weather-table"><thead><tr><th>' . esc_html__('Uhrzeit', 'rmd-weatherquery') . '</th>';
        foreach ($fields as $field) {
            $output .= $this->archive_header_cell($field, $this->get_field_label($field), $atts);
        }
        $output .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $output .= '<tr><td>' . esc_html(wp_date('H:i', (int) $row['ts'])) . '</td>';
            foreach ($fields as $field) {
                $output .= '<td>' . esc_html($this->format_archive_value($field, $row[$available[$field]], $atts)) . '</td>';
            }
            $output .= '</tr>';
        }

        return $output . '</tbody></table></div>';
    }

    private function render_archive_chart($station_id, $atts, $from, $to, $selectable) {
        $rows = $this->get_archive_daily_rows($station_id, $from, $to);
        $fahrenheit = strtolower($atts['temperature_unit']) === 'fahrenheit';

        $convert_temp = function ($value) use ($fahrenheit) {
            if ($value === null) {
                return null;
            }
            return round($fahrenheit ? (float) $value : ((float) $value - 32) * 5 / 9, 1);
        };

        $chart = array(
            'labels' => array(),
            'tempUnit' => $fahrenheit ? '°F' : '°C',
            'tempMin' => array(),
            'tempMax' => array(),
            'tempAvg' => array(),
            'rain' => array(),
            'color' => sanitize_hex_color($atts['icon_color']) ? $atts['icon_color'] : '#62C626',
            'strings' => array(
                'tempMin' => __('Tiefst', 'rmd-weatherquery'),
                'tempMax' => __('Höchst', 'rmd-weatherquery'),
                'tempAvg' => __('Mittel', 'rmd-weatherquery'),
                'rain' => __('Niederschlag', 'rmd-weatherquery'),
                'temp' => __('Temperatur', 'rmd-weatherquery')
            )
        );
        foreach ($rows as $row) {
            $chart['labels'][] = $this->format_archive_day($row['day'], 'd.m.');
            $chart['tempMin'][] = $convert_temp($row['temp_min']);
            $chart['tempMax'][] = $convert_temp($row['temp_max']);
            $chart['tempAvg'][] = $convert_temp($row['temp_avg']);
            $chart['rain'][] = $row['rain'] === null ? null : round((float) $row['rain'] * 25.4, 1);
        }

        $output = '<div class="rmd-wq-data rmd-wq-archive rmd-wq-archive-chart" data-view="chart" data-station-id="' . esc_attr($station_id) . '">';
        if ($selectable) {
            $output .= $this->render_archive_period_form($from, $to);
        }

        if (empty($rows)) {
            return $output . '<p class="rmd-wq-archive-empty">' . esc_html__('Für diesen Zeitraum sind keine Messwerte archiviert.', 'rmd-weatherquery') . '</p></div>';
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return $output . '<div class="rmd-wq-notice">' . esc_html(sprintf(__('Diagramm Temperatur und Niederschlag (%d Tage) – wird auf der Seite angezeigt.', 'rmd-weatherquery'), count($rows))) . '</div></div>';
        }

        wp_enqueue_script('rmd-wq-archive-chart');
        $output .= '<div class="rmd-wq-chart-canvas"><canvas role="img" aria-label="' . esc_attr(sprintf(__('Temperatur und Niederschlag vom %1$s bis %2$s', 'rmd-weatherquery'), $this->format_archive_day($from, 'd.m.Y'), $this->format_archive_day($to, 'd.m.Y'))) . '" data-rmd-wq-chart="' . esc_attr(wp_json_encode($chart)) . '"></canvas></div>';

        return $output . '</div>';
    }

    /** Day from the `date` attribute, overridden by ?wq_date= when visitors may pick the day. */
    private function archive_selected_day($atts, $selectable) {
        $day = $this->sanitize_day($atts['date']);
        if ($selectable && isset($_GET['wq_date'])) {
            $requested = $this->sanitize_day(wp_unslash($_GET['wq_date']));
            if ($requested) {
                $day = $requested;
            }
        }
        return $day ? $day : wp_date('Y-m-d');
    }

    /**
     * [wq_chart]: chart from the archive whose series visitors can switch on and off.
     * mode="readings" plots every reading of one day, mode="daily" one value per day.
     */
    public function weather_chart_shortcode($atts) {
        $atts = shortcode_atts(array(
            'station_id' => '',
            'mode' => 'readings',
            'fields' => '',
            'visible' => '',
            'date' => '',
            'days' => '30',
            'start_date' => '',
            'end_date' => '',
            'selectable' => 'true',
            'toggles' => 'true',
            'temperature_unit' => 'celsius',
            'height' => '360'
        ), $atts, 'wq_chart');

        if (!$this->is_configured()) {
            return '<div class="rmd-wq-error">' . esc_html__('WeatherQuery Plugin nicht konfiguriert', 'rmd-weatherquery') . '</div>';
        }

        return $this->render_chart($atts);
    }

    /**
     * Chart series per mode: key => [label, archive column (readings) or SQL aggregate (daily), axis, chart type].
     * Columns and aggregates are fixed here, so they can be put into SQL directly.
     */
    private function chart_series_definitions($mode) {
        if ($mode === 'daily') {
            return array(
                'temp_max'       => array(__('Temperatur max.', 'rmd-weatherquery'), 'MAX(temp)', 'temp', 'line'),
                'temp_avg'       => array(__('Temperatur Ø', 'rmd-weatherquery'), 'AVG(temp)', 'temp', 'line'),
                'temp_min'       => array(__('Temperatur min.', 'rmd-weatherquery'), 'MIN(temp)', 'temp', 'line'),
                'dew_point'      => array(__('Taupunkt Ø', 'rmd-weatherquery'), 'AVG(dew_point)', 'temp', 'line'),
                'humidity'       => array(__('Luftfeuchtigkeit Ø', 'rmd-weatherquery'), 'AVG(hum)', 'hum', 'line'),
                'pressure'       => array(__('Luftdruck Ø', 'rmd-weatherquery'), 'AVG(bar)', 'bar', 'line'),
                'wind_speed_avg' => array(__('Wind Ø', 'rmd-weatherquery'), 'AVG(wind_avg)', 'wind', 'line'),
                'wind_gust'      => array(__('Böe max.', 'rmd-weatherquery'), 'MAX(wind_gust)', 'wind', 'line'),
                'rainfall'       => array(__('Niederschlag', 'rmd-weatherquery'), 'MAX(rain_day)', 'rain', 'bar'),
                'rain_rate'      => array(__('Regenrate max.', 'rmd-weatherquery'), 'MAX(rain_rate)', 'rate', 'line'),
                'solar_rad'      => array(__('Sonnenstrahlung max.', 'rmd-weatherquery'), 'MAX(solar_rad)', 'solar', 'line'),
                'uv_index'       => array(__('UV-Index max.', 'rmd-weatherquery'), 'MAX(uv_index)', 'uv', 'line')
            );
        }

        $axes = array(
            'temp' => 'temp', 'dew_point' => 'temp', 'humidity' => 'hum', 'pressure' => 'bar',
            'wind_speed' => 'wind', 'wind_speed_avg' => 'wind', 'wind_gust' => 'wind', 'wind_dir' => 'dir',
            'rainfall' => 'rain', 'rain_15_min' => 'rain', 'rain_rate' => 'rate', 'solar_rad' => 'solar', 'uv_index' => 'uv'
        );
        $series = array();
        foreach (self::ARCHIVE_COLUMNS as $column => $map) {
            $field = $map[1];
            if (!isset($axes[$field])) {
                continue;
            }
            $label = $field === 'rainfall' ? __('Niederschlag (Tagessumme)', 'rmd-weatherquery') : $this->get_field_label($field);
            $series[$field] = array($label, $column, $axes[$field], $field === 'rain_15_min' ? 'bar' : 'line');
        }
        return $series;
    }

    private function chart_default_series($mode) {
        if ($mode === 'daily') {
            return array(
                'fields' => array('temp_max', 'temp_avg', 'temp_min', 'rainfall', 'humidity', 'pressure', 'wind_gust'),
                'visible' => array('temp_max', 'temp_avg', 'temp_min', 'rainfall')
            );
        }
        return array(
            'fields' => array('temp', 'dew_point', 'humidity', 'pressure', 'wind_speed', 'wind_gust', 'rain_15_min', 'solar_rad'),
            'visible' => array('temp', 'humidity')
        );
    }

    /** Y axes in display units; values are converted by chart_value(). */
    private function chart_axes($fahrenheit) {
        return array(
            'temp'  => array('title' => __('Temperatur', 'rmd-weatherquery'), 'unit' => $fahrenheit ? '°F' : '°C'),
            'hum'   => array('title' => __('Luftfeuchtigkeit', 'rmd-weatherquery'), 'unit' => '%', 'min' => 0, 'max' => 100),
            'bar'   => array('title' => __('Luftdruck', 'rmd-weatherquery'), 'unit' => 'hPa'),
            'wind'  => array('title' => __('Wind', 'rmd-weatherquery'), 'unit' => 'km/h', 'beginAtZero' => true),
            'dir'   => array('title' => __('Windrichtung', 'rmd-weatherquery'), 'unit' => '°', 'min' => 0, 'max' => 360),
            'rain'  => array('title' => __('Niederschlag', 'rmd-weatherquery'), 'unit' => 'mm', 'beginAtZero' => true, 'suggestedMax' => 2),
            'rate'  => array('title' => __('Regenrate', 'rmd-weatherquery'), 'unit' => 'mm/h', 'beginAtZero' => true),
            'solar' => array('title' => __('Sonnenstrahlung', 'rmd-weatherquery'), 'unit' => 'W/m²', 'beginAtZero' => true),
            'uv'    => array('title' => __('UV-Index', 'rmd-weatherquery'), 'unit' => '', 'beginAtZero' => true)
        );
    }

    private function chart_value($axis, $value, $fahrenheit) {
        if ($value === null) {
            return null;
        }
        $value = (float) $value;
        switch ($axis) {
            case 'temp':
                return round($fahrenheit ? $value : ($value - 32) * 5 / 9, 1);
            case 'bar':
                return round($value * 33.8639, 1);
            case 'wind':
                return round($value * 1.609344, 1);
            case 'rain':
            case 'rate':
                return round($value * 25.4, 1);
            default:
                return round($value, 1);
        }
    }

    private function chart_series_color($key) {
        $colors = array(
            'temp' => '#d9534f', 'temp_max' => '#d9534f', 'temp_avg' => '#62C626', 'temp_min' => '#5b8def',
            'dew_point' => '#9c36b5', 'humidity' => '#1c9c9c', 'pressure' => '#6c757d',
            'wind_speed' => '#f59f00', 'wind_speed_avg' => '#f59f00', 'wind_gust' => '#d9480f', 'wind_dir' => '#868e96',
            'rainfall' => '#3b82c4', 'rain_15_min' => '#3b82c4', 'rain_rate' => '#1864ab',
            'solar_rad' => '#e6b800', 'uv_index' => '#7048e8'
        );
        return isset($colors[$key]) ? $colors[$key] : '#495057';
    }

    /** Keys from a comma-separated list that exist in $definitions, in the given order. */
    private function chart_pick_series($list, $definitions) {
        $keys = is_array($list) ? $list : explode(',', (string) $list);
        $picked = array();
        foreach ($keys as $key) {
            $key = sanitize_key(trim($key));
            if (isset($definitions[$key]) && !in_array($key, $picked, true)) {
                $picked[] = $key;
            }
        }
        return $picked;
    }

    private function render_chart($atts) {
        global $wpdb;
        $table = $this->archive_table();

        $atts['sensor_id'] = '';
        $station_id = $this->resolve_archive_station($atts);
        if (!$station_id) {
            return '<div class="rmd-wq-error">' . esc_html__('Keine Station für das Wetterarchiv gefunden. Bitte station_id angeben oder eine Header-Station in den WeatherQuery-Einstellungen festlegen.', 'rmd-weatherquery') . '</div>';
        }

        $mode = $atts['mode'] === 'daily' ? 'daily' : 'readings';
        $selectable = strtolower((string) $atts['selectable']) !== 'false';
        $toggles = strtolower((string) $atts['toggles']) !== 'false';
        $fahrenheit = strtolower($atts['temperature_unit']) === 'fahrenheit';
        $height = min(800, max(200, absint($atts['height'])));
        $first_day = $this->get_archive_first_day($station_id);

        if ($first_day === null) {
            return '<div class="rmd-wq-data rmd-wq-archive"><p class="rmd-wq-archive-empty">' . esc_html__('Das Wetterarchiv enthält noch keine Messwerte. Die Aufzeichnung beginnt automatisch, sobald die Zeitsteuerung läuft.', 'rmd-weatherquery') . '</p></div>';
        }

        $definitions = $this->chart_series_definitions($mode);
        $defaults = $this->chart_default_series($mode);
        $keys = $this->chart_pick_series($atts['fields'] !== '' ? $atts['fields'] : $defaults['fields'], $definitions);
        $visible = $this->chart_pick_series($atts['visible'] !== '' ? $atts['visible'] : $defaults['visible'], $definitions);
        $visible = array_values(array_intersect($visible, $keys));
        if (empty($visible) && !empty($keys)) {
            $visible = array($keys[0]);
        }

        $output = '<div class="rmd-wq-data rmd-wq-archive rmd-wq-chart" data-mode="' . esc_attr($mode) . '" data-station-id="' . esc_attr($station_id) . '">';

        $select = array();
        foreach ($keys as $index => $key) {
            $select[] = $definitions[$key][1] . ' AS s' . $index;
        }
        $select = $select ? ', ' . implode(', ', $select) : '';

        if ($mode === 'daily') {
            list($from, $to) = $this->archive_range($atts, $selectable);
            if ($selectable) {
                $output .= $this->render_archive_period_form($from, $to);
            }
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT day $select FROM $table WHERE station_id = %d AND day BETWEEN %s AND %s GROUP BY day ORDER BY day ASC",
                $station_id, $from, $to
            ), ARRAY_A);
            $aria = sprintf(__('Tageswerte vom %1$s bis %2$s', 'rmd-weatherquery'), $this->format_archive_day($from, 'd.m.Y'), $this->format_archive_day($to, 'd.m.Y'));
            $empty = __('Für diesen Zeitraum sind keine Messwerte archiviert.', 'rmd-weatherquery');
        } else {
            $day = $this->archive_selected_day($atts, $selectable);
            if ($selectable) {
                $output .= $this->render_archive_day_form($day, $first_day, wp_date('Y-m-d'));
            }
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT ts $select FROM $table WHERE station_id = %d AND day = %s ORDER BY ts ASC",
                $station_id, $day
            ), ARRAY_A);
            $aria = sprintf(__('Messwerte vom %s', 'rmd-weatherquery'), $this->format_archive_day($day, 'd.m.Y'));
            $empty = sprintf(__('Für den %s sind keine Messwerte archiviert.', 'rmd-weatherquery'), $this->format_archive_day($day, 'd.m.Y'));
        }

        $axes = $this->chart_axes($fahrenheit);
        $chart = array(
            'mode' => $mode,
            'legend' => !$toggles,
            'labels' => array(),
            'x' => array(),
            'series' => array(),
            'axes' => array(),
            'strings' => array('clock' => __('%s Uhr', 'rmd-weatherquery'))
        );
        foreach ($rows as $row) {
            if ($mode === 'daily') {
                $chart['labels'][] = $this->format_archive_day($row['day'], 'd.m.');
            } else {
                $chart['x'][] = (int) wp_date('G', (int) $row['ts']) * 60 + (int) wp_date('i', (int) $row['ts']);
            }
        }

        foreach ($keys as $index => $key) {
            list($label, , $axis, $type) = $definitions[$key];
            $values = array();
            $has_value = false;
            foreach ($rows as $row) {
                $value = $this->chart_value($axis, $row['s' . $index], $fahrenheit);
                $has_value = $has_value || $value !== null;
                $values[] = $value;
            }
            // Fields the station does not deliver would only add empty toggles.
            if (!$has_value) {
                continue;
            }
            $chart['series'][] = array(
                'key' => $key,
                'label' => $label,
                'axis' => $axis,
                'type' => $type,
                'color' => $this->chart_series_color($key),
                'visible' => in_array($key, $visible, true),
                'data' => $values
            );
            if (!isset($chart['axes'][$axis])) {
                $chart['axes'][$axis] = $axes[$axis] + array('position' => count($chart['axes']) % 2 ? 'right' : 'left');
            }
        }

        if (empty($rows) || empty($chart['series'])) {
            return $output . '<p class="rmd-wq-archive-empty">' . esc_html($empty) . '</p></div>';
        }

        if ($toggles) {
            $output .= '<div class="rmd-wq-chart-toggles" role="group" aria-label="' . esc_attr__('Messgrößen ein- und ausblenden', 'rmd-weatherquery') . '">';
            foreach ($chart['series'] as $index => $series) {
                $output .= '<label class="rmd-wq-chart-toggle" style="--rmd-wq-series-color: ' . esc_attr($series['color']) . ';">';
                $output .= '<input type="checkbox" data-rmd-wq-series="' . esc_attr($index) . '"' . checked($series['visible'], true, false) . '>';
                $output .= '<span class="rmd-wq-chart-swatch" aria-hidden="true"></span>' . esc_html($series['label']) . '</label>';
            }
            $output .= '</div>';
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return $output . '<div class="rmd-wq-notice">' . esc_html(sprintf(_n('Diagramm mit %d Messpunkt – wird auf der Seite angezeigt.', 'Diagramm mit %d Messpunkten – wird auf der Seite angezeigt.', count($rows), 'rmd-weatherquery'), count($rows))) . '</div></div>';
        }

        wp_enqueue_script('rmd-wq-weather-chart');
        $output .= '<div class="rmd-wq-chart-canvas" style="height: ' . esc_attr($height) . 'px;"><canvas role="img" aria-label="' . esc_attr($aria) . '" data-rmd-wq-weather-chart="' . esc_attr(wp_json_encode($chart)) . '"></canvas></div>';

        return $output . '</div>';
    }

    public function rest_get_chart_series() {
        $result = array();
        foreach (array('readings', 'daily') as $mode) {
            $series = array();
            foreach ($this->chart_series_definitions($mode) as $key => $definition) {
                $series[] = array('value' => $key, 'label' => $definition[0]);
            }
            $result[$mode] = array('series' => $series) + $this->chart_default_series($mode);
        }
        return rest_ensure_response($result);
    }

    public function render_chart_block($attributes) {
        $a = wp_parse_args($attributes, array(
            'stationId' => 0,
            'chartMode' => 'readings',
            'series' => array(),
            'visibleSeries' => array(),
            'days' => 30,
            'date' => '',
            'selectable' => true,
            'toggles' => true,
            'temperatureUnit' => 'celsius',
            'height' => 360,
            'title' => '',
            'titleLevel' => 3
        ));

        $is_editor_preview = defined('REST_REQUEST') && REST_REQUEST;

        if (!$this->is_configured()) {
            return $is_editor_preview ? '<div class="rmd-wq-notice">' . esc_html__('WeatherQuery ist nicht konfiguriert. Bitte API-Zugangsdaten unter WeatherQuery → Konfiguration eintragen.', 'rmd-weatherquery') . '</div>' : '';
        }
        if (empty($a['stationId'])) {
            return $is_editor_preview ? '<div class="rmd-wq-notice">' . esc_html__('Bitte in den Block-Einstellungen eine Station auswählen.', 'rmd-weatherquery') . '</div>' : '';
        }
        $series = array_filter(array_map('sanitize_key', (array) $a['series']));
        if (empty($series)) {
            return $is_editor_preview ? '<div class="rmd-wq-notice">' . esc_html__('Bitte mindestens eine Messgröße auswählen.', 'rmd-weatherquery') . '</div>' : '';
        }

        $content = $this->render_chart(array(
            'station_id' => (string) absint($a['stationId']),
            'mode' => $a['chartMode'] === 'daily' ? 'daily' : 'readings',
            'fields' => implode(',', $series),
            'visible' => implode(',', array_map('sanitize_key', (array) $a['visibleSeries'])),
            'date' => (string) $a['date'],
            'days' => (string) absint($a['days']),
            'start_date' => '',
            'end_date' => '',
            'selectable' => $a['selectable'] ? 'true' : 'false',
            'toggles' => $a['toggles'] ? 'true' : 'false',
            'temperature_unit' => $a['temperatureUnit'] === 'fahrenheit' ? 'fahrenheit' : 'celsius',
            'height' => (string) absint($a['height'])
        ));

        $title = '';
        if (trim($a['title']) !== '') {
            $level = min(6, max(2, absint($a['titleLevel'])));
            $title = '<h' . $level . ' class="rmd-wq-title">' . esc_html($a['title']) . '</h' . $level . '>';
        }

        return '<div ' . get_block_wrapper_attributes() . '>' . $title . $content . '</div>';
    }

    /** Hidden inputs that keep foreign query arguments (e.g. page_id without pretty permalinks). */
    private function archive_form_hidden_fields($exclude) {
        $html = '';
        foreach ($_GET as $key => $value) {
            if (in_array($key, $exclude, true) || is_array($value)) {
                continue;
            }
            $html .= '<input type="hidden" name="' . esc_attr(sanitize_key($key)) . '" value="' . esc_attr(sanitize_text_field(wp_unslash($value))) . '">';
        }
        return $html;
    }

    private function render_archive_period_form($from, $to) {
        $current = isset($_GET['wq_days']) ? absint($_GET['wq_days']) : 0;
        $output = '<form class="rmd-wq-archive-controls" method="get">';
        $output .= $this->archive_form_hidden_fields(array('wq_days'));
        $output .= '<label>' . esc_html__('Zeitraum', 'rmd-weatherquery') . ' <select name="wq_days" onchange="this.form.submit()">';
        foreach ($this->archive_period_options() as $days => $label) {
            $selected = $current ? $current === $days : (int) ((strtotime($to) - strtotime($from)) / DAY_IN_SECONDS) + 1 === $days;
            $output .= '<option value="' . esc_attr($days) . '"' . selected($selected, true, false) . '>' . esc_html($label) . '</option>';
        }
        $output .= '</select></label>';
        $output .= '<noscript><button type="submit">' . esc_html__('Anzeigen', 'rmd-weatherquery') . '</button></noscript>';
        $output .= '<span class="rmd-wq-archive-range">' . esc_html($this->format_archive_day($from, 'd.m.Y') . ' – ' . $this->format_archive_day($to, 'd.m.Y')) . '</span>';
        return $output . '</form>';
    }

    private function render_archive_day_form($day, $first_day, $today) {
        $prev = $day > $first_day
            ? wp_date('Y-m-d', strtotime($day . ' 12:00:00 UTC') - DAY_IN_SECONDS, new DateTimeZone('UTC')) : null;
        $next = $day < $today
            ? wp_date('Y-m-d', strtotime($day . ' 12:00:00 UTC') + DAY_IN_SECONDS, new DateTimeZone('UTC')) : null;

        $output = '<form class="rmd-wq-archive-controls" method="get">';
        $output .= $this->archive_form_hidden_fields(array('wq_date'));
        if ($prev) {
            $output .= '<a class="rmd-wq-archive-nav" href="' . esc_url(add_query_arg('wq_date', $prev)) . '" aria-label="' . esc_attr__('Vorheriger Tag', 'rmd-weatherquery') . '">‹</a>';
        }
        $output .= '<label>' . esc_html__('Tag', 'rmd-weatherquery') . ' <input type="date" name="wq_date" value="' . esc_attr($day) . '" min="' . esc_attr($first_day) . '" max="' . esc_attr($today) . '" onchange="this.form.submit()"></label>';
        if ($next) {
            $output .= '<a class="rmd-wq-archive-nav" href="' . esc_url(add_query_arg('wq_date', $next)) . '" aria-label="' . esc_attr__('Nächster Tag', 'rmd-weatherquery') . '">›</a>';
        }
        $output .= '<noscript><button type="submit">' . esc_html__('Anzeigen', 'rmd-weatherquery') . '</button></noscript>';
        return $output . '</form>';
    }

    private function get_cache($key) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'rmd_weatherquery_cache';

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT cache_data, expires_at FROM $table_name WHERE cache_key = %s",
            $key
        ));

        if (!$result) {
            return false;
        }

        // Check if cache is expired
        if (strtotime($result->expires_at) < time()) {
            $this->delete_cache($key);
            return false;
        }

        return json_decode($result->cache_data, true);
    }

    private function set_cache($key, $data, $expiry_hours = 24) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'rmd_weatherquery_cache';
        $expires_at = date('Y-m-d H:i:s', time() + ($expiry_hours * 3600));

        $wpdb->replace(
            $table_name,
            array(
                'cache_key' => $key,
                'cache_data' => json_encode($data),
                'expires_at' => $expires_at
            ),
            array('%s', '%s', '%s')
        );
    }

    private function delete_cache($key) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'rmd_weatherquery_cache';

        $wpdb->delete(
            $table_name,
            array('cache_key' => $key),
            array('%s')
        );
    }

    private function clear_all_cache() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'rmd_weatherquery_cache';

        $wpdb->query("DELETE FROM $table_name");
    }

    private function get_weather_data($atts) {
        $api_key = !empty($atts['api_key']) ? $atts['api_key'] : $this->options['api_key'];
        $api_secret = $this->options['api_secret'];
        
        $base_url = rtrim($this->options['base_url'], '/');
        
        switch ($atts['mode']) {
            case 'current':
                $endpoint = '/current/' . $atts['station_id'];
                break;
            case 'historic':
                $endpoint = '/historic/' . $atts['station_id'];
                break;
            default:
                return false;
        }
        
        $url = $base_url . $endpoint . '?api-key=' . urlencode($api_key);
        
        // Add timestamps for historic data
        if ($atts['mode'] === 'historic' && !empty($atts['start_timestamp'])) {
            $url .= '&start-timestamp=' . urlencode($atts['start_timestamp']);
            if (!empty($atts['end_timestamp'])) {
                $url .= '&end-timestamp=' . urlencode($atts['end_timestamp']);
            }
        }
        
        $args = array(
            'headers' => array(
                'X-API-SECRET' => $api_secret
            ),
            'timeout' => 30
        );
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // Every successful live request also feeds the archive; duplicates are ignored.
        if ($atts['mode'] === 'current' && is_array($data) && isset($data['sensors'])) {
            $this->archive_reading((int) $atts['station_id'], $data);
        }

        return $data;
    }

    /**
     * Cron callback: fetches current conditions of every station on the account and archives them.
     */
    public function run_archive() {
        if (!$this->is_configured()) {
            return;
        }

        $stations = $this->get_stations_with_models();
        if (!is_array($stations) || isset($stations['error'])) {
            $this->set_archive_status(0, __('Stationen konnten nicht geladen werden', 'rmd-weatherquery'));
            return;
        }

        $stored = 0;
        $errors = array();
        foreach ($stations as $station) {
            if (empty($station['station_id'])) {
                continue;
            }
            $data = $this->get_weather_data(array(
                'mode' => 'current',
                'station_id' => (string) $station['station_id'],
                'api_key' => ''
            ));
            if (!is_array($data) || !isset($data['sensors'])) {
                $errors[] = sprintf(__('Station %s: keine Daten von WeatherLink', 'rmd-weatherquery'), $station['station_id']);
                continue;
            }
            // get_weather_data() already archived the reading.
            if ($this->last_archive_result === 'inserted') {
                $stored++;
            } elseif ($this->last_archive_result !== 'duplicate') {
                $errors[] = sprintf(__('Station %s', 'rmd-weatherquery'), $station['station_id']) . ': ' . $this->last_archive_result;
            }
        }

        $this->set_archive_status($stored, implode('; ', $errors));
    }

    /** Outcome of the last archive_reading() call: 'inserted', 'duplicate' or an error message. */
    private $last_archive_result = '';

    /**
     * Stores the station's current reading. The timestamp of the sensor that delivers the outdoor
     * temperature (ISS) identifies the reading; the health sensor reports on its own schedule.
     */
    private function archive_reading($station_id, $data) {
        global $wpdb;

        if ($station_id <= 0) {
            $this->last_archive_result = __('ungültige Stations-ID', 'rmd-weatherquery');
            return false;
        }

        $readings = array();
        $ts = 0;
        $tz_offset = null;
        foreach ($data['sensors'] as $sensor) {
            if (empty($sensor['data'][0]) || !is_array($sensor['data'][0])) {
                continue;
            }
            $reading = $sensor['data'][0];
            if ($ts === 0 && array_key_exists('temp', $reading) && !empty($reading['ts'])) {
                $ts = (int) $reading['ts'];
                $tz_offset = isset($reading['tz_offset']) ? (int) $reading['tz_offset'] : null;
            }
            foreach ($reading as $key => $value) {
                if (!array_key_exists($key, $readings)) {
                    $readings[$key] = $value;
                }
            }
        }

        if ($ts === 0) {
            $this->last_archive_result = __('keine Außensensor-Messung in der Antwort', 'rmd-weatherquery');
            return false;
        }
        if ($tz_offset === null) {
            $tz_offset = (int) wp_timezone()->getOffset(new DateTime('@' . $ts));
        }

        $row = array(
            'station_id' => $station_id,
            'ts' => $ts,
            'day' => gmdate('Y-m-d', $ts + $tz_offset)
        );
        $formats = array('%d', '%d', '%s');
        foreach (self::ARCHIVE_COLUMNS as $column => $map) {
            if (isset($readings[$map[0]]) && is_numeric($readings[$map[0]])) {
                $row[$column] = (float) $readings[$map[0]];
                $formats[] = '%f';
            }
        }

        $table = $this->archive_table();
        if ($wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE station_id = %d AND ts = %d", $station_id, $ts))) {
            $this->last_archive_result = 'duplicate';
            return true;
        }

        if ($wpdb->insert($table, $row, $formats) === false) {
            $this->last_archive_result = __('Datenbankfehler beim Archivieren', 'rmd-weatherquery') . ': ' . $wpdb->last_error;
            return false;
        }
        $this->last_archive_result = 'inserted';
        return true;
    }

    private function set_archive_status($stored, $error) {
        update_option('rmd_weatherquery_archive_status', array(
            'last_run' => time(),
            'stored' => (int) $stored,
            'error' => (string) $error
        ), false);
    }

    public function ajax_archive_now() {
        check_ajax_referer('rmd_wq_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Unzureichende Berechtigung', 'rmd-weatherquery'));
        }

        $this->run_archive();
        $status = get_option('rmd_weatherquery_archive_status', array());
        if (!empty($status['error'])) {
            wp_send_json_error($status['error']);
        }
        wp_send_json_success(array(
            'message' => $status['stored'] > 0
                ? sprintf(__('%d neue Messung(en) archiviert.', 'rmd-weatherquery'), $status['stored'])
                : __('Keine neue Messung – die aktuelle ist bereits archiviert.', 'rmd-weatherquery')
        ));
    }

    /**
     * Merges the first reading of every sensor into one flat array (first occurrence of a field wins).
     *
     * @return array{0: array, 1: int|null} Readings and the timestamp of the first reading.
     */
    private function collect_readings($data) {
        $all_readings = array();
        $timestamp = null;

        foreach ($data['sensors'] as $sensor) {
            if (!isset($sensor['data']) || !is_array($sensor['data'])) {
                continue;
            }

            foreach ($sensor['data'] as $reading) {
                if ($timestamp === null && isset($reading['ts'])) {
                    $timestamp = $reading['ts'];
                }
                foreach ($reading as $field_key => $field_value) {
                    if (!array_key_exists($field_key, $all_readings)) {
                        $all_readings[$field_key] = $field_value;
                    }
                }
                break;
            }
        }

        return array($all_readings, $timestamp);
    }

    /**
     * Weather data with an optional cache lifetime; 0 minutes queries the API on every call.
     */
    private function get_weather_data_cached($atts, $cache_minutes) {
        $cache_minutes = (int) $cache_minutes;
        if ($cache_minutes <= 0) {
            return $this->get_weather_data($atts);
        }

        $cache_key = 'weather_' . md5($atts['mode'] . '|' . $atts['station_id'] . '|' . $atts['start_timestamp'] . '|' . $atts['end_timestamp']);
        $cached = $this->get_cache($cache_key);
        if ($cached !== false && $cached !== null) {
            return $cached;
        }

        $data = $this->get_weather_data($atts);
        if (is_array($data) && isset($data['sensors'])) {
            $this->set_cache($cache_key, $data, $cache_minutes / 60);
        }
        return $data;
    }

    public function register_rest_routes() {
        $permission = function () {
            return current_user_can('edit_posts');
        };

        register_rest_route('rmd-weatherquery/v1', '/stations', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_get_stations'),
            'permission_callback' => $permission
        ));

        register_rest_route('rmd-weatherquery/v1', '/fields', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_get_fields'),
            'permission_callback' => $permission,
            'args' => array(
                'station_id' => array(
                    'required' => true,
                    'sanitize_callback' => 'absint'
                )
            )
        ));

        register_rest_route('rmd-weatherquery/v1', '/chart-series', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_get_chart_series'),
            'permission_callback' => $permission
        ));
    }

    public function rest_get_stations() {
        if (!$this->is_configured()) {
            return new WP_Error('rmd_wq_not_configured', __('WeatherQuery ist nicht konfiguriert. Bitte API-Zugangsdaten unter WeatherQuery → Konfiguration eintragen.', 'rmd-weatherquery'), array('status' => 400));
        }

        $stations = $this->get_stations_with_models();
        if ($stations === false || (is_array($stations) && isset($stations['error']))) {
            $message = is_array($stations) && isset($stations['error']) ? $stations['error'] : __('Fehler beim Laden der Stationen', 'rmd-weatherquery');
            return new WP_Error('rmd_wq_stations_failed', $message, array('status' => 502));
        }

        $result = array();
        foreach ($stations as $station) {
            $result[] = array(
                'id' => (int) $station['station_id'],
                'name' => isset($station['station_name']) ? $station['station_name'] : (string) $station['station_id'],
                'device_model' => isset($station['device_model']) ? $station['device_model'] : ''
            );
        }
        return rest_ensure_response($result);
    }

    /**
     * Fields the station currently delivers, as user-facing field names. Friendly aliases
     * (humidity, wind_speed, ...) win over raw API names that map to the same reading.
     */
    public function rest_get_fields($request) {
        $atts = array(
            'mode' => 'current',
            'station_id' => $request['station_id'],
            'api_key' => '',
            'start_timestamp' => '',
            'end_timestamp' => ''
        );
        $data = $this->get_weather_data_cached($atts, 5);
        if (!is_array($data) || !isset($data['sensors'])) {
            return new WP_Error('rmd_wq_fields_failed', __('Fehler beim Laden der Wetterdaten', 'rmd-weatherquery'), array('status' => 502));
        }

        list($all_readings) = $this->collect_readings($data);

        $candidates = array_merge(
            array('temp', 'humidity', 'pressure', 'wind_speed', 'wind_dir', 'rainfall', 'rain_rate'),
            $this->get_weather_related_fields()
        );

        $seen = array();
        $fields = array();
        foreach ($candidates as $field) {
            $actual = $this->map_field_name($field);
            if (isset($seen[$actual]) || !array_key_exists($actual, $all_readings) || $all_readings[$actual] === null) {
                continue;
            }
            $seen[$actual] = true;
            $fields[] = array(
                'value' => $field,
                'label' => $this->get_field_label($field)
            );
        }
        return rest_ensure_response($fields);
    }

    public function render_block($attributes) {
        $a = wp_parse_args($attributes, array(
            'stationId' => 0,
            'mode' => 'current',
            'fields' => array('temp'),
            'showAllFields' => false,
            'displayMode' => 'widget',
            'showIcons' => true,
            'iconColor' => '#62C626',
            'showTimestamp' => false,
            'temperatureUnit' => 'celsius',
            'archiveView' => 'daily',
            'archiveDays' => 30,
            'archiveDate' => '',
            'archiveSelectable' => true,
            'title' => '',
            'titleLevel' => 3,
            'columns' => 0,
            'labels' => array(),
            'hideLabels' => false,
            'plainCards' => false,
            'cardBackground' => '',
            'cacheMinutes' => 5
        ));

        $is_editor_preview = defined('REST_REQUEST') && REST_REQUEST;

        if (!$this->is_configured()) {
            return $is_editor_preview ? '<div class="rmd-wq-notice">' . esc_html__('WeatherQuery ist nicht konfiguriert. Bitte API-Zugangsdaten unter WeatherQuery → Konfiguration eintragen.', 'rmd-weatherquery') . '</div>' : '';
        }
        if (empty($a['stationId'])) {
            return $is_editor_preview ? '<div class="rmd-wq-notice">' . esc_html__('Bitte in den Block-Einstellungen eine Station auswählen.', 'rmd-weatherquery') . '</div>' : '';
        }

        $fields = array_filter(array_map('sanitize_key', (array) $a['fields']));
        $labels = array();
        foreach ((array) $a['labels'] as $field => $label) {
            if (is_string($label) && trim($label) !== '') {
                $labels[sanitize_key($field)] = sanitize_text_field($label);
            }
        }

        $icon_color = sanitize_hex_color($a['iconColor']);

        $atts = array(
            'mode' => 'current',
            'station_id' => (string) absint($a['stationId']),
            'fields' => implode(',', $fields),
            'api_key' => '',
            'display_mode' => in_array($a['displayMode'], array('list', 'table', 'widget'), true) ? $a['displayMode'] : 'widget',
            'show_icons' => $a['showIcons'] ? 'true' : 'false',
            'icon_color' => $icon_color ? $icon_color : '#62C626',
            'show_timestamp' => $a['showTimestamp'] ? 'true' : 'false',
            'start_timestamp' => '',
            'end_timestamp' => '',
            'temperature_unit' => $a['temperatureUnit'] === 'fahrenheit' ? 'fahrenheit' : 'celsius',
            'show_all_fields' => $a['showAllFields'] ? 'true' : 'false',
            'labels' => $labels,
            'columns' => min(6, absint($a['columns']))
        );

        if ($a['mode'] === 'archive') {
            $content = $this->render_archive(array(
                'station_id' => $atts['station_id'],
                'sensor_id' => '',
                'view' => sanitize_key($a['archiveView']),
                'days' => (string) absint($a['archiveDays']),
                'start_date' => '',
                'end_date' => '',
                'date' => (string) $a['archiveDate'],
                'fields' => $a['showAllFields'] ? '' : $atts['fields'],
                'show_icons' => $atts['show_icons'],
                'icon_color' => $atts['icon_color'],
                'temperature_unit' => $atts['temperature_unit'],
                'selectable' => $a['archiveSelectable'] ? 'true' : 'false'
            ));
        } else {
            $data = $this->get_weather_data_cached($atts, min(1440, absint($a['cacheMinutes'])));
            $content = $data === false
                ? '<div class="rmd-wq-error">' . esc_html__('Fehler beim Laden der Wetterdaten', 'rmd-weatherquery') . '</div>'
                : $this->format_weather_data($data, $atts);
        }

        $title = '';
        if (trim($a['title']) !== '') {
            $level = min(6, max(2, absint($a['titleLevel'])));
            $title = '<h' . $level . ' class="rmd-wq-title">' . esc_html($a['title']) . '</h' . $level . '>';
        }

        $classes = array();
        if ($a['hideLabels']) {
            $classes[] = 'rmd-wq-hide-labels';
        }
        if ($a['plainCards']) {
            $classes[] = 'rmd-wq-cards-plain';
        }
        $wrapper_args = array('class' => implode(' ', $classes));
        $card_bg = sanitize_hex_color($a['cardBackground']);
        if ($card_bg) {
            $wrapper_args['style'] = '--rmd-wq-card-bg: ' . $card_bg . ';';
        }

        return '<div ' . get_block_wrapper_attributes($wrapper_args) . '>' . $title . $content . '</div>';
    }

    private function format_weather_data($data, $atts) {
        if (!$data || !isset($data['sensors'])) {
            return '<div class="rmd-wq-error">' . __('Keine Daten verfügbar', 'rmd-weatherquery') . '</div>';
        }

        // Handle show_all_fields option
        if (strtolower($atts['show_all_fields']) === 'true') {
            $fields = array(); // Will be populated from actual data
        } else {
            $fields = explode(',', $atts['fields']);
            $fields = array_map('trim', $fields);
        }

        $weather_data = array();
        list($all_readings, $timestamp) = $this->collect_readings($data);

        // If show_all_fields is true, get weather-related fields from the data
        if (strtolower($atts['show_all_fields']) === 'true') {
            $available_fields = array_keys($all_readings);
            $weather_fields = $this->get_weather_related_fields();
            // Only include fields that are both available and weather-related
            $fields = array_intersect($available_fields, $weather_fields);
        }

        // Debug: Add available fields info if debug mode is enabled
        $debug_info = '';
        if ($this->debug_mode_enabled()) {
            $debug_info = '<!-- Available fields: ' . implode(', ', array_keys($all_readings)) . ' -->';
            $debug_info .= '<!-- Requested fields: ' . implode(', ', $fields) . ' -->';
            $debug_info .= '<!-- Raw sensor data: ' . print_r($data['sensors'], true) . ' -->';
        }


        // Now collect the requested field data
        foreach ($fields as $field) {
            // Map common field names to actual API field names
            $actual_field = $this->map_field_name($field);

            if (isset($all_readings[$actual_field])) {
                $label = !empty($atts['labels'][$field]) ? $atts['labels'][$field] : $this->get_field_label($field);
                $value = $this->format_field_value($field, $all_readings[$actual_field], $atts['temperature_unit']);
                $icon = $this->get_field_icon($field);
                $item = array(
                    'field' => $field,
                    'label' => $label,
                    'value' => $value,
                    'icon' => $icon
                );
                if ($this->is_wind_direction_field($field) && is_numeric($all_readings[$actual_field])) {
                    $item['degrees'] = (float) $all_readings[$actual_field];
                }
                $weather_data[] = $item;
            }
        }

        // Build data attributes for the container
        $data_attributes = '';
        $data_attributes .= ' data-station-id="' . esc_attr($atts['station_id']) . '"';
        $data_attributes .= ' data-mode="' . esc_attr($atts['mode']) . '"';
        $data_attributes .= ' data-fields="' . esc_attr($atts['fields']) . '"';
        $data_attributes .= ' data-display-mode="' . esc_attr($atts['display_mode']) . '"';
        if ($atts['display_mode'] === 'widget' && !empty($atts['columns'])) {
            $data_attributes .= ' style="grid-template-columns: repeat(' . absint($atts['columns']) . ', minmax(0, 1fr));"';
        }

        // Format output based on display mode
        $output = '';
        switch ($atts['display_mode']) {
            case 'table':
                $output = $this->format_table_display($weather_data, $data_attributes, $atts, $timestamp);
                break;
            case 'widget':
                $output = $this->format_widget_display($weather_data, $data_attributes, $atts, $timestamp);
                break;
            default: // list
                $output = $this->format_list_display($weather_data, $data_attributes, $atts, $timestamp);
        }

        return $debug_info . $output;
    }
    
    private function get_field_label($field) {
        $labels = array(
            // Temperature fields
            'temp' => __('Temperatur', 'rmd-weatherquery'),
            'temp_hi' => __('Höchsttemperatur', 'rmd-weatherquery'),
            'temp_lo' => __('Tiefsttemperatur', 'rmd-weatherquery'),
            'temp_avg' => __('Durchschnittstemperatur', 'rmd-weatherquery'),
            'temp_min' => __('Mindesttemperatur', 'rmd-weatherquery'),
            'temp_max' => __('Maximaltemperatur', 'rmd-weatherquery'),
            'heat_index' => __('Hitzeindex', 'rmd-weatherquery'),
            'heat_index_hi' => __('Höchster Hitzeindex', 'rmd-weatherquery'),
            'heat_index_lo' => __('Niedrigster Hitzeindex', 'rmd-weatherquery'),
            'wind_chill' => __('Windkühle', 'rmd-weatherquery'),
            'wind_chill_hi' => __('Höchste Windkühle', 'rmd-weatherquery'),
            'wind_chill_lo' => __('Niedrigste Windkühle', 'rmd-weatherquery'),
            'dew_point' => __('Taupunkt', 'rmd-weatherquery'),
            'dew_point_hi' => __('Höchster Taupunkt', 'rmd-weatherquery'),
            'dew_point_lo' => __('Niedrigster Taupunkt', 'rmd-weatherquery'),
            'wet_bulb' => __('Feuchttemperatur', 'rmd-weatherquery'),
            'wet_bulb_hi' => __('Höchste Feuchttemperatur', 'rmd-weatherquery'),
            'wet_bulb_lo' => __('Niedrigste Feuchttemperatur', 'rmd-weatherquery'),

            // Humidity fields
            'humidity' => __('Luftfeuchtigkeit', 'rmd-weatherquery'),
            'hum' => __('Luftfeuchtigkeit', 'rmd-weatherquery'),
            'hum_hi' => __('Höchste Luftfeuchtigkeit', 'rmd-weatherquery'),
            'hum_lo' => __('Niedrigste Luftfeuchtigkeit', 'rmd-weatherquery'),
            'hum_avg' => __('Durchschnittliche Luftfeuchtigkeit', 'rmd-weatherquery'),

            // Pressure fields
            'pressure' => __('Luftdruck', 'rmd-weatherquery'),
            'bar' => __('Luftdruck', 'rmd-weatherquery'),
            'bar_hi' => __('Höchster Luftdruck', 'rmd-weatherquery'),
            'bar_lo' => __('Niedrigster Luftdruck', 'rmd-weatherquery'),
            'bar_avg' => __('Durchschnittlicher Luftdruck', 'rmd-weatherquery'),
            'bar_sea_level' => __('Luftdruck auf Meereshöhe', 'rmd-weatherquery'),
            'bar_absolute' => __('Absoluter Luftdruck', 'rmd-weatherquery'),

            // Wind fields
            'wind_speed' => __('Windgeschwindigkeit', 'rmd-weatherquery'),
            'wind_speed_hi' => __('Höchste Windgeschwindigkeit', 'rmd-weatherquery'),
            'wind_speed_lo' => __('Niedrigste Windgeschwindigkeit', 'rmd-weatherquery'),
            'wind_speed_avg' => __('Durchschnittliche Windgeschwindigkeit', 'rmd-weatherquery'),
            'wind_dir' => __('Windrichtung', 'rmd-weatherquery'),
            'wind_dir_at_hi_speed' => __('Windrichtung bei Höchstgeschwindigkeit', 'rmd-weatherquery'),
            'wind_dir_of_prevail' => __('Vorherrschende Windrichtung', 'rmd-weatherquery'),
            'wind_gust' => __('Windböe', 'rmd-weatherquery'),
            'wind_gust_hi' => __('Stärkste Windböe', 'rmd-weatherquery'),
            'wind_gust_dir' => __('Windböen-Richtung', 'rmd-weatherquery'),

            // Precipitation fields
            'rainfall' => __('Niederschlag', 'rmd-weatherquery'),
            'rain_rate' => __('Regenrate', 'rmd-weatherquery'),
            'rain_rate_hi' => __('Höchste Regenrate', 'rmd-weatherquery'),
            'rain_storm' => __('Sturmniederschlag', 'rmd-weatherquery'),
            'rain_storm_start_at' => __('Sturm begann um', 'rmd-weatherquery'),
            'rain_15_min' => __('Regen 15 Min', 'rmd-weatherquery'),
            'rain_60_min' => __('Regen 60 Min', 'rmd-weatherquery'),
            'rain_24_hr' => __('Regen 24 Std', 'rmd-weatherquery'),
            'rain_month' => __('Monatlicher Regen', 'rmd-weatherquery'),
            'rain_year' => __('Jährlicher Regen', 'rmd-weatherquery'),

            // Solar/UV fields
            'solar_rad' => __('Sonnenstrahlung', 'rmd-weatherquery'),
            'solar_rad_hi' => __('Höchste Sonnenstrahlung', 'rmd-weatherquery'),
            'uv_index' => __('UV-Index', 'rmd-weatherquery'),
            'uv_index_hi' => __('Höchster UV-Index', 'rmd-weatherquery'),

            // Other weather fields
            'et' => __('Evapotranspiration', 'rmd-weatherquery'),
            'reception' => __('Empfangsqualität', 'rmd-weatherquery'),
            'forecast_icon' => __('Wettervorhersage-Symbol', 'rmd-weatherquery'),
            'forecast_rule' => __('Wettervorhersage-Regel', 'rmd-weatherquery')
        );

        return isset($labels[$field]) ? $labels[$field] : ucfirst(str_replace('_', ' ', $field));
    }
    
    private function format_field_value($field, $value, $temperature_unit = 'celsius') {
        // Handle all temperature fields
        if ($this->is_temperature_field($field)) {
            if (strtolower($temperature_unit) === 'celsius') {
                $celsius = ($value - 32) * 5/9;
                return $this->format_german_number($celsius, 1) . ' °C';
            } else {
                return $this->format_german_number($value, 1) . ' °F';
            }
        }

        // Handle pressure fields with conversion
        if ($this->is_pressure_field($field)) {
            // Convert inches Hg to hPa/mbar
            // 1 inHg = 33.8639 hPa
            $hpa = $value * 33.8639;
            return $this->format_german_number($hpa, 1) . ' hPa';
        }

        // Handle other weather fields
        switch ($field) {
            // Humidity fields
            case 'humidity':
            case 'hum':
            case 'hum_hi':
            case 'hum_lo':
            case 'hum_avg':
                return $this->format_german_number($value, 0) . ' %';

            // Wind speed fields - convert from mph to km/h
            case 'wind_speed':
            case 'wind_speed_hi':
            case 'wind_speed_lo':
            case 'wind_speed_avg':
            case 'wind_gust':
            case 'wind_gust_hi':
                // Convert mph to km/h (1 mph = 1.609344 km/h)
                $kmh = $value * 1.609344;
                return $this->format_german_number($kmh, 1) . ' km/h';

            // Wind direction fields - convert degrees to compass direction
            case 'wind_dir':
            case 'wind_dir_at_hi_speed':
            case 'wind_dir_of_prevail':
            case 'wind_gust_dir':
                $compass = $this->degrees_to_compass($value);
                return $compass . ' (' . $this->format_german_number($value, 0) . '°)';

            // Precipitation fields - convert from inches to mm
            case 'rainfall':
            case 'rain_rate':
            case 'rain_rate_hi':
            case 'rain_storm':
            case 'rain_15_min':
            case 'rain_60_min':
            case 'rain_24_hr':
            case 'rain_month':
            case 'rain_year':
                // Convert inches to millimeters (1 inch = 25.4 mm)
                $mm = $value * 25.4;
                return $this->format_german_number($mm, 1) . ' mm';

            // Solar/UV fields
            case 'solar_rad':
            case 'solar_rad_hi':
                return $this->format_german_number($value, 0) . ' W/m²';
            case 'uv_index':
            case 'uv_index_hi':
                return $this->format_german_number($value, 1);

            // Other fields
            case 'et':
                // Convert evapotranspiration from inches to mm
                $mm = $value * 25.4;
                return $this->format_german_number($mm, 1) . ' mm';
            case 'reception':
                return $this->format_german_number($value, 0) . ' %';

            default:
                return $value;
        }
    }

    private function get_weather_related_fields() {
        return array(
            // Temperature fields (all in Fahrenheit, need conversion)
            'temp', 'temp_hi', 'temp_lo', 'temp_avg', 'temp_min', 'temp_max',
            'heat_index', 'heat_index_hi', 'heat_index_lo',
            'wind_chill', 'wind_chill_hi', 'wind_chill_lo',
            'dew_point', 'dew_point_hi', 'dew_point_lo',
            'wet_bulb', 'wet_bulb_hi', 'wet_bulb_lo',

            // Humidity fields
            'hum', 'humidity', 'hum_hi', 'hum_lo', 'hum_avg',

            // Pressure fields
            'bar', 'pressure', 'bar_hi', 'bar_lo', 'bar_avg',
            'bar_sea_level', 'bar_absolute',

            // Wind fields
            'wind_speed', 'wind_speed_hi', 'wind_speed_lo', 'wind_speed_avg',
            'wind_dir', 'wind_dir_at_hi_speed', 'wind_dir_of_prevail',
            'wind_gust', 'wind_gust_hi', 'wind_gust_dir',

            // Precipitation fields
            'rainfall', 'rain_rate', 'rain_rate_hi', 'rain_storm', 'rain_storm_start_at',
            'rain_15_min', 'rain_60_min', 'rain_24_hr', 'rain_month', 'rain_year',

            // Solar/UV fields
            'solar_rad', 'solar_rad_hi', 'uv_index', 'uv_index_hi',

            // Other weather fields
            'et', 'reception', 'forecast_icon', 'forecast_rule'
        );
    }

    private function is_temperature_field($field) {
        $temp_fields = array(
            'temp', 'temp_hi', 'temp_lo', 'temp_avg', 'temp_min', 'temp_max',
            'heat_index', 'heat_index_hi', 'heat_index_lo',
            'wind_chill', 'wind_chill_hi', 'wind_chill_lo',
            'dew_point', 'dew_point_hi', 'dew_point_lo',
            'wet_bulb', 'wet_bulb_hi', 'wet_bulb_lo'
        );
        return in_array($field, $temp_fields);
    }

    private function is_pressure_field($field) {
        $pressure_fields = array(
            'pressure', 'bar', 'bar_hi', 'bar_lo', 'bar_avg',
            'bar_sea_level', 'bar_absolute'
        );
        return in_array($field, $pressure_fields);
    }

    private function is_wind_direction_field($field) {
        return in_array($field, array('wind_dir', 'wind_dir_at_hi_speed', 'wind_dir_of_prevail', 'wind_gust_dir'), true);
    }

    /**
     * Wind direction fields get an arrow pointing where the wind blows to:
     * the reported degrees are where it comes from, so the up-arrow is turned by degrees + 180.
     */
    private function render_icon($item, $atts) {
        if (strtolower($atts['show_icons']) !== 'true') {
            return '';
        }

        $classes = 'wi ' . $item['icon'];
        $styles = array();

        if (!empty($atts['icon_color']) && $atts['icon_color'] !== '#62C626') {
            $styles[] = 'color: ' . $atts['icon_color'];
        }

        if (isset($item['degrees'])) {
            $classes = 'wi wi-direction-up rmd-wq-wind-arrow';
            $rotation = (int) round(fmod($item['degrees'] + 180, 360));
            $styles[] = 'transform: rotate(' . $rotation . 'deg)';
        }

        $style_attr = $styles ? ' style="' . esc_attr(implode('; ', $styles) . ';') . '"' : '';

        return '<i class="' . esc_attr($classes) . '"' . $style_attr . '></i>';
    }

    private function format_german_number($number, $decimals = 1) {
        // Format number with German locale (comma as decimal separator)
        return str_replace('.', ',', number_format($number, $decimals, '.', ''));
    }

    private function degrees_to_compass($degrees) {
        // Convert wind direction degrees to German compass directions
        $directions = array(
            'N', 'NNO', 'NO', 'ONO',
            'O', 'OSO', 'SO', 'SSO',
            'S', 'SSW', 'SW', 'WSW',
            'W', 'WNW', 'NW', 'NNW'
        );

        // Normalize degrees to 0-360 range
        $degrees = fmod($degrees + 360, 360);

        // Each direction covers 22.5 degrees (360/16)
        // Add 11.25 to shift the ranges so North is centered on 0
        $index = round(($degrees + 11.25) / 22.5) % 16;

        return $directions[$index];
    }

    private function format_timestamp($timestamp) {
        if (!$timestamp) {
            return '';
        }

        // Convert timestamp to German date/time format
        $dt = new DateTime('@' . $timestamp);
        $dt->setTimezone(new DateTimeZone('Europe/Berlin'));
        return $dt->format('d.m.Y H:i');
    }

    private function get_field_icon($field) {
        // Get the German label for the field
        $label = $this->get_field_label($field);

        // Check if label contains "Temperatur" - use thermometer icon
        if (strpos($label, 'Temperatur') !== false) {
            return 'wi-thermometer';
        }

        // Check if label contains "Luftdruck" - use barometer icon
        if (strpos($label, 'Luftdruck') !== false) {
            return 'wi-barometer';
        }

        // Field-specific icons based on field names
        $icons = array(
            // Temperature fields
            'temp' => 'wi-thermometer',
            'temp_hi' => 'wi-thermometer',
            'temp_lo' => 'wi-thermometer',
            'temp_avg' => 'wi-thermometer',
            'temp_min' => 'wi-thermometer',
            'temp_max' => 'wi-thermometer',
            'heat_index' => 'wi-hot',
            'heat_index_hi' => 'wi-hot',
            'heat_index_lo' => 'wi-hot',
            'wind_chill' => 'wi-snowflake-cold',
            'wind_chill_hi' => 'wi-snowflake-cold',
            'wind_chill_lo' => 'wi-snowflake-cold',
            'dew_point' => 'wi-raindrop',
            'dew_point_hi' => 'wi-raindrop',
            'dew_point_lo' => 'wi-raindrop',
            'wet_bulb' => 'wi-thermometer',
            'wet_bulb_hi' => 'wi-thermometer',
            'wet_bulb_lo' => 'wi-thermometer',

            // Humidity fields
            'humidity' => 'wi-humidity',
            'hum' => 'wi-humidity',
            'hum_hi' => 'wi-humidity',
            'hum_lo' => 'wi-humidity',
            'hum_avg' => 'wi-humidity',

            // Wind fields
            'wind_speed' => 'wi-strong-wind',
            'wind_speed_hi' => 'wi-strong-wind',
            'wind_speed_lo' => 'wi-strong-wind',
            'wind_speed_avg' => 'wi-strong-wind',
            'wind_dir' => 'wi-wind-direction',
            'wind_dir_at_hi_speed' => 'wi-wind-direction',
            'wind_dir_of_prevail' => 'wi-wind-direction',
            'wind_gust' => 'wi-cloudy-gusts',
            'wind_gust_hi' => 'wi-cloudy-gusts',
            'wind_gust_dir' => 'wi-wind-direction',

            // Precipitation fields
            'rainfall' => 'wi-rain',
            'rain_rate' => 'wi-raindrops',
            'rain_rate_hi' => 'wi-raindrops',
            'rain_storm' => 'wi-thunderstorm',
            'rain_15_min' => 'wi-rain',
            'rain_60_min' => 'wi-rain',
            'rain_24_hr' => 'wi-rain',
            'rain_month' => 'wi-rain',
            'rain_year' => 'wi-rain',

            // Pressure fields
            'pressure' => 'wi-barometer',
            'bar' => 'wi-barometer',
            'bar_hi' => 'wi-barometer',
            'bar_lo' => 'wi-barometer',
            'bar_avg' => 'wi-barometer',
            'bar_sea_level' => 'wi-barometer',
            'bar_absolute' => 'wi-barometer',

            // Solar/UV fields
            'solar_rad' => 'wi-day-sunny',
            'solar_rad_hi' => 'wi-day-sunny',
            'uv_index' => 'wi-hot',
            'uv_index_hi' => 'wi-hot',

            // Other fields
            'et' => 'wi-sprinkle',
            'reception' => 'wi-wifi',
            'forecast_icon' => 'wi-forecast-io',
            'forecast_rule' => 'wi-forecast-io'
        );

        return isset($icons[$field]) ? $icons[$field] : 'wi-na';
    }

    private function format_list_display($weather_data, $data_attributes, $atts, $timestamp = null) {
        $output = '<div class="rmd-wq-data rmd-wq-list"' . $data_attributes . '>';
        $output .= '<ul>';

        foreach ($weather_data as $item) {
            $icon_html = $this->render_icon($item, $atts);
            if ($icon_html !== '') {
                $icon_html .= ' ';
            }
            $output .= '<li>' . $icon_html . esc_html($item['label']) . ': ' . esc_html($item['value']) . '</li>';
        }

        $output .= '</ul>';

        // Add timestamp if enabled
        if (strtolower($atts['show_timestamp']) === 'true' && $timestamp) {
            $formatted_timestamp = $this->format_timestamp($timestamp);
            if ($formatted_timestamp) {
                $output .= '<div class="rmd-wq-timestamp">' . __('Letzte Aktualisierung', 'rmd-weatherquery') . ': ' . esc_html($formatted_timestamp) . '</div>';
            }
        }

        $output .= '</div>';
        return $output;
    }

    private function format_table_display($weather_data, $data_attributes, $atts, $timestamp = null) {
        $output = '<div class="rmd-wq-data rmd-wq-table"' . $data_attributes . '>';
        $output .= '<table class="rmd-wq-weather-table">';

        $output .= '<thead><tr><th>' . __('Messwert', 'rmd-weatherquery') . '</th><th>' . __('Wert', 'rmd-weatherquery') . '</th></tr></thead>';
        $output .= '<tbody>';

        foreach ($weather_data as $item) {
            $output .= '<tr>';

            // Messwert-Spalte mit optionalem Icon
            $icon_html = $this->render_icon($item, $atts);
            $label_content = ($icon_html !== '' ? $icon_html . ' ' : '') . esc_html($item['label']);

            $output .= '<td>' . $label_content . '</td>';
            $output .= '<td>' . esc_html($item['value']) . '</td>';
            $output .= '</tr>';
        }

        $output .= '</tbody>';
        $output .= '</table>';

        // Add timestamp if enabled
        if (strtolower($atts['show_timestamp']) === 'true' && $timestamp) {
            $formatted_timestamp = $this->format_timestamp($timestamp);
            if ($formatted_timestamp) {
                $output .= '<div class="rmd-wq-timestamp">' . __('Letzte Aktualisierung', 'rmd-weatherquery') . ': ' . esc_html($formatted_timestamp) . '</div>';
            }
        }

        $output .= '</div>';
        return $output;
    }

    private function map_field_name($field) {
        // Map user-friendly field names to actual API field names
        $field_map = array(
            'humidity' => 'hum',
            'temperature' => 'temp',
            'pressure' => 'bar_sea_level', // Use sea level pressure as default
            'wind_speed' => 'wind_speed_last', // Use last wind speed reading
            'wind_dir' => 'wind_dir_last', // Use last wind direction
            'rainfall' => 'rainfall_daily_in', // Use daily rainfall in inches
            'rain_rate' => 'rain_rate_last_in', // Use last rain rate
            'solar_radiation' => 'solar_rad',
            'uv' => 'uv_index',
        );

        return isset($field_map[$field]) ? $field_map[$field] : $field;
    }

    private function format_widget_display($weather_data, $data_attributes, $atts, $timestamp = null) {
        $output = '<div class="rmd-wq-data rmd-wq-widget"' . $data_attributes . '>';

        foreach ($weather_data as $item) {
            $output .= '<div class="rmd-wq-widget-item">';

            $icon_html = $this->render_icon($item, $atts);
            if ($icon_html !== '') {
                $output .= '<div class="rmd-wq-widget-icon">' . $icon_html . '</div>';
            }

            $output .= '<div class="rmd-wq-widget-content">';
            $output .= '<div class="rmd-wq-widget-label">' . esc_html($item['label']) . '</div>';
            $output .= '<div class="rmd-wq-widget-value">' . esc_html($item['value']) . '</div>';
            $output .= '</div>';
            $output .= '</div>';
        }

        // Add timestamp if enabled
        if (strtolower($atts['show_timestamp']) === 'true' && $timestamp) {
            $formatted_timestamp = $this->format_timestamp($timestamp);
            if ($formatted_timestamp) {
                $output .= '<div class="rmd-wq-timestamp rmd-wq-widget-timestamp">' . __('Letzte Aktualisierung', 'rmd-weatherquery') . ': ' . esc_html($formatted_timestamp) . '</div>';
            }
        }

        $output .= '</div>';
        return $output;
    }
}

// Initialize the plugin
new RMD_WeatherQuery();

// Create assets directory structure on activation
register_activation_hook(__FILE__, 'rmd_wq_create_assets');

function rmd_wq_create_assets() {
    $assets_dir = RMD_WQ_PLUGIN_DIR . 'assets/';
    
    if (!file_exists($assets_dir)) {
        wp_mkdir_p($assets_dir);
    }
}
?>