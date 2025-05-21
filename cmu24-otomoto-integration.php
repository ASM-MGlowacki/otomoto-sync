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

/**
 * Modyfikuje slug dla określonych kategorii Otomoto.
 */
function cmu_custom_otomoto_category_slug($term_slug_candidate, $otomoto_category_id, $term_name, $cat_details)
{
    // Sprawdź, czy to kategoria "Ciągniki (traktory)" (Otomoto ID: 3)
    if ((string) $otomoto_category_id === '3') {
        return 'ciagniki-rolnicze';
    }

    // Możesz dodać inne warunki dla innych kategorii
    // if ( (string) $otomoto_category_id === '99' && $term_slug_candidate === 'kombajny' ) {
    //     return 'kombajny-zbozowe';
    // }

    // Dla wszystkich innych kategorii, zwróć oryginalny slug
    return $term_slug_candidate;
}
add_filter('cmu_otomoto_category_slug', 'cmu_custom_otomoto_category_slug', 10, 4);

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

// Initialize plugin components
if (class_exists('CMU_Otomoto_Post_Type')) {
    $cmu_otomoto_post_type = new CMU_Otomoto_Post_Type();
    // Hook for creating initial terms on plugin activation
    register_activation_hook(__FILE__, [$cmu_otomoto_post_type, 'create_initial_terms']);
}

// Global instance of API client - can be refined with a service locator or DI container later
$cmu_otomoto_api_client = null;
if (class_exists('CMU_Otomoto_Api_Client')) {
    // Ensure constants are defined before instantiating
    if (defined('OTOMOTO_CLIENT_ID') && defined('OTOMOTO_CLIENT_SECRET') && defined('OTOMOTO_EMAIL') && defined('OTOMOTO_PASSWORD')) {
        $cmu_otomoto_api_client = new CMU_Otomoto_Api_Client();
    } else {
        // Log error if constants are missing, prevents fatal errors if class is called elsewhere without checks
        add_action('admin_notices', function () {
            if (current_user_can('manage_options')) {
                echo '<div class="notice notice-error is-dismissible"><p>';
                echo '<strong>CMU Otomoto Integration:</strong> Brak kluczowych stałych API Otomoto (OTOMOTO_CLIENT_ID, OTOMOTO_CLIENT_SECRET, OTOMOTO_EMAIL, OTOMOTO_PASSWORD) w pliku wp-config.php. Plugin nie będzie mógł połączyć się z API.';
                echo '</p></div>';
            }
        });
        // We can also log this to our file, but admin_notices is more visible for setup issues.
        if (function_exists('cmu_otomoto_log')) {
            cmu_otomoto_log('CMU Otomoto API Client not instantiated due to missing wp-config.php constants.', 'ERROR');
        }
    }
}

