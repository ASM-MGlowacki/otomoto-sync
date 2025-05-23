<?php

/**
 * Plugin Name: CMU24 Otomoto Integration
 * Description: Integracja z API Otomoto do synchronizacji ogłoszeń maszyn rolniczych.
 * Version: 0.1.0
 * Author: CMU24 Team
 * Author URI: https://cmu.pl/
 * License: GPLv2 or later
 * Text Domain: cmu-otomoto-integration
 * Domain Path: /languages
 */

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CMU_OTOMOTO_INTEGRATION_VERSION', '0.1.0');
define('CMU_OTOMOTO_INTEGRATION_PATH', plugin_dir_path(__FILE__));
define('CMU_OTOMOTO_INTEGRATION_URL', plugin_dir_url(__FILE__));
define('CMU_OTOMOTO_LOGS_DIR', CMU_OTOMOTO_INTEGRATION_PATH . 'logs');

// Create logs directory if it doesn't exist
if (! file_exists(CMU_OTOMOTO_LOGS_DIR)) {
    wp_mkdir_p(CMU_OTOMOTO_LOGS_DIR);
}

// Include utilities and class files
require_once CMU_OTOMOTO_INTEGRATION_PATH . 'includes/cmu-utilities.php';
require_once CMU_OTOMOTO_INTEGRATION_PATH . 'includes/class-cmu-otomoto-api-client.php';
require_once CMU_OTOMOTO_INTEGRATION_PATH . 'includes/class-cmu-otomoto-post-type.php';
require_once CMU_OTOMOTO_INTEGRATION_PATH . 'includes/class-cmu-otomoto-sync-manager.php';
require_once CMU_OTOMOTO_INTEGRATION_PATH . 'includes/class-cmu-otomoto-cron.php'; // Dodano require dla CRON
require_once CMU_OTOMOTO_INTEGRATION_PATH . 'includes/class-cmu-otomoto-admin.php'; // Dodano require dla Admin

if (defined('WP_CLI') && WP_CLI) {
    $cli_fetch_adverts_path = CMU_OTOMOTO_INTEGRATION_PATH . 'includes/class-cmu-otomoto-cli-fetch-adverts.php';
    if (file_exists($cli_fetch_adverts_path)) {
        require_once $cli_fetch_adverts_path;
        // Rejestrujemy polecenie PO załadowaniu pliku z klasą
        if (class_exists('CMU_Otomoto_Fetch_Adverts_Command')) {
            WP_CLI::add_command('cmuotomoto fetch_adverts', 'CMU_Otomoto_Fetch_Adverts_Command');
        } else {
            if (function_exists('cmu_otomoto_log')) { // Sprawdź czy funkcja logowania jest dostępna
                cmu_otomoto_log('WP-CLI: CMU_Otomoto_Fetch_Adverts_Command class not found after requiring file.', 'ERROR');
            }
            WP_CLI::warning('WP-CLI: CMU_Otomoto_Fetch_Adverts_Command class not found after requiring file.');
        }
    } else {
        if (function_exists('cmu_otomoto_log')) {
            cmu_otomoto_log('WP-CLI: File class-cmu-otomoto-cli-fetch-adverts.php not found.', 'ERROR');
        }
        WP_CLI::warning('WP-CLI: File class-cmu-otomoto-cli-fetch-adverts.php not found.');
    }
}

/**
 * Modyfikuje slug dla określonych kategorii Otomoto.
 */
function cmu_custom_otomoto_category_slug($term_slug_candidate, $otomoto_category_id, $term_name, $cat_details)
{
    // Sprawdź, czy to kategoria "Ciągniki (traktory)" (Otomoto ID: 3)
    if ((string) $otomoto_category_id === '3') {
        return 'ciagniki-rolnicze';
    }
    return $term_slug_candidate;
}
add_filter('cmu_otomoto_category_slug', 'cmu_custom_otomoto_category_slug', 10, 4);


