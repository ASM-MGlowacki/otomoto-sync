cmu24-otomoto-integration.php:
```php
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
```

----------------------

class-cmu-otomoto-api-client.php:
```php
<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CMU_Otomoto_Api_Client {

    private $otomoto_email;
    private $otomoto_password;
    private $otomoto_client_id;
    private $otomoto_client_secret;
    private $api_base_url = 'https://www.otomoto.pl/api/open';
    private $token_transient_name = 'cmu_otomoto_token_data';

    /**
     * Constructor.
     */
    public function __construct() {
        // Load credentials from wp-config.php constants
        $this->otomoto_email         = defined( 'OTOMOTO_EMAIL' ) ? OTOMOTO_EMAIL : '';
        $this->otomoto_password      = defined( 'OTOMOTO_PASSWORD' ) ? OTOMOTO_PASSWORD : '';
        $this->otomoto_client_id     = defined( 'OTOMOTO_CLIENT_ID' ) ? OTOMOTO_CLIENT_ID : '';
        $this->otomoto_client_secret = defined( 'OTOMOTO_CLIENT_SECRET' ) ? OTOMOTO_CLIENT_SECRET : '';

        if ( empty( $this->otomoto_email ) || empty( $this->otomoto_password ) || empty( $this->otomoto_client_id ) || empty( $this->otomoto_client_secret ) ) {
            cmu_otomoto_log( 'Otomoto API credentials are not fully defined in wp-config.php.', 'ERROR' );
            // Optionally, you could throw an exception or handle this more gracefully
        }
    }

    /**
     * Retrieves the access token for Otomoto API.
     *
     * Handles token caching, fetching new token, and refreshing existing token.
     *
     * @return string|false Access token string on success, false on failure.
     */
    public function get_access_token() {
        // Check if credentials are set
        if ( empty( $this->otomoto_client_id ) || empty( $this->otomoto_client_secret ) ) {
            cmu_otomoto_log( 'Client ID or Client Secret is missing for Otomoto API.', 'ERROR' );
            return false;
        }

        $token_data = get_transient( $this->token_transient_name );

        // Check if token exists and is not expired (with a 5-minute buffer)
        if ( false !== $token_data && isset( $token_data['access_token'] ) && isset( $token_data['expires_at'] ) && ( $token_data['expires_at'] > ( time() + 300 ) ) ) {
            cmu_otomoto_log( 'Using cached Otomoto access token.', 'INFO' );
            return $token_data['access_token'];
        }

        // If token expired or about to expire, try to refresh it
        if ( false !== $token_data && isset( $token_data['refresh_token'] ) && isset( $token_data['expires_at'] ) && ( $token_data['expires_at'] <= ( time() + 300 ) ) ) {
            cmu_otomoto_log( 'Otomoto access token expired or expiring soon. Attempting to refresh.', 'INFO' );
            $refreshed_token_data = $this->refresh_otomoto_token( $token_data['refresh_token'] );
            if ( $refreshed_token_data && isset( $refreshed_token_data['access_token'] ) ) {
                return $refreshed_token_data['access_token'];
            }
            cmu_otomoto_log( 'Failed to refresh Otomoto access token. Attempting to get a new one.', 'WARNING' );
        }

        // If no valid token or refresh failed, get a new one
        cmu_otomoto_log( 'Requesting new Otomoto access token.', 'INFO' );
        
        $auth_header = 'Basic ' . base64_encode( $this->otomoto_client_id . ':' . $this->otomoto_client_secret );
        
        $request_args = [
            'method'    => 'POST',
            'headers'   => [
                'Authorization' => $auth_header,
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body'      => [
                'grant_type' => 'password',
                'username'   => $this->otomoto_email,
                'password'   => $this->otomoto_password,
            ],
            'timeout'   => 30, // seconds
        ];

        $response = wp_remote_post( $this->api_base_url . '/oauth/token', $request_args );

        if ( is_wp_error( $response ) ) {
            cmu_otomoto_log( 'Error requesting new Otomoto access token: ' . $response->get_error_message(), 'ERROR', $request_args );
            return false;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $data = json_decode( $response_body, true );

        if ( $response_code === 200 && isset( $data['access_token'] ) ) {
            $new_token_data = [
                'access_token'  => $data['access_token'],
                'refresh_token' => isset( $data['refresh_token'] ) ? $data['refresh_token'] : null,
                'expires_in'    => isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600, // Default to 1 hour if not set
                'expires_at'    => time() + ( isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600 ),
            ];
            set_transient( $this->token_transient_name, $new_token_data, $new_token_data['expires_in'] );
            cmu_otomoto_log( 'Successfully obtained new Otomoto access token.', 'INFO', ['expires_at' => date('Y-m-d H:i:s', $new_token_data['expires_at'])] );
            return $new_token_data['access_token'];
        } else {
            cmu_otomoto_log( 'Failed to obtain new Otomoto access token.', 'ERROR', [ 'response_code' => $response_code, 'response_body' => $response_body, 'request_args' => $request_args ] );
            return false;
        }
    }

    /**
     * Refreshes the Otomoto API access token.
     *
     * @param string $refresh_token The refresh token.
     * @return array|false Token data array on success, false on failure.
     */
    private function refresh_otomoto_token( $refresh_token ) {
        cmu_otomoto_log( 'Attempting to refresh Otomoto token.', 'INFO' );

        if ( empty( $this->otomoto_client_id ) || empty( $this->otomoto_client_secret ) ) {
            cmu_otomoto_log( 'Client ID or Client Secret is missing for Otomoto API token refresh.', 'ERROR' );
            return false;
        }
        
        $auth_header = 'Basic ' . base64_encode( $this->otomoto_client_id . ':' . $this->otomoto_client_secret );

        $request_args = [
            'method'    => 'POST',
            'headers'   => [
                'Authorization' => $auth_header,
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body'      => [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refresh_token,
            ],
            'timeout'   => 30,
        ];

        $response = wp_remote_post( $this->api_base_url . '/oauth/token', $request_args );

        if ( is_wp_error( $response ) ) {
            cmu_otomoto_log( 'Error refreshing Otomoto access token: ' . $response->get_error_message(), 'ERROR', $request_args );
            return false;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $data = json_decode( $response_body, true );

        if ( $response_code === 200 && isset( $data['access_token'] ) ) {
            $new_token_data = [
                'access_token'  => $data['access_token'],
                'refresh_token' => isset( $data['refresh_token'] ) ? $data['refresh_token'] : $refresh_token, // Preserve old refresh token if new one is not provided
                'expires_in'    => isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600,
                'expires_at'    => time() + ( isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600 ),
            ];
            set_transient( $this->token_transient_name, $new_token_data, $new_token_data['expires_in'] );
            cmu_otomoto_log( 'Successfully refreshed Otomoto access token.', 'INFO', ['expires_at' => date('Y-m-d H:i:s', $new_token_data['expires_at'])] );
            return $new_token_data;
        } else {
            cmu_otomoto_log( 'Failed to refresh Otomoto access token.', 'ERROR', [ 'response_code' => $response_code, 'response_body' => $response_body, 'request_args' => $request_args ] );
            // If refresh fails, delete the transient to force a new token request next time
            delete_transient( $this->token_transient_name );
            return false;
        }
    }

    /**
     * Retrieves adverts from the Otomoto API.
     *
     * @param int $page Page number to fetch.
     * @param int $limit Number of adverts per page.
     * @return array|WP_Error Array of adverts on success, WP_Error on failure.
     */
    public function get_adverts( $page = 1, $limit = 10 ) {
        $access_token = $this->get_access_token();

        if ( ! $access_token ) {
            cmu_otomoto_log( 'Cannot get adverts, Otomoto access token is not available.', 'ERROR' );
            return new WP_Error( 'otomoto_api_token_error', 'Brak tokenu dostępu do API Otomoto.' );
        }

        $request_url = add_query_arg(
            [
                'page'  => (int) $page,
                'limit' => (int) $limit,
            ],
            $this->api_base_url . '/account/adverts'
        );

        $request_args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'User-Agent'    => $this->otomoto_email, // As per API documentation
                'Accept'        => 'application/json',
            ],
            'timeout' => 30, // seconds
        ];

        cmu_otomoto_log( 'Requesting adverts from Otomoto API.', 'INFO', [ 'url' => $request_url, 'page' => $page, 'limit' => $limit ] );

        $response = wp_remote_get( $request_url, $request_args );

        if ( is_wp_error( $response ) ) {
            cmu_otomoto_log( 'Error requesting adverts from Otomoto: ' . $response->get_error_message(), 'ERROR', [ 'url' => $request_url, 'args' => $request_args ] );
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $data = json_decode( $response_body, true );

        if ( $response_code === 200 && isset( $data['results'] ) ) { // Assuming 'results' based on typical API pagination, adjust if API returns directly an array
            cmu_otomoto_log( 'Successfully retrieved adverts from Otomoto.', 'INFO', [ 'count' => count( $data['results'] ), 'page' => $page ] );
            return $data['results'];
        } elseif ( $response_code === 200 && is_array( $data ) ) { // If the API returns a direct array of adverts (no 'results' wrapper)
             cmu_otomoto_log( 'Successfully retrieved adverts from Otomoto (direct array).', 'INFO', [ 'count' => count( $data ), 'page' => $page ] );
            return $data;
        }
        else {
            cmu_otomoto_log( 'Failed to retrieve adverts from Otomoto.', 'ERROR', [ 'url' => $request_url, 'response_code' => $response_code, 'response_body' => $response_body ] );
            return new WP_Error( 'otomoto_api_adverts_error', 'Nie udało się pobrać ogłoszeń z Otomoto.', [ 'status' => $response_code, 'response' => $response_body ] );
        }
    }

    /**
     * Retrieves category details from the Otomoto API.
     *
     * @param int $otomoto_category_id The ID of the Otomoto category.
     * @return array|WP_Error Array of category data on success, WP_Error on failure.
     */
    public function get_otomoto_category_details( $otomoto_category_id ) {
        $access_token = $this->get_access_token();

        if ( ! $access_token ) {
            cmu_otomoto_log( 'Cannot get category details, Otomoto access token is not available.', 'ERROR', ['category_id' => $otomoto_category_id] );
            return new WP_Error( 'otomoto_api_token_error', 'Brak tokenu dostępu do API Otomoto.' );
        }

        if ( empty( $otomoto_category_id ) ) {
            cmu_otomoto_log( 'Cannot get category details, Otomoto category ID is missing.', 'ERROR' );
            return new WP_Error( 'otomoto_api_param_error', 'Brak ID kategorii Otomoto.' );
        }

        $request_url = $this->api_base_url . '/categories/' . intval( $otomoto_category_id );

        $request_args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'User-Agent'    => $this->otomoto_email, // As per API documentation
                'Accept'        => 'application/json',
            ],
            'timeout' => 30, // seconds
        ];

        cmu_otomoto_log( 'Requesting category details from Otomoto API.', 'INFO', [ 'url' => $request_url, 'category_id' => $otomoto_category_id ] );

        $response = wp_remote_get( $request_url, $request_args );

        if ( is_wp_error( $response ) ) {
            cmu_otomoto_log( 'Error requesting category details from Otomoto: ' . $response->get_error_message(), 'ERROR', [ 'url' => $request_url, 'args' => $request_args ] );
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $data = json_decode( $response_body, true );

        // The /categories/:id endpoint returns the category object directly, not wrapped in 'results'
        if ( $response_code === 200 && is_array( $data ) && isset( $data['id'] ) ) {
            cmu_otomoto_log( 'Successfully retrieved category details from Otomoto.', 'INFO', [ 'category_id' => $data['id'], 'name' => isset($data['names']['pl']) ? $data['names']['pl'] : 'N/A' ] );
            return $data;
        } else {
            cmu_otomoto_log( 'Failed to retrieve category details from Otomoto.', 'ERROR', [ 'url' => $request_url, 'response_code' => $response_code, 'response_body' => $response_body ] );
            return new WP_Error( 'otomoto_api_category_error', 'Nie udało się pobrać szczegółów kategorii z Otomoto.', [ 'status' => $response_code, 'response' => $response_body ] );
        }
    }
}
?> ```

----------------------

class-cmu-otomoto-cron.php:
```php
<?php
// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Class CMU_Otomoto_Cron
 *
 * Manages scheduled tasks for Otomoto integration, including batch processing of adverts.
 */
class CMU_Otomoto_Cron
{

    const MASTER_SYNC_HOOK = 'cmu_otomoto_master_sync_hook';
    const PROCESS_BATCH_HOOK = 'cmu_otomoto_process_batch_hook';
    const BATCH_STATUS_OPTION_NAME = '_cmu_otomoto_batch_status';
    const BATCH_LOCK_TRANSIENT_NAME = 'lock_cmu_otomoto_process_batch'; // Shorter name for transient
    const BATCH_LOCK_TIMEOUT = 10 * MINUTE_IN_SECONDS; // Increased timeout for safety
    const DEFAULT_BATCH_SIZE = 3; // FR-011
    const INTER_BATCH_SCHEDULE_DELAY = 2 * MINUTE_IN_SECONDS;
    const EMAIL_THROTTLE_TRANSIENT_PREFIX = 'cmu_otomoto_email_throttle_';
    const EMAIL_THROTTLE_DURATION = HOUR_IN_SECONDS;

    private $api_client;
    private $sync_manager;

    /**
     * Constructor.
     * Hooks into WordPress actions.
     */
    public function __construct()
    {
        // Hook for daily master sync and batch processing
        add_action(self::MASTER_SYNC_HOOK, [$this, 'initiate_master_sync_cycle']);
        add_action(self::PROCESS_BATCH_HOOK, [$this, 'process_advert_batch']);

        // (Optional) Custom cron schedule if 'daily' is not enough, for testing or specific needs
        // add_filter('cron_schedules', [$this, 'add_custom_cron_schedules']);
    }

    /**
     * Plugin activation tasks related to CRON.
     * Called from the main plugin file's activation hook.
     */
    public function activate()
    {
        cmu_otomoto_log('Plugin activation: Scheduling CRON tasks.', 'INFO');

        // Schedule the master sync hook daily if not already scheduled
        if (! wp_next_scheduled(self::MASTER_SYNC_HOOK)) {
            wp_schedule_event(time(), 'daily', self::MASTER_SYNC_HOOK);
            cmu_otomoto_log('Daily master sync task (' . self::MASTER_SYNC_HOOK . ') scheduled.', 'INFO');
        } else {
            cmu_otomoto_log('Daily master sync task (' . self::MASTER_SYNC_HOOK . ') was already scheduled.', 'INFO');
        }

        // Schedule an initial master sync shortly after activation (FR-006, US-CRON-006)
        // This one-time event will trigger the master sync cycle.
        // It uses a unique hook to avoid conflict if activate is called multiple times.
        $initial_sync_hook = self::MASTER_SYNC_HOOK . '_initial';
        if (! wp_next_scheduled($initial_sync_hook)) {
            wp_schedule_single_event(time() + 60, $initial_sync_hook); // 1 minute delay
            add_action($initial_sync_hook, [$this, 'run_initial_master_sync_once']);
            cmu_otomoto_log('One-time initial master sync task (' . $initial_sync_hook . ') scheduled in 60 seconds.', 'INFO');
        }

        // Ensure initial terms for taxonomies are created (if not handled elsewhere reliably)
        if (class_exists('CMU_Otomoto_Post_Type')) {
            $post_type_handler = new CMU_Otomoto_Post_Type();
            $post_type_handler->create_initial_terms();
        }
    }

    /**
     * Callback for the one-time initial sync hook.
     */
    public function run_initial_master_sync_once()
    {
        cmu_otomoto_log('Executing one-time initial master sync via ' . self::MASTER_SYNC_HOOK . '_initial hook.', 'INFO');
        $this->initiate_master_sync_cycle();
    }


    /**
     * Plugin deactivation tasks related to CRON.
     * Called from the main plugin file's deactivation hook.
     */
    public function deactivate()
    {
        cmu_otomoto_log('Plugin deactivation: Clearing scheduled CRON tasks.', 'INFO');
        wp_clear_scheduled_hook(self::MASTER_SYNC_HOOK);
        wp_clear_scheduled_hook(self::PROCESS_BATCH_HOOK);
        wp_clear_scheduled_hook(self::MASTER_SYNC_HOOK . '_initial'); // Clear the one-time initial hook too

        // Optionally, clear the batch status option or the lock
        // delete_option(self::BATCH_STATUS_OPTION_NAME);
        // delete_transient(self::BATCH_LOCK_TRANSIENT_NAME);
        cmu_otomoto_log('Scheduled tasks cleared.', 'INFO');
    }

    /**
     * Initiates a new master synchronization cycle. (FR-012, US-CRON-007)
     * This is the callback for the main daily cron hook.
     */
    public function initiate_master_sync_cycle()
    {
        cmu_otomoto_log('Master sync cycle initiated by ' . self::MASTER_SYNC_HOOK . '.', 'INFO');

        // Clear any previously scheduled batch processing hooks to avoid overlaps from aborted cycles
        // This will remove all instances of the hook, regardless of arguments.
        $timestamp = wp_next_scheduled(self::PROCESS_BATCH_HOOK);
        while ($timestamp) {
            wp_unschedule_event($timestamp, self::PROCESS_BATCH_HOOK);
            $timestamp = wp_next_scheduled(self::PROCESS_BATCH_HOOK);
        }
        cmu_otomoto_log('Cleared any pending batch processing tasks from previous cycles.', 'INFO');

        // Reset or initialize the batch status option
        $initial_batch_status = [
            'cycle_status'      => 'pending', // pending, running, completed, error
            'current_api_page'  => 1,
            'total_api_pages'   => null,      // To be determined from API response or estimated
            'processed_otomoto_ids_in_cycle' => [],
            'errors_in_cycle'   => [],
            'cycle_start_time'  => current_time('mysql'),
            'cycle_last_batch_processed_time' => null,
            'cycle_completed_time' => null,
            'cycle_summary'     => $this->get_initial_cycle_summary_structure(),
        ];
        update_option(self::BATCH_STATUS_OPTION_NAME, $initial_batch_status, 'no'); // 'no' for autoload
        cmu_otomoto_log('Batch status option reset for new cycle.', 'INFO', ['initial_status' => $initial_batch_status]);

        // Schedule the first batch processing task
        wp_schedule_single_event(time() + 5, self::PROCESS_BATCH_HOOK); // Small delay
        cmu_otomoto_log('First batch processing task (' . self::PROCESS_BATCH_HOOK . ') scheduled for the new cycle.', 'INFO');
    }

    /**
     * Processes a single batch of adverts. (FR-002, US-CRON-002)
     * This is the callback for the batch processing hook.
     */
    public function process_advert_batch()
    {
        // Set the CMU_OTOMOTO_DOING_SYNC constant (US-CRON-008)
        if (! defined('CMU_OTOMOTO_DOING_SYNC')) {
            define('CMU_OTOMOTO_DOING_SYNC', true);
        }
        cmu_otomoto_log('CMU_OTOMOTO_DOING_SYNC constant defined for batch processing.', 'DEBUG');

        // Lock mechanism (FR-005, US-CRON-003)
        if (get_transient(self::BATCH_LOCK_TRANSIENT_NAME)) {
            cmu_otomoto_log('Batch processing (' . self::PROCESS_BATCH_HOOK . ') skipped: Lock transient exists, another batch might be running or recently finished.', 'WARNING');
            return;
        }
        set_transient(self::BATCH_LOCK_TRANSIENT_NAME, true, self::BATCH_LOCK_TIMEOUT);
        cmu_otomoto_log('Lock transient (' . self::BATCH_LOCK_TRANSIENT_NAME . ') set for batch processing.', 'DEBUG');

        $batch_status = get_option(self::BATCH_STATUS_OPTION_NAME);

        if (empty($batch_status) || !is_array($batch_status) || !isset($batch_status['cycle_status'])) {
            cmu_otomoto_log('Batch processing (' . self::PROCESS_BATCH_HOOK . ') aborted: Invalid or missing batch status option.', 'ERROR');
            $this->send_admin_notification(
                'CMU Otomoto Sync Error: Batch Status Missing',
                'The batch status option (' . self::BATCH_STATUS_OPTION_NAME . ') is missing or invalid. Automatic synchronization cannot proceed.',
                'batch_status_missing'
            );
            delete_transient(self::BATCH_LOCK_TRANSIENT_NAME);
            return;
        }

        if ($batch_status['cycle_status'] === 'completed' || $batch_status['cycle_status'] === 'error') {
            cmu_otomoto_log('Batch processing (' . self::PROCESS_BATCH_HOOK . ') aborted: Cycle status is already "' . $batch_status['cycle_status'] . '". No new batch will be processed.', 'INFO');
            delete_transient(self::BATCH_LOCK_TRANSIENT_NAME);
            return;
        }

        if ($batch_status['cycle_status'] === 'pending') {
            $batch_status['cycle_status'] = 'running';
            cmu_otomoto_log('Batch cycle status updated to "running".', 'INFO');
        }

        cmu_otomoto_log('Starting batch processing for API page: ' . $batch_status['current_api_page'], 'INFO');

        // Initialize API client and Sync Manager (US-CRON-009)
        if (!$this->initialize_services()) {
            $batch_status['cycle_status'] = 'error';
            $batch_status['errors_in_cycle'][] = 'Failed to initialize API client or Sync Manager due to missing credentials.';
            update_option(self::BATCH_STATUS_OPTION_NAME, $batch_status, 'no');
            delete_transient(self::BATCH_LOCK_TRANSIENT_NAME);
            return; // Notification already sent by initialize_services
        }

        $batch_size = defined('CMU_OTOMOTO_BATCH_SIZE_ADVERTS') ? (int) CMU_OTOMOTO_BATCH_SIZE_ADVERTS : self::DEFAULT_BATCH_SIZE;
        if ($batch_size <= 0) $batch_size = self::DEFAULT_BATCH_SIZE;

        // Use the new method in SyncManager to process a page of adverts
        // This method should internally fetch from API for the given page and limit
        $page_result = $this->sync_manager->process_api_page_for_batch(
            $batch_status['current_api_page'],
            $batch_size,
            false, // $force_update_all
            $batch_status['cycle_summary'], // Pass by reference
            $batch_status['processed_otomoto_ids_in_cycle'] // Pass by reference
        );

        if ($page_result['status'] === 'api_error') {
            cmu_otomoto_log('Batch processing: API error encountered. ' . ($page_result['message'] ?? ''), 'ERROR');
            $batch_status['errors_in_cycle'][] = 'API Error on page ' . $batch_status['current_api_page'] . ': ' . ($page_result['message'] ?? 'Unknown API error');
            // Decide if this is a fatal error for the cycle
            // For now, let's try to schedule the next batch after a longer delay or stop the cycle.
            // For simplicity, we'll mark the cycle as error and stop.
            $batch_status['cycle_status'] = 'error';
            $this->send_admin_notification(
                'CMU Otomoto Sync Error: API Failure',
                'Failed to fetch data from Otomoto API during batch processing for page ' . $batch_status['current_api_page'] . '. Error: ' . ($page_result['message'] ?? 'Unknown API error') . '. The sync cycle has been stopped.',
                'api_failure_batch'
            );
        } elseif ($page_result['status'] === 'success' || $page_result['status'] === 'no_more_adverts') {
            $batch_status['current_api_page']++;
            $batch_status['cycle_last_batch_processed_time'] = current_time('mysql');

            if ($page_result['status'] === 'no_more_adverts' || ($page_result['adverts_on_page_count'] < $batch_size && $batch_size > 0)) {
                // End of cycle
                cmu_otomoto_log('Batch processing cycle completed successfully. All pages processed.', 'INFO');
                $batch_status['cycle_status'] = 'completed';
                $batch_status['cycle_completed_time'] = current_time('mysql');
                update_option(self::BATCH_STATUS_OPTION_NAME, $batch_status, 'no');

                // Perform cleanup of inactive posts (FR-009)
                $this->cleanup_inactive_posts($batch_status['processed_otomoto_ids_in_cycle'], $batch_status['cycle_summary']);

                $final_summary_message = "CMU Otomoto Sync: Full cycle completed successfully.\n";
                $final_summary_message .= "Start: " . $batch_status['cycle_start_time'] . ", End: " . $batch_status['cycle_completed_time'] . "\n";
                foreach ($batch_status['cycle_summary'] as $key => $value) {
                    $final_summary_message .= ucfirst(str_replace('_', ' ', $key)) . ": " . $value . "\n";
                }
                if (!empty($batch_status['errors_in_cycle'])) {
                    $final_summary_message .= "Errors encountered during cycle: \n" . implode("\n", $batch_status['errors_in_cycle']) . "\n";
                }
                $this->send_admin_notification(
                    'CMU Otomoto Sync: Cycle Completed',
                    $final_summary_message,
                    'cycle_completed_report'
                );
                cmu_otomoto_log('Final sync summary: ', 'INFO', $batch_status['cycle_summary']);
            } else {
                // Schedule next batch
                wp_schedule_single_event(time() + self::INTER_BATCH_SCHEDULE_DELAY, self::PROCESS_BATCH_HOOK);
                cmu_otomoto_log('Next batch processing task scheduled in ' . self::INTER_BATCH_SCHEDULE_DELAY . ' seconds.', 'INFO');
            }
        }

        // Update the batch status option regardless of outcome for this batch (unless it was missing)
        update_option(self::BATCH_STATUS_OPTION_NAME, $batch_status, 'no');
        cmu_otomoto_log('Batch status option updated after processing.', 'DEBUG', ['current_status' => $batch_status]);

        delete_transient(self::BATCH_LOCK_TRANSIENT_NAME);
        cmu_otomoto_log('Lock transient (' . self::BATCH_LOCK_TRANSIENT_NAME . ') deleted.', 'DEBUG');
    }

    /**
     * Initializes API client and Sync Manager.
     * @return bool True on success, false on failure.
     */
    private function initialize_services()
    {
        if (!defined('OTOMOTO_CLIENT_ID') || !defined('OTOMOTO_CLIENT_SECRET') || !defined('OTOMOTO_EMAIL') || !defined('OTOMOTO_PASSWORD')) {
            $message = 'One or more OTOMOTO API constants are not defined in wp-config.php. Cannot initialize services for CRON.';
            cmu_otomoto_log($message, 'ERROR');
            $this->send_admin_notification('CMU Otomoto Sync Error: Missing API Credentials', $message, 'missing_api_creds_cron');
            return false;
        }

        if (null === $this->api_client) {
            $this->api_client = new CMU_Otomoto_Api_Client();
        }
        if (null === $this->sync_manager) {
            $this->sync_manager = new CMU_Otomoto_Sync_Manager($this->api_client);
            // Ensure parent term exists if SyncManager constructor doesn't handle it or if it's the first run
            if (method_exists($this->sync_manager, 'ensure_parent_wp_term_exists_public')) { // Assuming we add a public accessor if needed
                $this->sync_manager->ensure_parent_wp_term_exists_public();
            } elseif (property_exists($this->sync_manager, 'parent_wp_term_id') && $this->sync_manager->parent_wp_term_id === null) {
                // This is a bit hacky, ideally ensure_parent_wp_term_exists is idempotent and callable
                if (method_exists($this->sync_manager, 'ensure_parent_wp_term_exists')) {
                    $this->sync_manager->ensure_parent_wp_term_exists(); // Call it if accessible
                }
            }
        }
        return true;
    }

    /**
     * Gets the initial structure for the cycle summary.
     * @return array
     */
    private function get_initial_cycle_summary_structure()
    {
        return [
            'total_api_pages_fetched' => 0,
            'total_adverts_processed_from_api' => 0, // Adverts that SyncManager attempted to process
            'posts_created' => 0,
            'posts_updated' => 0,
            'posts_skipped_not_used' => 0,
            'posts_skipped_inactive_status' => 0, // From API advert status, not WP post status
            'posts_skipped_no_title' => 0,
            'posts_skipped_no_changes' => 0,
            'posts_skipped_manual_edit' => 0,
            'categories_created_during_cycle' => 0, // Placeholder, if we want to track this per cycle
            'errors_processing_adverts' => 0, // Errors from process_single_advert_data
            'posts_deleted_as_inactive_in_otomoto' => 0,
        ];
    }

    /**
     * Sends an email notification to the admin. (FR-004)
     *
     * @param string $subject The subject of the email.
     * @param string $message The body of the email.
     * @param string $error_type_key A unique key for the error type to enable throttling.
     */
    private function send_admin_notification($subject, $message, $error_type_key)
    {
        $throttle_transient_name = self::EMAIL_THROTTLE_TRANSIENT_PREFIX . sanitize_key($error_type_key);

        if (get_transient($throttle_transient_name)) {
            cmu_otomoto_log('Admin notification throttled for: ' . $subject, 'INFO', ['error_key' => $error_type_key]);
            return;
        }

        $admin_email = get_option('admin_email');
        if (empty($admin_email) || !is_email($admin_email)) {
            cmu_otomoto_log('Cannot send admin notification: Admin email is not configured or invalid.', 'WARNING');
            return;
        }

        $headers = ['Content-Type: text/html; charset=UTF-T'];
        $full_message = "<p>This is an automated notification from the CMU Otomoto Integration plugin.</p>";
        $full_message .= "<p>" . nl2br(esc_html($message)) . "</p>";
        $full_message .= "<p>Timestamp: " . current_time('mysql') . "</p>";

        wp_mail($admin_email, '[CMU Otomoto Sync] ' . $subject, $full_message, $headers);
        set_transient($throttle_transient_name, true, self::EMAIL_THROTTLE_DURATION);
        cmu_otomoto_log('Admin notification sent: ' . $subject, 'INFO', ['to' => $admin_email]);
    }

    /**
     * Cleans up (moves to trash) WordPress posts that are no longer active in Otomoto. (FR-009)
     * This is called after a full successful batch cycle.
     *
     * @param array $active_otomoto_ids_from_cycle Array of Otomoto IDs found active during the cycle.
     * @param array &$cycle_summary_ref Reference to the cycle summary to update the count.
     */
    private function cleanup_inactive_posts(array $active_otomoto_ids_from_cycle, array &$cycle_summary_ref)
    {
        cmu_otomoto_log('Starting cleanup of inactive posts. Active Otomoto IDs in this cycle: ' . count($active_otomoto_ids_from_cycle), 'INFO');

        if (empty($active_otomoto_ids_from_cycle)) {
            cmu_otomoto_log('Cleanup skipped: No active Otomoto IDs were processed in this cycle. This might indicate an issue or an empty Otomoto account.', 'WARNING');
            // Potentially send a notification if this is unexpected.
            return;
        }

        $args_wp_posts_to_check = [
            'post_type'      => 'maszyna-rolnicza',
            'post_status'    => 'publish', // Only check published posts
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_otomoto_id',
                    'compare' => 'EXISTS',
                ],
            ],
        ];
        $all_otomoto_wp_post_ids = get_posts($args_wp_posts_to_check);
        $deleted_posts_count = 0;

        if (!empty($all_otomoto_wp_post_ids)) {
            cmu_otomoto_log('Found ' . count($all_otomoto_wp_post_ids) . ' published "maszyna-rolnicza" posts in WP to verify.', 'INFO');
            foreach ($all_otomoto_wp_post_ids as $wp_post_id) {
                $otomoto_id_of_wp_post = get_post_meta($wp_post_id, '_otomoto_id', true);

                if (!empty($otomoto_id_of_wp_post) && !in_array((string)$otomoto_id_of_wp_post, array_map('strval', $active_otomoto_ids_from_cycle), true)) {
                    // This post's Otomoto ID was not found in the list of active IDs from the completed sync cycle.

                    // Ensure CMU_OTOMOTO_DOING_SYNC is defined to prevent save_post hook issues.
                    // It should be defined if this method is called from process_advert_batch.
                    if (!defined('CMU_OTOMOTO_DOING_SYNC') || !CMU_OTOMOTO_DOING_SYNC) {
                        cmu_otomoto_log("CRITICAL WARNING: Attempting to delete post ID $wp_post_id (Otomoto ID: $otomoto_id_of_wp_post) without CMU_OTOMOTO_DOING_SYNC. This is unexpected during cleanup.", 'ERROR');
                        // Proceed with caution or add a define here if this path is legitimate
                        // define('CMU_OTOMOTO_DOING_SYNC', true); 
                    }

                    $delete_result = wp_delete_post($wp_post_id, false); // false = move to trash

                    if ($delete_result !== false && $delete_result !== null) {
                        cmu_otomoto_log("Post ID $wp_post_id (Otomoto ID: $otomoto_id_of_wp_post) MOVED TO TRASH as it's no longer active in Otomoto or was not found in the latest sync.", 'INFO');
                        $deleted_posts_count++;
                    } else {
                        cmu_otomoto_log("Failed to move post ID $wp_post_id (Otomoto ID: $otomoto_id_of_wp_post) to trash.", 'ERROR', ['wp_delete_post_result' => $delete_result]);
                        $cycle_summary_ref['errors_processing_adverts']++; // Or a specific error counter for deletions
                    }
                }
            }
        } else {
            cmu_otomoto_log('No published "maszyna-rolnicza" posts found in WP for cleanup verification.', 'INFO');
        }
        $cycle_summary_ref['posts_deleted_as_inactive_in_otomoto'] = $deleted_posts_count;
        cmu_otomoto_log("Cleanup of inactive posts finished. Moved to trash: $deleted_posts_count.", 'INFO');
    }

    // Optional: Method to add custom cron schedules if needed
    // public function add_custom_cron_schedules($schedules) {
    //    $schedules['every_5_minutes'] = array(
    //        'interval' => 5 * MINUTE_IN_SECONDS,
    //        'display'  => __('Every 5 Minutes')
    //    );
    //    return $schedules;
    // }
}
```

