<?php
/**
 * Plugin Name: RMD WeatherQuery
 * Plugin URI: https://github.com/reicheltmediadesign/wordpress-rmd-weatherquery
 * Description: Ein professionelles WordPress Plugin zur Anzeige von Wetterdaten über die WeatherLink v2 API. Unterstützt multiple Darstellungsmodi (Liste, Tabelle, Widget), Wetter-Icons, deutsche Lokalisierung, Temperatur-/Druckumrechnung und intelligente Caching-Funktionen. Entwickelt unter Mitwirkung von Anthropic Claude.
 * Version: 1.2.0
 * Requires at least: 6.0
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
define('RMD_WQ_VERSION', '1.2.0');
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
    
    private $options;
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_shortcode('wq', array($this, 'weather_shortcode'));

        // Add AJAX handlers
        add_action('wp_ajax_rmd_wq_get_stations', array($this, 'ajax_get_stations'));
        add_action('wp_ajax_rmd_wq_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_rmd_wq_refresh_cache', array($this, 'ajax_refresh_cache'));
        add_action('wp_ajax_rmd_wq_clear_cache', array($this, 'ajax_clear_cache'));

        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        $this->options = get_option('rmd_weatherquery_options');
    }
    
    public function init() {
        load_plugin_textdomain('rmd-weatherquery', false, dirname(plugin_basename(__FILE__)) . '/languages');
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
        // Cleanup if needed
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
            __('Konfiguration', 'rmd-weatherquery'),
            __('Konfiguration', 'rmd-weatherquery'),
            'manage_options',
            'rmd-weatherquery-config',
            array($this, 'config_page')
        );
    }
    
    public function admin_init() {
        register_setting('rmd_weatherquery_group', 'rmd_weatherquery_options');
        
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
    
    public function enqueue_scripts() {
        // Enqueue weather icons CSS from CDN
        wp_enqueue_style('weather-icons', 'https://cdnjs.cloudflare.com/ajax/libs/weather-icons/2.0.12/css/weather-icons.min.css', array(), '2.0.12');

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

        // Create product number to model mapping
        $product_models = array();
        if (is_array($sensors)) {
            foreach ($sensors as $sensor) {
                if (isset($sensor['product_number']) && isset($sensor['manufacturer']) && isset($sensor['product_name'])) {
                    $product_models[$sensor['product_number']] = array(
                        'manufacturer' => $sensor['manufacturer'],
                        'product_name' => $sensor['product_name']
                    );
                }
            }
        }

        // Add model info to stations
        foreach ($stations as &$station) {
            if (isset($station['product_number']) && isset($product_models[$station['product_number']])) {
                $station['device_model'] = $product_models[$station['product_number']]['manufacturer'] . ' ' .
                                          $product_models[$station['product_number']]['product_name'];
            } else {
                $station['device_model'] = __('Unbekanntes Modell', 'rmd-weatherquery');
            }
        }

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
        
        return $data;
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

        // Collect all data first
        $weather_data = array();
        $timestamp = null;

        // Collect all available data first from all sensors
        $all_readings = array();
        foreach ($data['sensors'] as $sensor) {
            if (!isset($sensor['data']) || !is_array($sensor['data'])) {
                continue;
            }

            foreach ($sensor['data'] as $reading) {
                // Extract timestamp from first reading
                if ($timestamp === null && isset($reading['ts'])) {
                    $timestamp = $reading['ts'];
                }

                // Merge all readings from different sensors - preserve all fields
                foreach ($reading as $field_key => $field_value) {
                    // Only add field if not already present (first occurrence wins)
                    if (!array_key_exists($field_key, $all_readings)) {
                        $all_readings[$field_key] = $field_value;
                    }
                }
                break; // Only first reading per sensor for current mode
            }
        }

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
                $label = $this->get_field_label($field); // Use original field name for label
                $value = $this->format_field_value($field, $all_readings[$actual_field], $atts['temperature_unit']);
                $icon = $this->get_field_icon($field);
                $weather_data[] = array(
                    'field' => $field,
                    'label' => $label,
                    'value' => $value,
                    'icon' => $icon
                );
            }
        }

        // Build data attributes for the container
        $data_attributes = '';
        $data_attributes .= ' data-station-id="' . esc_attr($atts['station_id']) . '"';
        $data_attributes .= ' data-mode="' . esc_attr($atts['mode']) . '"';
        $data_attributes .= ' data-fields="' . esc_attr($atts['fields']) . '"';
        $data_attributes .= ' data-display-mode="' . esc_attr($atts['display_mode']) . '"';

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
            $icon_html = '';
            if (strtolower($atts['show_icons']) === 'true') {
                $icon_style = '';
                if (!empty($atts['icon_color']) && $atts['icon_color'] !== '#62C626') {
                    $icon_style = ' style="color: ' . esc_attr($atts['icon_color']) . ';"';
                }
                $icon_html = '<i class="wi ' . esc_attr($item['icon']) . '"' . $icon_style . '></i> ';
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
            $label_content = '';
            if (strtolower($atts['show_icons']) === 'true') {
                $icon_style = '';
                if (!empty($atts['icon_color']) && $atts['icon_color'] !== '#62C626') {
                    $icon_style = ' style="color: ' . esc_attr($atts['icon_color']) . ';"';
                }
                $label_content = '<i class="wi ' . esc_attr($item['icon']) . '"' . $icon_style . '></i> ' . esc_html($item['label']);
            } else {
                $label_content = esc_html($item['label']);
            }

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

            if (strtolower($atts['show_icons']) === 'true') {
                $icon_style = '';
                if (!empty($atts['icon_color']) && $atts['icon_color'] !== '#62C626') {
                    $icon_style = ' style="color: ' . esc_attr($atts['icon_color']) . ';"';
                }
                $output .= '<div class="rmd-wq-widget-icon"><i class="wi ' . esc_attr($item['icon']) . '"' . $icon_style . '></i></div>';
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