/**
 * Funkcja wykonywana przy aktywacji wtyczki.
 * Rejestruje CPT/Tax, tworzy termy i planuje zadania CRON.
 */
function cmu_otomoto_plugin_activate()
{
    // Upewnij się, że klasy są załadowane (powinny być już załadowane przez require_once powyżej)
    if (!class_exists('CMU_Otomoto_Post_Type')) {
        // Ten require jest tu na wszelki wypadek, ale nie powinien być potrzebny
        require_once CMU_OTOMOTO_INTEGRATION_PATH . 'includes/class-cmu-otomoto-post-type.php';
    }
    if (!class_exists('CMU_Otomoto_Cron')) {
        require_once CMU_OTOMOTO_INTEGRATION_PATH . 'includes/class-cmu-otomoto-cron.php';
    }

    // Inicjalizacja CMU_Otomoto_Post_Type dla operacji aktywacyjnych
    if (class_exists('CMU_Otomoto_Post_Type')) {
        $post_type_manager_for_activation = new CMU_Otomoto_Post_Type();

        // Bezpośrednio zarejestruj CPT i taksonomie, aby były dostępne
        $post_type_manager_for_activation->register_cpt_maszyna_rolnicza();
        $post_type_manager_for_activation->register_taxonomies();

        // Utwórz termy początkowe
        $post_type_manager_for_activation->create_initial_terms();
        cmu_otomoto_log('CPT, Taxonomies registered and initial terms created during activation.', 'INFO');
    } else {
        cmu_otomoto_log('CMU_Otomoto_Post_Type class not found during activation.', 'ERROR');
    }

    // Inicjalizacja CMU_Otomoto_Cron dla operacji aktywacyjnych
    if (class_exists('CMU_Otomoto_Cron')) {
        $cron_manager_for_activation = new CMU_Otomoto_Cron();
        $cron_manager_for_activation->activate(); // Metoda activate z CMU_Otomoto_Cron zajmie się planowaniem zadań
    } else {
        cmu_otomoto_log('CMU_Otomoto_Cron class not found during activation.', 'ERROR');
    }
}
register_activation_hook(__FILE__, 'cmu_otomoto_plugin_activate');

/**
 * Funkcja wykonywana przy deaktywacji wtyczki.
 */
function cmu_otomoto_plugin_deactivate()
{
    if (!class_exists('CMU_Otomoto_Cron')) {
        require_once CMU_OTOMOTO_INTEGRATION_PATH . 'includes/class-cmu-otomoto-cron.php';
    }
    if (class_exists('CMU_Otomoto_Cron')) {
        $cron_manager_for_deactivation = new CMU_Otomoto_Cron();
        $cron_manager_for_deactivation->deactivate();
    } else {
        cmu_otomoto_log('CMU_Otomoto_Cron class not found during deactivation.', 'ERROR');
    }
}
register_deactivation_hook(__FILE__, 'cmu_otomoto_plugin_deactivate');


// --- Inicjalizacja globalnych instancji klas (poza hookami aktywacji/deaktywacji) ---

// Instancja CMU_Otomoto_Post_Type (dla hooków 'init')
if (class_exists('CMU_Otomoto_Post_Type')) {
    // Ta instancja jest potrzebna, aby hooki 'init' w jej konstruktorze zadziałały
    $cmu_otomoto_post_type_instance = new CMU_Otomoto_Post_Type();
}

// Globalna instancja API client
$cmu_otomoto_api_client = null;
if (class_exists('CMU_Otomoto_Api_Client')) {
    if (defined('OTOMOTO_CLIENT_ID') && defined('OTOMOTO_CLIENT_SECRET') && defined('OTOMOTO_EMAIL') && defined('OTOMOTO_PASSWORD')) {
        $cmu_otomoto_api_client = new CMU_Otomoto_Api_Client();
    } else {
        add_action('admin_notices', function () {
            if (current_user_can('manage_options')) {
                echo '<div class="notice notice-error is-dismissible"><p>';
                echo '<strong>CMU Otomoto Integration:</strong> Brak kluczowych stałych API Otomoto (OTOMOTO_CLIENT_ID, OTOMOTO_CLIENT_SECRET, OTOMOTO_EMAIL, OTOMOTO_PASSWORD) w pliku wp-config.php. Plugin nie będzie mógł połączyć się z API.';
                echo '</p></div>';
            }
        });
        if (function_exists('cmu_otomoto_log')) {
            cmu_otomoto_log('CMU Otomoto API Client not instantiated due to missing wp-config.php constants.', 'ERROR');
        }
    }
}

