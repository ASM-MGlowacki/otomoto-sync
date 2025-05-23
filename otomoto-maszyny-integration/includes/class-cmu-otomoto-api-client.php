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
     * Retrieves details for a single advert from the Otomoto API.
     *
     * @param string|int $advert_id The ID of the Otomoto advert.
     * @return array|WP_Error Array of advert data on success, WP_Error on failure.
     */
    public function get_advert_details($advert_id) {
        $access_token = $this->get_access_token();

        if (!$access_token) {
            cmu_otomoto_log('Cannot get advert details, Otomoto access token is not available.', 'ERROR', ['advert_id' => $advert_id]);
            return new WP_Error('otomoto_api_token_error', 'Brak tokenu dostępu do API Otomoto.');
        }

        if (empty($advert_id)) {
            cmu_otomoto_log('Cannot get advert details, Otomoto advert ID is missing.', 'ERROR');
            return new WP_Error('otomoto_api_param_error', 'Brak ID ogłoszenia Otomoto.');
        }

        // Endpoint: GET /account/adverts/:id
        $request_url = $this->api_base_url . '/account/adverts/' . $advert_id;

        $request_args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'User-Agent'    => $this->otomoto_email,
                'Accept'        => 'application/json',
            ],
            'timeout' => 30, // seconds
        ];

        cmu_otomoto_log('Requesting single advert details from Otomoto API.', 'INFO', ['url' => $request_url, 'advert_id' => $advert_id]);

        $response = wp_remote_get($request_url, $request_args);

        if (is_wp_error($response)) {
            cmu_otomoto_log('Error requesting single advert details from Otomoto: ' . $response->get_error_message(), 'ERROR', ['url' => $request_url, 'args' => $request_args, 'advert_id' => $advert_id]);
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        // Log the raw response for debugging this specific case
        cmu_otomoto_log('RAW API Response for get_advert_details (ID: ' . $advert_id . '): Code ' . $response_code . ' Body: ' . $response_body, 'DEBUG_API_RESPONSE');

        $data = json_decode($response_body, true);

        if ($response_code === 200 && is_array($data) && isset($data['id'])) { // Sprawdź, czy odpowiedź jest OK i zawiera ID
            cmu_otomoto_log('Successfully retrieved single advert details from Otomoto.', 'INFO', ['advert_id' => $data['id'], 'title_sample' => isset($data['title']) ? substr($data['title'], 0, 50) . '...' : 'N/A']);
            return $data;
        } else {
            cmu_otomoto_log('Failed to retrieve single advert details from Otomoto or invalid data structure.', 'ERROR', ['url' => $request_url, 'advert_id' => $advert_id, 'response_code' => $response_code, 'response_body' => $response_body]);
            return new WP_Error('otomoto_api_single_advert_error', 'Nie udało się pobrać szczegółów ogłoszenia z Otomoto lub odpowiedź ma nieprawidłową strukturę.', ['status' => $response_code, 'response' => $response_body]);
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