----------------------

class-cmu-otomoto-post-type.php:
```php
<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CMU_Otomoto_Post_Type
 *
 * Handles the registration of Custom Post Type "Maszyna Rolnicza"
 * and its associated taxonomies "Kategorie Maszyn" and "Stan Maszyny".
 */
class CMU_Otomoto_Post_Type {

    /**
     * Constructor.
     * Hooks into WordPress init action.
     */
    public function __construct() {
        add_action( 'init', [ $this, 'register_cpt_maszyna_rolnicza' ] );
        add_action( 'init', [ $this, 'register_taxonomies' ] );
        // Hook for creating initial terms - can be called on plugin activation
        // For now, we will call it directly or via a separate activation hook later.
        // add_action( 'init', [ $this, 'create_initial_terms' ] ); 

        // Add filter for post type link
        add_filter( 'post_type_link', [ $this, 'custom_post_type_link' ], 10, 2 );
    }

    /**
     * Registers the Custom Post Type "Maszyna Rolnicza".
     */
    public function register_cpt_maszyna_rolnicza() {
        $labels = [
            'name'                  => _x( 'Maszyny Rolnicze', 'Post Type General Name', 'cmu-otomoto-integration' ),
            'singular_name'         => _x( 'Maszyna Rolnicza', 'Post Type Singular Name', 'cmu-otomoto-integration' ),
            'menu_name'             => __( 'Maszyny Rolnicze', 'cmu-otomoto-integration' ),
            'name_admin_bar'        => __( 'Maszyna Rolnicza', 'cmu-otomoto-integration' ),
            'archives'              => __( 'Archiwum Maszyn Rolniczych', 'cmu-otomoto-integration' ),
            'attributes'            => __( 'Atrybuty Maszyny Rolniczej', 'cmu-otomoto-integration' ),
            'parent_item_colon'     => __( 'Maszyna nadrzędna:', 'cmu-otomoto-integration' ),
            'all_items'             => __( 'Wszystkie Maszyny', 'cmu-otomoto-integration' ),
            'add_new_item'          => __( 'Dodaj nową Maszynę Rolniczą', 'cmu-otomoto-integration' ),
            'add_new'               => __( 'Dodaj nową', 'cmu-otomoto-integration' ),
            'new_item'              => __( 'Nowa Maszyna Rolnicza', 'cmu-otomoto-integration' ),
            'edit_item'             => __( 'Edytuj Maszynę Rolniczą', 'cmu-otomoto-integration' ),
            'update_item'           => __( 'Zaktualizuj Maszynę Rolniczą', 'cmu-otomoto-integration' ),
            'view_item'             => __( 'Zobacz Maszynę Rolniczą', 'cmu-otomoto-integration' ),
            'view_items'            => __( 'Zobacz Maszyny Rolnicze', 'cmu-otomoto-integration' ),
            'search_items'          => __( 'Szukaj Maszyny Rolniczej', 'cmu-otomoto-integration' ),
            'not_found'             => __( 'Nie znaleziono maszyn', 'cmu-otomoto-integration' ),
            'not_found_in_trash'    => __( 'Nie znaleziono maszyn w koszu', 'cmu-otomoto-integration' ),
            'featured_image'        => __( 'Obrazek wyróżniający', 'cmu-otomoto-integration' ),
            'set_featured_image'    => __( 'Ustaw obrazek wyróżniający', 'cmu-otomoto-integration' ),
            'remove_featured_image' => __( 'Usuń obrazek wyróżniający', 'cmu-otomoto-integration' ),
            'use_featured_image'    => __( 'Użyj jako obrazek wyróżniający', 'cmu-otomoto-integration' ),
            'insert_into_item'      => __( 'Wstaw do maszyny', 'cmu-otomoto-integration' ),
            'uploaded_to_this_item' => __( 'Załadowano do tej maszyny', 'cmu-otomoto-integration' ),
            'items_list'            => __( 'Lista maszyn rolniczych', 'cmu-otomoto-integration' ),
            'items_list_navigation' => __( 'Nawigacja listy maszyn', 'cmu-otomoto-integration' ),
            'filter_items_list'     => __( 'Filtruj listę maszyn', 'cmu-otomoto-integration' ),
        ];
        $args = [
            'label'                 => __( 'Maszyna Rolnicza', 'cmu-otomoto-integration' ),
            'description'           => __( 'Custom Post Type dla maszyn rolniczych z Otomoto.', 'cmu-otomoto-integration' ),
            'labels'                => $labels,
            'supports'              => [ 'title', 'editor', 'thumbnail', 'custom-fields', 'excerpt' ],
            'taxonomies'            => [ 'kategorie-maszyn', 'stan-maszyny' ],
            'hierarchical'          => false,
            'public'                => true,
            'show_ui'               => true,
            'show_in_menu'          => true,
            'menu_position'         => 5,
            'menu_icon'             => 'dashicons-car', // Placeholder icon
            'show_in_admin_bar'     => true,
            'show_in_nav_menus'     => true,
            'can_export'            => true,
            'has_archive'           => 'maszyny-rolnicze', // Enables archive page at /maszyny-rolnicze
            'exclude_from_search'   => false,
            'publicly_queryable'    => true,
            'capability_type'       => 'post',
            // 'rewrite'               => [ 'slug' => 'maszyny-rolnicze/%kategorie-maszyn%', 'with_front' => false ], // More complex rewrite, handle later if needed
            'rewrite'               => [ 'slug' => 'maszyny-rolnicze/%kategorie-maszyn%', 'with_front' => false ],
            'show_in_rest'          => true, // Enable Gutenberg editor and REST API support
        ];
        register_post_type( 'maszyna-rolnicza', $args );
    }

    /**
     * Registers the "Kategorie Maszyn" and "Stan Maszyny" taxonomies.
     */
    public function register_taxonomies() {
        // Taksonomia: Kategorie Maszyn
        $kategorie_labels = [
            'name'                       => _x( 'Kategorie Maszyn', 'Taxonomy General Name', 'cmu-otomoto-integration' ),
            'singular_name'              => _x( 'Kategoria Maszyn', 'Taxonomy Singular Name', 'cmu-otomoto-integration' ),
            'menu_name'                  => __( 'Kategorie Maszyn', 'cmu-otomoto-integration' ),
            'all_items'                  => __( 'Wszystkie Kategorie', 'cmu-otomoto-integration' ),
            'parent_item'                => __( 'Kategoria nadrzędna', 'cmu-otomoto-integration' ),
            'parent_item_colon'          => __( 'Kategoria nadrzędna:', 'cmu-otomoto-integration' ),
            'new_item_name'              => __( 'Nowa nazwa Kategorii', 'cmu-otomoto-integration' ),
            'add_new_item'               => __( 'Dodaj nową Kategorię', 'cmu-otomoto-integration' ),
            'edit_item'                  => __( 'Edytuj Kategorię', 'cmu-otomoto-integration' ),
            'update_item'                => __( 'Zaktualizuj Kategorię', 'cmu-otomoto-integration' ),
            'view_item'                  => __( 'Zobacz Kategorię', 'cmu-otomoto-integration' ),
            'separate_items_with_commas' => __( 'Oddziel kategorie przecinkami', 'cmu-otomoto-integration' ),
            'add_or_remove_items'        => __( 'Dodaj lub usuń kategorie', 'cmu-otomoto-integration' ),
            'choose_from_most_used'      => __( 'Wybierz z najczęściej używanych', 'cmu-otomoto-integration' ),
            'popular_items'              => __( 'Popularne Kategorie', 'cmu-otomoto-integration' ),
            'search_items'               => __( 'Szukaj Kategorii', 'cmu-otomoto-integration' ),
            'not_found'                  => __( 'Nie znaleziono kategorii', 'cmu-otomoto-integration' ),
            'no_terms'                   => __( 'Brak kategorii', 'cmu-otomoto-integration' ),
            'items_list'                 => __( 'Lista kategorii', 'cmu-otomoto-integration' ),
            'items_list_navigation'      => __( 'Nawigacja listy kategorii', 'cmu-otomoto-integration' ),
        ];
        $kategorie_args = [
            'labels'            => $kategorie_labels,
            'hierarchical'      => true,
            'public'            => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_nav_menus' => true,
            'show_tagcloud'     => false,
            // 'rewrite'           => [ 'slug' => 'maszyny-rolnicze', 'hierarchical' => true, 'with_front' => false ], // e.g. /maszyny-rolnicze/ciagniki/
            // Important: If CPT slug is maszyny-rolnicze/%kategorie-maszyn%
            // then the taxonomy slug should ideally be just 'kategorie-maszyn' or similar,
            // or if it's also 'maszyny-rolnicze', WordPress handles it by creating /maszyny-rolnicze/term-slug/ for term archives.
            // Let's keep it as 'maszyny-rolnicze' for now as per previous logic for term archives.
            // The CPT rewrite will handle the post link structure.
            'rewrite'           => [ 'slug' => 'maszyny-rolnicze', 'hierarchical' => true, 'with_front' => false ], 
            'show_in_rest'      => true,
        ];
        register_taxonomy( 'kategorie-maszyn', [ 'maszyna-rolnicza' ], $kategorie_args );

        // Taksonomia: Stan Maszyny
        $stan_labels = [
            'name'                       => _x( 'Stany Maszyn', 'Taxonomy General Name', 'cmu-otomoto-integration' ),
            'singular_name'              => _x( 'Stan Maszyny', 'Taxonomy Singular Name', 'cmu-otomoto-integration' ),
            'menu_name'                  => __( 'Stan Maszyny', 'cmu-otomoto-integration' ),
            // ... (add more labels as needed, similar to Kategorie Maszyn)
            'all_items'                  => __( 'Wszystkie stany', 'cmu-otomoto-integration' ),
            'new_item_name'              => __( 'Nowa nazwa stanu', 'cmu-otomoto-integration' ),
            'add_new_item'               => __( 'Dodaj nowy stan', 'cmu-otomoto-integration' ),
            'edit_item'                  => __( 'Edytuj stan', 'cmu-otomoto-integration' ),
            'update_item'                => __( 'Aktualizuj stan', 'cmu-otomoto-integration' ),
            'search_items'               => __( 'Szukaj stanów', 'cmu-otomoto-integration' ),
            'popular_items'              => __( 'Popularne stany', 'cmu-otomoto-integration' ),
            'separate_items_with_commas' => __( 'Oddziel stany przecinkami', 'cmu-otomoto-integration' ),
            'add_or_remove_items'        => __( 'Dodaj lub usuń stany', 'cmu-otomoto-integration' ),
            'choose_from_most_used'      => __( 'Wybierz z najczęściej używanych stanów', 'cmu-otomoto-integration' ),
            'not_found'                  => __( 'Nie znaleziono stanów', 'cmu-otomoto-integration' ),
        ];
        $stan_args = [
            'labels'            => $stan_labels,
            'hierarchical'      => false, // Not hierarchical
            'public'            => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_nav_menus' => true,
            'show_tagcloud'     => false,
            'show_in_menu'      => false, // Hide from admin menu
            'rewrite'           => [ 'slug' => 'stan-maszyny', 'with_front' => false ],
            'show_in_rest'      => true,
        ];
        register_taxonomy( 'stan-maszyny', [ 'maszyna-rolnicza' ], $stan_args );
    }

    /**
     * Customizes the permalink for 'maszyna-rolnicza' post type.
     * Replaces %kategorie-maszyn% with the actual term slug.
     *
     * @param string $post_link The original post link.
     * @param WP_Post $post The post object.
     * @return string The modified post link.
     */
    public function custom_post_type_link( $post_link, $post ) {
        if ( 'maszyna-rolnicza' === $post->post_type ) {
            // $terms = wp_get_object_terms( $post->ID, 'kategorie-maszyn', ['fields' => 'slugs', 'orderby' => 'term_id'] ); 
            $_terms = wp_get_object_terms( $post->ID, 'kategorie-maszyn', array( 'orderby' => 'parent', 'order' => 'ASC' ) );

            if ( !empty($_terms) ) {
                $term_slug = '';
                $deepest_term = null;
                $max_depth = -1;

                // Find the deepest term (most specific one)
                foreach ($_terms as $t) {
                    $ancestors = get_ancestors($t->term_id, 'kategorie-maszyn');
                    $depth = count($ancestors);
                    if ($depth > $max_depth) {
                        $max_depth = $depth;
                        $deepest_term = $t;
                    }
                }

                if ($deepest_term) {
                    $term_slug = $deepest_term->slug; // Use only the slug of the deepest term
                }

                if ( !empty( $term_slug ) ) {
                    $post_link = str_replace( '%kategorie-maszyn%', $term_slug, $post_link );
                } else {
                    $post_link = str_replace( '/%kategorie-maszyn%', '', $post_link ); 
                }
            } else {
                $post_link = str_replace( '/%kategorie-maszyn%', '', $post_link );
            }
        }
        return $post_link;
    }

    /**
     * Creates initial terms for "Stan Maszyny" taxonomy.
     * Should be called on plugin activation.
     */
    public function create_initial_terms() {
        $taxonomy = 'stan-maszyny';
        $terms = [
            'Nowa'    => 'nowa',
            'Używana' => 'uzywana',
        ];

        foreach ( $terms as $name => $slug ) {
            if ( ! term_exists( $slug, $taxonomy ) ) {
                $result = wp_insert_term( $name, $taxonomy, [ 'slug' => $slug ] );
                if ( is_wp_error( $result ) ) {
                    cmu_otomoto_log( 'Failed to create term "' . $name . '" in "' . $taxonomy . '": ' . $result->get_error_message(), 'ERROR' );
                } else {
                    cmu_otomoto_log( 'Successfully created term "' . $name . '" (ID: ' . $result['term_id'] . ') in "' . $taxonomy . '" taxonomy.', 'INFO' );
                }
            }
        }
    }
}
?> ```

----------------------