// Globalna instancja Sync Manager
$cmu_otomoto_sync_manager = null;
if (class_exists('CMU_Otomoto_Sync_Manager') && $cmu_otomoto_api_client instanceof CMU_Otomoto_Api_Client) {
    $cmu_otomoto_sync_manager = new CMU_Otomoto_Sync_Manager($cmu_otomoto_api_client);
} elseif (class_exists('CMU_Otomoto_Sync_Manager')) {
    if (function_exists('cmu_otomoto_log')) {
        cmu_otomoto_log('CMU Otomoto Sync Manager not instantiated because API client is not available.', 'ERROR');
    }
}

// Globalna instancja CMU_Otomoto_Cron (dla podpinania hooków CRON, które nie są częścią aktywacji/deaktywacji)
// Metody activate/deactivate są już obsłużone przez register_activation/deactivation_hook
if (class_exists('CMU_Otomoto_Cron')) {
    // Sama instancja CMU_Otomoto_Cron w swoim konstruktorze podpina hooki dla MASTER_SYNC_HOOK i PROCESS_BATCH_HOOK
    $cmu_otomoto_cron_instance = new CMU_Otomoto_Cron();
}

// Globalna instancja CMU_Otomoto_Admin (dla interfejsu administracyjnego)
$cmu_otomoto_admin_instance = null;
if (class_exists('CMU_Otomoto_Admin')) {
    // Instancja CMU_Otomoto_Admin w swoim konstruktorze podpina wszystkie potrzebne hooki admin
    $cmu_otomoto_admin_instance = new CMU_Otomoto_Admin();
    cmu_otomoto_log('CMU_Otomoto_Admin instance created successfully', 'INFO');
} else {
    if (function_exists('cmu_otomoto_log')) {
        cmu_otomoto_log('CMU_Otomoto_Admin class not found', 'ERROR');
    }
}


/**
 * Sets a flag when a 'maszyna-rolnicza' post is saved through the WordPress admin editor.
 * This helps prevent the sync process from overwriting manual changes unless forced.
 *
 * @param int     $post_id The ID of the post being saved.
 * @param WP_Post $post    The post object.
 * @param bool    $update  Whether this is an existing post being updated or not.
 */
function cmu_otomoto_handle_manual_edit_flag($post_id, $post, $update)
{
    if (wp_is_post_revision($post_id)) {
        return;
    }
    if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || (defined('CMU_OTOMOTO_DOING_SYNC') && CMU_OTOMOTO_DOING_SYNC)) {
        return;
    }
    if (
        current_user_can('edit_post', $post_id) &&
        $post->post_type == 'maszyna-rolnicza' &&
        isset($_POST['_wpnonce']) &&
        wp_verify_nonce($_POST['_wpnonce'], 'update-post_' . $post_id)
    ) {
        update_post_meta($post_id, '_otomoto_is_edited_manually', true);
        cmu_otomoto_log('Post ID ' . $post_id . ' marked as manually edited due to admin save.', 'INFO');
    }
}
add_action('save_post_maszyna-rolnicza', 'cmu_otomoto_handle_manual_edit_flag', 10, 3);


/**
 * Simple test function to check API client functionality.
 * Hooks into admin_init so it runs in the admin area.
 *
 * IMPORTANT: For testing purposes only. Remove or secure properly for production.
 */