$cmu_otomoto_sync_manager = null;
if (class_exists('CMU_Otomoto_Sync_Manager') && $cmu_otomoto_api_client instanceof CMU_Otomoto_Api_Client) {
    $cmu_otomoto_sync_manager = new CMU_Otomoto_Sync_Manager($cmu_otomoto_api_client);
} elseif (class_exists('CMU_Otomoto_Sync_Manager')) {
    // Log error if API client wasn't instantiated
    if (function_exists('cmu_otomoto_log')) {
        cmu_otomoto_log('CMU Otomoto Sync Manager not instantiated because API client is not available.', 'ERROR');
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
function cmu_otomoto_handle_manual_edit_flag($post_id, $post, $update) {
    // If this is just a revision, don't do anything.
    if (wp_is_post_revision($post_id)) {
        return;
    }

    // If it's an autosave, or the action is coming from our cron/sync, don't mark as manually edited.
    if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || (defined('CMU_OTOMOTO_DOING_SYNC') && CMU_OTOMOTO_DOING_SYNC)) {
        return;
    }

    // Check if the current user has capabilities to edit the post and it's the correct post type.
    // Also check if the request seems to be a standard post edit screen submission.
    if (current_user_can('edit_post', $post_id) && 
        $post->post_type == 'maszyna-rolnicza' && 
        isset($_POST['_wpnonce']) && 
        wp_verify_nonce($_POST['_wpnonce'], 'update-post_' . $post_id)
    ) {
         update_post_meta($post_id, '_otomoto_is_edited_manually', true);
         cmu_otomoto_log('Post ID ' . $post_id . ' marked as manually edited due to admin save.', 'INFO');
    }
}
add_action('save_post_maszyna-rolnicza', 'cmu_otomoto_handle_manual_edit_flag', 10, 3);

// Further plugin initialization will go here

/**
 * Simple test function to check API client functionality.
 * Hooks into admin_init so it runs in the admin area.
 * 
 * IMPORTANT: For testing purposes only. Remove or secure properly for production.
 */
function cmu_otomoto_test_api_client()
{
    // Ensure this runs only for administrators and on a specific page or action if needed for safety
    if (! current_user_can('manage_options')) {
        return;
    }

    // Trigger this test only if a specific query parameter is set, e.g., ?cmu_otomoto_test_api=1
    // This is a basic way to control execution. For real testing, a dedicated admin page is better.
    if (isset($_GET['cmu_otomoto_test_api']) && $_GET['cmu_otomoto_test_api'] === '1') {
        cmu_otomoto_log('Starting API client test via admin_init hook.', 'INFO');

        // Define a constant to indicate sync is in progress for this test run
        if (!defined('CMU_OTOMOTO_DOING_SYNC')) {
            define('CMU_OTOMOTO_DOING_SYNC', true);
        }

        // Check if constants are defined (they should be in wp-config.php)
        if (!defined('OTOMOTO_CLIENT_ID') || !defined('OTOMOTO_CLIENT_SECRET') || !defined('OTOMOTO_EMAIL') || !defined('OTOMOTO_PASSWORD')) {
            $message = 'One or more OTOMOTO API constants (OTOMOTO_CLIENT_ID, OTOMOTO_CLIENT_SECRET, OTOMOTO_EMAIL, OTOMOTO_PASSWORD) are not defined in wp-config.php.';
            cmu_otomoto_log($message, 'ERROR');
            wp_die(esc_html($message) . ' Please define them to test the API client.');
            return;
        }

        // $api_client = new CMU_Otomoto_Api_Client(); // Use the global instance if available
        global $cmu_otomoto_api_client, $cmu_otomoto_sync_manager;

        if (! $cmu_otomoto_api_client) {
            $message = 'Global API client instance (CMU_Otomoto_Api_Client) is not available. This usually means API constants are missing in wp-config.php.';
            cmu_otomoto_log($message, 'ERROR');
            wp_die(esc_html($message));
            return;
        }

        if (! $cmu_otomoto_sync_manager) {
            $message = 'Global Sync Manager instance (CMU_Otomoto_Sync_Manager) is not available. This usually means API client failed to instantiate.';
            cmu_otomoto_log($message, 'ERROR');
            wp_die(esc_html($message));
            return;
        }

        cmu_otomoto_log('Test: Initializing CMU_Otomoto_Post_Type to ensure terms are created if hooked to init or for activation.', 'INFO');
        // Ensure terms from CMU_Otomoto_Post_Type are available. 
        // If create_initial_terms is hooked to plugin activation, this manual call isn't strictly needed here for testing
        // but doesn't hurt to ensure they exist for the test run if activation hook didn't fire (e.g. plugin already active).
        if (class_exists('CMU_Otomoto_Post_Type')) {
            $post_type_handler_for_terms = new CMU_Otomoto_Post_Type();
            $post_type_handler_for_terms->create_initial_terms();
            cmu_otomoto_log('Test: Called create_initial_terms() manually for Stan Maszyny.', 'INFO');
        }

        // Test 1: Get Access Token (already done by sync manager implicitly, but good to double check client)
        $token = $cmu_otomoto_api_client->get_access_token();
        if ($token) {
            cmu_otomoto_log('Test: Successfully retrieved access token.', 'INFO', ['token_start' => substr($token, 0, 10) . '...']);
        } else {
            cmu_otomoto_log('Test: Failed to retrieve access token.', 'ERROR');
            wp_die('Test API: Nie udało się pobrać tokenu dostępu. Sprawdź logi wtyczki w katalogu /logs/.');
            return; // Stop further tests if token fails
        }

        // Test 2: Get Adverts (first page, 2 adverts)
        // $adverts = $cmu_otomoto_api_client->get_adverts( 1, 2 ); 
        // Now we test the full sync (which includes getting adverts)
        cmu_otomoto_log('Test: Attempting to run sync_adverts() from Sync Manager.', 'INFO');
        
        // $cmu_otomoto_sync_manager->sync_adverts(); // This will try to sync (create only for now)
        // Store the result of sync_adverts
        $sync_results = $cmu_otomoto_sync_manager->sync_adverts(false); // false = do not force update all 

        cmu_otomoto_log('Test: sync_adverts() process completed.', 'INFO', ['sync_status' => $sync_results['status'], 'sync_message' => $sync_results['message']]);

        // Undefine the constant after sync is done (optional, as it's request-scoped)
        // However, if there were other save_post actions after this on the same request, it might be useful.
        // For this test script ending in wp_die, it's less critical.
        // Note: constants cannot be truly undefined. We would need a different mechanism if this was an issue.
        // For now, this is sufficient for the test context.

        // Verify CPT and Taxonomies are registered
        $output_message = "Test API (Faza 2) zakończony!\n";
        $output_message .= "Status synchronizacji: " . esc_html($sync_results['status']) . "\n";
        $output_message .= "Komunikat: " . esc_html($sync_results['message']) . "\n\n";

        if (isset($sync_results['summary'])) {
            $output_message .= "Podsumowanie operacji:\n";
            foreach ($sync_results['summary'] as $key => $value) {
                $output_message .= esc_html(ucfirst(str_replace('_', ' ', $key))) . ": " . esc_html($value) . "\n";
            }
            $output_message .= "\n";
        }

        $output_message .= "Sprawdź logi wtyczki (`wp-content/plugins/otomoto-maszyny-integration/logs/otomoto_sync.log`) aby zobaczyć szczegóły synchronizacji.\n";
        $output_message .= "Zweryfikuj w panelu WP:\n";
        $output_message .= "1. Czy CPT 'Maszyny Rolnicze' jest widoczny i działa.\n";
        $output_message .= "2. Czy taksonomie 'Kategorie Maszyn' i 'Stan Maszyny' są widoczne i przypisane do CPT.\n";
        $output_message .= "3. Czy term 'Używane Maszyny Rolnicze' (lub ze stałej OTOMOTO_MAIN_CATEGORY_SLUG_WP) istnieje w 'Kategorie Maszyn'.\n";
        $output_message .= "4. Czy termy 'Nowa' i 'Używana' istnieją w 'Stan Maszyny'.\n";
        $output_message .= "5. Czy zostały utworzone nowe wpisy dla maszyn 'używanych' z API.\n";
        $output_message .= "6. Czy nowo utworzone wpisy mają poprawne tytuły, treści i przypisane kategorie/stan.\n";
        $output_message .= "7. Czy dynamicznie utworzono podkategorie w 'Kategorie Maszyn' i czy mają meta _otomoto_category_id.\n";

        // Display raw API response sample
        if (!empty($sync_results['adverts_sample'])) {
            $output_message .= "\n--- Próbka surowych danych z API Otomoto (max 30 ogłoszeń) ---\n";
            $output_message .= "<pre style='background-color: #f5f5f5; border: 1px solid #ccc; padding: 10px; max-height: 500px; overflow-y: auto; text-align: left; white-space: pre-wrap; word-wrap: break-word;'>";
            $output_message .= esc_html(print_r($sync_results['adverts_sample'], true));
            $output_message .= "</pre>";
        } else {
            $output_message .= "\n--- Brak próbki danych z API Otomoto do wyświetlenia ---\n";
        }

        wp_die('<div style="font-family: monospace; white-space: pre-wrap;">' . nl2br($output_message) . '</div>');

        /* Previous test structure - kept for reference, replaced by sync_adverts() call
// ... existing code ...
             <pre>' . esc_html(print_r(['token_status' => 'SUCCESS', 'adverts_status' => 'NO_ADVERTS_OR_EMPTY_RESPONSE'], true)) . '</pre>');
        }

        // Important: wp_die() is used here to stop execution after the test.
        // For a real admin page, you would display messages differently.
        // wp_die( 'API Client Test Complete. Check logs/otomoto_sync.log for details.' );
        */
    }
}
add_action('admin_init', 'cmu_otomoto_test_api_client');