class-cmu-otomoto-sync-manager.php:
```php
<?php
// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Class CMU_Otomoto_Sync_Manager
 *
 * Manages the synchronization of adverts from Otomoto API to WordPress posts.
 */
class CMU_Otomoto_Sync_Manager
{

    private $api_client;
    private $parent_wp_term_id = null;
    const PARENT_TERM_OPTION_NAME = 'cmu_otomoto_parent_term_id';

    /**
     * Constructor.
     *
     * @param CMU_Otomoto_Api_Client $api_client Instance of the API client.
     */
    public function __construct(CMU_Otomoto_Api_Client $api_client)
    {
        $this->api_client = $api_client;
        // Note: ensure_parent_wp_term_exists() might be better called explicitly 
        // once after plugin activation or from a settings page, 
        // rather than on every instantiation if it involves DB writes.
        // For now, as per plan, let's call it here or ensure it's idempotent.
        // $this->parent_wp_term_id = $this->ensure_parent_wp_term_exists(); 
        // Let's retrieve it, ensure_parent_wp_term_exists will be called by sync_adverts
        $this->parent_wp_term_id = get_option(self::PARENT_TERM_OPTION_NAME, null);
    }

    /**
     * Finds an existing post by Otomoto ID.
     *
     * @param string|int $otomoto_id The Otomoto advert ID.
     * @return int|null Post ID if found, null otherwise.
     */
    private function find_existing_post_by_otomoto_id($otomoto_id) {
        $args = [
            'post_type'      => 'maszyna-rolnicza',
            'meta_key'       => '_otomoto_id',
            'meta_value'     => $otomoto_id,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'post_status'    => 'any', // Check all statuses to handle potential duplicates in trash etc.
        ];
        $posts = get_posts($args);
        if (!empty($posts)) {
            return $posts[0];
        }
        return null;
    }

    /**
     * Ensures the main parent term for Otomoto categories exists in WordPress.
     * Creates it if not found and stores its ID in wp_options.
     *
     * @return int|null Term ID on success, null on failure.
     */
    public function ensure_parent_wp_term_exists()
    {
        // If already fetched and valid, return it.
        if ($this->parent_wp_term_id && term_exists((int) $this->parent_wp_term_id, 'kategorie-maszyn')) {
            return $this->parent_wp_term_id;
        }

        $parent_term_slug = 'maszyny-rolnicze'; // Default slug
        if (defined('OTOMOTO_MAIN_CATEGORY_SLUG_WP')) {
            $parent_term_slug_defined = constant('OTOMOTO_MAIN_CATEGORY_SLUG_WP');
            if (!empty($parent_term_slug_defined) && is_string($parent_term_slug_defined)) {
                $parent_term_slug = sanitize_title($parent_term_slug_defined);
            }
        }

        $parent_term_name = 'Używane Maszyny Rolnicze'; // As defined in the plan
        $taxonomy = 'kategorie-maszyn';

        $term = get_term_by('slug', $parent_term_slug, $taxonomy);

        if ($term instanceof WP_Term) {
            update_option(self::PARENT_TERM_OPTION_NAME, $term->term_id);
            $this->parent_wp_term_id = $term->term_id;
            cmu_otomoto_log('Parent term "' . $parent_term_name . '" already exists with ID: ' . $term->term_id . '. Option updated.', 'INFO');
            return $term->term_id;
        } else {
            cmu_otomoto_log('Parent term "' . $parent_term_name . '" (slug: ' . $parent_term_slug . ') not found. Attempting to create.', 'INFO');
            $term_data = wp_insert_term($parent_term_name, $taxonomy, ['slug' => $parent_term_slug]);

            if (is_wp_error($term_data)) {
                cmu_otomoto_log('Failed to create parent term "' . $parent_term_name . '": ' . $term_data->get_error_message(), 'ERROR');
                $this->parent_wp_term_id = null;
                delete_option(self::PARENT_TERM_OPTION_NAME); // Clear potentially stale option
                return null;
            } else {
                $this->parent_wp_term_id = $term_data['term_id'];
                update_option(self::PARENT_TERM_OPTION_NAME, $this->parent_wp_term_id);
                cmu_otomoto_log('Successfully created parent term "' . $parent_term_name . '" with ID: ' . $this->parent_wp_term_id . '. Option set.', 'INFO');
                return $this->parent_wp_term_id;
            }
        }
    }

    /**
     * Gets or creates the default fallback category "Inne maszyny rolnicze".
     *
     * @param int $parent_wp_term_id The ID of the parent term.
     * @return int|null Term ID on success, null on failure.
     */
    public function get_or_create_default_fallback_category($parent_wp_term_id)
    {
        // If $parent_wp_term_id is 0, it means we are creating a top-level fallback category.
        $log_parent_text = ($parent_wp_term_id > 0) ? 'under parent ID: ' . $parent_wp_term_id : 'as a top-level category';

        // Original check for $parent_wp_term_id might need adjustment if 0 is now a valid operational value for "top-level"
        // However, the original logic was: if ( ! $parent_wp_term_id ), which means if it's 0, null, false, etc.
        // If we now intend for 0 to mean "top-level parent", the condition `!$parent_wp_term_id` is problematic if it was meant to catch missing actual parent IDs.
        // For creating a fallback, if no specific parent is given (i.e. $parent_wp_term_id is 0 meaning top-level), that is fine.
        // The original log: 'Cannot create fallback category: Parent WP term ID is missing.' was for when a *specific non-zero parent* was expected but missing.
        // Let's adjust the logging slightly if $parent_wp_term_id is 0.

        if ($parent_wp_term_id < 0) { // Only error if it's explicitly invalid, allow 0 for top-level.
            cmu_otomoto_log('Cannot create fallback category: Invalid Parent WP term ID provided: ' . $parent_wp_term_id, 'ERROR');
            return null;
        }

        $fallback_term_name = 'Inne maszyny rolnicze';
        $fallback_term_slug = 'inne-maszyny-rolnicze';
        $taxonomy = 'kategorie-maszyn';

        // Check if term exists as a child of $parent_wp_term_id
        $existing_terms = get_terms([
            'taxonomy'   => $taxonomy,
            'slug'       => $fallback_term_slug,
            'parent'     => $parent_wp_term_id,
            'hide_empty' => false,
        ]);

        if (! empty($existing_terms) && ! is_wp_error($existing_terms)) {
            $term_id = $existing_terms[0]->term_id;
            cmu_otomoto_log('Default fallback category "' . $fallback_term_name . '" already exists with ID: ' . $term_id . '.', 'INFO');
            return $term_id;
        }

        cmu_otomoto_log('Default fallback category "' . $fallback_term_name . '" not found. Attempting to create under parent ID: ' . $parent_wp_term_id . '.', 'INFO');
        $term_data = wp_insert_term(
            $fallback_term_name,
            $taxonomy,
            [
                'slug'   => $fallback_term_slug,
                'parent' => $parent_wp_term_id,
            ]
        );

        if (is_wp_error($term_data)) {
            cmu_otomoto_log('Failed to create default fallback category "' . $fallback_term_name . '" ' . $log_parent_text . ': ' . $term_data->get_error_message(), 'ERROR', ['parent_id' => $parent_wp_term_id]);
            return null;
        } else {
            $term_id = $term_data['term_id'];
            cmu_otomoto_log('Successfully created default fallback category "' . $fallback_term_name . '" with ID: ' . $term_id . ' ' . $log_parent_text . '.', 'INFO');
            return $term_id;
        }
    }

    /**
     * Gets or creates a WordPress term for a given Otomoto category ID.
     *
     * @param int $otomoto_category_id The Otomoto category ID.
     * @param int $parent_wp_term_id The ID of the parent WordPress term.
     * @return int|null Term ID on success, null on failure.
     */
    public function get_or_create_wp_term_for_otomoto_category($otomoto_category_id, $parent_wp_term_id)
    {
        // $parent_wp_term_id is the ID of "Używane Maszyny Rolnicze" term.
        // We want categories like "Kombajny" to be top-level to achieve /maszyny-rolnicze/kombajny/
        // So, we will ignore the passed $parent_wp_term_id for creating these primary categories from API.
        // It might be used later if we have sub-sub-categories from API or for other organizational purposes.

        // For now, all Otomoto categories will be top-level within 'kategorie-maszyn'.
        $actual_parent_id_for_wp_term = 0;

        // Original validation for otomoto_category_id remains important.
        if (empty($otomoto_category_id)) {
            cmu_otomoto_log('Cannot get/create WP term: Otomoto category ID is missing. Attempting to use default fallback (top-level).', 'ERROR');
            // The fallback category should also be top-level if primary categories are top-level.
            return $this->get_or_create_default_fallback_category(0 /* parent_id = 0 for top level */);
        }

        $taxonomy = 'kategorie-maszyn';

        // Check if term with this Otomoto ID already exists (as a top-level term)
        $args = [
            'taxonomy'   => $taxonomy,
            'parent'     => $actual_parent_id_for_wp_term, // Search for top-level terms
            'hide_empty' => false,
            'meta_query' => [
                [
                    'key'   => '_otomoto_category_id',
                    'value' => $otomoto_category_id,
                ],
            ],
        ];
        $existing_terms = get_terms($args);

        if (! empty($existing_terms) && ! is_wp_error($existing_terms)) {
            $term_id = $existing_terms[0]->term_id;
            cmu_otomoto_log('Top-level term for Otomoto category ID ' . $otomoto_category_id . ' already exists in WP with ID: ' . $term_id . '.', 'INFO');
            return $term_id;
        }

        // Term does not exist, try to fetch details from API and create it as a top-level term
        cmu_otomoto_log('Top-level term for Otomoto category ID ' . $otomoto_category_id . ' not found. Fetching details from API.', 'INFO');
        $cat_details = $this->api_client->get_otomoto_category_details($otomoto_category_id);

        if (is_wp_error($cat_details) || empty($cat_details['names']['pl'])) {
            $error_message = is_wp_error($cat_details) ? $cat_details->get_error_message() : 'empty or missing Polish name';
            cmu_otomoto_log('Failed to fetch category details or Polish name missing for Otomoto category ID ' . $otomoto_category_id . '. Details: ' . $error_message . '. Using fallback category (top-level).', 'WARNING');
            return $this->get_or_create_default_fallback_category(0 /* parent_id = 0 for top level */);
        }

        $term_name = sanitize_text_field($cat_details['names']['pl']);
        $term_slug_base = ! empty($cat_details['code']) ? $cat_details['code'] : $term_name;
        $term_slug = sanitize_title($term_slug_base);

        /**
         * Filtruje proponowany slug dla kategorii Otomoto.
         *
         * @param string $term_slug Proponowany slug (już po sanitize_title).
         * @param int    $otomoto_category_id ID kategorii Otomoto.
         * @param string $term_name           Nazwa kategorii.
         * @param array  $cat_details         Szczegóły kategorii z API Otomoto.
         */
        $term_slug = apply_filters('cmu_otomoto_category_slug', $term_slug, $otomoto_category_id, $term_name, $cat_details);

        // Ensure slug is unique for a top-level term
        $unique_term_slug = $term_slug;
        $counter = 1;
        // Check if this specific slug exists as a top-level term.
        // We are trying to avoid situations like 'kombajny' and 'kombajny-1' for DIFFERENT Otomoto categories.
        // But if 'kombajny' (from Otomoto ID 99) exists, and we are now processing Otomoto ID 100 which also wants 'kombajny',
        // then 'kombajny-1' for Otomoto ID 100 is correct.
        // The primary check via _otomoto_category_id meta should prevent creating duplicates for the SAME Otomoto category.
        // This loop is more about handling distinct Otomoto categories that might naturally have the same name/code.
        while (term_exists($unique_term_slug, $taxonomy, $actual_parent_id_for_wp_term)) {
            $unique_term_slug = $term_slug . '-' . $counter++;
        }

        cmu_otomoto_log('Attempting to create new top-level WP term "' . $term_name . '" (slug: ' . $unique_term_slug . ') for Otomoto category ID ' . $otomoto_category_id . '.', 'INFO');
        $term_data = wp_insert_term(
            $term_name,
            $taxonomy,
            [
                'slug'   => $unique_term_slug,
                'parent' => $actual_parent_id_for_wp_term, // This will be 0 for top-level
            ]
        );

        if (is_wp_error($term_data)) {
            cmu_otomoto_log('Failed to create top-level WP term "' . $term_name . '" (slug: ' . $unique_term_slug . '): ' . $term_data->get_error_message() . '. Using fallback category (top-level).', 'ERROR', ['otomoto_category_id' => $otomoto_category_id]);
            return $this->get_or_create_default_fallback_category(0 /* parent_id = 0 for top level */);
        } else {
            $term_id = $term_data['term_id'];
            update_term_meta($term_id, '_otomoto_category_id', $otomoto_category_id);
            $log_message = 'Successfully created top-level WP term "' . $term_name . '" with ID: ' . $term_id . ' for Otomoto category ID ' . $otomoto_category_id . '. Meta _otomoto_category_id set to ' . $otomoto_category_id;

            if (! empty($cat_details['code'])) {
                update_term_meta($term_id, '_otomoto_category_code', $cat_details['code']);
                $log_message .= ', Meta _otomoto_category_code set to ' . $cat_details['code'];
            }
            $log_message .= '.';
            cmu_otomoto_log($log_message, 'INFO');
            return $term_id;
        }
    }

    /**
     * Assigns WordPress category and machine state (new/used) to a post.
     *
     * @param int    $post_id The ID of the WordPress post.
     * @param int    $otomoto_category_id The Otomoto category ID.
     * @param string $new_used_status The machine status ('new' or 'used').
     * @param int    $parent_wp_term_id The ID of the parent WordPress term for categories.
     */
    public function assign_wp_category_and_state($post_id, $otomoto_category_id, $new_used_status, $parent_wp_term_id)
    {
        if (! $post_id) {
            cmu_otomoto_log('Cannot assign category/state: Post ID is missing.', 'ERROR');
            return;
        }

        // Assign category
        $wp_term_id = $this->get_or_create_wp_term_for_otomoto_category($otomoto_category_id, $parent_wp_term_id);
        if ($wp_term_id) {
            $result = wp_set_object_terms($post_id, (int) $wp_term_id, 'kategorie-maszyn', false); // false = overwrite
            if (is_wp_error($result)) {
                cmu_otomoto_log('Failed to assign WP category ID ' . $wp_term_id . ' to post ID ' . $post_id . ': ' . $result->get_error_message(), 'ERROR');
            } else {
                cmu_otomoto_log('Successfully assigned WP category ID ' . $wp_term_id . ' to post ID ' . $post_id . '.', 'INFO');
            }
        } else {
            cmu_otomoto_log('Could not assign WP category to post ID ' . $post_id . ' as term ID was not obtained for Otomoto category ID ' . $otomoto_category_id . '.', 'WARNING');
        }

        // Assign state
        $stan_taxonomy = 'stan-maszyny';
        $stan_term_slug = (strtolower((string) $new_used_status) === 'used') ? 'uzywana' : 'nowa';

        // Ensure the term exists before trying to assign it.
        // The CMU_Otomoto_Post_Type::create_initial_terms() should have created these.
        $term_exists = term_exists($stan_term_slug, $stan_taxonomy);
        if (! $term_exists) {
            cmu_otomoto_log('Term slug "' . $stan_term_slug . '" does not exist in taxonomy "' . $stan_taxonomy . '". Cannot assign state to post ID ' . $post_id . '.', 'ERROR');
        } else {
            $term_id_to_assign = is_array($term_exists) ? $term_exists['term_id'] : $term_exists;
            $result = wp_set_object_terms($post_id, (int) $term_id_to_assign, $stan_taxonomy, false); // Overwrite, assuming one state
            if (is_wp_error($result)) {
                cmu_otomoto_log('Failed to assign state "' . $stan_term_slug . '" to post ID ' . $post_id . ': ' . $result->get_error_message(), 'ERROR');
            } else {
                cmu_otomoto_log('Successfully assigned state "' . $stan_term_slug . '" to post ID ' . $post_id . '.', 'INFO');
            }
        }
    }

    /**
     * Prepares post data and meta for creation or update.
     *
     * @param array $advert_data Data for a single advert from Otomoto API.
     * @param int   $post_id (Optional) Existing post ID if updating.
     * @return array Prepared post_args and meta_input for wp_insert_post or wp_update_post.
     */
    private function prepare_post_and_meta_data($advert_data, $post_id = null) {
        $otomoto_advert_id = $advert_data['id'];
        $post_title        = sanitize_text_field($advert_data['title']);
        $post_content_base = isset($advert_data['description']) ? wp_kses_post($advert_data['description']) : '';

        $post_args = [
            'post_title'   => $post_title,
            'post_content' => $post_content_base, // Base content, params will be appended by a helper
            'post_status'  => 'publish',
            'post_type'    => 'maszyna-rolnicza',
        ];
        if ($post_id) {
            $post_args['ID'] = $post_id;
        }

        $meta_input = [
            '_otomoto_id'                   => $otomoto_advert_id,
            '_otomoto_url'                  => isset($advert_data['url']) ? esc_url_raw($advert_data['url']) : '',
            '_otomoto_category_id_from_api' => $advert_data['category_id'] ?? null,
            '_otomoto_last_sync'            => current_time('mysql'),
            // '_otomoto_is_edited_manually' is managed by a save_post hook, not directly here
        ];

        $params = $advert_data['params'] ?? [];

        if (isset($params['make'])) $meta_input['_otomoto_make'] = sanitize_text_field($params['make']);
        if (isset($params['model'])) $meta_input['_otomoto_model'] = sanitize_text_field($params['model']);
        if (isset($params['year'])) $meta_input['_otomoto_year'] = intval($params['year']);

        if (isset($params['price']) && is_array($params['price'])) {
            $price_details = $params['price'];
            if (isset($price_details[1])) $meta_input['_otomoto_price_value'] = filter_var(str_replace(',', '.', $price_details[1]), FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            if (isset($price_details['currency'])) $meta_input['_otomoto_price_currency'] = sanitize_text_field($price_details['currency']);
            if (isset($price_details['gross_net'])) $meta_input['_otomoto_price_gross_net'] = sanitize_text_field($price_details['gross_net']);
            if (isset($price_details[0])) $meta_input['_otomoto_price_type'] = sanitize_text_field($price_details[0]);
        }

        if (isset($params['lifetime'])) {
            $meta_input['_otomoto_hours'] = sanitize_text_field($params['lifetime']) . ' mth';
        } elseif (isset($params['mileage']) && !empty($params['mileage']) && is_numeric($params['mileage'])) {
            $meta_input['_otomoto_hours'] = sanitize_text_field($params['mileage']) . ' mth';
        }
        
        // Add more meta fields as needed from the original create_otomoto_post logic
        if (isset($params['fuel_type'])) {
            $fuel_type_map = [
                'diesel' => 'Diesel', 'petrol' => 'Benzyna', 'lpg' => 'LPG',
                'petrol-lpg' => 'Benzyna+LPG', 'hybrid' => 'Hybryda', 'electric' => 'Elektryczny',
            ];
            $fuel_type_key = strtolower(sanitize_text_field($params['fuel_type']));
            $meta_input['_otomoto_fuel_type'] = $fuel_type_map[$fuel_type_key] ?? ucfirst($fuel_type_key);
        }

        if (isset($params['engine_capacity'])) {
            $capacity_str = str_replace([' ', 'cm3', 'cc'], '', $params['engine_capacity']);
            $capacity_str = str_replace(',', '.', $capacity_str);
            if (is_numeric($capacity_str)) {
                $capacity = floatval($capacity_str);
                if ($capacity > 0 && $capacity < 1000) { 
                    $meta_input['_otomoto_engine_capacity_display'] = ($capacity > 200) ? round($capacity) . ' cm³' : number_format_i18n($capacity, 1) . ' l';
                } elseif ($capacity >= 1000) {
                    $meta_input['_otomoto_engine_capacity_display'] = number_format_i18n($capacity / 1000, 1) . ' l';
                } else {
                    $meta_input['_otomoto_engine_capacity_display'] = sanitize_text_field($params['engine_capacity']);
                }
            } else {
                $meta_input['_otomoto_engine_capacity_display'] = sanitize_text_field($params['engine_capacity']);
            }
        }

        if (isset($params['engine_power'])) {
            $power_str = preg_replace('/[^0-9.]/', '', $params['engine_power']);
            if (is_numeric($power_str) && floatval($power_str) > 0) {
                $meta_input['_otomoto_engine_power_display'] = round(floatval($power_str)) . ' KM';
            } else {
                $meta_input['_otomoto_engine_power_display'] = sanitize_text_field($params['engine_power']);
            }
        }

        if (isset($params['gearbox'])) {
            $gearbox_map = [
                'manual' => 'Manualna', 'automatic' => 'Automatyczna', 'semi-automatic' => 'Półautomatyczna',
            ];
            $gearbox_key = strtolower(sanitize_text_field($params['gearbox']));
            $meta_input['_otomoto_gearbox_display'] = $gearbox_map[$gearbox_key] ?? ucfirst($gearbox_key);
        }

        if (isset($params['country_origin'])) {
            $meta_input['_otomoto_origin'] = sanitize_text_field($params['country_origin']);
        }
        // Handle appending other params to content
        $additional_content_html = $this->generate_additional_params_html($params);
        $post_args['post_content'] .= $additional_content_html;

        return ['post_args' => $post_args, 'meta_input' => $meta_input];
    }

    /**
     * Generates HTML for additional parameters to be appended to post content.
     *
     * @param array $api_params Parameters from Otomoto API.
     * @return string HTML string of additional parameters.
     */
    private function generate_additional_params_html($api_params) {
        $other_params_for_content = [];
        $handled_param_keys = apply_filters('cmu_otomoto_handled_param_keys_for_meta', [
            'make', 'model', 'year', 'price', 'lifetime', 'mileage',
            'fuel_type', 'engine_capacity', 'engine_power', 'gearbox', 'country_origin',
            'vin', 'color', 'door_count', 'generation', 'version', 'body_type', 
            'cepik_authorization', 'video'
        ]);

        foreach ($api_params as $key => $value) {
            if (in_array(strtolower($key), array_map('strtolower', $handled_param_keys))) {
                continue;
            }
            if (is_array($value) && $key === 'price') continue; // Already handled

            $param_label = ucfirst(str_replace('_', ' ', sanitize_key($key)));
            $param_value_str = '';

            if (is_array($value)) {
                $param_value_str = implode(', ', array_map('sanitize_text_field', $value));
            } else if (is_bool($value)) {
                $param_value_str = $value ? __('Tak', 'cmu-otomoto-integration') : __('Nie', 'cmu-otomoto-integration');
            } else if (!empty($value) || $value === 0 || $value === '0') { // Allow '0' as a valid value
                $param_value_str = sanitize_text_field((string) $value);
            } else {
                continue; // Skip truly empty values
            }
            $other_params_for_content[$param_label] = $param_value_str;
        }

        if (empty($other_params_for_content)) {
            return '';
        }

        $html = "\n\n<hr class=\"otomoto-additional-info-separator\">\n<h3 class=\"otomoto-additional-info-title\">" . __('Dodatkowe informacje', 'cmu-otomoto-integration') . "</h3>\n<ul class=\"otomoto-additional-info-list\">\n";
        foreach ($other_params_for_content as $label => $val) {
            $html .= "<li><strong>" . esc_html($label) . ":</strong> " . esc_html($val) . "</li>\n";
        }
        $html .= "</ul>";
        return $html;
    }

    /**
     * Creates a new WordPress post for an Otomoto advert.
     *
     * @param array $advert_data Data for a single advert from Otomoto API.
     * @param int   $parent_wp_term_id The ID of the parent WordPress term for categories.
     * @return int|WP_Error Post ID on success, WP_Error on failure.
     */
    public function create_otomoto_post($advert_data, $parent_wp_term_id)
    {
        $otomoto_advert_id = $advert_data['id']; // Validation already done in process_single_advert_data
        cmu_otomoto_log('Preparing to insert new post for Otomoto advert ID: ' . $otomoto_advert_id, 'INFO', ['post_title' => $advert_data['title']]);

        $prepared_data = $this->prepare_post_and_meta_data($advert_data);
        $post_args = $prepared_data['post_args'];
        $meta_input = $prepared_data['meta_input'];
        $post_args['meta_input'] = $meta_input; // wp_insert_post handles meta_input

        $post_id = wp_insert_post($post_args, true);

        if (is_wp_error($post_id)) {
            cmu_otomoto_log('Failed to insert post for Otomoto advert ID: ' . $otomoto_advert_id . '. Error: ' . $post_id->get_error_message(), 'ERROR', ['post_args_keys' => array_keys($post_args)]);
            return $post_id;
        }

        cmu_otomoto_log('Successfully inserted post ID: ' . $post_id . ' for Otomoto advert ID: ' . $otomoto_advert_id, 'INFO');
        
        // Handle images
        if (isset($advert_data['photos']) && !empty($advert_data['photos'])) {
            $this->handle_advert_images($post_id, $advert_data['photos'], $otomoto_advert_id, $post_args['post_title']);
        }

        // Assign category and state
        if (isset($advert_data['category_id']) && isset($advert_data['new_used'])) {
            $this->assign_wp_category_and_state($post_id, $advert_data['category_id'], $advert_data['new_used'], $this->parent_wp_term_id); // Use $this->parent_wp_term_id
        }

        return $post_id;
    }

    /**
     * Updates an existing WordPress post with data from an Otomoto advert.
     *
     * @param int   $post_id The ID of the WordPress post to update.
     * @param array $advert_data Data for a single advert from Otomoto API.
     * @param int   $parent_wp_term_id The ID of the parent WordPress term for categories.
     * @param bool  $force_update Force update even if no changes detected.
     * @return int|string|WP_Error Post ID on success, 'skipped_no_changes'/'skipped_manual_edit' if skipped, WP_Error on failure.
     */
    public function update_otomoto_post($post_id, $advert_data, $parent_wp_term_id, $force_update = false) {
        cmu_otomoto_log('Preparing to update post ID: ' . $post_id . ' for Otomoto advert ID: ' . $advert_data['id'], 'INFO', ['force_update' => $force_update]);

        $is_manually_edited = get_post_meta($post_id, '_otomoto_is_edited_manually', true);
        if ($is_manually_edited && !$force_update) {
            cmu_otomoto_log('Update skipped for post ID ' . $post_id . ' (Otomoto ID: ' . $advert_data['id'] . '): Manually edited, not forcing update.', 'INFO');
            return 'skipped_manual_edit';
        }

        $otomoto_last_modified_api = $advert_data['last_update_date'] ?? null;
        $wp_last_sync = get_post_meta($post_id, '_otomoto_last_sync', true);
        
        $needs_update = $force_update;
        if (!$needs_update && $otomoto_last_modified_api && $wp_last_sync) {
            if (strtotime($otomoto_last_modified_api) > strtotime($wp_last_sync)) {
                $needs_update = true;
                cmu_otomoto_log("Post ID $post_id: Needs update based on last_update_date from API.", 'INFO');
            }
        } elseif (!$needs_update) { 
            $current_post_data_prepared = $this->prepare_post_and_meta_data($advert_data, $post_id); 
            $current_wp_post = get_post($post_id);
            
            if ($current_wp_post->post_title !== $current_post_data_prepared['post_args']['post_title']) {
                $needs_update = true;
                cmu_otomoto_log("Post ID $post_id: Title changed.", 'INFO');
            }
            
            $api_base_desc = isset($advert_data['description']) ? wp_kses_post($advert_data['description']) : '';
            $current_content_base = $current_wp_post->post_content;
            $separator_pos = strpos($current_content_base, "\n\n<hr class=\"otomoto-additional-info-separator\">");
            if ($separator_pos !== false) {
                $current_content_base = substr($current_content_base, 0, $separator_pos);
            }
            if (trim($current_content_base) !== trim($api_base_desc)) { // Added trim for safer comparison
                $needs_update = true;
                 cmu_otomoto_log("Post ID $post_id: Base description changed.", 'INFO', ['current_desc_len' => strlen(trim($current_content_base)), 'api_desc_len' => strlen(trim($api_base_desc))]);
            }
            
            $meta_fields_to_check = ['_otomoto_price_value', '_otomoto_year', '_otomoto_make', '_otomoto_model', '_otomoto_hours', '_otomoto_price_currency', '_otomoto_price_gross_net', '_otomoto_price_type'];
            foreach ($meta_fields_to_check as $meta_key) {
                $current_meta_value = get_post_meta($post_id, $meta_key, true);
                $new_meta_value = $current_post_data_prepared['meta_input'][$meta_key] ?? null;
                
                // Normalize for comparison (e.g. numeric might be string vs int, float precision)
                if (is_numeric($new_meta_value) && is_numeric($current_meta_value)) {
                    if (abs((float)$current_meta_value - (float)$new_meta_value) > 0.001) { // Compare floats with tolerance
                        $needs_update = true;
                        cmu_otomoto_log("Post ID $post_id: Numeric meta field '$meta_key' changed. Old: '$current_meta_value', New: '$new_meta_value'", 'INFO');
                        break;
                    }
                } elseif ((string)$current_meta_value !== (string)$new_meta_value) {
                    $needs_update = true;
                    cmu_otomoto_log("Post ID $post_id: Meta field '$meta_key' changed. Old: '$current_meta_value', New: '$new_meta_value'", 'INFO');
                    break;
                }
            }
        }

        if (!$needs_update) {
            // Before returning 'skipped_no_changes', ensure _otomoto_last_sync is updated if it was missing, to avoid re-checking content next time
            if (empty($wp_last_sync)) {
                update_post_meta($post_id, '_otomoto_last_sync', current_time('mysql'));
                 cmu_otomoto_log('Post ID ' . $post_id . ': No changes detected, but updated missing _otomoto_last_sync.', 'INFO');
            }
            return 'skipped_no_changes';
        }

        $prepared_data = $this->prepare_post_and_meta_data($advert_data, $post_id);
        $post_args = $prepared_data['post_args'];
        $meta_input = $prepared_data['meta_input'];
        
        // To ensure removed API params are also removed from WP meta, we should ideally get all _otomoto_* meta
        // and remove those not present in the new $meta_input.
        // For simplicity now, as per plan: clear specific meta keys that are part of $meta_input before update.
        // This is a bit broad but safer than missing a removed field.
        $all_possible_otomoto_meta_keys = array_keys($meta_input); 
        // Add other known meta keys that might be set by prepare_post_and_meta_data
        // This should ideally be more dynamic or use a prefix to get all _otomoto_ meta.
        $additional_known_keys = ['_otomoto_fuel_type', '_otomoto_engine_capacity_display', '_otomoto_engine_power_display', '_otomoto_gearbox_display', '_otomoto_origin'];
        $all_possible_otomoto_meta_keys = array_merge($all_possible_otomoto_meta_keys, $additional_known_keys);

        $existing_meta = get_post_meta($post_id);
        foreach ($existing_meta as $key => $value) {
            if (strpos($key, '_otomoto_') === 0) { // Check if it's an otomoto meta key
                if (!array_key_exists($key, $meta_input)) { // If this key is not in the new meta set, delete it
                    delete_post_meta($post_id, $key);
                    cmu_otomoto_log("Post ID $post_id: Removed old meta field '$key' as it's not in new API data.", 'INFO');
                }
            }
        }
        
        $post_args['meta_input'] = $meta_input; // wp_update_post will handle setting these

        $updated_post_id = wp_update_post($post_args, true);

        if (is_wp_error($updated_post_id)) {
            cmu_otomoto_log('Failed to update post ID: ' . $post_id . '. Error: ' . $updated_post_id->get_error_message(), 'ERROR', ['post_args_keys' => array_keys($post_args)]);
            return $updated_post_id;
        }
        cmu_otomoto_log('Successfully updated post core data & meta for post ID: ' . $post_id, 'INFO');
        
        // Clear the manual edit flag after a successful sync update, unless force_update was used for other reasons
        // If force_update was true AND is_manually_edited was true, we respect the manual edit and don't clear the flag.
        // The goal is: if API changed and we updated, clear the flag. If user changed and API didn't, flag remains.
        // If force_update is true, it means we're overriding everything.
        // So, if it was manually edited AND we are NOT forcing update, it would have been skipped.
        // If it was manually edited AND we ARE forcing update, we clear the flag after update.
        // If it was NOT manually edited, and we update (either by date or content change), clear the flag.
        if ($force_update || !$is_manually_edited) {
            delete_post_meta($post_id, '_otomoto_is_edited_manually');
             cmu_otomoto_log("Post ID $post_id: Cleared manual edit flag after successful sync update.", 'INFO');
        }


        // Handle images: Delete old gallery images and featured image, then re-add.
        $existing_gallery_ids = get_post_meta($post_id, '_otomoto_gallery_ids', true);
        if (is_array($existing_gallery_ids)) {
            foreach ($existing_gallery_ids as $att_id) {
                wp_delete_attachment($att_id, true); // true to force delete from disk
            }
        }
        delete_post_meta($post_id, '_otomoto_gallery_ids'); // Clear the meta field
        delete_post_thumbnail($post_id); // Remove featured image

        if (isset($advert_data['photos']) && !empty($advert_data['photos'])) {
            $this->handle_advert_images($post_id, $advert_data['photos'], $advert_data['id'], $post_args['post_title']);
        }

        // Assign category and state (this will overwrite existing)
        if (isset($advert_data['category_id']) && isset($advert_data['new_used'])) {
            $this->assign_wp_category_and_state($post_id, $advert_data['category_id'], $advert_data['new_used'], $this->parent_wp_term_id); // Use $this->parent_wp_term_id
        }
        
        return $post_id;
    }

    /**
     * Processes a single advert data from Otomoto (basic version - only creation).
     *
     * @param array $advert_data Data of the advert.
     * @param bool  $force_update_all (Not used in this basic version).
     * @param int   $parent_wp_term_id The ID of the parent WordPress term for categories.
     */
    public function process_single_advert_data($advert_data, $force_update_all, $parent_wp_term_id, &$sync_summary_ref)
    {
        $otomoto_advert_id = $advert_data['id'] ?? null;

        // --- Initial Validations ---
        if (empty($otomoto_advert_id)) {
            cmu_otomoto_log('Skipping advert: Advert ID is missing.', 'ERROR', ['advert_data_sample' => array_slice((array) $advert_data, 0, 5, true)]);
            $sync_summary_ref['errors_encountered']++;
            return ['status' => 'error_missing_id', 'otomoto_id' => null];
        }

        // Filter by status "active" - CRITICAL
        if (!(isset($advert_data['status']) && $advert_data['status'] === 'active')) {
            cmu_otomoto_log('Skipping advert ID ' . $otomoto_advert_id . ': Status is not "active". Status: ' . ($advert_data['status'] ?? 'N/A'), 'INFO', ['title' => $advert_data['title'] ?? 'N/A']);
            $sync_summary_ref['posts_skipped_inactive_status']++;
            return ['status' => 'skipped_inactive', 'otomoto_id' => $otomoto_advert_id];
        }
        
        // Filter by non-empty title - CRITICAL
        if (empty(trim($advert_data['title'] ?? ''))) {
            cmu_otomoto_log('Skipping advert ID ' . $otomoto_advert_id . ': Title is missing or empty.', 'ERROR', ['otomoto_id' => $otomoto_advert_id]);
            $sync_summary_ref['posts_skipped_no_title']++;
            $sync_summary_ref['errors_encountered']++; // Treat as an error
            return ['status' => 'error_missing_title', 'otomoto_id' => $otomoto_advert_id];
        }

        // Filter by new_used status - only 'used' (as per current plugin logic)
        $new_used_status = strtolower((string) ($advert_data['new_used'] ?? ''));
        if ($new_used_status !== 'used') {
            cmu_otomoto_log('Skipping advert ID ' . $otomoto_advert_id . ' (not marked as "used"): ' . ($advert_data['title'] ?? '[No Title]'), 'INFO');
            $sync_summary_ref['posts_skipped_not_used']++;
            return ['status' => 'skipped_not_used', 'otomoto_id' => $otomoto_advert_id];
        }
        // --- End Initial Validations ---

        $existing_post_id = $this->find_existing_post_by_otomoto_id($otomoto_advert_id);

        if ($existing_post_id) {
            // Post exists, attempt to update it
            cmu_otomoto_log('Post for Otomoto advert ID ' . $otomoto_advert_id . ' exists (WP Post ID: ' . $existing_post_id . '). Attempting update.', 'INFO');
            // Pass $this->parent_wp_term_id instead of $parent_wp_term_id from argument, as it's a class property now.
            $update_status = $this->update_otomoto_post($existing_post_id, $advert_data, $this->parent_wp_term_id, $force_update_all);
            
            if (is_wp_error($update_status)) {
                $sync_summary_ref['errors_encountered']++;
                cmu_otomoto_log('Error updating post ID ' . $existing_post_id . ': ' . $update_status->get_error_message(), 'ERROR');
                return ['status' => 'error_updating', 'otomoto_id' => $otomoto_advert_id, 'post_id' => $existing_post_id];
            } elseif ($update_status === 'skipped_no_changes') {
                $sync_summary_ref['posts_skipped_no_changes']++;
                 cmu_otomoto_log('Update skipped for post ID ' . $existing_post_id . ' (Otomoto ID: ' . $otomoto_advert_id . '): No changes detected.', 'INFO');
                return ['status' => 'skipped_no_changes', 'otomoto_id' => $otomoto_advert_id, 'post_id' => $existing_post_id];
            } elseif ($update_status === 'skipped_manual_edit') {
                $sync_summary_ref['posts_skipped_manual_edit']++;
                cmu_otomoto_log('Update skipped for post ID ' . $existing_post_id . ' (Otomoto ID: ' . $otomoto_advert_id . '): Manually edited, not forcing update.', 'INFO');
                return ['status' => 'skipped_manual_edit', 'otomoto_id' => $otomoto_advert_id, 'post_id' => $existing_post_id];
            } else { // Assuming $update_status is the post ID on successful update
                $sync_summary_ref['posts_updated']++;
                return ['status' => 'updated', 'otomoto_id' => $otomoto_advert_id, 'post_id' => $existing_post_id];
            }
        } else {
            // Post does not exist, create it
            cmu_otomoto_log('Creating new post for Otomoto advert ID: ' . $otomoto_advert_id . ' (Title: ' . ($advert_data['title'] ?? '[No Title]') . ')', 'INFO');
             // Pass $this->parent_wp_term_id
            $created_post_id = $this->create_otomoto_post($advert_data, $this->parent_wp_term_id);
            
            if (is_wp_error($created_post_id)) {
                $sync_summary_ref['errors_encountered']++;
                cmu_otomoto_log('Error creating post for Otomoto ID ' . $otomoto_advert_id . ': ' . $created_post_id->get_error_message(), 'ERROR');
                return ['status' => 'error_creating', 'otomoto_id' => $otomoto_advert_id];
            } else {
                $sync_summary_ref['posts_created']++;
                return ['status' => 'created', 'otomoto_id' => $otomoto_advert_id, 'post_id' => $created_post_id];
            }
        }
    }

    /**
     * Main synchronization method.
     *
     * @param bool  $force_update_all Force update even if Otomoto update date is not newer.
     * @param int   $parent_wp_term_id The ID of the parent WordPress term for categories.
     * @param array &$sync_summary_ref Reference to the sync summary array for updating counters.
     * @return array Status of the operation.
     */
    public function sync_adverts($force_update_all = false)
    {
        cmu_otomoto_log('Starting Otomoto adverts synchronization.', 'INFO', ['force_update' => $force_update_all]);

        $processed_active_otomoto_ids_in_current_sync = [];
        $sync_summary = [
            'total_pages_fetched' => 0,
            'total_adverts_processed_from_api' => 0,
            'posts_created' => 0,
            'posts_updated' => 0,
            'posts_skipped_not_used' => 0,
            'posts_skipped_inactive_status' => 0,
            'posts_skipped_no_title' => 0,
            'posts_skipped_no_changes' => 0,
            'posts_skipped_manual_edit' => 0,
            'categories_created' => 0,
            'errors_encountered' => 0,
            'posts_deleted_as_inactive_in_otomoto' => 0,
            'adverts_sample' => []
        ];

        if (!$this->parent_wp_term_id) {
            $this->parent_wp_term_id = $this->ensure_parent_wp_term_exists();
        }
        
        if (! $this->parent_wp_term_id) {
            $message = 'Synchronization aborted: Could not ensure parent WP term exists.';
            cmu_otomoto_log($message, 'ERROR');
            $sync_summary['errors_encountered']++;
            $sync_summary['adverts_sample'] = []; 
            return ['status' => 'error', 'message' => $message, 'summary' => $sync_summary, 'adverts_sample' => []];
        }
        cmu_otomoto_log('Using parent WP term ID for categories: ' . $this->parent_wp_term_id, 'INFO');


        $current_page = 1;
        $max_pages_to_fetch = 100; // Safety limit for API pagination

        // DEV_NOTE_LIMIT_ADVERTS: Limit the number of *processed* (created/updated) active adverts.
        // Set to 0 or a very high number for production.
        // FINAL_GOAL: Remove or set to a very high number for production.
        $dev_max_processed_active_adverts_limit = 50; 
        $processed_active_adverts_count = 0;
        $all_fetched_adverts_data_sample = [];

        while ($current_page <= $max_pages_to_fetch) {
            if ($dev_max_processed_active_adverts_limit > 0 && $processed_active_adverts_count >= $dev_max_processed_active_adverts_limit) {
                cmu_otomoto_log('Development limit of ' . $dev_max_processed_active_adverts_limit . ' processed active adverts reached. Stopping API fetching.', 'INFO');
                break;
            }

            cmu_otomoto_log('Fetching page ' . $current_page . ' from Otomoto API.', 'INFO');
            $api_limit_per_page = apply_filters('cmu_otomoto_api_adverts_per_page', 10);

            $adverts_page_data = $this->api_client->get_adverts($current_page, $api_limit_per_page);
            $sync_summary['total_pages_fetched'] = $current_page;

            if (is_wp_error($adverts_page_data)) {
                $error_message = 'Error fetching adverts from API on page ' . $current_page . ': ' . $adverts_page_data->get_error_message();
                cmu_otomoto_log($error_message . '. Synchronization stopped.', 'ERROR');
                $sync_summary['errors_encountered']++;
                $sync_summary['adverts_sample'] = array_slice($all_fetched_adverts_data_sample, 0, 50);
                return [
                    'status' => !empty($all_fetched_adverts_data_sample) ? 'partial_error' : 'error',
                    'message' => $error_message,
                    'summary' => $sync_summary,
                    'adverts_sample' => $sync_summary['adverts_sample']
                ];
            }

            if (empty($adverts_page_data)) {
                cmu_otomoto_log('No more adverts found on page ' . $current_page . '. Ending synchronization.', 'INFO');
                break; 
            }
            
            // Collect sample for summary (max 50 items)
            if (count($all_fetched_adverts_data_sample) < 50) {
                 $needed_sample_items = 50 - count($all_fetched_adverts_data_sample);
                 $all_fetched_adverts_data_sample = array_merge($all_fetched_adverts_data_sample, array_slice($adverts_page_data, 0, $needed_sample_items));
            }
            // $sync_summary['total_adverts_processed_from_api'] should count items that passed initial API fetch,
            // not items that were fully processed by process_single_advert_data.
            // The name "total_adverts_processed_from_api" can be misleading.
            // Let's rename to total_adverts_fetched_from_api for clarity.
            // And add a counter for how many were *attempted* to be processed by process_single_advert_data.
            // For now, keeping the original name and incrementing by count($adverts_page_data).
            $sync_summary['total_adverts_processed_from_api'] += count($adverts_page_data);


            foreach ($adverts_page_data as $advert_data) {
                if ($dev_max_processed_active_adverts_limit > 0 && $processed_active_adverts_count >= $dev_max_processed_active_adverts_limit) {
                    cmu_otomoto_log('Development limit of ' . $dev_max_processed_active_adverts_limit . ' processed active adverts reached during page processing. Breaking from this page.', 'INFO');
                    break; // Break from foreach loop for this page
                }
                
                $result = $this->process_single_advert_data($advert_data, $force_update_all, $this->parent_wp_term_id, $sync_summary);

                if (isset($result['otomoto_id']) && !empty($result['otomoto_id']) && ($result['status'] === 'created' || $result['status'] === 'updated')) {
                    $processed_active_otomoto_ids_in_current_sync[] = $result['otomoto_id'];
                    $processed_active_adverts_count++;
                }
            }
             
            if ($dev_max_processed_active_adverts_limit > 0 && $processed_active_adverts_count >= $dev_max_processed_active_adverts_limit) {
                cmu_otomoto_log('Development limit for processed active adverts met. Terminating sync loop.', 'INFO');
                break; 
            }

            $current_page++;
        }

        cmu_otomoto_log('Otomoto adverts synchronization process finished.', 'INFO', $sync_summary);
        
        $sync_summary['adverts_sample'] = array_slice($all_fetched_adverts_data_sample, 0, 50);

        // --- POCZĄTEK LOGIKI USUWANIA POSTÓW NIEAKTYWNYCH W OTOMOTO ---
        cmu_otomoto_log('Rozpoczęcie procesu usuwania postów, które nie są już aktywne w Otomoto.', 'INFO', ['active_otomoto_ids_count' => count($processed_active_otomoto_ids_in_current_sync)]);

        $args_wp_posts_to_check = [
            'post_type'      => 'maszyna-rolnicza',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_otomoto_id',
                    'compare' => 'EXISTS',
                ],
            ],
        ];
        $all_otomoto_wp_post_ids = get_posts($args_wp_posts_to_check);
        $deleted_posts_count = 0;

        if (!empty($all_otomoto_wp_post_ids)) {
            cmu_otomoto_log('Znaleziono ' . count($all_otomoto_wp_post_ids) . ' opublikowanych postów maszyn rolniczych w WP do weryfikacji.', 'INFO');
            foreach ($all_otomoto_wp_post_ids as $wp_post_id) {
                $otomoto_id_of_wp_post = get_post_meta($wp_post_id, '_otomoto_id', true);

                if (!empty($otomoto_id_of_wp_post) && !in_array($otomoto_id_of_wp_post, $processed_active_otomoto_ids_in_current_sync)) {
                    // Ten post nie był wśród aktywnych ogłoszeń Otomoto w tej synchronizacji.
                    // Flaga _otomoto_is_edited_manually nie chroni już przed usunięciem w tym scenariuszu.

                    if (!defined('CMU_OTOMOTO_DOING_SYNC') || !CMU_OTOMOTO_DOING_SYNC) {
                         cmu_otomoto_log("KRYTYCZNE OSTRZEŻENIE: Próba usunięcia posta ID $wp_post_id (Otomoto ID: $otomoto_id_of_wp_post) bez aktywnej stałej CMU_OTOMOTO_DOING_SYNC. Może to wpłynąć na inne hooki.", 'ERROR');
                         // Rozważ przerwanie lub dodatkowe zabezpieczenia, jeśli stała nie jest zdefiniowana,
                         // ale głównym założeniem jest, że metoda sync_adverts jest wywoływana w odpowiednim kontekście.
                    }

                    $delete_result = wp_delete_post($wp_post_id, false); // false = przenieś do kosza

                    if ($delete_result !== false && $delete_result !== null) { // wp_delete_post zwraca WP_Post on success, false or null on failure.
                        cmu_otomoto_log("Post ID $wp_post_id (Otomoto ID: $otomoto_id_of_wp_post) został PRZENIESIONY DO KOSZA, ponieważ nie jest już aktywny w Otomoto.", 'INFO');
                        $deleted_posts_count++;
                    } else {
                        cmu_otomoto_log("Nie udało się przenieść do kosza posta ID $wp_post_id (Otomoto ID: $otomoto_id_of_wp_post).", 'ERROR', ['wp_delete_post_result' => $delete_result]);
                        $sync_summary['errors_encountered']++; // Zliczamy błąd, jeśli usunięcie się nie powiedzie
                    }
                }
            }
        } else {
            cmu_otomoto_log('Nie znaleziono żadnych opublikowanych postów maszyn rolniczych w WP do weryfikacji (lub wszystkie znalezione są nadal aktywne w Otomoto).', 'INFO');
        }
        $sync_summary['posts_deleted_as_inactive_in_otomoto'] = $deleted_posts_count;
        cmu_otomoto_log("Zakończono proces usuwania nieaktywnych postów. Przeniesiono do kosza: $deleted_posts_count.", 'INFO');
        // --- KONIEC LOGIKI USUWANIA ---

        cmu_otomoto_log('Otomoto adverts synchronization process finished.', 'INFO', $sync_summary);

        return [
            'status' => ($sync_summary['errors_encountered'] > 0) ? 'partial_error' : 'success',
            'message' => ($sync_summary['errors_encountered'] > 0) ? 'Synchronization completed with some errors.' : 'Synchronization completed successfully.',
            'summary' => $sync_summary,
            'adverts_sample' => $sync_summary['adverts_sample'] // This was duplicated, ensure it's only assigned once.
        ];
    }

    /**
     * Handles advert images: downloads them, attaches to the post, sets featured image, and gallery.
     *
     * @param int   $post_id           The ID of the WordPress post.
     * @param array $photos_api_data   Array/object of photos data from Otomoto API (e.g., $advert_data['photos']).
     * @param string $otomoto_advert_id The original Otomoto advert ID (for image descriptions).
     * @param string $post_title        The title of the post (for image descriptions).
     */
    public function handle_advert_images($post_id, $photos_api_data, $otomoto_advert_id, $post_title = '')
    {
        if (empty($photos_api_data) || ! is_array($photos_api_data)) {
            cmu_otomoto_log('No photos data provided or data is not an array for post ID: ' . $post_id . ', Otomoto ID: ' . $otomoto_advert_id, 'INFO');
            return;
        }

        if (! function_exists('media_handle_sideload')) { // media_sideload_image is deprecated
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $gallery_attachment_ids = [];
        $featured_image_set = false;
        $sideloaded_image_count = 0;

        // DEV_NOTE_LIMIT_IMAGES: Set to 1 for testing. For production, set to desired max (e.g., 5).
        // Set to 0 or a high number to download all available images from API.
        // FINAL_GOAL: Change to 5 or make configurable. For current tests, use 1.
        $max_images_to_sideload = 1; 

        // Sort photos by their numeric key if they are structured like '1', '2', ...
        if (is_array($photos_api_data) && count($photos_api_data) > 0) {
            $first_key = array_key_first($photos_api_data);
            if (is_numeric($first_key)) {
                ksort($photos_api_data, SORT_NUMERIC);
            }
        }
        
        $current_image_index_for_naming = 1; // For naming images like 'post-title-1.jpg', 'post-title-2.jpg'

        foreach ($photos_api_data as $photo_order_key => $photo_urls_obj) {
            if ($max_images_to_sideload > 0 && $sideloaded_image_count >= $max_images_to_sideload) {
                cmu_otomoto_log('Image sideload limit (' . $max_images_to_sideload . ') reached for post ID: ' . $post_id . '. Stopping further image processing.', 'INFO');
                break;
            }

            $image_url_to_download = null;
            $photo_urls = (array) $photo_urls_obj; // Ensure it's an array for consistent access
            // Re-ordered quality preference as per plan, added 'original'
            $quality_preference = ['2048x1360', '1280x800', '1080x720', 'original', '732x488', '800x600', '640x480'];


            foreach ($quality_preference as $quality_key) {
                if (isset($photo_urls[$quality_key]) && filter_var($photo_urls[$quality_key], FILTER_VALIDATE_URL)) {
                    $image_url_to_download = $photo_urls[$quality_key];
                    cmu_otomoto_log("Post ID $post_id: Selected image URL '$image_url_to_download' with quality key '$quality_key'.", "DEBUG");
                    break;
                }
            }
            
            // Fallback if keys aren't found but array contains direct URLs (e.g. if API returns a simple list of URLs)
            if (!$image_url_to_download) {
                $potential_urls = array_filter($photo_urls, function ($url) {
                    return is_string($url) && filter_var($url, FILTER_VALIDATE_URL);
                });
                if (!empty($potential_urls)) {
                    $image_url_to_download = reset($potential_urls);
                     cmu_otomoto_log("Post ID $post_id: Selected image URL '$image_url_to_download' from direct URL list (fallback).", "DEBUG");
                }
            }

            if (empty($image_url_to_download)) {
                cmu_otomoto_log('No valid image URL found for photo entry for Otomoto ID: ' . $otomoto_advert_id . '. Key: ' . $photo_order_key, 'WARNING', ['post_id' => $post_id, 'photo_data_dump' => $photo_urls_obj]);
                continue;
            }

            $image_description = sprintf(
                'Zdjęcie %d dla %s (Otomoto ID: %s)',
                $current_image_index_for_naming,
                !empty($post_title) ? $post_title : 'Maszyna Rolnicza',
                $otomoto_advert_id
            );

            $tmp_file = download_url($image_url_to_download, 30); // Increased timeout to 30s

            if (is_wp_error($tmp_file)) {
                cmu_otomoto_log('Failed to download image: ' . $tmp_file->get_error_message(), 'ERROR', ['post_id' => $post_id, 'image_url' => $image_url_to_download]);
            } else {
                $file_array = ['tmp_name' => $tmp_file];
                // Determine file type and extension more reliably
                $file_type_info = wp_check_filetype_and_ext($tmp_file, basename($image_url_to_download)); // Pass filename from URL for better hint
                $ext = !empty($file_type_info['ext']) ? $file_type_info['ext'] : pathinfo(parse_url($image_url_to_download, PHP_URL_PATH), PATHINFO_EXTENSION);
                if (empty($ext) || !in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif', 'webp'])) { // Validate extension
                    $ext = 'jpg'; // Default to jpg if detection fails or invalid
                }
                
                $base_filename = sanitize_title($post_title ?: ('otomoto-' . $otomoto_advert_id));
                if (empty($base_filename)) $base_filename = 'image-' . $otomoto_advert_id; // Ensure base_filename is not empty
                // Ensure filename is unique enough for media_handle_sideload to work well.
                // media_handle_sideload internally calls wp_unique_filename.
                $file_name = sanitize_file_name($base_filename . '-' . $current_image_index_for_naming . '.' . $ext);
                $file_array['name'] = $file_name;

                cmu_otomoto_log('Image downloaded to: ' . $tmp_file . '. Attempting sideload as: ' . $file_name, 'INFO', ['post_id' => $post_id]);
                $attachment_id = media_handle_sideload($file_array, $post_id, $image_description);

                if (is_wp_error($attachment_id)) {
                    @unlink($tmp_file);
                    cmu_otomoto_log('Failed to sideload image: ' . $attachment_id->get_error_message(), 'ERROR', ['post_id' => $post_id, 'image_url' => $image_url_to_download, 'tmp_file' => $tmp_file]);
                } else {
                    cmu_otomoto_log('Successfully sideloaded image. Attachment ID: ' . $attachment_id . ' for post ID: ' . $post_id, 'INFO');
                    $gallery_attachment_ids[] = $attachment_id;
                    if (!$featured_image_set) {
                        set_post_thumbnail($post_id, $attachment_id);
                        $featured_image_set = true;
                        cmu_otomoto_log('Set attachment ID: ' . $attachment_id . ' as featured image for post ID: ' . $post_id, 'INFO');
                    }
                    $sideloaded_image_count++; // Increment count of successfully sideloaded images
                }
            }
            $current_image_index_for_naming++; // Increment for unique naming, regardless of sideload success
        }

        if (!empty($gallery_attachment_ids)) {
            update_post_meta($post_id, '_otomoto_gallery_ids', $gallery_attachment_ids);
            cmu_otomoto_log('Updated _otomoto_gallery_ids meta for post ID: ' . $post_id . ' with ' . count($gallery_attachment_ids) . ' images.', 'INFO');
        } elseif (get_post_meta($post_id, '_otomoto_gallery_ids', true)) {
            // If no new images were added but old gallery meta exists, clear it
            // This happens if all images failed to download or max_images_to_sideload was 0 and there were previous images.
            delete_post_meta($post_id, '_otomoto_gallery_ids');
            cmu_otomoto_log('Cleared _otomoto_gallery_ids meta for post ID: ' . $post_id . ' as no new images were successfully sideloaded.', 'INFO');
        }
    }
}
```