function cmu_otomoto_test_api_client()
{
    if (! current_user_can('manage_options')) {
        return;
    }

    if (isset($_GET['cmu_otomoto_test_api']) && $_GET['cmu_otomoto_test_api'] === '1') {
        cmu_otomoto_log('Starting API client test via admin_init hook.', 'INFO');

        if (!defined('CMU_OTOMOTO_DOING_SYNC')) {
            define('CMU_OTOMOTO_DOING_SYNC', true);
        }

        if (!defined('OTOMOTO_CLIENT_ID') || !defined('OTOMOTO_CLIENT_SECRET') || !defined('OTOMOTO_EMAIL') || !defined('OTOMOTO_PASSWORD')) {
            $message = 'One or more OTOMOTO API constants are not defined in wp-config.php.';
            cmu_otomoto_log($message, 'ERROR');
            wp_die(esc_html($message) . ' Please define them to test the API client.');
            return;
        }

        global $cmu_otomoto_api_client, $cmu_otomoto_sync_manager;

        if (! $cmu_otomoto_api_client) {
            $message = 'Global API client instance (CMU_Otomoto_Api_Client) is not available.';
            cmu_otomoto_log($message, 'ERROR');
            wp_die(esc_html($message));
            return;
        }

        if (! $cmu_otomoto_sync_manager) {
            $message = 'Global Sync Manager instance (CMU_Otomoto_Sync_Manager) is not available.';
            cmu_otomoto_log($message, 'ERROR');
            wp_die(esc_html($message));
            return;
        }

        // Test: Manual call to ensure CPT/Tax exist if activation hook didn't fire for some reason during dev
        // This is more for robustness in a dev environment.
        if (class_exists('CMU_Otomoto_Post_Type')) {
            $post_type_handler_for_terms = new CMU_Otomoto_Post_Type();
            $post_type_handler_for_terms->register_cpt_maszyna_rolnicza(); // Ensure CPT is registered
            $post_type_handler_for_terms->register_taxonomies();      // Ensure Taxonomies are registered
            $post_type_handler_for_terms->create_initial_terms();     // Create terms
            cmu_otomoto_log('Test: Manually ensured CPT/Tax/Terms for Stan Maszyny.', 'INFO');
        }


        $token = $cmu_otomoto_api_client->get_access_token();
        if ($token) {
            cmu_otomoto_log('Test: Successfully retrieved access token.', 'INFO', ['token_start' => substr($token, 0, 10) . '...']);
        } else {
            cmu_otomoto_log('Test: Failed to retrieve access token.', 'ERROR');
            wp_die('Test API: Nie udało się pobrać tokenu dostępu. Sprawdź logi wtyczki w katalogu /logs/.');
            return;
        }

        cmu_otomoto_log('Test: Attempting to run sync_adverts() from Sync Manager.', 'INFO');
        $force_sync = isset($_GET['force_sync']) && $_GET['force_sync'] === '1';
        $sync_results = $cmu_otomoto_sync_manager->sync_adverts($force_sync);

        cmu_otomoto_log('Test: sync_adverts() process completed.', 'INFO', ['sync_status' => $sync_results['status'], 'sync_message' => $sync_results['message']]);

        $output_message = "Test API zakończony!\n";
        $output_message .= "Parametr force_sync: " . ($force_sync ? 'true' : 'false') . "\n";
        $output_message .= "Status synchronizacji: " . esc_html($sync_results['status']) . "\n";
        $output_message .= "Komunikat: " . esc_html($sync_results['message']) . "\n\n";

        if (isset($sync_results['summary'])) {
            $output_message .= "Podsumowanie operacji:\n";
            foreach ($sync_results['summary'] as $key => $value) {
                $output_message .= esc_html(ucfirst(str_replace('_', ' ', $key))) . ": " . esc_html($value) . "\n";
            }
            $output_message .= "\n";
        }

        $output_message .= "Sprawdź logi wtyczki (`wp-content/plugins/cmu24-otomoto-integration/logs/otomoto_sync.log`) aby zobaczyć szczegóły synchronizacji.\n";
        // ... (reszta komunikatów weryfikacyjnych)

        if (!empty($sync_results['adverts_sample'])) {
            $output_message .= "\n--- Próbka surowych danych z API Otomoto (max 50 ogłoszeń) ---\n";
            $output_message .= "<pre style='background-color: #f5f5f5; border: 1px solid #ccc; padding: 10px; max-height: 500px; overflow-y: auto; text-align: left; white-space: pre-wrap; word-wrap: break-word;'>";
            $output_message .= esc_html(print_r($sync_results['adverts_sample'], true));
            $output_message .= "</pre>";
        } else {
            $output_message .= "\n--- Brak próbki danych z API Otomoto do wyświetlenia ---\n";
        }

        wp_die('<div style="font-family: monospace; white-space: pre-wrap;">' . nl2br($output_message) . '</div>');
    }
}
add_action('admin_init', 'cmu_otomoto_test_api_client');