----------------------

cmu-utilities.php:
```php
<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'cmu_otomoto_log' ) ) {
	/**
	 * Logs a message to the Otomoto sync log file.
	 *
	 * @param string $message The message to log.
	 * @param string $type    The type of log entry (e.g., INFO, ERROR, WARNING). Defaults to INFO.
	 * @param array  $context_data Optional context data to include in the log.
	 */
	function cmu_otomoto_log( $message, $type = 'INFO', $context_data = [] ) {
		// Ensure the logs directory exists.
		if ( ! defined( 'CMU_OTOMOTO_LOGS_DIR' ) ) {
			// Fallback if the constant is not defined, though it should be.
			$log_dir = WP_PLUGIN_DIR . '/otomoto-maszyny-integration/logs';
		} else {
			$log_dir = CMU_OTOMOTO_LOGS_DIR;
		}

		if ( ! file_exists( $log_dir ) ) {
			wp_mkdir_p( $log_dir );
		}

		$log_file = $log_dir . '/otomoto_sync.log';

		$formatted_message = sprintf(
			'[%s] [%s] - %s', 
			current_time( 'mysql' ), // WordPress function for current time in MySQL format
			strtoupper( $type ),
			trim( $message )
		);

		if ( ! empty( $context_data ) ) {
			$formatted_message .= ' [Context: ' . wp_json_encode( $context_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ']';
		}

		$formatted_message .= "\n";

		// Simple log rotation: if file > 5MB, archive it.
		// Clear stat cache to get the real current file size.
		clearstatcache(true, $log_file);
		if ( file_exists( $log_file ) && filesize( $log_file ) > 5 * 1024 * 1024 ) { // 5 MB
			$archived_log_file = $log_dir . '/otomoto_sync_archive_' . time() . '_' . wp_generate_password(8, false) . '.log';
			@rename( $log_file, $archived_log_file );
		}

		@file_put_contents( $log_file, $formatted_message, FILE_APPEND );
	}
}
?> ```

----------------------

api-response.php:
```php
Test API zakończony pomyślnie! Sprawdź logi wtyczki (`wp-content/plugins/otomoto-maszyny-integration/logs/otomoto_sync.log`) aby zobaczyć szczegóły pobranych danych (token, ogłoszenia, kategoria).
Array
(
    [token_status] => SUCCESS
    [adverts_sample] => Array
        (
            [0] => Array
                (
                    [id] => 6138112550
                    [user_id] => 110110
                    [status] => active
                    [title] => Heder Taśmowy Geringhoff Flex 30, szerokość robocza 9,1 metra
                    [url] => https://www.otomoto.pl/maszyny-rolnicze/oferta/geringhoff-flex-30-heder-tasmowy-geringhoff-flex-30-szerokosc-robocza-9-1-metra-ID6HoTtk.html
                    [created_at] => 2025-05-20 10:55:32
                    [valid_to] => 2025-06-19 10:55:50
                    [description] => <p>Agro-Sieć Maszyny - Autoryzowany dealer Marki John Deere ma w swojej ofercie:</p><p><br></p><p>Uwaga! Zmiana lokalizacji – Maszyna do obejrzenia pod adresem:</p><p><br></p><p>Centrum Maszyn Używanych Agro-Sieć Maszyny sp. z o.o.</p><p>Ul. Przejazdowa 30</p><p>87-200 Wąbrzeźno</p><p><br></p><p>Używany Heder zbożowy marki Geringhoff model Flex 30</p><p><br></p><p>rok produkcji 2021</p><p>szerokość cięcia 9.11 m</p><p>szerokość całkowita 9.44</p><p>masa własna 2800 kg</p><p>rozdzielacze łanu</p><p>dwie kosy boczne</p><p>stawiacze łanu</p><p>wózek transportowy</p><p>centralka sterująca</p><p><br></p><p>przystosowanie do kombajnu John Deere T670</p><p><br></p><p>W przypadku zainteresowania prosimy o kontakt z numerem 664144575</p><p><br></p><p>Niniejsze ogłoszenie jest wyłącznie informacją handlową i nie stanowi oferty w myśl art. 6, par.1 Kodeksu Cywilnego. Sprzedający nie odpowiada za ewentualne błędy lub nieaktualności ogłoszenia.</p>
                    [category_id] => 99
                    [region_id] => 15
                    [city_id] => 22501
                    [municipality] => Chełmno
                    [city] => Array
                        (
                            [pl] => Chełmno
                            [en] => Chełmno
                        )

                    [coordinates] => Array
                        (
                            [latitude] => 53.35
                            [longitude] => 18.43333
                            [radius] => 0
                            [zoom_level] => 12
                        )

                    [advertiser_type] => business
                    [contact] => Array
                        (
                            [person] => Agro-Sieć Maszyny
                            [phone_numbers] => Array
                                (
                                    [0] => 664144575
                                )

                        )

                    [params] => Array
                        (
                            [make] => geringhoff
                            [model] => Flex 30
                            [year] => 2021
                            [damaged] => 0
                            [price] => Array
                                (
                                    [0] => price
                                    [1] => 165000
                                    [currency] => PLN
                                    [gross_net] => net
                                )

                        )

                    [photos] => Array
                        (
                            [1] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImZidXJqamExdXRtNC1PVE9NT1RPUEwifQ.p_6ukgA9vrqYL0opPlpJFCeIke7btxizZ0uKL1st-as/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImZidXJqamExdXRtNC1PVE9NT1RPUEwiLCJ3IjpbeyJmbiI6IndnNGducXA2eTFmLU9UT01PVE9QTCIsInMiOiIxNiIsInAiOiIxMCwtMTAiLCJhIjoiMCJ9XX0.LijhUC-vHThnC3haoD-k0IKOVzeSnNuBl8eT-OTr5tI/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImZidXJqamExdXRtNC1PVE9NT1RPUEwiLCJ3IjpbeyJmbiI6IndnNGducXA2eTFmLU9UT01PVE9QTCIsInMiOiIxNiIsInAiOiIxMCwtMTAiLCJhIjoiMCJ9XX0.LijhUC-vHThnC3haoD-k0IKOVzeSnNuBl8eT-OTr5tI/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImZidXJqamExdXRtNC1PVE9NT1RPUEwiLCJ3IjpbeyJmbiI6IndnNGducXA2eTFmLU9UT01PVE9QTCIsInMiOiIxNiIsInAiOiIxMCwtMTAiLCJhIjoiMCJ9XX0.LijhUC-vHThnC3haoD-k0IKOVzeSnNuBl8eT-OTr5tI/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImZidXJqamExdXRtNC1PVE9NT1RPUEwifQ.p_6ukgA9vrqYL0opPlpJFCeIke7btxizZ0uKL1st-as/image;s=1280x800
                                )

                            [2] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImR6NjM1bzRtZGJqZjMtT1RPTU9UT1BMIn0.qYY_AaMEhTP3rpDLl-ojmkpqKtmSOlvSodLz9nAeSNo/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImR6NjM1bzRtZGJqZjMtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.W9w5icYKcB0BmttFXJA-VX-kF9BaPo1vJTQqwQ47I2w/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImR6NjM1bzRtZGJqZjMtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.W9w5icYKcB0BmttFXJA-VX-kF9BaPo1vJTQqwQ47I2w/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImR6NjM1bzRtZGJqZjMtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.W9w5icYKcB0BmttFXJA-VX-kF9BaPo1vJTQqwQ47I2w/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImR6NjM1bzRtZGJqZjMtT1RPTU9UT1BMIn0.qYY_AaMEhTP3rpDLl-ojmkpqKtmSOlvSodLz9nAeSNo/image;s=1280x800
                                )

                            [3] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InFyNDhhczdvY3RjbjEtT1RPTU9UT1BMIn0.o6KgCojPACKfQudOH70gbt3AVkbK67SsMTJ8vUacTVI/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InFyNDhhczdvY3RjbjEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.PzWM6aOIWZkJynKohGG05yZ8ry6wlhBhdVFM5P5hV-4/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InFyNDhhczdvY3RjbjEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.PzWM6aOIWZkJynKohGG05yZ8ry6wlhBhdVFM5P5hV-4/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InFyNDhhczdvY3RjbjEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.PzWM6aOIWZkJynKohGG05yZ8ry6wlhBhdVFM5P5hV-4/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InFyNDhhczdvY3RjbjEtT1RPTU9UT1BMIn0.o6KgCojPACKfQudOH70gbt3AVkbK67SsMTJ8vUacTVI/image;s=1280x800
                                )

                            [4] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InlidHU1MGVmcXNucTEtT1RPTU9UT1BMIn0.3FKGcR39VupxLoByTRi0hAdz4hAOXLDe7f9tx8bnbkY/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InlidHU1MGVmcXNucTEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.TIfCGYFXz4G9b7c8peRwlKgzBFKIPLOuHRB9HdfczVM/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InlidHU1MGVmcXNucTEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.TIfCGYFXz4G9b7c8peRwlKgzBFKIPLOuHRB9HdfczVM/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InlidHU1MGVmcXNucTEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.TIfCGYFXz4G9b7c8peRwlKgzBFKIPLOuHRB9HdfczVM/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InlidHU1MGVmcXNucTEtT1RPTU9UT1BMIn0.3FKGcR39VupxLoByTRi0hAdz4hAOXLDe7f9tx8bnbkY/image;s=1280x800
                                )

                            [5] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Imw2eXFldTk2azJvMTItT1RPTU9UT1BMIn0.qWQfo973uBwblAA6bLkspTCPcXfNvdUWS5aBVJb8ah4/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Imw2eXFldTk2azJvMTItT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.-rG3zREkOJJpn4vU2uqE0knTdTh9z1IIlKwPO48Rs6A/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Imw2eXFldTk2azJvMTItT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.-rG3zREkOJJpn4vU2uqE0knTdTh9z1IIlKwPO48Rs6A/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Imw2eXFldTk2azJvMTItT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.-rG3zREkOJJpn4vU2uqE0knTdTh9z1IIlKwPO48Rs6A/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Imw2eXFldTk2azJvMTItT1RPTU9UT1BMIn0.qWQfo973uBwblAA6bLkspTCPcXfNvdUWS5aBVJb8ah4/image;s=1280x800
                                )

                            [6] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjJ0bnFjbGNxbzl4djEtT1RPTU9UT1BMIn0.dPiFQ76lPRMp4U80wX9qzqy8kkBquhKP7m_URhoGx2E/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjJ0bnFjbGNxbzl4djEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.DTS9dYz4L2haUR6BGKf9G10KAKv1k2xtlz7uS6jCL04/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjJ0bnFjbGNxbzl4djEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.DTS9dYz4L2haUR6BGKf9G10KAKv1k2xtlz7uS6jCL04/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjJ0bnFjbGNxbzl4djEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.DTS9dYz4L2haUR6BGKf9G10KAKv1k2xtlz7uS6jCL04/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjJ0bnFjbGNxbzl4djEtT1RPTU9UT1BMIn0.dPiFQ76lPRMp4U80wX9qzqy8kkBquhKP7m_URhoGx2E/image;s=1280x800
                                )

                            [7] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjZjNjU5eDhlcTh4ZzEtT1RPTU9UT1BMIn0.jEb2wPHZJIPnAF0eG7mOrBXvivo9sPI4cIzlb26Dnj4/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjZjNjU5eDhlcTh4ZzEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.wR1AdZ-3u_r9cfkNa4Zdi0lTPJhzAxDkk09APbJ8--I/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjZjNjU5eDhlcTh4ZzEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.wR1AdZ-3u_r9cfkNa4Zdi0lTPJhzAxDkk09APbJ8--I/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjZjNjU5eDhlcTh4ZzEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.wR1AdZ-3u_r9cfkNa4Zdi0lTPJhzAxDkk09APbJ8--I/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjZjNjU5eDhlcTh4ZzEtT1RPTU9UT1BMIn0.jEb2wPHZJIPnAF0eG7mOrBXvivo9sPI4cIzlb26Dnj4/image;s=1280x800
                                )

                            [8] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InM4bW94MzAweDVxNzMtT1RPTU9UT1BMIn0.lchu9kv_glo17W3prhCj04AdSP8cHyiLN_JpVYMK7XA/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InM4bW94MzAweDVxNzMtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.15TkgM0KrJKj5AfLZqrbesBbmxHEMYCSU2I3azLCVyU/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InM4bW94MzAweDVxNzMtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.15TkgM0KrJKj5AfLZqrbesBbmxHEMYCSU2I3azLCVyU/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InM4bW94MzAweDVxNzMtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.15TkgM0KrJKj5AfLZqrbesBbmxHEMYCSU2I3azLCVyU/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InM4bW94MzAweDVxNzMtT1RPTU9UT1BMIn0.lchu9kv_glo17W3prhCj04AdSP8cHyiLN_JpVYMK7XA/image;s=1280x800
                                )

                        )

                    [image_collection_id] => 1047875994
                    [new_used] => used
                    [visible_in_profile] => 1
                    [last_update_date] => 2025-05-20 11:16:27
                )

            [1] => Array
                (
                    [id] => 6121985074
                    [user_id] => 110110
                    [status] => active
                    [title] => 
                    [url] => https://www.otomoto.pl/maszyny-rolnicze/oferta/john-deere-s680i-ID6GjdYK.html
                    [created_at] => 2024-03-25 14:47:00
                    [valid_to] => 2025-06-18 16:31:19
                    [description] => <p>Agro-Sieć Maszyny - Autoryzowany dealer Marki John Deere ma w swojej ofercie:</p><p><br></p><p>Uwaga! Zmiana lokalizacji – Maszyna do obejrzenia pod adresem:</p><p><br></p><p>Centrum Maszyn Używanych Agro-Sieć Maszyny sp. z o.o.</p><p>Ul. Przejazdowa 30</p><p>87-200 Wąbrzeźno</p><p><br></p><p>Kombajn Zbożowy John Deere S680i</p><p><br></p><p>- rok produkcji 2012</p><p>- wskazanie licznika silnik - 3305 mth</p><p>- wskazanie licznika rotor - 2122 mth</p><p>- silnik John Deere 13,5 litra 6-cylindorwy</p><p>- Przekładnia Pro-Drive</p><p>- moc znamionowa silnika 480 KM</p><p>- zbiornik 14 100 l</p><p>- napęd osi tylnej</p><p>- gąsienice na osi przedniej</p><p>- rozrzutnik słomy i sieczkarnia</p><p>- komputer sterujący Greenstar 2630</p><p>- Harvest Monitor</p><p>- Zmienna wydajność Rotora</p><p>- rura wyładowcza 6,9m</p><p>- sieczkarnia ze zwiększoną ilością noży</p><p>- elektrycznie nastawiane sita</p><p><br></p><p>- Heder Zurn Premium Flow 9,15m - taśmowy</p><p>- szerokość cięcia - 9,15m</p><p>- Wózek transportowy</p><p><br></p><p>W przypadku zainteresowania prosimy o kontakt z numerem +48 664 144 575</p><p><br></p><p>Niniejsze ogłoszenie jest wyłącznie informacją handlową i nie stanowi oferty w myśl art. 6, par.1 Kodeksu Cywilnego. Sprzedający nie odpowiada za ewentualne błędy lub nieaktualności ogłoszenia.</p><p>-</p><p><br></p><p>Numer oferty: AKL644XH</p>
                    [category_id] => 99
                    [region_id] => 15
                    [city_id] => 22501
                    [municipality] => Chełmno
                    [city] => Array
                        (
                            [pl] => Chełmno
                            [en] => Chełmno
                        )

                    [coordinates] => Array
                        (
                            [latitude] => 53.35
                            [longitude] => 18.43333
                            [radius] => 0
                            [zoom_level] => 12
                        )

                    [advertiser_type] => business
                    [contact] => Array
                        (
                            [person] => Agro-Sieć Maszyny
                            [phone_numbers] => Array
                                (
                                    [0] => 664144575
                                )

                        )

                    [params] => Array
                        (
                            [make] => john-deere
                            [model] => S680i
                            [year] => 2012
                            [lifetime] => 2122
                            [price] => Array
                                (
                                    [0] => arranged
                                    [1] => 399000
                                    [currency] => PLN
                                    [gross_net] => net
                                )

                            [vat] => 1
                            [power] => 480
                        )

                    [photos] => Array
                        (
                            [1] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InVhYnd4MGhwNG4zaS1PVE9NT1RPUEwifQ.Jsc4k1-6qtQdqSpnSYimk8ACwvn127VUCNekw_7vlDo/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InVhYnd4MGhwNG4zaS1PVE9NT1RPUEwiLCJ3IjpbeyJmbiI6IndnNGducXA2eTFmLU9UT01PVE9QTCIsInMiOiIxNiIsInAiOiIxMCwtMTAiLCJhIjoiMCJ9XX0.qlAnY4dV-YO47RGixfVZdPWpo8gUeaGvMxI047mVzzw/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InVhYnd4MGhwNG4zaS1PVE9NT1RPUEwiLCJ3IjpbeyJmbiI6IndnNGducXA2eTFmLU9UT01PVE9QTCIsInMiOiIxNiIsInAiOiIxMCwtMTAiLCJhIjoiMCJ9XX0.qlAnY4dV-YO47RGixfVZdPWpo8gUeaGvMxI047mVzzw/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InVhYnd4MGhwNG4zaS1PVE9NT1RPUEwiLCJ3IjpbeyJmbiI6IndnNGducXA2eTFmLU9UT01PVE9QTCIsInMiOiIxNiIsInAiOiIxMCwtMTAiLCJhIjoiMCJ9XX0.qlAnY4dV-YO47RGixfVZdPWpo8gUeaGvMxI047mVzzw/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InVhYnd4MGhwNG4zaS1PVE9NT1RPUEwifQ.Jsc4k1-6qtQdqSpnSYimk8ACwvn127VUCNekw_7vlDo/image;s=1280x800
                                )

                            [2] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjBydmRxYzM1dmxlajItT1RPTU9UT1BMIn0.RQj-_YxA4rh7EN_VHEdBHRU6tUeGkEQhYPRe6e8UDRo/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjBydmRxYzM1dmxlajItT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.JA0k9mwj-WttKBACWLlyrULuzqKl5eXLfPd2NjmtQWw/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjBydmRxYzM1dmxlajItT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.JA0k9mwj-WttKBACWLlyrULuzqKl5eXLfPd2NjmtQWw/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjBydmRxYzM1dmxlajItT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.JA0k9mwj-WttKBACWLlyrULuzqKl5eXLfPd2NjmtQWw/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjBydmRxYzM1dmxlajItT1RPTU9UT1BMIn0.RQj-_YxA4rh7EN_VHEdBHRU6tUeGkEQhYPRe6e8UDRo/image;s=1280x800
                                )

                            [3] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InJqajFnNzdmbGczcS1PVE9NT1RPUEwifQ.HsDhJVIM9enmPlrh63QvKt5os6PpXv8auZKJXqR5oO8/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InJqajFnNzdmbGczcS1PVE9NT1RPUEwiLCJ3IjpbeyJmbiI6IndnNGducXA2eTFmLU9UT01PVE9QTCIsInMiOiIxNiIsInAiOiIxMCwtMTAiLCJhIjoiMCJ9XX0.f8k0JxNBGLLzSy8lbCcxN_nVE36E7yv28Ci8PkeRDA4/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InJqajFnNzdmbGczcS1PVE9NT1RPUEwiLCJ3IjpbeyJmbiI6IndnNGducXA2eTFmLU9UT01PVE9QTCIsInMiOiIxNiIsInAiOiIxMCwtMTAiLCJhIjoiMCJ9XX0.f8k0JxNBGLLzSy8lbCcxN_nVE36E7yv28Ci8PkeRDA4/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InJqajFnNzdmbGczcS1PVE9NT1RPUEwiLCJ3IjpbeyJmbiI6IndnNGducXA2eTFmLU9UT01PVE9QTCIsInMiOiIxNiIsInAiOiIxMCwtMTAiLCJhIjoiMCJ9XX0.f8k0JxNBGLLzSy8lbCcxN_nVE36E7yv28Ci8PkeRDA4/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InJqajFnNzdmbGczcS1PVE9NT1RPUEwifQ.HsDhJVIM9enmPlrh63QvKt5os6PpXv8auZKJXqR5oO8/image;s=1280x800
                                )

                            [4] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Imh0Y2sxcGlpNnV1cTEtT1RPTU9UT1BMIn0.HAmW4qVxactQ-G_3lOHhU5wG56Tt8NJUoL1pXFCkvPI/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Imh0Y2sxcGlpNnV1cTEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.VWn-Yd8axNnrmvfX1M6xgVw_oZobCWjPpwJey0gLtK4/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Imh0Y2sxcGlpNnV1cTEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.VWn-Yd8axNnrmvfX1M6xgVw_oZobCWjPpwJey0gLtK4/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Imh0Y2sxcGlpNnV1cTEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.VWn-Yd8axNnrmvfX1M6xgVw_oZobCWjPpwJey0gLtK4/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Imh0Y2sxcGlpNnV1cTEtT1RPTU9UT1BMIn0.HAmW4qVxactQ-G_3lOHhU5wG56Tt8NJUoL1pXFCkvPI/image;s=1280x800
                                )

                            [5] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjRmbXVmaWs5c2YycS1PVE9NT1RPUEwifQ.WQO4UR6D-NogVbzOHsQFiSC3RuLdOSiFH6uAIZRnh6M/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjRmbXVmaWs5c2YycS1PVE9NT1RPUEwiLCJ3IjpbeyJmbiI6IndnNGducXA2eTFmLU9UT01PVE9QTCIsInMiOiIxNiIsInAiOiIxMCwtMTAiLCJhIjoiMCJ9XX0.LldlVy43ZhGJZKs-oriJqnMwbqXZHmr379-L8E_Zrdg/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjRmbXVmaWs5c2YycS1PVE9NT1RPUEwiLCJ3IjpbeyJmbiI6IndnNGducXA2eTFmLU9UT01PVE9QTCIsInMiOiIxNiIsInAiOiIxMCwtMTAiLCJhIjoiMCJ9XX0.LldlVy43ZhGJZKs-oriJqnMwbqXZHmr379-L8E_Zrdg/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjRmbXVmaWs5c2YycS1PVE9NT1RPUEwiLCJ3IjpbeyJmbiI6IndnNGducXA2eTFmLU9UT01PVE9QTCIsInMiOiIxNiIsInAiOiIxMCwtMTAiLCJhIjoiMCJ9XX0.LldlVy43ZhGJZKs-oriJqnMwbqXZHmr379-L8E_Zrdg/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjRmbXVmaWs5c2YycS1PVE9NT1RPUEwifQ.WQO4UR6D-NogVbzOHsQFiSC3RuLdOSiFH6uAIZRnh6M/image;s=1280x800
                                )

                            [6] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Im1mdjY0YWR3YzJ3aTEtT1RPTU9UT1BMIn0.fHhiOMBlaICseMUWtuaEFSXgTYUjuElOG01EO0Ha3sU/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Im1mdjY0YWR3YzJ3aTEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.UHVw3YWZh6Pll1ftAsr1fGxgcyvEi0LAicmqAO6vUvg/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Im1mdjY0YWR3YzJ3aTEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.UHVw3YWZh6Pll1ftAsr1fGxgcyvEi0LAicmqAO6vUvg/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Im1mdjY0YWR3YzJ3aTEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.UHVw3YWZh6Pll1ftAsr1fGxgcyvEi0LAicmqAO6vUvg/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Im1mdjY0YWR3YzJ3aTEtT1RPTU9UT1BMIn0.fHhiOMBlaICseMUWtuaEFSXgTYUjuElOG01EO0Ha3sU/image;s=1280x800
                                )

                            [7] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InBnOHdpYmh2ZHk0dDItT1RPTU9UT1BMIn0.IlMPq3ntsGcs9sVDWIBanShg_8h4inqiLLHBzno_UMA/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InBnOHdpYmh2ZHk0dDItT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.0Ay-noc9ErbeP0q7rMbTBBoQ4UrwEpcjIxz93Vuxxfs/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InBnOHdpYmh2ZHk0dDItT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.0Ay-noc9ErbeP0q7rMbTBBoQ4UrwEpcjIxz93Vuxxfs/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InBnOHdpYmh2ZHk0dDItT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.0Ay-noc9ErbeP0q7rMbTBBoQ4UrwEpcjIxz93Vuxxfs/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InBnOHdpYmh2ZHk0dDItT1RPTU9UT1BMIn0.IlMPq3ntsGcs9sVDWIBanShg_8h4inqiLLHBzno_UMA/image;s=1280x800
                                )

                            [8] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Imdwa2d0Zjg1d3BvYi1PVE9NT1RPUEwifQ.2E70H_ND_9qWv0GMeob4X6MZzxYH-o6-urqDJOlTYqk/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Imdwa2d0Zjg1d3BvYi1PVE9NT1RPUEwiLCJ3IjpbeyJmbiI6IndnNGducXA2eTFmLU9UT01PVE9QTCIsInMiOiIxNiIsInAiOiIxMCwtMTAiLCJhIjoiMCJ9XX0.UR0Z56P4B-kHoa-Tf7LuiKEqzYJcMW_GEDsRdCWZz-c/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Imdwa2d0Zjg1d3BvYi1PVE9NT1RPUEwiLCJ3IjpbeyJmbiI6IndnNGducXA2eTFmLU9UT01PVE9QTCIsInMiOiIxNiIsInAiOiIxMCwtMTAiLCJhIjoiMCJ9XX0.UR0Z56P4B-kHoa-Tf7LuiKEqzYJcMW_GEDsRdCWZz-c/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Imdwa2d0Zjg1d3BvYi1PVE9NT1RPUEwiLCJ3IjpbeyJmbiI6IndnNGducXA2eTFmLU9UT01PVE9QTCIsInMiOiIxNiIsInAiOiIxMCwtMTAiLCJhIjoiMCJ9XX0.UR0Z56P4B-kHoa-Tf7LuiKEqzYJcMW_GEDsRdCWZz-c/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Imdwa2d0Zjg1d3BvYi1PVE9NT1RPUEwifQ.2E70H_ND_9qWv0GMeob4X6MZzxYH-o6-urqDJOlTYqk/image;s=1280x800
                                )

                            [9] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImV5bmkxMmgweWFlZjItT1RPTU9UT1BMIn0.2DknNEKVyaNvQpWIFGYvaRQaQTO7T5PVQwx3IlZjsBY/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImV5bmkxMmgweWFlZjItT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.YAZHBZRXjEP7Fs3t12trQB5dEu8T6R7dQE8w_iaZOYk/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImV5bmkxMmgweWFlZjItT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.YAZHBZRXjEP7Fs3t12trQB5dEu8T6R7dQE8w_iaZOYk/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImV5bmkxMmgweWFlZjItT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.YAZHBZRXjEP7Fs3t12trQB5dEu8T6R7dQE8w_iaZOYk/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImV5bmkxMmgweWFlZjItT1RPTU9UT1BMIn0.2DknNEKVyaNvQpWIFGYvaRQaQTO7T5PVQwx3IlZjsBY/image;s=1280x800
                                )

                            [10] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjFoN2c2M2IybGhsdzEtT1RPTU9UT1BMIn0.cxJjdIyNazQfMkKQpl-EbE7WTkS4D1NXm_Vk4t2iiHI/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjFoN2c2M2IybGhsdzEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.c581Xhw-7l7531cqMza-oUotoEaykm1PnBdK8FvhCCo/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjFoN2c2M2IybGhsdzEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.c581Xhw-7l7531cqMza-oUotoEaykm1PnBdK8FvhCCo/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjFoN2c2M2IybGhsdzEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.c581Xhw-7l7531cqMza-oUotoEaykm1PnBdK8FvhCCo/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjFoN2c2M2IybGhsdzEtT1RPTU9UT1BMIn0.cxJjdIyNazQfMkKQpl-EbE7WTkS4D1NXm_Vk4t2iiHI/image;s=1280x800
                                )

                            [11] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImdnYW4wOWNpbmRybzEtT1RPTU9UT1BMIn0._BT70FYhit3raBqOUA8B8cOgGSZ4wugSdp5xPfQmFFM/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImdnYW4wOWNpbmRybzEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.nx5KNCHZPTbHw85awgr4utGf470jLgfshCR_zDJQWOU/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImdnYW4wOWNpbmRybzEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.nx5KNCHZPTbHw85awgr4utGf470jLgfshCR_zDJQWOU/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImdnYW4wOWNpbmRybzEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.nx5KNCHZPTbHw85awgr4utGf470jLgfshCR_zDJQWOU/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImdnYW4wOWNpbmRybzEtT1RPTU9UT1BMIn0._BT70FYhit3raBqOUA8B8cOgGSZ4wugSdp5xPfQmFFM/image;s=1280x800
                                )

                            [12] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InVrODdzb2JybzZtdDEtT1RPTU9UT1BMIn0.asSIe3CK7oJu57Hpwm63Zpi5mlmMVuaCR4wPEFU5z1Q/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InVrODdzb2JybzZtdDEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.rwkggXm2Wu_qPFtSdVd3CVbAAXRco4gK8EPGNypCsTA/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InVrODdzb2JybzZtdDEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.rwkggXm2Wu_qPFtSdVd3CVbAAXRco4gK8EPGNypCsTA/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InVrODdzb2JybzZtdDEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.rwkggXm2Wu_qPFtSdVd3CVbAAXRco4gK8EPGNypCsTA/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InVrODdzb2JybzZtdDEtT1RPTU9UT1BMIn0.asSIe3CK7oJu57Hpwm63Zpi5mlmMVuaCR4wPEFU5z1Q/image;s=1280x800
                                )

                            [13] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Imw1bzM1NG42ajBhMjEtT1RPTU9UT1BMIn0.1gM-6QNiquZ_jswNC8hlUkJg0NEXM6ZVLyHwBxTH7rc/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Imw1bzM1NG42ajBhMjEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.kbcKOagO5PSiznqUJ2Lu3pPamjw9b3fCyLpP-6OJ9Ao/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Imw1bzM1NG42ajBhMjEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.kbcKOagO5PSiznqUJ2Lu3pPamjw9b3fCyLpP-6OJ9Ao/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Imw1bzM1NG42ajBhMjEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.kbcKOagO5PSiznqUJ2Lu3pPamjw9b3fCyLpP-6OJ9Ao/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Imw1bzM1NG42ajBhMjEtT1RPTU9UT1BMIn0.1gM-6QNiquZ_jswNC8hlUkJg0NEXM6ZVLyHwBxTH7rc/image;s=1280x800
                                )

                            [14] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImJ1bTdjazdlOGFiMi1PVE9NT1RPUEwifQ.9BsU1pbub42utcmSsEjVZ0EoKikbmyFLwmGnIy1Mm0M/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImJ1bTdjazdlOGFiMi1PVE9NT1RPUEwiLCJ3IjpbeyJmbiI6IndnNGducXA2eTFmLU9UT01PVE9QTCIsInMiOiIxNiIsInAiOiIxMCwtMTAiLCJhIjoiMCJ9XX0.2p53kKc0yK6XvSliPrWWfvl6ZCxvaFjyyynXuedLBnQ/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImJ1bTdjazdlOGFiMi1PVE9NT1RPUEwiLCJ3IjpbeyJmbiI6IndnNGducXA2eTFmLU9UT01PVE9QTCIsInMiOiIxNiIsInAiOiIxMCwtMTAiLCJhIjoiMCJ9XX0.2p53kKc0yK6XvSliPrWWfvl6ZCxvaFjyyynXuedLBnQ/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImJ1bTdjazdlOGFiMi1PVE9NT1RPUEwiLCJ3IjpbeyJmbiI6IndnNGducXA2eTFmLU9UT01PVE9QTCIsInMiOiIxNiIsInAiOiIxMCwtMTAiLCJhIjoiMCJ9XX0.2p53kKc0yK6XvSliPrWWfvl6ZCxvaFjyyynXuedLBnQ/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImJ1bTdjazdlOGFiMi1PVE9NT1RPUEwifQ.9BsU1pbub42utcmSsEjVZ0EoKikbmyFLwmGnIy1Mm0M/image;s=1280x800
                                )

                            [15] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImN5ZGUydHA3bmtudDMtT1RPTU9UT1BMIn0.6edtlQHlUtIJDvmbaP7q1XLtHbzblfM7YAeVkyo6IJk/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImN5ZGUydHA3bmtudDMtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.LjGzMwiOZn98R4XP5XyBa2B_NPqjfTSMykLdq12lA34/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImN5ZGUydHA3bmtudDMtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.LjGzMwiOZn98R4XP5XyBa2B_NPqjfTSMykLdq12lA34/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImN5ZGUydHA3bmtudDMtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.LjGzMwiOZn98R4XP5XyBa2B_NPqjfTSMykLdq12lA34/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImN5ZGUydHA3bmtudDMtT1RPTU9UT1BMIn0.6edtlQHlUtIJDvmbaP7q1XLtHbzblfM7YAeVkyo6IJk/image;s=1280x800
                                )

                            [16] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InA5aGgxd2c0OTlkbzMtT1RPTU9UT1BMIn0.psY3PZIs6uMNm6KlXqotTEVxvOnCYiT6cGN9-3CVwv8/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InA5aGgxd2c0OTlkbzMtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.0jagjEQ75q3cjxJ2FdWGsEfAJ1y-G2n3MQSC9EHojvs/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InA5aGgxd2c0OTlkbzMtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.0jagjEQ75q3cjxJ2FdWGsEfAJ1y-G2n3MQSC9EHojvs/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InA5aGgxd2c0OTlkbzMtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.0jagjEQ75q3cjxJ2FdWGsEfAJ1y-G2n3MQSC9EHojvs/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InA5aGgxd2c0OTlkbzMtT1RPTU9UT1BMIn0.psY3PZIs6uMNm6KlXqotTEVxvOnCYiT6cGN9-3CVwv8/image;s=1280x800
                                )

                            [17] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InU1aWd1ZzJyaWxwMTItT1RPTU9UT1BMIn0.jDAHCjxjFmLFmMGfSikXgCBPqfDPvPh1JAo1mmpfmlk/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InU1aWd1ZzJyaWxwMTItT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.uXjTYBX5mqk0b-gZAR1c4Urk0tV5ZW3L1EKKSTPp-os/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InU1aWd1ZzJyaWxwMTItT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.uXjTYBX5mqk0b-gZAR1c4Urk0tV5ZW3L1EKKSTPp-os/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InU1aWd1ZzJyaWxwMTItT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.uXjTYBX5mqk0b-gZAR1c4Urk0tV5ZW3L1EKKSTPp-os/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InU1aWd1ZzJyaWxwMTItT1RPTU9UT1BMIn0.jDAHCjxjFmLFmMGfSikXgCBPqfDPvPh1JAo1mmpfmlk/image;s=1280x800
                                )

                            [18] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Ijhob3dsY2l5bjR6bjEtT1RPTU9UT1BMIn0.6ynVhJOIGIrnQTTl62FAG3Xu85mJzhIcxzWfjJhjmAQ/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Ijhob3dsY2l5bjR6bjEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.hl3RxQhn5rGuuD79ZNhfCx4xNq6EZn_QU4qgNGoYkQM/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Ijhob3dsY2l5bjR6bjEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.hl3RxQhn5rGuuD79ZNhfCx4xNq6EZn_QU4qgNGoYkQM/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Ijhob3dsY2l5bjR6bjEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.hl3RxQhn5rGuuD79ZNhfCx4xNq6EZn_QU4qgNGoYkQM/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Ijhob3dsY2l5bjR6bjEtT1RPTU9UT1BMIn0.6ynVhJOIGIrnQTTl62FAG3Xu85mJzhIcxzWfjJhjmAQ/image;s=1280x800
                                )

                            [19] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InVoMDZnb255b3l4bzMtT1RPTU9UT1BMIn0.21rcCLeBRdTdYKe3UYPXqbkzxdYrvv4-RQsoYWySMpM/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InVoMDZnb255b3l4bzMtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.tL17GmsNEIFr_JR5Civ4Hid0SLV4uq-XiESyGuKLjZQ/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InVoMDZnb255b3l4bzMtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.tL17GmsNEIFr_JR5Civ4Hid0SLV4uq-XiESyGuKLjZQ/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InVoMDZnb255b3l4bzMtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.tL17GmsNEIFr_JR5Civ4Hid0SLV4uq-XiESyGuKLjZQ/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InVoMDZnb255b3l4bzMtT1RPTU9UT1BMIn0.21rcCLeBRdTdYKe3UYPXqbkzxdYrvv4-RQsoYWySMpM/image;s=1280x800
                                )

                            [20] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InhkZDJybDl6MGtjcTItT1RPTU9UT1BMIn0.OK14PvmNf4OUVH1I4EDy4GBBMe-hv18azrKiJZJJuks/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InhkZDJybDl6MGtjcTItT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.FuVxYBjKq4sq3h0eKvIwgFOmzNp7z-Mvb6fSq2HP75Y/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InhkZDJybDl6MGtjcTItT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.FuVxYBjKq4sq3h0eKvIwgFOmzNp7z-Mvb6fSq2HP75Y/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InhkZDJybDl6MGtjcTItT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.FuVxYBjKq4sq3h0eKvIwgFOmzNp7z-Mvb6fSq2HP75Y/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InhkZDJybDl6MGtjcTItT1RPTU9UT1BMIn0.OK14PvmNf4OUVH1I4EDy4GBBMe-hv18azrKiJZJJuks/image;s=1280x800
                                )

                            [21] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjU5bDI5czA2MzAzODMtT1RPTU9UT1BMIn0.s44FREJfiHMY25WDRG_svguHUHj-o9LTHcr-nruRnpI/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjU5bDI5czA2MzAzODMtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19._44PWD2IhqJAhTlsdiTCaJiNCwXoTSBHmEyC3brqIu8/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjU5bDI5czA2MzAzODMtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19._44PWD2IhqJAhTlsdiTCaJiNCwXoTSBHmEyC3brqIu8/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjU5bDI5czA2MzAzODMtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19._44PWD2IhqJAhTlsdiTCaJiNCwXoTSBHmEyC3brqIu8/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjU5bDI5czA2MzAzODMtT1RPTU9UT1BMIn0.s44FREJfiHMY25WDRG_svguHUHj-o9LTHcr-nruRnpI/image;s=1280x800
                                )

                            [22] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImY2ZDJiazFkaWt5dzItT1RPTU9UT1BMIn0.3Rb9g5vSLO5VM1yJuIijZM4XOr2ylmU_8OJL2aNgFgw/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImY2ZDJiazFkaWt5dzItT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.QFXLdhZYBqToU3PoV7EqQeRzFrRQNh5PlLzYPDtCKqk/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImY2ZDJiazFkaWt5dzItT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.QFXLdhZYBqToU3PoV7EqQeRzFrRQNh5PlLzYPDtCKqk/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImY2ZDJiazFkaWt5dzItT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.QFXLdhZYBqToU3PoV7EqQeRzFrRQNh5PlLzYPDtCKqk/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImY2ZDJiazFkaWt5dzItT1RPTU9UT1BMIn0.3Rb9g5vSLO5VM1yJuIijZM4XOr2ylmU_8OJL2aNgFgw/image;s=1280x800
                                )

                            [23] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImNjNDFuMTE5aXRzZjEtT1RPTU9UT1BMIn0.Gk-rPzLcGPoXbRiHxK0U_Z0rKnvH8-4N6M1KbT7j-yo/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImNjNDFuMTE5aXRzZjEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.g75tYuy4PahQE-0LbH1H6a98tiPRWQ5exr54X7KkqNE/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImNjNDFuMTE5aXRzZjEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.g75tYuy4PahQE-0LbH1H6a98tiPRWQ5exr54X7KkqNE/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImNjNDFuMTE5aXRzZjEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.g75tYuy4PahQE-0LbH1H6a98tiPRWQ5exr54X7KkqNE/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImNjNDFuMTE5aXRzZjEtT1RPTU9UT1BMIn0.Gk-rPzLcGPoXbRiHxK0U_Z0rKnvH8-4N6M1KbT7j-yo/image;s=1280x800
                                )

                            [24] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Im05cmh1YW96MGVlZy1PVE9NT1RPUEwifQ.g4u3NJyKCim9C8xcLQJ8CZWr2-X3wgeqUJm-bnPGdGM/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Im05cmh1YW96MGVlZy1PVE9NT1RPUEwiLCJ3IjpbeyJmbiI6IndnNGducXA2eTFmLU9UT01PVE9QTCIsInMiOiIxNiIsInAiOiIxMCwtMTAiLCJhIjoiMCJ9XX0.huP-LVq1yLCm9_znj9iGKqxlYWCff0H_-SaebZEx42Q/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Im05cmh1YW96MGVlZy1PVE9NT1RPUEwiLCJ3IjpbeyJmbiI6IndnNGducXA2eTFmLU9UT01PVE9QTCIsInMiOiIxNiIsInAiOiIxMCwtMTAiLCJhIjoiMCJ9XX0.huP-LVq1yLCm9_znj9iGKqxlYWCff0H_-SaebZEx42Q/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Im05cmh1YW96MGVlZy1PVE9NT1RPUEwiLCJ3IjpbeyJmbiI6IndnNGducXA2eTFmLU9UT01PVE9QTCIsInMiOiIxNiIsInAiOiIxMCwtMTAiLCJhIjoiMCJ9XX0.huP-LVq1yLCm9_znj9iGKqxlYWCff0H_-SaebZEx42Q/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6Im05cmh1YW96MGVlZy1PVE9NT1RPUEwifQ.g4u3NJyKCim9C8xcLQJ8CZWr2-X3wgeqUJm-bnPGdGM/image;s=1280x800
                                )

                            [25] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InVsZ2tnZndleHlzZjMtT1RPTU9UT1BMIn0.JqmcLrbZ8OWy7TlBr6fE3-lliygU2khSUATpRTxxBgk/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InVsZ2tnZndleHlzZjMtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.IxSdM6Kb1UM2IRguGjxQ925cuw9h9oYCC1qa6guqZGs/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InVsZ2tnZndleHlzZjMtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.IxSdM6Kb1UM2IRguGjxQ925cuw9h9oYCC1qa6guqZGs/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InVsZ2tnZndleHlzZjMtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.IxSdM6Kb1UM2IRguGjxQ925cuw9h9oYCC1qa6guqZGs/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InVsZ2tnZndleHlzZjMtT1RPTU9UT1BMIn0.JqmcLrbZ8OWy7TlBr6fE3-lliygU2khSUATpRTxxBgk/image;s=1280x800
                                )

                            [26] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjUybWV6NzF6ODhtdzEtT1RPTU9UT1BMIn0.HSVBUt3hhx971cPwyBottIp2VTahtFi6L4pKPBTE5QI/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjUybWV6NzF6ODhtdzEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.VHDMo0-ciMhDRWWoMTJXUhqWyziHuLhnJ8p_fquV5js/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjUybWV6NzF6ODhtdzEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.VHDMo0-ciMhDRWWoMTJXUhqWyziHuLhnJ8p_fquV5js/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjUybWV6NzF6ODhtdzEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.VHDMo0-ciMhDRWWoMTJXUhqWyziHuLhnJ8p_fquV5js/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6IjUybWV6NzF6ODhtdzEtT1RPTU9UT1BMIn0.HSVBUt3hhx971cPwyBottIp2VTahtFi6L4pKPBTE5QI/image;s=1280x800
                                )

                            [27] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InZqaXhuMW9qdDV6eDItT1RPTU9UT1BMIn0.juO93-NDkbM2fKKoOfB9Zobpq6EteFtugnJhdGAqRFo/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InZqaXhuMW9qdDV6eDItT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.9tNhCsRJhd7cxkcsZ45qpaOsGrWAVwGD-SknEEdC178/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InZqaXhuMW9qdDV6eDItT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.9tNhCsRJhd7cxkcsZ45qpaOsGrWAVwGD-SknEEdC178/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InZqaXhuMW9qdDV6eDItT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.9tNhCsRJhd7cxkcsZ45qpaOsGrWAVwGD-SknEEdC178/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6InZqaXhuMW9qdDV6eDItT1RPTU9UT1BMIn0.juO93-NDkbM2fKKoOfB9Zobpq6EteFtugnJhdGAqRFo/image;s=1280x800
                                )

                            [28] => Array
                                (
                                    [2048x1360] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImN6cWJvY3lkenJldTEtT1RPTU9UT1BMIn0.claT95KkT0SjicWDPSLdxFX-3tg0DM8CPX0_66CWebw/image;s=2048x1360
                                    [732x488] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImN6cWJvY3lkenJldTEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.IT8hfyZDbd8jXQIgXtpkxMXrN_M9QfTHXrlSkBhW-eI/image;s=732x488
                                    [148x110] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImN6cWJvY3lkenJldTEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.IT8hfyZDbd8jXQIgXtpkxMXrN_M9QfTHXrlSkBhW-eI/image;s=148x110
                                    [320x240] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImN6cWJvY3lkenJldTEtT1RPTU9UT1BMIiwidyI6W3siZm4iOiJ3ZzRnbnFwNnkxZi1PVE9NT1RPUEwiLCJzIjoiMTYiLCJwIjoiMTAsLTEwIiwiYSI6IjAifV19.IT8hfyZDbd8jXQIgXtpkxMXrN_M9QfTHXrlSkBhW-eI/image;s=320x240
                                    [1280x800] => https://ireland.apollo.olxcdn.com/v1/files/eyJmbiI6ImN6cWJvY3lkenJldTEtT1RPTU9UT1BMIn0.claT95KkT0SjicWDPSLdxFX-3tg0DM8CPX0_66CWebw/image;s=1280x800
                                )

                        )

                    [image_collection_id] => 1017823889
                    [new_used] => used
                    [visible_in_profile] => 1
                    [last_update_date] => 2025-05-19 16:31:20
                )

        )

    [category_sample] => Array
        (
            [id] => 99
            [names] => Array
                (
                    [pl] => Kombajny
                    [en] => Combines
                )

            [parent_id] => 1
            [code] => kombajny
            [path_url] => maszyny-rolnicze/kombajny
            [depth] => 2
            [parameters] => Array
                (
                    [0] => Array
                        (
                            [code] => make
                            [type] => select
                            [labels] => Array
                                (
                                    [pl] => Marka pojazdu
                                    [en] => Make
                                )

                            [required] => 1
                            [purpose] => both
                            [options] => Array
                                (
                                    [siloking] => Array
                                        (
                                            [pl] => SILOKING
                                            [en] => SILOKING
                                        )

                                    [rol-ex] => Array
                                        (
                                            [pl] => ROL-EX
                                            [en] => ROL-EX
                                        )

                                    [wolagri] => Array
                                        (
                                            [pl] => Wolagri
                                            [en] => Wolagri
                                        )

                                    [sodimac] => Array
                                        (
                                            [pl] => Sodimac
                                            [en] => Sodimac
                                        )

                                    [komatsu] => Array
                                        (
                                            [pl] => KOMATSU
                                            [en] => KOMATSU
                                        )

                                    [tolmet] => Array
                                        (
                                            [pl] => Tolmet
                                            [en] => Tolmet
                                        )

                                    [teknamotor] => Array
                                        (
                                            [pl] => TEKNAMOTOR
                                            [en] => TEKNAMOTOR
                                        )

                                    [bobrujsk-agromasz] => Array
                                        (
                                            [pl] => Bobrujsk Agromasz
                                            [en] => Bobrujsk Agromasz
                                        )

                                    [hako-werke] => Array
                                        (
                                            [pl] => Hako Werke
                                            [en] => Hako Werke
                                        )

                                    [czajkowski] => Array
                                        (
                                            [pl] => Czajkowski
                                            [en] => Czajkowski
                                        )

                                    [corvus] => Array
                                        (
                                            [pl] => CORVUS
                                            [en] => CORVUS
                                        )

                                    [dewulf] => Array
                                        (
                                            [pl] => Dewulf
                                            [en] => Dewulf
                                        )

                                    [simon] => Array
                                        (
                                            [pl] => Simon
                                            [en] => Simon
                                        )

                                    [brantner] => Array
                                        (
                                            [pl] => Brantner
                                            [en] => Brantner
                                        )

                                    [emmarol] => Array
                                        (
                                            [pl] => EMMAROL
                                            [en] => EMMAROL
                                        )

                                    [peruzzo] => Array
                                        (
                                            [pl] => Peruzzo
                                            [en] => Peruzzo
                                        )

                                    [john-greaves] => Array
                                        (
                                            [pl] => JOHN GREAVES
                                            [en] => JOHN GREAVES
                                        )

                                    [cargo] => Array
                                        (
                                            [pl] => Cargo
                                            [en] => Cargo
                                        )

                                    [he-va] => Array
                                        (
                                            [pl] => HE-VA
                                            [en] => HE-VA
                                        )

                                    [staltech] => Array
                                        (
                                            [pl] => Staltech
                                            [en] => Staltech
                                        )

                                    [dongfeng] => Array
                                        (
                                            [pl] => DongFeng
                                            [en] => DongFeng
                                        )

                                    [agrotechnology] => Array
                                        (
                                            [pl] => Agrotechnology
                                            [en] => Agrotechnology
                                        )

                                    [breviglieri] => Array
                                        (
                                            [pl] => Breviglieri
                                            [en] => Breviglieri
                                        )

                                    [agrimaster] => Array
                                        (
                                            [pl] => Agrimaster
                                            [en] => Agrimaster
                                        )

                                    [sfoggia] => Array
                                        (
                                            [pl] => Sfoggia
                                            [en] => Sfoggia
                                        )

                                    [mandam] => Array
                                        (
                                            [pl] => Mandam
                                            [en] => Mandam
                                        )

                                    [agripol] => Array
                                        (
                                            [pl] => AGRIPOL
                                            [en] => AGRIPOL
                                        )

                                    [as-motor] => Array
                                        (
                                            [pl] => AS MOTOR
                                            [en] => AS MOTOR
                                        )

                                    [excat] => Array
                                        (
                                            [pl] => Excat
                                            [en] => Excat
                                        )

                                    [bogballe] => Array
                                        (
                                            [pl] => Bogballe
                                            [en] => Bogballe
                                        )

                                    [chd] => Array
                                        (
                                            [pl] => CHD
                                            [en] => CHD
                                        )

                                    [sid] => Array
                                        (
                                            [pl] => SID
                                            [en] => SID
                                        )

                                    [taxon] => Array
                                        (
                                            [pl] => TAXON
                                            [en] => TAXON
                                        )

                                    [tech-projekt] => Array
                                        (
                                            [pl] => Tech-Project
                                            [en] => Tech-Project
                                        )

                                    [tozamet] => Array
                                        (
                                            [pl] => TOZAMET
                                            [en] => TOZAMET
                                        )

                                    [dammann] => Array
                                        (
                                            [pl] => DAMMANN 
                                            [en] => DAMMANN 
                                        )

                                    [ecorobotix] => Array
                                        (
                                            [pl] => ECOROBOTIX
                                            [en] => ECOROBOTIX
                                        )

                                    [frisma] => Array
                                        (
                                            [pl] => Frisma
                                            [en] => Frisma
                                        )

                                    [ls-traktor] => Array
                                        (
                                            [pl] => LS Traktor
                                            [en] => LS Traktor
                                        )

                                    [bednar] => Array
                                        (
                                            [pl] => BEDNAR
                                            [en] => BEDNAR
                                        )

                                    [umega] => Array
                                        (
                                            [pl] => UMEGA
                                            [en] => UMEGA
                                        )

                                    [locust] => Array
                                        (
                                            [pl] => LOCUST
                                            [en] => LOCUST
                                        )

                                    [rm] => Array
                                        (
                                            [pl] => RM
                                            [en] => RM
                                        )

                                    [caffini] => Array
                                        (
                                            [pl] => Caffini
                                            [en] => Caffini
                                        )

                                    [holaras] => Array
                                        (
                                            [pl] => Holaras
                                            [en] => Holaras
                                        )

                                    [rauch] => Array
                                        (
                                            [pl] => RAUCH
                                            [en] => RAUCH
                                        )

                                    [euromacchine] => Array
                                        (
                                            [pl] => EUROMACCHINE
                                            [en] => EUROMACCHINE
                                        )

                                    [accord] => Array
                                        (
                                            [pl] => Accord
                                            [en] => Accord
                                        )

                                    [kmk-maszyny] => Array
                                        (
                                            [pl] => KMK MASZYNY
                                            [en] => KMK MASZYNY
                                        )

                                    [agco] => Array
                                        (
                                            [pl] => Agco
                                            [en] => Agco
                                        )

                                    [antonio-carraro] => Array
                                        (
                                            [pl] => ANTONIO CARRARO
                                            [en] => ANTONIO CARRARO
                                        )

                                    [agro-masz] => Array
                                        (
                                            [pl] => Agro-Masz
                                            [en] => Agro-Masz
                                        )

                                    [aguirre] => Array
                                        (
                                            [pl] => Aguirre
                                            [en] => Aguirre
                                        )

                                    [rovatti] => Array
                                        (
                                            [pl] => ROVATTI
                                            [en] => ROVATTI
                                        )

                                    [allis] => Array
                                        (
                                            [pl] => Allis
                                            [en] => Allis
                                        )

                                    [alpego] => Array
                                        (
                                            [pl] => Alpego
                                            [en] => Alpego
                                        )

                                    [amazone] => Array
                                        (
                                            [pl] => Amazone
                                            [en] => Amazone
                                        )

                                    [arbos] => Array
                                        (
                                            [pl] => ARBOS
                                            [en] => ARBOS
                                        )

                                    [ausa] => Array
                                        (
                                            [pl] => Ausa
                                            [en] => Ausa
                                        )

                                    [avant] => Array
                                        (
                                            [pl] => Avant
                                            [en] => Avant
                                        )

                                    [bargam] => Array
                                        (
                                            [pl] => Bargam
                                            [en] => Bargam
                                        )

                                    [bcs] => Array
                                        (
                                            [pl] => BCS
                                            [en] => BCS
                                        )

                                    [belarus] => Array
                                        (
                                            [pl] => Belarus
                                            [en] => Belarus
                                        )

                                    [berthoud] => Array
                                        (
                                            [pl] => Berthoud
                                            [en] => Berthoud
                                        )

                                    [bizon] => Array
                                        (
                                            [pl] => Bizon
                                            [en] => Bizon
                                        )

                                    [bobcat] => Array
                                        (
                                            [pl] => Bobcat
                                            [en] => Bobcat
                                        )

                                    [bonnel] => Array
                                        (
                                            [pl] => Bonnel
                                            [en] => Bonnel
                                        )

                                    [brandi] => Array
                                        (
                                            [pl] => Brandi
                                            [en] => Brandi
                                        )

                                    [bourgoin] => Array
                                        (
                                            [pl] => Bourgoin
                                            [en] => Bourgoin
                                        )

                                    [braud] => Array
                                        (
                                            [pl] => Braud
                                            [en] => Braud
                                        )

                                    [carraro] => Array
                                        (
                                            [pl] => Carraro
                                            [en] => Carraro
                                        )

                                    [caruelle] => Array
                                        (
                                            [pl] => Caruelle
                                            [en] => Caruelle
                                        )

                                    [case-ih] => Array
                                        (
                                            [pl] => Case IH
                                            [en] => Case IH
                                        )

                                    [caterpillar] => Array
                                        (
                                            [pl] => Caterpillar
                                            [en] => Caterpillar
                                        )

                                    [celli] => Array
                                        (
                                            [pl] => Celli
                                            [en] => Celli
                                        )

                                    [challenger] => Array
                                        (
                                            [pl] => Challenger
                                            [en] => Challenger
                                        )

                                    [claas] => Array
                                        (
                                            [pl] => Claas
                                            [en] => Claas
                                        )

                                    [cmt] => Array
                                        (
                                            [pl] => CMT
                                            [en] => CMT
                                        )

                                    [cochet] => Array
                                        (
                                            [pl] => Cochet
                                            [en] => Cochet
                                        )

                                    [crystal] => Array
                                        (
                                            [pl] => Crystal
                                            [en] => Crystal
                                        )

                                    [damax] => Array
                                        (
                                            [pl] => Damax
                                            [en] => Damax
                                        )

                                    [david-brown] => Array
                                        (
                                            [pl] => David Brown
                                            [en] => David Brown
                                        )

                                    [deutz-fahr] => Array
                                        (
                                            [pl] => Deutz-Fahr
                                            [en] => Deutz-Fahr
                                        )

                                    [dieci] => Array
                                        (
                                            [pl] => Dieci
                                            [en] => Dieci
                                        )

                                    [dubex] => Array
                                        (
                                            [pl] => Dubex
                                            [en] => Dubex
                                        )

                                    [eurotrak] => Array
                                        (
                                            [pl] => Eurotrac
                                            [en] => Eurotrac
                                        )

                                    [evrard] => Array
                                        (
                                            [pl] => Evrard
                                            [en] => Evrard
                                        )

                                    [falc] => Array
                                        (
                                            [pl] => Falc
                                            [en] => Falc
                                        )

                                    [faresin] => Array
                                        (
                                            [pl] => Faresin
                                            [en] => Faresin
                                        )

                                    [farmer] => Array
                                        (
                                            [pl] => Farmer
                                            [en] => Farmer
                                        )

                                    [farmtrack] => Array
                                        (
                                            [pl] => Farmtrac
                                            [en] => Farmtrac
                                        )

                                    [fendt] => Array
                                        (
                                            [pl] => Fendt
                                            [en] => Fendt
                                        )

                                    [ferju] => Array
                                        (
                                            [pl] => Ferju
                                            [en] => Ferju
                                        )

                                    [fiat] => Array
                                        (
                                            [pl] => Fiat
                                            [en] => Fiat
                                        )

                                    [fiona] => Array
                                        (
                                            [pl] => Fiona
                                            [en] => Fiona
                                        )

                                    [ford] => Array
                                        (
                                            [pl] => Ford
                                            [en] => Ford
                                        )

                                    [foton] => Array
                                        (
                                            [pl] => Foton
                                            [en] => Foton
                                        )

                                    [gaspardo] => Array
                                        (
                                            [pl] => Gaspardo
                                            [en] => Gaspardo
                                        )

                                    [gleaner] => Array
                                        (
                                            [pl] => Gleaner
                                            [en] => Gleaner
                                        )

                                    [goldoni] => Array
                                        (
                                            [pl] => Goldoni
                                            [en] => Goldoni
                                        )

                                    [hand-made] => Array
                                        (
                                            [pl] => Hand-made
                                            [en] => Hand-made
                                        )

                                    [hanomag] => Array
                                        (
                                            [pl] => Hanomag
                                            [en] => Hanomag
                                        )

                                    [hardi] => Array
                                        (
                                            [pl] => Hardi
                                            [en] => Hardi
                                        )

                                    [hassia] => Array
                                        (
                                            [pl] => Hassia
                                            [en] => Hassia
                                        )

                                    [hinomoto] => Array
                                        (
                                            [pl] => Hinomoto
                                            [en] => Hinomoto
                                        )

                                    [holder] => Array
                                        (
                                            [pl] => Holder
                                            [en] => Holder
                                        )

                                    [horsch] => Array
                                        (
                                            [pl] => Horsch
                                            [en] => Horsch
                                        )

                                    [howard] => Array
                                        (
                                            [pl] => Howard
                                            [en] => Howard
                                        )

                                    [hurlimann] => Array
                                        (
                                            [pl] => Hurlimann
                                            [en] => Hurlimann
                                        )

                                    [hydramet] => Array
                                        (
                                            [pl] => Hydramet
                                            [en] => Hydramet
                                        )

                                    [ick] => Array
                                        (
                                            [pl] => ICK
                                            [en] => ICK
                                        )

                                    [ifa] => Array
                                        (
                                            [pl] => Ifa
                                            [en] => Ifa
                                        )

                                    [irum] => Array
                                        (
                                            [pl] => IRUM
                                            [en] => IRUM
                                        )

                                    [iseki] => Array
                                        (
                                            [pl] => Iseki
                                            [en] => Iseki
                                        )

                                    [jcb] => Array
                                        (
                                            [pl] => JCB
                                            [en] => JCB
                                        )

                                    [john-deere] => Array
                                        (
                                            [pl] => John Deere
                                            [en] => John Deere
                                        )

                                    [joskin] => Array
                                        (
                                            [pl] => Joskin
                                            [en] => Joskin
                                        )

                                    [kioti] => Array
                                        (
                                            [pl] => Kioti
                                            [en] => Kioti
                                        )

                                    [kirong] => Array
                                        (
                                            [pl] => Kirong
                                            [en] => Kirong
                                        )

                                    [kockerling] => Array
                                        (
                                            [pl] => Köckerling
                                            [en] => Köckerling
                                        )

                                    [kongskilde] => Array
                                        (
                                            [pl] => Kongskilde
                                            [en] => Kongskilde
                                        )

                                    [krone] => Array
                                        (
                                            [pl] => Krone
                                            [en] => Krone
                                        )

                                    [krukowiak] => Array
                                        (
                                            [pl] => Krukowiak
                                            [en] => Krukowiak
                                        )

                                    [kubota] => Array
                                        (
                                            [pl] => Kubota
                                            [en] => Kubota
                                        )

                                    [kuhn] => Array
                                        (
                                            [pl] => Kuhn
                                            [en] => Kuhn
                                        )

                                    [kverneland] => Array
                                        (
                                            [pl] => Kverneland
                                            [en] => Kverneland
                                        )

                                    [lamborgini] => Array
                                        (
                                            [pl] => Lamborghini
                                            [en] => Lamborghini
                                        )

                                    [landini] => Array
                                        (
                                            [pl] => Landini
                                            [en] => Landini
                                        )

                                    [laverda] => Array
                                        (
                                            [pl] => Laverda
                                            [en] => Laverda
                                        )

                                    [lely] => Array
                                        (
                                            [pl] => Lely
                                            [en] => Lely
                                        )

                                    [lemtech] => Array
                                        (
                                            [pl] => Lemtech
                                            [en] => Lemtech
                                        )

                                    [lemken] => Array
                                        (
                                            [pl] => Lemken
                                            [en] => Lemken
                                        )

                                    [ma-ag] => Array
                                        (
                                            [pl] => Ma-Ag
                                            [en] => Ma-Ag
                                        )

                                    [macks] => Array
                                        (
                                            [pl] => Macks
                                            [en] => Macks
                                        )

                                    [mahindra] => Array
                                        (
                                            [pl] => Mahindra
                                            [en] => Mahindra
                                        )

                                    [manitou] => Array
                                        (
                                            [pl] => Manitou
                                            [en] => Manitou
                                        )

                                    [marzia] => Array
                                        (
                                            [pl] => Marzia
                                            [en] => Marzia
                                        )

                                    [mascio] => Array
                                        (
                                            [pl] => Maschio
                                            [en] => Maschio
                                        )

                                    [massey-ferguson] => Array
                                        (
                                            [pl] => Massey Ferguson
                                            [en] => Massey Ferguson
                                        )

                                    [mat] => Array
                                        (
                                            [pl] => MAT
                                            [en] => MAT
                                        )

                                    [mercedes-benz] => Array
                                        (
                                            [pl] => Mercedes-Benz
                                            [en] => Mercedes-Benz
                                        )

                                    [mccormic] => Array
                                        (
                                            [pl] => McCormick
                                            [en] => McCormick
                                        )

                                    [mecanica] => Array
                                        (
                                            [pl] => Mecanica
                                            [en] => Mecanica
                                        )

                                    [meprozet] => Array
                                        (
                                            [pl] => Meprozet
                                            [en] => Meprozet
                                        )

                                    [moskit] => Array
                                        (
                                            [pl] => Moskit
                                            [en] => Moskit
                                        )

                                    [mtz] => Array
                                        (
                                            [pl] => MTZ
                                            [en] => MTZ
                                        )

                                    [new-holland] => Array
                                        (
                                            [pl] => New Holland
                                            [en] => New Holland
                                        )

                                    [nodet] => Array
                                        (
                                            [pl] => Nodet
                                            [en] => Nodet
                                        )

                                    [pasquali] => Array
                                        (
                                            [pl] => Pasquali
                                            [en] => Pasquali
                                        )

                                    [toscano] => Array
                                        (
                                            [pl] => TOSCANO
                                            [en] => TOSCANO
                                        )

                                    [pichon] => Array
                                        (
                                            [pl] => Pichon
                                            [en] => Pichon
                                        )

                                    [pom-augustow] => Array
                                        (
                                            [pl] => POM Augustów
                                            [en] => POM Augustów
                                        )

                                    [pom-ltd] => Array
                                        (
                                            [pl] => POM Ltd.
                                            [en] => POM Ltd.
                                        )

                                    [pomarol] => Array
                                        (
                                            [pl] => Pomarol
                                            [en] => Pomarol
                                        )

                                    [prokmar] => Array
                                        (
                                            [pl] => Prokmar
                                            [en] => Prokmar
                                        )

                                    [pronar] => Array
                                        (
                                            [pl] => Pronar
                                            [en] => Pronar
                                        )

                                    [rabe] => Array
                                        (
                                            [pl] => Rabe
                                            [en] => Rabe
                                        )

                                    [rau] => Array
                                        (
                                            [pl] => RAU
                                            [en] => RAU
                                        )

                                    [renault] => Array
                                        (
                                            [pl] => Renault
                                            [en] => Renault
                                        )

                                    [roger] => Array
                                        (
                                            [pl] => Roger
                                            [en] => Roger
                                        )

                                    [ruris] => Array
                                        (
                                            [pl] => Ruris
                                            [en] => Ruris
                                        )

                                    [samasz] => Array
                                        (
                                            [pl] => SaMasz
                                            [en] => SaMasz
                                        )

                                    [same] => Array
                                        (
                                            [pl] => Same
                                            [en] => Same
                                        )

                                    [sampo] => Array
                                        (
                                            [pl] => Sampo
                                            [en] => Sampo
                                        )

                                    [sauerburger] => Array
                                        (
                                            [pl] => Sauerburger
                                            [en] => Sauerburger
                                        )

                                    [schaffer] => Array
                                        (
                                            [pl] => Schaffer
                                            [en] => Schaffer
                                        )

                                    [seguip] => Array
                                        (
                                            [pl] => Seguip
                                            [en] => Seguip
                                        )

                                    [sigma] => Array
                                        (
                                            [pl] => Sigma
                                            [en] => Sigma
                                        )

                                    [sipma] => Array
                                        (
                                            [pl] => Sipma
                                            [en] => Sipma
                                        )

                                    [sola] => Array
                                        (
                                            [pl] => Sola
                                            [en] => Sola
                                        )

                                    [someca] => Array
                                        (
                                            [pl] => Someca
                                            [en] => Someca
                                        )

                                    [steyer] => Array
                                        (
                                            [pl] => Steyer
                                            [en] => Steyer
                                        )

                                    [steyr] => Array
                                        (
                                            [pl] => Steyr
                                            [en] => Steyr
                                        )

                                    [everun] => Array
                                        (
                                            [pl] => Everun
                                            [en] => Everun
                                        )

                                    [agro-osek] => Array
                                        (
                                            [pl] => AGRO-OSEK
                                            [en] => AGRO-OSEK
                                        )

                                    [bauer] => Array
                                        (
                                            [pl] => BAUER
                                            [en] => BAUER
                                        )

                                    [sulky] => Array
                                        (
                                            [pl] => Sulky
                                            [en] => Sulky
                                        )

                                    [tecnoma] => Array
                                        (
                                            [pl] => Tecnoma
                                            [en] => Tecnoma
                                        )

                                    [tenias] => Array
                                        (
                                            [pl] => Tenias
                                            [en] => Tenias
                                        )

                                    [thaler] => Array
                                        (
                                            [pl] => Thaler
                                            [en] => Thaler
                                        )

                                    [trioliet] => Array
                                        (
                                            [pl] => Trioliet
                                            [en] => Trioliet
                                        )

                                    [tym] => Array
                                        (
                                            [pl] => TYM
                                            [en] => TYM
                                        )

                                    [universal] => Array
                                        (
                                            [pl] => Universal
                                            [en] => Universal
                                        )

                                    [ursus] => Array
                                        (
                                            [pl] => Ursus
                                            [en] => Ursus
                                        )

                                    [utb] => Array
                                        (
                                            [pl] => UTB
                                            [en] => UTB
                                        )

                                    [vaderstadt] => Array
                                        (
                                            [pl] => Väderstad
                                            [en] => Väderstad
                                        )

                                    [valmet] => Array
                                        (
                                            [pl] => Valmet
                                            [en] => Valmet
                                        )

                                    [valpadana] => Array
                                        (
                                            [pl] => Valpadana
                                            [en] => Valpadana
                                        )

                                    [valtec] => Array
                                        (
                                            [pl] => Valtec
                                            [en] => Valtec
                                        )

                                    [valtra] => Array
                                        (
                                            [pl] => Valtra
                                            [en] => Valtra
                                        )

                                    [vicon] => Array
                                        (
                                            [pl] => Vicon
                                            [en] => Vicon
                                        )

                                    [vogel-noot] => Array
                                        (
                                            [pl] => Vogel&Noot
                                            [en] => Vogel&Noot
                                        )

                                    [weidemann] => Array
                                        (
                                            [pl] => Weidemann
                                            [en] => Weidemann
                                        )

                                    [wielton] => Array
                                        (
                                            [pl] => Wielton
                                            [en] => Wielton
                                        )

                                    [yanmar] => Array
                                        (
                                            [pl] => Yanmar
                                            [en] => Yanmar
                                        )

                                    [zetor] => Array
                                        (
                                            [pl] => Zetor
                                            [en] => Zetor
                                        )

                                    [other] => Array
                                        (
                                            [pl] => Inny
                                            [en] => Other
                                        )

                                    [agrex] => Array
                                        (
                                            [pl] => Agrex
                                            [en] => Agrex
                                        )

                                    [agro-factory] => Array
                                        (
                                            [pl] => Agro Factory
                                            [en] => Agro Factory
                                        )

                                    [agromet-mogilno] => Array
                                        (
                                            [pl] => Agromet Mogilno
                                            [en] => Agromet Mogilno
                                        )

                                    [agrostoj-pelhrimov] => Array
                                        (
                                            [pl] => Agrostoj Pelhrimov
                                            [en] => Agrostoj Pelhrimov
                                        )

                                    [annaburger] => Array
                                        (
                                            [pl] => Annaburger
                                            [en] => Annaburger
                                        )

                                    [banrol] => Array
                                        (
                                            [pl] => Banrol
                                            [en] => Banrol
                                        )

                                    [bielrol] => Array
                                        (
                                            [pl] => Bielrol
                                            [en] => Bielrol
                                        )

                                    [bmhorse] => Array
                                        (
                                            [pl] => Bmhorse
                                            [en] => Bmhorse
                                        )

                                    [bomet] => Array
                                        (
                                            [pl] => Bomet
                                            [en] => Bomet
                                        )

                                    [cynkomet] => Array
                                        (
                                            [pl] => Cynkomet
                                            [en] => Cynkomet
                                        )

                                    [gregoire-besson] => Array
                                        (
                                            [pl] => Gregoire Besson
                                            [en] => Gregoire Besson
                                        )

                                    [grimme] => Array
                                        (
                                            [pl] => Grimme
                                            [en] => Grimme
                                        )

                                    [inter-tech] => Array
                                        (
                                            [pl] => Inter-tech
                                            [en] => Inter-tech
                                        )

                                    [jar-met] => Array
                                        (
                                            [pl] => Jar-met
                                            [en] => Jar-met
                                        )

                                    [langren] => Array
                                        (
                                            [pl] => Langren
                                            [en] => Langren
                                        )

                                    [leboulch] => Array
                                        (
                                            [pl] => Leboulch
                                            [en] => Leboulch
                                        )

                                    [lisicki] => Array
                                        (
                                            [pl] => Lisicki
                                            [en] => Lisicki
                                        )

                                    [metal-technik] => Array
                                        (
                                            [pl] => Metal-Technik
                                            [en] => Metal-Technik
                                        )

                                    [pottinger] => Array
                                        (
                                            [pl] => Pottinger
                                            [en] => Pottinger
                                        )

                                    [sip] => Array
                                        (
                                            [pl] => SIP
                                            [en] => SIP
                                        )

                                    [sonarol] => Array
                                        (
                                            [pl] => Sonarol
                                            [en] => Sonarol
                                        )

                                    [strautmann] => Array
                                        (
                                            [pl] => Strautmann
                                            [en] => Strautmann
                                        )

                                    [sukov] => Array
                                        (
                                            [pl] => Sukov
                                            [en] => Sukov
                                        )

                                    [traclift] => Array
                                        (
                                            [pl] => TracLift
                                            [en] => TracLift
                                        )

                                    [agrisem] => Array
                                        (
                                            [pl] => Agrisem
                                            [en] => Agrisem
                                        )

                                    [danfoil] => Array
                                        (
                                            [pl] => Danfoil
                                            [en] => Danfoil
                                        )

                                    [marco-polo] => Array
                                        (
                                            [pl] => Marco Polo
                                            [en] => Marco Polo
                                        )

                                    [blanchard] => Array
                                        (
                                            [pl] => Blanchard
                                            [en] => Blanchard
                                        )

                                    [great-plains] => Array
                                        (
                                            [pl] => Great Plains
                                            [en] => Great Plains
                                        )

                                    [dexwal-bis] => Array
                                        (
                                            [pl] => Dexwal Bis
                                            [en] => Dexwal Bis
                                        )

                                    [henart] => Array
                                        (
                                            [pl] => Henart
                                            [en] => Henart
                                        )

                                    [giant] => Array
                                        (
                                            [pl] => Giant
                                            [en] => Giant
                                        )

                                    [lovol] => Array
                                        (
                                            [pl] => Lovol
                                            [en] => Lovol
                                        )

                                    [monosem] => Array
                                        (
                                            [pl] => Monosem
                                            [en] => Monosem
                                        )

                                    [metal-fach] => Array
                                        (
                                            [pl] => Metal Fach
                                            [en] => Metal Fach
                                        )

                                    [gunter-grossmann] => Array
                                        (
                                            [pl] => GUNTER-GROSSMANN
                                            [en] => GUNTER-GROSSMANN
                                        )

                                    [grassrol] => Array
                                        (
                                            [pl] => Grassrol
                                            [en] => Grassrol
                                        )

                                    [krpan] => Array
                                        (
                                            [pl] => Krpan
                                            [en] => Krpan
                                        )

                                    [unia] => Array
                                        (
                                            [pl] => Unia
                                            [en] => Unia
                                        )

                                    [kraft] => Array
                                        (
                                            [pl] => Kraft
                                            [en] => Kraft
                                        )

                                    [moro-aratri] => Array
                                        (
                                            [pl] => Moro Aratri
                                            [en] => Moro Aratri
                                        )

                                    [ponsse] => Array
                                        (
                                            [pl] => Ponsse
                                            [en] => Ponsse
                                        )

                                    [holmer] => Array
                                        (
                                            [pl] => Holmer
                                            [en] => Holmer
                                        )

                                    [remprodex] => Array
                                        (
                                            [pl] => Remprodex
                                            [en] => Remprodex
                                        )

                                    [rolmako] => Array
                                        (
                                            [pl] => Rolmako
                                            [en] => Rolmako
                                        )

                                    [kinshofer] => Array
                                        (
                                            [pl] => Kinshofer
                                            [en] => Kinshofer
                                        )

                                    [sany] => Array
                                        (
                                            [pl] => Sany
                                            [en] => Sany
                                        )

                                    [weycor] => Array
                                        (
                                            [pl] => Weycor
                                            [en] => Weycor
                                        )

                                    [kowalski] => Array
                                        (
                                            [pl] => Kowalski
                                            [en] => Kowalski
                                        )

                                    [merlo] => Array
                                        (
                                            [pl] => Merlo
                                            [en] => Merlo
                                        )

                                    [claydon] => Array
                                        (
                                            [pl] => Claydon
                                            [en] => Claydon
                                        )

                                    [zanila] => Array
                                        (
                                            [pl] => Zanila
                                            [en] => Zanila
                                        )

                                    [ozdoken] => Array
                                        (
                                            [pl] => Ozdoken
                                            [en] => Ozdoken
                                        )

                                    [fimaks] => Array
                                        (
                                            [pl] => Fimaks
                                            [en] => Fimaks
                                        )

                                    [wizard] => Array
                                        (
                                            [pl] => Wizard
                                            [en] => Wizard
                                        )

                                    [befard] => Array
                                        (
                                            [pl] => Befard
                                            [en] => Befard
                                        )

                                    [promar] => Array
                                        (
                                            [pl] => Promar
                                            [en] => Promar
                                        )

                                    [rostselmash] => Array
                                        (
                                            [pl] => Rostselmash
                                            [en] => Rostselmash
                                        )

                                    [striegel] => Array
                                        (
                                            [pl] => Striegel
                                            [en] => Striegel
                                        )

                                    [bergman] => Array
                                        (
                                            [pl] => Bergmann
                                            [en] => Bergmann
                                        )

                                    [goweil] => Array
                                        (
                                            [pl] => Goweil
                                            [en] => Goweil
                                        )

                                    [kemper] => Array
                                        (
                                            [pl] => Kemper
                                            [en] => Kemper
                                        )

                                    [capello] => Array
                                        (
                                            [pl] => Capello
                                            [en] => Capello
                                        )

                                    [piast] => Array
                                        (
                                            [pl] => Piast
                                            [en] => Piast
                                        )

                                    [hisarlar] => Array
                                        (
                                            [pl] => Hisarlar
                                            [en] => Hisarlar
                                        )

                                    [del-morino] => Array
                                        (
                                            [pl] => Del Morino
                                            [en] => Del Morino
                                        )

                                    [tierre] => Array
                                        (
                                            [pl] => Tierre
                                            [en] => Tierre
                                        )

                                    [harmak] => Array
                                        (
                                            [pl] => Harmak
                                            [en] => Harmak
                                        )

                                    [tutkun] => Array
                                        (
                                            [pl] => Tutkun
                                            [en] => Tutkun
                                        )

                                    [unlu] => Array
                                        (
                                            [pl] => Unlu
                                            [en] => Unlu
                                        )

                                    [aksan-kardan] => Array
                                        (
                                            [pl] => Aksan Kardan
                                            [en] => Aksan Kardan
                                        )

                                    [gencsan-kardan] => Array
                                        (
                                            [pl] => Gencsan Kardan
                                            [en] => Gencsan Kardan
                                        )

                                    [gianni-ferrari] => Array
                                        (
                                            [pl] => Gianni Ferrari
                                            [en] => Gianni Ferrari
                                        )

                                    [agro-tom] => Array
                                        (
                                            [pl] => Agro-tom
                                            [en] => Agro-tom
                                        )

                                    [gamatechnik] => Array
                                        (
                                            [pl] => Gamatechnik
                                            [en] => Gamatechnik
                                        )

                                    [solis] => Array
                                        (
                                            [pl] => Solis
                                            [en] => Solis
                                        )

                                    [edlington] => Array
                                        (
                                            [pl] => Edlington
                                            [en] => Edlington
                                        )

                                    [miedema] => Array
                                        (
                                            [pl] => Miedema
                                            [en] => Miedema
                                        )

                                    [tong] => Array
                                        (
                                            [pl] => Tong
                                            [en] => Tong
                                        )

                                    [peal] => Array
                                        (
                                            [pl] => Peal
                                            [en] => Peal
                                        )

                                    [herbert] => Array
                                        (
                                            [pl] => Herbert
                                            [en] => Herbert
                                        )

                                    [downs] => Array
                                        (
                                            [pl] => Downs
                                            [en] => Downs
                                        )

                                    [samon] => Array
                                        (
                                            [pl] => Samon
                                            [en] => Samon
                                        )

                                    [keulmac] => Array
                                        (
                                            [pl] => Keulmac
                                            [en] => Keulmac
                                        )

                                    [haith] => Array
                                        (
                                            [pl] => Haith
                                            [en] => Haith
                                        )

                                    [newtec] => Array
                                        (
                                            [pl] => Newtec
                                            [en] => Newtec
                                        )

                                    [bijlsma-hercules] => Array
                                        (
                                            [pl] => Bijlsma Hercules
                                            [en] => Bijlsma Hercules
                                        )

                                    [checchi-magli] => Array
                                        (
                                            [pl] => Checchi & Magli
                                            [en] => Checchi & Magli
                                        )

                                    [gruse] => Array
                                        (
                                            [pl] => Gruse
                                            [en] => Gruse
                                        )

                                    [rumptstad] => Array
                                        (
                                            [pl] => Rumptstad
                                            [en] => Rumptstad
                                        )

                                    [m-rol] => Array
                                        (
                                            [pl] => M-ROL
                                            [en] => M-ROL
                                        )

                                    [ferri] => Array
                                        (
                                            [pl] => Ferri
                                            [en] => Ferri
                                        )

                                    [stroer] => Array
                                        (
                                            [pl] => Stroer
                                            [en] => Stroer
                                        )

                                    [kuar] => Array
                                        (
                                            [pl] => Kuar
                                            [en] => Kuar
                                        )

                                    [martin] => Array
                                        (
                                            [pl] => Martin
                                            [en] => Martin
                                        )

                                    [noremat] => Array
                                        (
                                            [pl] => Noremat
                                            [en] => Noremat
                                        )

                                    [grutech] => Array
                                        (
                                            [pl] => Grutech
                                            [en] => Grutech
                                        )

                                    [haybuster] => Array
                                        (
                                            [pl] => Haybuster
                                            [en] => Haybuster
                                        )

                                    [empro] => Array
                                        (
                                            [pl] => Empro
                                            [en] => Empro
                                        )

                                    [agro-klon] => Array
                                        (
                                            [pl] => AGRO-KLON
                                            [en] => AGRO-KLON
                                        )

                                    [overum] => Array
                                        (
                                            [pl] => Överum
                                            [en] => Överum
                                        )

                                    [agro-lift] => Array
                                        (
                                            [pl] => Agro-Lift
                                            [en] => Agro-Lift
                                        )

                                    [geringhoff] => Array
                                        (
                                            [pl] => Geringhoff
                                            [en] => Geringhoff
                                        )

                                    [olimac] => Array
                                        (
                                            [pl] => Olimac
                                            [en] => Olimac
                                        )

                                    [fantini] => Array
                                        (
                                            [pl] => Fantini
                                            [en] => Fantini
                                        )

                                    [oros] => Array
                                        (
                                            [pl] => Oros
                                            [en] => Oros
                                        )

                                    [idass] => Array
                                        (
                                            [pl] => Idass
                                            [en] => Idass
                                        )

                                    [dominoni] => Array
                                        (
                                            [pl] => Dominoni
                                            [en] => Dominoni
                                        )

                                    [agrola] => Array
                                        (
                                            [pl] => Agrola
                                            [en] => Agrola
                                        )

                                    [quicke] => Array
                                        (
                                            [pl] => Quicke
                                            [en] => Quicke
                                        )

                                    [stoll] => Array
                                        (
                                            [pl] => Stoll
                                            [en] => Stoll
                                        )

                                    [bredal] => Array
                                        (
                                            [pl] => Bredal
                                            [en] => Bredal
                                        )

                                    [mchale] => Array
                                        (
                                            [pl] => McHale
                                            [en] => McHale
                                        )

                                    [expom] => Array
                                        (
                                            [pl] => Expom
                                            [en] => Expom
                                        )

                                    [sky] => Array
                                        (
                                            [pl] => Sky
                                            [en] => Sky
                                        )

                                    [basak] => Array
                                        (
                                            [pl] => Basak
                                            [en] => Basak
                                        )

                                    [bury] => Array
                                        (
                                            [pl] => BURY
                                            [en] => BURY
                                        )

                                    [mascar] => Array
                                        (
                                            [pl] => Mascar
                                            [en] => Mascar
                                        )

                                    [fella] => Array
                                        (
                                            [pl] => FELLA
                                            [en] => FELLA
                                        )

                                    [samson] => Array
                                        (
                                            [pl] => Samson
                                            [en] => Samson
                                        )

                                    [matermacc] => Array
                                        (
                                            [pl] => Matermacc
                                            [en] => Matermacc
                                        )

                                    [landstal] => Array
                                        (
                                            [pl] => Landstal
                                            [en] => Landstal
                                        )

                                    [wiedenmann] => Array
                                        (
                                            [pl] => Wiedenmann
                                            [en] => Wiedenmann
                                        )

                                    [muthing] => Array
                                        (
                                            [pl] => Muthing
                                            [en] => Muthing
                                        )

                                    [honda] => Array
                                        (
                                            [pl] => Honda
                                            [en] => Honda
                                        )

                                    [boguslav] => Array
                                        (
                                            [pl] => Boguslav
                                            [en] => Boguslav
                                        )

                                    [farmtech] => Array
                                        (
                                            [pl] => Farmtech
                                            [en] => Farmtech
                                        )

                                    [ozduman] => Array
                                        (
                                            [pl] => Ozduman
                                            [en] => Ozduman
                                        )

                                    [enorossi] => Array
                                        (
                                            [pl] => Enorossi
                                            [en] => Enorossi
                                        )

                                    [tume] => Array
                                        (
                                            [pl] => Tume
                                            [en] => Tume
                                        )

                                    [vermeer] => Array
                                        (
                                            [pl] => Vermeer
                                            [en] => Vermeer
                                        )

                                    [gomselmash] => Array
                                        (
                                            [pl] => Gomselmash
                                            [en] => Gomselmash
                                        )

                                    [kobzarenko] => Array
                                        (
                                            [pl] => Kobzarenko
                                            [en] => Kobzarenko
                                        )

                                    [veles-agro] => Array
                                        (
                                            [pl] => Veles Agro
                                            [en] => Veles Agro
                                        )

                                    [veles-alt] => Array
                                        (
                                            [pl] => Veles Alt
                                            [en] => Veles Alt
                                        )

                                    [promagro] => Array
                                        (
                                            [pl] => Promagro
                                            [en] => Promagro
                                        )

                                    [perard] => Array
                                        (
                                            [pl] => Perard
                                            [en] => Perard
                                        )

                                    [brochard] => Array
                                        (
                                            [pl] => Brochard
                                            [en] => Brochard
                                        )

                                    [armor] => Array
                                        (
                                            [pl] => Armor
                                            [en] => Armor
                                        )

                                    [zdt] => Array
                                        (
                                            [pl] => ZDT
                                            [en] => ZDT
                                        )

                                    [horus] => Array
                                        (
                                            [pl] => Horus
                                            [en] => Horus
                                        )

                                    [regent] => Array
                                        (
                                            [pl] => Regent
                                            [en] => Regent
                                        )

                                    [farmet] => Array
                                        (
                                            [pl] => Farmet
                                            [en] => Farmet
                                        )

                                    [sam] => Array
                                        (
                                            [pl] => SAM
                                            [en] => SAM
                                        )

                                    [aps] => Array
                                        (
                                            [pl] => APS
                                            [en] => APS
                                        )

                                    [rivierre-greenland] => Array
                                        (
                                            [pl] => Rivierre Greenland
                                            [en] => Rivierre Greenland
                                        )

                                    [casella] => Array
                                        (
                                            [pl] => Casella
                                            [en] => Casella
                                        )

                                    [mar-pol] => Array
                                        (
                                            [pl] => MAR-POL
                                            [en] => MAR-POL
                                        )

                                    [orkan] => Array
                                        (
                                            [pl] => Orkan
                                            [en] => Orkan
                                        )

                                    [hattat] => Array
                                        (
                                            [pl] => Hattat
                                            [en] => Hattat
                                        )

                                    [nex] => Array
                                        (
                                            [pl] => NEX
                                            [en] => NEX
                                        )

                                    [talex] => Array
                                        (
                                            [pl] => Talex
                                            [en] => Talex
                                        )

                                    [okayama] => Array
                                        (
                                            [pl] => Okayama
                                            [en] => Okayama
                                        )

                                    [kingway] => Array
                                        (
                                            [pl] => Kingway
                                            [en] => Kingway
                                        )

                                    [amj-agro] => Array
                                        (
                                            [pl] => AMJ Agro
                                            [en] => AMJ Agro
                                        )

                                    [agro-instal] => Array
                                        (
                                            [pl] => Agro-Instal
                                            [en] => Agro-Instal
                                        )

                                    [agromex] => Array
                                        (
                                            [pl] => Agromex
                                            [en] => Agromex
                                        )

                                    [cub_cadet] => Array
                                        (
                                            [pl] => Cub Cadet
                                            [en] => Cub Cadet
                                        )

                                    [husqvarna] => Array
                                        (
                                            [pl] => Husqvarna
                                            [en] => Husqvarna
                                        )

                                    [craftsman] => Array
                                        (
                                            [pl] => Craftsman
                                            [en] => Craftsman
                                        )

                                    [ariens] => Array
                                        (
                                            [pl] => Ariens
                                            [en] => Ariens
                                        )

                                    [uniforest] => Array
                                        (
                                            [pl] => Uniforest
                                            [en] => Uniforest
                                        )

                                    [feraboli] => Array
                                        (
                                            [pl] => Feraboli
                                            [en] => Feraboli
                                        )

                                    [gallignani] => Array
                                        (
                                            [pl] => Gallignani
                                            [en] => Gallignani
                                        )

                                    [rivierre-casalis] => Array
                                        (
                                            [pl] => Rivierre Casalis
                                            [en] => Rivierre Casalis
                                        )

                                    [kaweco] => Array
                                        (
                                            [pl] => Kaweco
                                            [en] => Kaweco
                                        )

                                    [lozova] => Array
                                        (
                                            [pl] => Lozova
                                            [en] => Lozova
                                        )

                                    [opall-agri] => Array
                                        (
                                            [pl] => Opall Agri
                                            [en] => Opall Agri
                                        )

                                    [yaroslavich] => Array
                                        (
                                            [pl] => Yaroslavich
                                            [en] => Yaroslavich
                                        )

                                    [mitsubishi] => Array
                                        (
                                            [pl] => Mitsubishi
                                            [en] => Mitsubishi
                                        )

                                    [hummel] => Array
                                        (
                                            [pl] => Hummel
                                            [en] => Hummel
                                        )

                                    [jastech] => Array
                                        (
                                            [pl] => Jastech
                                            [en] => Jastech
                                        )

                                    [metal-plast] => Array
                                        (
                                            [pl] => Metal-Plast
                                            [en] => Metal-Plast
                                        )

                                    [koppl] => Array
                                        (
                                            [pl] => Koppl
                                            [en] => Koppl
                                        )

                                    [spider] => Array
                                        (
                                            [pl] => Spider
                                            [en] => Spider
                                        )

                                    [bomech] => Array
                                        (
                                            [pl] => Bomech
                                            [en] => Bomech
                                        )

                                    [kts] => Array
                                        (
                                            [pl] => KTS
                                            [en] => KTS
                                        )

                                    [agristal] => Array
                                        (
                                            [pl] => AGRISTAL
                                            [en] => AGRISTAL
                                        )

                                    [kirovets] => Array
                                        (
                                            [pl] => Kirovets
                                            [en] => Kirovets
                                        )

                                    [htz] => Array
                                        (
                                            [pl] => HTZ
                                            [en] => HTZ
                                        )

                                    [shibaura] => Array
                                        (
                                            [pl] => Shibaura
                                            [en] => Shibaura
                                        )

                                    [welger] => Array
                                        (
                                            [pl] => Welger
                                            [en] => Welger
                                        )

                                    [spearhead] => Array
                                        (
                                            [pl] => Spearhead
                                            [en] => Spearhead
                                        )

                                    [debon] => Array
                                        (
                                            [pl] => Debon
                                            [en] => Debon
                                        )

                                    [ekopom] => Array
                                        (
                                            [pl] => EkoPOM
                                            [en] => EkoPOM
                                        )

                                    [agromilka] => Array
                                        (
                                            [pl] => AGROMILKA
                                            [en] => AGROMILKA
                                        )

                                    [agro-bartona] => Array
                                        (
                                            [pl] => AGRO-BARTONA
                                            [en] => AGRO-BARTONA
                                        )

                                    [heuling] => Array
                                        (
                                            [pl] => HEULING
                                            [en] => HEULING
                                        )

                                    [imants] => Array
                                        (
                                            [pl] => IMANTS
                                            [en] => IMANTS
                                        )

                                    [dcm] => Array
                                        (
                                            [pl] => DCM
                                            [en] => DCM
                                        )

                                    [fliegl] => Array
                                        (
                                            [pl] => Fliegl
                                            [en] => Fliegl
                                        )

                                    [woprol] => Array
                                        (
                                            [pl] => Woprol
                                            [en] => Woprol
                                        )

                                    [aupax] => Array
                                        (
                                            [pl] => Aupax
                                            [en] => Aupax
                                        )

                                    [supertino] => Array
                                        (
                                            [pl] => Supertino
                                            [en] => Supertino
                                        )

                                    [western] => Array
                                        (
                                            [pl] => WESTERN
                                            [en] => WESTERN
                                        )

                                    [kramer] => Array
                                        (
                                            [pl] => Kramer
                                            [en] => Kramer
                                        )

                                    [stanhay] => Array
                                        (
                                            [pl] => Stanhay
                                            [en] => Stanhay
                                        )

                                )

                        )

                    [1] => Array
                        (
                            [code] => model
                            [type] => input
                            [labels] => Array
                                (
                                    [pl] => Model pojazdu
                                    [en] => Model
                                )

                            [required] => 
                            [purpose] => both
                            [options] => Array
                                (
                                )

                        )

                    [2] => Array
                        (
                            [code] => video
                            [type] => input
                            [labels] => Array
                                (
                                    [pl] => Film na YouTube
                                    [en] => Add Youtube video url
                                )

                            [required] => 
                            [purpose] => add
                            [options] => Array
                                (
                                )

                        )

                    [3] => Array
                        (
                            [code] => year
                            [type] => input
                            [labels] => Array
                                (
                                    [pl] => Rok produkcji
                                    [en] => Year
                                )

                            [required] => 1
                            [purpose] => both
                            [options] => Array
                                (
                                )

                        )

                    [4] => Array
                        (
                            [code] => lifetime
                            [type] => input
                            [labels] => Array
                                (
                                    [pl] => Motogodziny
                                    [en] => Lifetime
                                )

                            [required] => 
                            [purpose] => both
                            [options] => Array
                                (
                                )

                        )

                    [5] => Array
                        (
                            [code] => damaged
                            [type] => toggle
                            [labels] => Array
                                (
                                    [pl] => Uszkodzony
                                    [en] => Damaged
                                )

                            [required] => 
                            [purpose] => both
                            [options] => Array
                                (
                                    [1] => Array
                                        (
                                            [pl] => Tak
                                            [en] => Yes
                                        )

                                )

                        )

                    [6] => Array
                        (
                            [code] => features
                            [type] => checkboxes
                            [labels] => Array
                                (
                                    [pl] => Dodatkowe wyposażenie
                                    [en] => Features
                                )

                            [required] => 
                            [purpose] => both
                            [options] => Array
                                (
                                    [cabin] => Array
                                        (
                                            [pl] => Kabina
                                            [en] => Cabin
                                        )

                                    [air-conditioning] => Array
                                        (
                                            [pl] => Klimatyzacja
                                            [en] => Air conditioning
                                        )

                                    [radio] => Array
                                        (
                                            [pl] => Radio
                                            [en] => Radio
                                        )

                                    [auto-pilot] => Array
                                        (
                                            [pl] => Autopilot
                                            [en] => Auto-pilot
                                        )

                                    [cd] => Array
                                        (
                                            [pl] => CD
                                            [en] => CD
                                        )

                                    [chopper] => Array
                                        (
                                            [pl] => Chopper
                                            [en] => Chopper
                                        )

                                    [gps] => Array
                                        (
                                            [pl] => GPS
                                            [en] => GPS
                                        )

                                    [transporter] => Array
                                        (
                                            [pl] => Transporter
                                            [en] => Transporter
                                        )

                                )

                        )

                    [7] => Array
                        (
                            [code] => price
                            [type] => price
                            [labels] => Array
                                (
                                    [pl] => Cena
                                    [en] => Price
                                )

                            [required] => 1
                            [purpose] => both
                            [options] => Array
                                (
                                    [price] => Array
                                        (
                                            [pl] => Cena
                                            [en] => Price
                                        )

                                    [arranged] => Array
                                        (
                                            [pl] => Do negocjacji
                                            [en] => Arranged
                                        )

                                )

                        )

                    [8] => Array
                        (
                            [code] => authorized_dealer
                            [type] => checkbox
                            [labels] => Array
                                (
                                    [pl] => Autoryzowanego Dealera
                                    [en] => Authorized dealer
                                )

                            [required] => 
                            [purpose] => search
                            [options] => 
                        )

                    [9] => Array
                        (
                            [code] => vat_discount
                            [type] => checkbox
                            [labels] => Array
                                (
                                    [pl] => VAT marża
                                    [en] => VAT discount
                                )

                            [required] => 
                            [purpose] => both
                            [options] => 
                        )

                    [10] => Array
                        (
                            [code] => financial_option
                            [type] => checkbox
                            [labels] => Array
                                (
                                    [pl] => Możliwość finansowania
                                    [en] => Financing option
                                )

                            [required] => 
                            [purpose] => both
                            [options] => 
                        )

                    [11] => Array
                        (
                            [code] => vat
                            [type] => checkbox
                            [labels] => Array
                                (
                                    [pl] => Faktura VAT
                                    [en] => VAT free
                                )

                            [required] => 
                            [purpose] => both
                            [options] => 
                        )

                    [12] => Array
                        (
                            [code] => leasing_concession
                            [type] => checkbox
                            [labels] => Array
                                (
                                    [pl] => Leasing
                                    [en] => Leasing concession
                                )

                            [required] => 
                            [purpose] => both
                            [options] => 
                        )

                    [13] => Array
                        (
                            [code] => down_payment
                            [type] => input
                            [labels] => Array
                                (
                                    [pl] => Opłata początkowa
                                    [en] => Initial down-payment
                                )

                            [required] => 
                            [purpose] => add
                            [options] => Array
                                (
                                )

                        )

                    [14] => Array
                        (
                            [code] => monthly_payment
                            [type] => input
                            [labels] => Array
                                (
                                    [pl] => Miesięczna rata
                                    [en] => Monthly payment value
                                )

                            [required] => 
                            [purpose] => add
                            [options] => Array
                                (
                                )

                        )

                    [15] => Array
                        (
                            [code] => remaining_payments
                            [type] => input
                            [labels] => Array
                                (
                                    [pl] => Liczba pozostałych rat
                                    [en] => Number of remaining payments
                                )

                            [required] => 
                            [purpose] => add
                            [options] => Array
                                (
                                )

                        )

                    [16] => Array
                        (
                            [code] => residual_value
                            [type] => input
                            [labels] => Array
                                (
                                    [pl] => Wartość wykupu
                                    [en] => Residual value
                                )

                            [required] => 
                            [purpose] => add
                            [options] => Array
                                (
                                )

                        )

                    [17] => Array
                        (
                            [code] => vendors_warranty_valid_until_date
                            [type] => input
                            [labels] => Array
                                (
                                    [pl] => Gwarancja dealerska (w cenie)
                                    [en] => Vendors warranty valid until
                                )

                            [required] => 
                            [purpose] => both
                            [options] => Array
                                (
                                )

                        )

                    [18] => Array
                        (
                            [code] => country_origin
                            [type] => select
                            [labels] => Array
                                (
                                    [pl] => Kraj pochodzenia
                                    [en] => Country of origin
                                )

                            [required] => 
                            [purpose] => both
                            [options] => Array
                                (
                                    [cn] => Array
                                        (
                                            [pl] => Chiny
                                            [en] => China
                                        )

                                    [kr] => Array
                                        (
                                            [pl] => Korea
                                            [en] => Korea
                                        )

                                    [a] => Array
                                        (
                                            [pl] => Austria
                                            [en] => Austria
                                        )

                                    [b] => Array
                                        (
                                            [pl] => Belgia
                                            [en] => Belgium
                                        )

                                    [by] => Array
                                        (
                                            [pl] => Białoruś
                                            [en] => Belarus
                                        )

                                    [bg] => Array
                                        (
                                            [pl] => Bułgaria
                                            [en] => Bulgaria
                                        )

                                    [hr] => Array
                                        (
                                            [pl] => Chorwacja
                                            [en] => Croatia
                                        )

                                    [cz] => Array
                                        (
                                            [pl] => Czechy
                                            [en] => Czech Republic
                                        )

                                    [dk] => Array
                                        (
                                            [pl] => Dania
                                            [en] => Denmark
                                        )

                                    [est] => Array
                                        (
                                            [pl] => Estonia
                                            [en] => Estonia
                                        )

                                    [fi] => Array
                                        (
                                            [pl] => Finlandia
                                            [en] => Finland
                                        )

                                    [f] => Array
                                        (
                                            [pl] => Francja
                                            [en] => France
                                        )

                                    [gr] => Array
                                        (
                                            [pl] => Grecja
                                            [en] => Greece
                                        )

                                    [e] => Array
                                        (
                                            [pl] => Hiszpania
                                            [en] => Spain
                                        )

                                    [nl] => Array
                                        (
                                            [pl] => Holandia
                                            [en] => Netherlands
                                        )

                                    [irl] => Array
                                        (
                                            [pl] => Irlandia
                                            [en] => Ireland
                                        )

                                    [is] => Array
                                        (
                                            [pl] => Islandia
                                            [en] => Iceland
                                        )

                                    [cdn] => Array
                                        (
                                            [pl] => Kanada
                                            [en] => Canada
                                        )

                                    [jp] => Array
                                        (
                                            [pl] => Japonia
                                            [en] => Japan
                                        )

                                    [li] => Array
                                        (
                                            [pl] => Liechtenstein
                                            [en] => Liechtenstein
                                        )

                                    [lt] => Array
                                        (
                                            [pl] => Litwa
                                            [en] => Lithuania
                                        )

                                    [l] => Array
                                        (
                                            [pl] => Luksemburg
                                            [en] => Luxembourg
                                        )

                                    [lv] => Array
                                        (
                                            [pl] => Łotwa
                                            [en] => Latvia
                                        )

                                    [mc] => Array
                                        (
                                            [pl] => Monako
                                            [en] => Monaco
                                        )

                                    [d] => Array
                                        (
                                            [pl] => Niemcy
                                            [en] => Germany
                                        )

                                    [n] => Array
                                        (
                                            [pl] => Norwegia
                                            [en] => Norway
                                        )

                                    [pl] => Array
                                        (
                                            [pl] => Polska
                                            [en] => Poland
                                        )

                                    [ru] => Array
                                        (
                                            [pl] => Rosja
                                            [en] => Russia
                                        )

                                    [ro] => Array
                                        (
                                            [pl] => Rumunia
                                            [en] => Romania
                                        )

                                    [sk] => Array
                                        (
                                            [pl] => Słowacja
                                            [en] => Slovakia
                                        )

                                    [si] => Array
                                        (
                                            [pl] => Słowenia
                                            [en] => Slovenia
                                        )

                                    [usa] => Array
                                        (
                                            [pl] => Stany Zjednoczone
                                            [en] => USA
                                        )

                                    [ch] => Array
                                        (
                                            [pl] => Szwajcaria
                                            [en] => Switzerland
                                        )

                                    [s] => Array
                                        (
                                            [pl] => Szwecja
                                            [en] => Sweden
                                        )

                                    [tr] => Array
                                        (
                                            [pl] => Turcja
                                            [en] => Turkey
                                        )

                                    [ua] => Array
                                        (
                                            [pl] => Ukraina
                                            [en] => Ukraine
                                        )

                                    [hu] => Array
                                        (
                                            [pl] => Węgry
                                            [en] => Hungary
                                        )

                                    [gb] => Array
                                        (
                                            [pl] => Wielka Brytania
                                            [en] => United Kingdom
                                        )

                                    [i] => Array
                                        (
                                            [pl] => Włochy
                                            [en] => Italy
                                        )

                                    [others] => Array
                                        (
                                            [pl] => Inny
                                            [en] => Others
                                        )

                                )

                        )

                    [19] => Array
                        (
                            [code] => date_registration
                            [type] => date
                            [labels] => Array
                                (
                                    [pl] => Data pierwszej rejestracji w historii pojazdu
                                    [en] => First registration
                                )

                            [required] => 
                            [purpose] => both
                            [options] => Array
                                (
                                )

                        )

                    [20] => Array
                        (
                            [code] => registered
                            [type] => checkbox
                            [labels] => Array
                                (
                                    [pl] => Zarejestrowany w Polsce
                                    [en] => Registered in Poland
                                )

                            [required] => 
                            [purpose] => both
                            [options] => 
                        )

                    [21] => Array
                        (
                            [code] => original_owner
                            [type] => checkbox
                            [labels] => Array
                                (
                                    [pl] =>  Pierwszy właściciel (od nowości)
                                    [en] => Original owner
                                )

                            [required] => 
                            [purpose] => both
                            [options] => 
                        )

                    [22] => Array
                        (
                            [code] => no_accident
                            [type] => checkbox
                            [labels] => Array
                                (
                                    [pl] => Bezwypadkowy
                                    [en] => No accident
                                )

                            [required] => 
                            [purpose] => both
                            [options] => 
                        )

                    [23] => Array
                        (
                            [code] => power
                            [type] => input
                            [labels] => Array
                                (
                                    [pl] => Moc
                                    [en] => Power
                                )

                            [required] => 
                            [purpose] => both
                            [options] => Array
                                (
                                )

                        )

                    [24] => Array
                        (
                            [code] => rental_option
                            [type] => checkbox
                            [labels] => Array
                                (
                                    [pl] => Opcja wynajem
                                    [en] => Rental option
                                )

                            [required] => 
                            [purpose] => both
                            [options] => 
                        )

                )

        )

)```

----------------------