if (defined('WP_CLI') && WP_CLI) {
    /**
     * Fetches and displays raw data for a specific Otomoto advert ID using the single advert endpoint.
     */
    class CMU_Otomoto_Debug_Advert_Command
    {
        /**
         * Fetches details for a single Otomoto advert by its ID.
         *
         * ## OPTIONS
         *
         * <advert_id>
         * : The Otomoto advert ID to fetch.
         *
         * ## EXAMPLES
         *
         *     wp cmuotomoto debug_advert 6121985074
         *
         * @when after_wp_load
         */
        public function __invoke($args, $assoc_args)
        {
            list($advert_id_to_find) = $args;

            WP_CLI::line("Attempting to fetch details for Otomoto advert ID: $advert_id_to_find using single advert endpoint.");

            global $cmu_otomoto_api_client;

            if (!$cmu_otomoto_api_client) {
                if (defined('OTOMOTO_CLIENT_ID') && defined('OTOMOTO_CLIENT_SECRET') && defined('OTOMOTO_EMAIL') && defined('OTOMOTO_PASSWORD')) {
                    // Ensure all necessary classes are loaded
                    if (!class_exists('CMU_Otomoto_Api_Client')) {
                        require_once CMU_OTOMOTO_INTEGRATION_PATH . 'includes/class-cmu-otomoto-api-client.php';
                    }
                    $cmu_otomoto_api_client = new CMU_Otomoto_Api_Client();
                    WP_CLI::line("Instantiated CMU_Otomoto_Api_Client locally for this command.");
                } else {
                    WP_CLI::error("CMU_Otomoto_Api_Client is not available and API constants are not defined. Cannot proceed.");
                    return;
                }
            }

            // Wywołaj nową metodę
            $advert_details = $cmu_otomoto_api_client->get_advert_details($advert_id_to_find);

            if (is_wp_error($advert_details)) {
                WP_CLI::error("Failed to fetch advert details: " . $advert_details->get_error_message());
                // Możesz chcieć wyświetlić dodatkowe dane błędu, jeśli są dostępne
                $error_data = $advert_details->get_error_data();
                if (is_array($error_data) && isset($error_data['response'])) {
                    WP_CLI::log("Raw API error response: " . $error_data['response']);
                }
                return;
            }

            if (empty($advert_details) || !isset($advert_details['id'])) {
                WP_CLI::warning("No details found for advert ID $advert_id_to_find, or response was empty/invalid.");
                return;
            }

            WP_CLI::success("Successfully fetched details for advert ID $advert_id_to_find!");
            WP_CLI::line("Raw data for advert ID $advert_id_to_find:");
            WP_CLI::log(print_r($advert_details, true));

            if (isset($advert_details['title'])) {
                WP_CLI::line("Title found in API data: '" . $advert_details['title'] . "'");
                if (empty(trim($advert_details['title']))) {
                    WP_CLI::warning("Title field exists but is empty or consists only of whitespace.");
                }
            } else {
                WP_CLI::warning("Title field is MISSING in the API data for this advert.");
            }
        }
    }
    WP_CLI::add_command('cmuotomoto debug_advert', 'CMU_Otomoto_Debug_Advert_Command');
}
