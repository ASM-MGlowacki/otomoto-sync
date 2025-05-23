<?php

/**
 * CMU Otomoto Admin Class
 * 
 * Handles WordPress admin interface for CMU Otomoto Integration plugin.
 * Provides metabox for posts and options page for plugin settings.
 * 
 * @package CMU_Otomoto_Integration
 * @version 0.1.1
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Ensure the class is not already defined before declaring it.
if (!class_exists('CMU_Otomoto_Admin')) {

    /**
     * CMU_Otomoto_Admin class
     * 
     * Manages the WordPress admin interface for the CMU Otomoto Integration plugin.
     */
    class CMU_Otomoto_Admin
    {

        /**
         * Constructor - initialize admin hooks and actions.
         */
        public function __construct()
        {
            add_action('admin_init', array($this, 'init_admin'));
            add_action('add_meta_boxes', array($this, 'add_otomoto_meta_box'));

            // START MODIFICATION: Add hook for new specs meta box
            add_action('add_meta_boxes', array($this, 'add_otomoto_specs_meta_box'));
            // Hook to save_post_maszyna-rolnicza is better than save_post for CPTs
            add_action('save_post_maszyna-rolnicza', array($this, 'save_otomoto_specs_meta_data'));
            // END MODIFICATION

            add_action('admin_menu', array($this, 'add_admin_page'));
            add_action('admin_post_cmu_refresh_single_post', array($this, 'handle_refresh_single_post'));
            add_action('admin_post_cmu_reset_manual_flag', array($this, 'handle_reset_manual_flag'));
            add_action('admin_post_cmu_manual_sync', array($this, 'handle_manual_sync_actions'));

            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

            add_filter('manage_maszyna-rolnicza_posts_columns', array($this, 'add_custom_columns'));
            add_action('manage_maszyna-rolnicza_posts_custom_column', array($this, 'render_custom_columns'), 10, 2);
            add_filter('manage_edit-maszyna-rolnicza_sortable_columns', array($this, 'add_sortable_columns'));
            add_action('pre_get_posts', array($this, 'handle_custom_column_sorting'));

            add_action('wp_ajax_cmu_otomoto_get_status', array($this, 'ajax_get_status'));
        }

        /**
         * Initialize admin interface.
         */
        public function init_admin()
        {
            cmu_otomoto_log('CMU_Otomoto_Admin initialized', 'INFO');

            if ($this->user_can_manage_plugin()) {
                $last_check = get_transient('cmu_otomoto_last_critical_check');
                if ($last_check === false) {
                    $this->check_and_notify_critical_errors();
                    set_transient('cmu_otomoto_last_critical_check', time(), HOUR_IN_SECONDS);
                }
            }
        }

        /**
         * Add metabox to 'maszyna-rolnicza' post type.
         */
        public function add_otomoto_meta_box()
        {
            add_meta_box(
                'cmu_otomoto_sync_status',
                __('Status Synchronizacji Otomoto', 'cmu-otomoto-integration'),
                array($this, 'render_otomoto_meta_box'),
                'maszyna-rolnicza',
                'side',
                'high'
            );
        }

        /**
         * Add metabox for technical specifications to 'maszyna-rolnicza' post type.
         */
        public function add_otomoto_specs_meta_box()
        {
            add_meta_box(
                'cmu_otomoto_specs_metabox',
                __('Specyfikacje Techniczne (Wyświetlane)', 'cmu-otomoto-integration'),
                array($this, 'render_otomoto_specs_meta_box'),
                'maszyna-rolnicza',
                'normal',
                'default'
            );
        }

        /**
         * Render the content of the Otomoto sync status metabox.
         * @param WP_Post $post The current post object.
         */
        public function render_otomoto_meta_box($post)
        {
            $otomoto_id = get_post_meta($post->ID, '_otomoto_id', true);
            $otomoto_url = get_post_meta($post->ID, '_otomoto_url', true);
            $last_sync = get_post_meta($post->ID, '_otomoto_last_sync', true);
            $is_edited_manually = get_post_meta($post->ID, '_otomoto_is_edited_manually', true);

            wp_nonce_field('cmu_otomoto_metabox_action', 'cmu_otomoto_metabox_nonce');

            echo '<div class="cmu-otomoto-metabox">';
            echo '<p><strong>' . __('Status edycji:', 'cmu-otomoto-integration') . '</strong> ';
            if ($is_edited_manually) {
                echo '<span style="color: #d63638;">' . __('Edytowany manualnie', 'cmu-otomoto-integration') . '</span>';
            } else {
                echo '<span style="color: #00a32a;">' . __('Synchronizowany z Otomoto', 'cmu-otomoto-integration') . '</span>';
            }
            echo '</p>';

            if ($otomoto_id) {
                echo '<p><strong>' . __('ID Otomoto:', 'cmu-otomoto-integration') . '</strong> ' . esc_html($otomoto_id) . '</p>';
            } else {
                echo '<p><strong>' . __('ID Otomoto:', 'cmu-otomoto-integration') . '</strong> <em>' . __('Brak', 'cmu-otomoto-integration') . '</em></p>';
            }

            if ($otomoto_url) {
                echo '<p><strong>' . __('Link do ogłoszenia:', 'cmu-otomoto-integration') . '</strong><br/>';
                echo '<a href="' . esc_url($otomoto_url) . '" target="_blank" rel="noopener">' . esc_html($otomoto_url) . '</a></p>';
            } else {
                echo '<p><strong>' . __('Link do ogłoszenia:', 'cmu-otomoto-integration') . '</strong> <em>' . __('Brak', 'cmu-otomoto-integration') . '</em></p>';
            }

            if ($last_sync) {
                $formatted_date = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_sync));
                echo '<p><strong>' . __('Ostatnia synchronizacja:', 'cmu-otomoto-integration') . '</strong> ' . esc_html($formatted_date) . '</p>';
            } else {
                echo '<p><strong>' . __('Ostatnia synchronizacja:', 'cmu-otomoto-integration') . '</strong> <em>' . __('Nigdy', 'cmu-otomoto-integration') . '</em></p>';
            }

            if ($otomoto_id) {
                echo '<hr style="margin: 15px 0;">';
                echo '<p><strong>' . __('Akcje:', 'cmu-otomoto-integration') . '</strong></p>';

                $refresh_url = admin_url('admin-post.php');
                $refresh_url = add_query_arg(array(
                    'action' => 'cmu_refresh_single_post',
                    'post_id' => $post->ID,
                    'otomoto_id' => $otomoto_id,
                    '_wpnonce' => wp_create_nonce('cmu_refresh_single_post_' . $post->ID)
                ), $refresh_url);

                echo '<p>';
                echo '<a href="' . esc_url($refresh_url) . '" class="button button-primary" ';
                echo 'onclick="return confirm(\'' . esc_js(__('Czy na pewno chcesz odświeżyć dane tego wpisu z Otomoto? Zmiany wprowadzone manualnie mogą zostać nadpisane.', 'cmu-otomoto-integration')) . '\');">';
                echo __('Odśwież dane z Otomoto', 'cmu-otomoto-integration');
                echo '</a>';
                echo '</p>';

                if ($is_edited_manually) {
                    $reset_flag_url = admin_url('admin-post.php');
                    $reset_flag_url = add_query_arg(array(
                        'action' => 'cmu_reset_manual_flag',
                        'post_id' => $post->ID,
                        '_wpnonce' => wp_create_nonce('cmu_reset_manual_flag_' . $post->ID)
                    ), $reset_flag_url);

                    echo '<p>';
                    echo '<a href="' . esc_url($reset_flag_url) . '" class="button button-secondary">';
                    echo __('Zresetuj flagę edycji manualnej', 'cmu-otomoto-integration');
                    echo '</a>';
                    echo '</p>';
                }
            } else {
                echo '<hr style="margin: 15px 0;">';
                echo '<p><em>' . __('Ten wpis nie jest powiązany z żadnym ogłoszeniem z Otomoto.', 'cmu-otomoto-integration') . '</em></p>';
            }

            echo '</div>';
            echo '<style>
            .cmu-otomoto-metabox p { margin: 8px 0; }
            .cmu-otomoto-metabox .button { margin-right: 10px; }
            .cmu-otomoto-metabox a[target="_blank"]:after { content: " ↗"; font-size: 12px; color: #666; }
            </style>';
        }

        /**
         * Render the content of the Otomoto technical specifications metabox.
         * @param WP_Post $post The current post object.
         */
        public function render_otomoto_specs_meta_box($post)
        {
            wp_nonce_field('cmu_otomoto_specs_metabox_action', 'cmu_otomoto_specs_metabox_nonce');

            $fields = [
                '_otomoto_hours' => __('Liczba godzin (np. 6638 mth)', 'cmu-otomoto-integration'),
                '_otomoto_fuel_type' => __('Rodzaj paliwa (np. Diesel)', 'cmu-otomoto-integration'),
                '_otomoto_engine_capacity_display' => __('Silnik - Pojemność (np. 6,8 l lub 6800 cm³)', 'cmu-otomoto-integration'),
                '_otomoto_engine_power_display' => __('Moc (np. 620 KM)', 'cmu-otomoto-integration'),
                '_otomoto_gearbox_display' => __('Skrzynia (np. Automatyczna)', 'cmu-otomoto-integration'),
            ];

            echo '<div class="cmu-otomoto-specs-metabox">';
            echo '<p><em>' . __('Wartości wprowadzone tutaj będą wyświetlane w sekcji "Pas z Ikonami - Kluczowe Parametry" na stronie produktu. Pozostawienie pola pustego spowoduje, że dana specyfikacja nie będzie wyświetlana.', 'cmu-otomoto-integration') . '</em></p>';
            echo '<table class="form-table">';

            foreach ($fields as $meta_key => $label) {
                $value = get_post_meta($post->ID, $meta_key, true);
?>
                <tr valign="top">
                    <th scope="row">
                        <label for="<?php echo esc_attr($meta_key); ?>"><?php echo esc_html($label); ?></label>
                    </th>
                    <td>
                        <input type="text" id="<?php echo esc_attr($meta_key); ?>" name="<?php echo esc_attr($meta_key); ?>" value="<?php echo esc_attr($value); ?>" class="regular-text" />
                    </td>
                </tr>
<?php
            }
            echo '</table>';
            echo '</div>';
        }

        /**
         * Save the meta data from the Otomoto technical specifications metabox.
         * @param int $post_id The ID of the post being saved.
         */
        public function save_otomoto_specs_meta_data($post_id)
        {
            if (!isset($_POST['cmu_otomoto_specs_metabox_nonce']) || !wp_verify_nonce($_POST['cmu_otomoto_specs_metabox_nonce'], 'cmu_otomoto_specs_metabox_action')) {
                return;
            }
            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
                return;
            }
            if (!current_user_can('edit_post', $post_id)) {
                return;
            }
            // No need to check post type again if hooked to save_post_maszyna-rolnicza

            $fields_to_save = [
                '_otomoto_hours',
                '_otomoto_fuel_type',
                '_otomoto_engine_capacity_display',
                '_otomoto_engine_power_display',
                '_otomoto_gearbox_display',
            ];

            foreach ($fields_to_save as $meta_key) {
                if (array_key_exists($meta_key, $_POST)) { // Check if the key exists in $_POST
                    $new_value = sanitize_text_field(wp_unslash($_POST[$meta_key])); // Use wp_unslash before sanitizing
                    $old_value = get_post_meta($post_id, $meta_key, true);

                    if ($new_value !== $old_value) {
                        if (empty($new_value)) { // If new value is empty, delete meta if old value existed
                            if ($old_value !== '') { // Only delete if there was an old value
                                delete_post_meta($post_id, $meta_key);
                            }
                        } else { // If new value is not empty, update meta
                            update_post_meta($post_id, $meta_key, $new_value);
                        }
                    }
                }
            }
            // Manual edit flag will be handled by `cmu_otomoto_handle_manual_edit_flag`
        }

        /**
         * Add admin options page to WordPress admin menu.
         */
        public function add_admin_page()
        {
            add_options_page(
                __('CMU Otomoto Integration', 'cmu-otomoto-integration'),
                __('Otomoto Sync', 'cmu-otomoto-integration'),
                'manage_options',
                'cmu-otomoto-settings',
                array($this, 'render_admin_page')
            );
        }

        /**
         * Render the admin options page.
         */
        public function render_admin_page()
        {
            if (!$this->user_can_manage_plugin()) {
                wp_die(__('Nie masz uprawnień do przeglądania tej strony.', 'cmu-otomoto-integration'));
            }

            echo '<div class="wrap">';
            echo '<h1>' . __('CMU Otomoto Integration - Ustawienia', 'cmu-otomoto-integration') . '</h1>';
            settings_errors('cmu_otomoto_notices');
            echo '<div class="cmu-otomoto-admin-page">';
            echo '<div class="cmu-otomoto-status-section">';
            echo '<h2>' . __('Status Synchronizacji', 'cmu-otomoto-integration') . '</h2>';
            $this->render_sync_status_info();
            echo '</div>';
            echo '<hr style="margin: 30px 0;">';
            echo '<div class="cmu-otomoto-actions-section">';
            echo '<h2>' . __('Akcje Synchronizacji', 'cmu-otomoto-integration') . '</h2>';
            echo '<p>' . __('Użyj poniższych przycisków do ręcznego uruchomienia różnych typów synchronizacji.', 'cmu-otomoto-integration') . '</p>';
            $this->render_sync_action_buttons();
            echo '</div>';
            echo '<hr style="margin: 30px 0;">';
            echo '<div class="cmu-otomoto-info-section">';
            echo '<h2>' . __('Informacje o konfiguracji', 'cmu-otomoto-integration') . '</h2>';
            $this->render_configuration_info();
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }

        private function render_sync_status_info()
        {
            $batch_status = get_option('_cmu_otomoto_batch_status', array());
            $last_sync_time = get_option('_cmu_otomoto_last_successful_sync', '');
            echo '<table class="cmu-otomoto-status-table">';
            echo '<tr>';
            echo '<th>' . __('Status cyklu wsadowego:', 'cmu-otomoto-integration') . '</th>';
            echo '<td>';
            if (!empty($batch_status['cycle_status'])) {
                $status = $batch_status['cycle_status'];
                $status_class = 'status-idle';
                $status_text = __('Nieznany', 'cmu-otomoto-integration');
                switch ($status) {
                    case 'running':
                        $status_class = 'status-running';
                        $status_text = __('W trakcie', 'cmu-otomoto-integration');
                        break;
                    case 'completed':
                        $status_class = 'status-completed';
                        $status_text = __('Zakończony', 'cmu-otomoto-integration');
                        break;
                    case 'error':
                        $status_class = 'status-error';
                        $status_text = __('Błąd', 'cmu-otomoto-integration');
                        break;
                    case 'idle':
                    default:
                        $status_class = 'status-idle';
                        $status_text = __('Bezczynny', 'cmu-otomoto-integration');
                        break;
                }
                echo '<span class="cmu-otomoto-status-indicator ' . $status_class . '">' . $status_text . '</span>';
            } else {
                echo '<span class="cmu-otomoto-status-indicator status-idle">' . __('Brak danych', 'cmu-otomoto-integration') . '</span>';
            }
            echo '</td>';
            echo '</tr>';
            if (!empty($batch_status['current_page']) || !empty($batch_status['total_pages'])) {
                echo '<tr>';
                echo '<th>' . __('Postęp cyklu:', 'cmu-otomoto-integration') . '</th>';
                echo '<td>';
                $current_page = $batch_status['current_page'] ?? 0;
                $total_pages = $batch_status['total_pages'] ?? 0;
                if ($total_pages > 0) {
                    echo sprintf(__('Strona %d z %d', 'cmu-otomoto-integration'), $current_page, $total_pages);
                } else {
                    echo __('Brak aktywnego cyklu', 'cmu-otomoto-integration');
                }
                echo '</td>';
                echo '</tr>';
            }
            if (!empty($batch_status['cycle_completed_time'])) {
                echo '<tr>';
                echo '<th>' . __('Ostatnie zakończenie cyklu:', 'cmu-otomoto-integration') . '</th>';
                echo '<td>';
                $completed_time = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($batch_status['cycle_completed_time']));
                echo esc_html($completed_time);
                echo '</td>';
                echo '</tr>';
            }
            if (!empty($last_sync_time)) {
                echo '<tr>';
                echo '<th>' . __('Ostatnia pomyślna synchronizacja:', 'cmu-otomoto-integration') . '</th>';
                echo '<td>';
                $sync_time = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_sync_time));
                echo esc_html($sync_time);
                echo '</td>';
                echo '</tr>';
            }
            $next_cron = wp_next_scheduled('cmu_otomoto_master_sync');
            if ($next_cron) {
                echo '<tr>';
                echo '<th>' . __('Następna zaplanowana synchronizacja:', 'cmu-otomoto-integration') . '</th>';
                echo '<td>';
                $next_time = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_cron);
                echo esc_html($next_time);
                echo '</td>';
                echo '</tr>';
            }
            echo '</table>';
        }

        private function render_sync_action_buttons()
        {
            echo '<div class="cmu-sync-actions">';
            echo '<div class="cmu-sync-action">';
            echo '<h3>' . __('Synchronizacja Wsadowa', 'cmu-otomoto-integration') . '</h3>';
            echo '<p>' . __('Uruchamia pełny cykl synchronizacji wsadowej w tle. Rekomendowana metoda dla regularnych aktualizacji.', 'cmu-otomoto-integration') . '</p>';
            echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
            echo '<input type="hidden" name="action" value="cmu_manual_sync">';
            echo '<input type="hidden" name="sync_type" value="batch">';
            wp_nonce_field('cmu_manual_sync_batch', 'cmu_sync_nonce');
            echo '<input type="submit" class="button button-primary" value="' . __('Rozpocznij Synchronizację Wsadową', 'cmu-otomoto-integration') . '">';
            echo '</form>';
            echo '</div>';
            echo '<div class="cmu-sync-action">';
            echo '<h3>' . __('Synchronizacja Manualna', 'cmu-otomoto-integration') . '</h3>';
            echo '<p>' . __('Uruchamia natychmiastową synchronizację bez mechanizmu wsadowego. Może zająć więcej czasu.', 'cmu-otomoto-integration') . '</p>';
            echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
            echo '<input type="hidden" name="action" value="cmu_manual_sync">';
            echo '<input type="hidden" name="sync_type" value="manual">';
            wp_nonce_field('cmu_manual_sync_manual', 'cmu_sync_nonce');
            echo '<input type="submit" class="button button-secondary" value="' . __('Synchronizuj Teraz (Manualna)', 'cmu-otomoto-integration') . '" onclick="return confirm(\'' . esc_js(__('Czy na pewno chcesz uruchomić synchronizację manualną? Może to zająć kilka minut.', 'cmu-otomoto-integration')) . '\');">';
            echo '</form>';
            echo '</div>';
            echo '<div class="cmu-sync-action">';
            echo '<h3>' . __('Wymuś Pełne Odświeżenie', 'cmu-otomoto-integration') . '</h3>';
            echo '<p>' . __('Wymusza aktualizację wszystkich postów, ignorując flagi edycji manualnej i daty modyfikacji.', 'cmu-otomoto-integration') . '</p>';
            echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
            echo '<input type="hidden" name="action" value="cmu_manual_sync">';
            echo '<input type="hidden" name="sync_type" value="force">';
            wp_nonce_field('cmu_manual_sync_force', 'cmu_sync_nonce');
            echo '<input type="submit" class="button button-warning" value="' . __('Wymuś Pełne Odświeżenie', 'cmu-otomoto-integration') . '" onclick="return confirm(\'' . esc_js(__('UWAGA: To nadpisze wszystkie manualne zmiany we wszystkich postach! Czy na pewno chcesz kontynuować?', 'cmu-otomoto-integration')) . '\');">';
            echo '</form>';
            echo '</div>';
            echo '</div>';
        }

        private function render_configuration_info()
        {
            global $cmu_otomoto_api_client, $cmu_otomoto_sync_manager;
            echo '<table class="cmu-otomoto-status-table">';
            echo '<tr>';
            echo '<th>' . __('Status API Client:', 'cmu-otomoto-integration') . '</th>';
            echo '<td>';
            if ($cmu_otomoto_api_client instanceof CMU_Otomoto_Api_Client) {
                echo '<span style="color: #00a32a;">' . __('✓ Dostępny', 'cmu-otomoto-integration') . '</span>';
            } else {
                echo '<span style="color: #d63638;">' . __('✗ Niedostępny (sprawdź stałe w wp-config.php)', 'cmu-otomoto-integration') . '</span>';
            }
            echo '</td>';
            echo '</tr>';
            echo '<tr>';
            echo '<th>' . __('Status Sync Manager:', 'cmu-otomoto-integration') . '</th>';
            echo '<td>';
            if ($cmu_otomoto_sync_manager instanceof CMU_Otomoto_Sync_Manager) {
                echo '<span style="color: #00a32a;">' . __('✓ Dostępny', 'cmu-otomoto-integration') . '</span>';
            } else {
                echo '<span style="color: #d63638;">' . __('✗ Niedostępny', 'cmu-otomoto-integration') . '</span>';
            }
            echo '</td>';
            echo '</tr>';
            $parent_term_id = get_option('cmu_otomoto_parent_term_id', null);
            echo '<tr>';
            echo '<th>' . __('Kategoria nadrzędna:', 'cmu-otomoto-integration') . '</th>';
            echo '<td>';
            if ($parent_term_id) {
                $term = get_term($parent_term_id);
                if (is_object($term) && !is_wp_error($term) && isset($term->name)) {
                    echo esc_html($term->name) . ' (ID: ' . $parent_term_id . ')';
                } else {
                    echo '<span style="color: #d63638;">' . __('Nie skonfigurowano lub błędne ID', 'cmu-otomoto-integration') . '</span>';
                }
            } else {
                echo '<span style="color: #d63638;">' . __('Nie skonfigurowano', 'cmu-otomoto-integration') . '</span>';
            }
            echo '</td>';
            echo '</tr>';
            $posts_count = wp_count_posts('maszyna-rolnicza');
            echo '<tr>';
            echo '<th>' . __('Zarządzane maszyny:', 'cmu-otomoto-integration') . '</th>';
            echo '<td>';
            echo sprintf(__('%d opublikowanych postów', 'cmu-otomoto-integration'), (is_object($posts_count) && isset($posts_count->publish)) ? $posts_count->publish : 0);
            echo '</td>';
            echo '</tr>';
            echo '<tr>';
            echo '<th>' . __('Wersja wtyczki:', 'cmu-otomoto-integration') . '</th>';
            echo '<td>' . esc_html(CMU_OTOMOTO_INTEGRATION_VERSION) . '</td>';
            echo '</tr>';
            echo '</table>';
            echo '<p style="margin-top: 20px;">';
            echo '<strong>' . __('Logi:', 'cmu-otomoto-integration') . '</strong> ';
            echo sprintf(__('Sprawdź szczegółowe logi w: %s', 'cmu-otomoto-integration'), '<code>' . CMU_OTOMOTO_LOGS_DIR . '/otomoto_sync.log</code>');
            echo '</p>';
        }

        public function handle_refresh_single_post()
        {
            if (!$this->user_can_manage_plugin()) {
                wp_die(__('Nie masz uprawnień do wykonywania tej akcji.', 'cmu-otomoto-integration'));
            }
            $post_id = absint($_GET['post_id'] ?? 0);
            $otomoto_id = sanitize_text_field($_GET['otomoto_id'] ?? '');
            $nonce = sanitize_text_field($_GET['_wpnonce'] ?? '');
            if (!wp_verify_nonce($nonce, 'cmu_refresh_single_post_' . $post_id)) {
                wp_die(__('Błąd bezpieczeństwa. Spróbuj ponownie.', 'cmu-otomoto-integration'));
            }
            $post = get_post($post_id);
            if (!$post || $post->post_type !== 'maszyna-rolnicza') {
                $this->add_admin_notice(__('Nieprawidłowy ID wpisu.', 'cmu-otomoto-integration'), 'error');
                wp_safe_redirect(wp_get_referer());
                exit;
            }
            if (empty($otomoto_id)) {
                $this->add_admin_notice(__('Brak ID Otomoto dla tego wpisu.', 'cmu-otomoto-integration'), 'error');
                wp_safe_redirect(wp_get_referer());
                exit;
            }
            cmu_otomoto_log('Attempting to refresh single post: ' . $post_id . ' with Otomoto ID: ' . $otomoto_id, 'INFO');
            global $cmu_otomoto_api_client, $cmu_otomoto_sync_manager;
            if (!$cmu_otomoto_api_client instanceof CMU_Otomoto_Api_Client) {
                $this->add_admin_notice(__('Klient API Otomoto nie jest dostępny. Sprawdź konfigurację wtyczki.', 'cmu-otomoto-integration'), 'error');
                wp_safe_redirect(wp_get_referer());
                exit;
            }
            if (!$cmu_otomoto_sync_manager instanceof CMU_Otomoto_Sync_Manager) {
                $this->add_admin_notice(__('Menedżer synchronizacji nie jest dostępny. Sprawdź konfigurację wtyczki.', 'cmu-otomoto-integration'), 'error');
                wp_safe_redirect(wp_get_referer());
                exit;
            }
            if (!defined('CMU_OTOMOTO_DOING_SYNC')) {
                define('CMU_OTOMOTO_DOING_SYNC', true);
            }
            try {
                $advert_details = $cmu_otomoto_api_client->get_advert_details($otomoto_id);
                if (is_wp_error($advert_details)) {
                    $error_message = $advert_details->get_error_message();
                    cmu_otomoto_log('Failed to fetch advert details for post ' . $post_id . ': ' . $error_message, 'ERROR');
                    $this->add_admin_notice(sprintf(__('Nie udało się pobrać danych z Otomoto: %s', 'cmu-otomoto-integration'), $error_message), 'error');
                    wp_safe_redirect(wp_get_referer());
                    exit;
                }
                if (empty($advert_details) || !isset($advert_details['id'])) {
                    cmu_otomoto_log('Empty or invalid advert details for post ' . $post_id, 'ERROR');
                    $this->add_admin_notice(__('Otrzymano niepoprawne dane z API Otomoto.', 'cmu-otomoto-integration'), 'error');
                    wp_safe_redirect(wp_get_referer());
                    exit;
                }
                $parent_wp_term_id = get_option('cmu_otomoto_parent_term_id', null);
                if (!$parent_wp_term_id) {
                    $parent_wp_term_id = $cmu_otomoto_sync_manager->ensure_parent_wp_term_exists();
                    if (!$parent_wp_term_id) {
                        cmu_otomoto_log('Failed to get parent WP term ID for post refresh ' . $post_id, 'ERROR');
                        $this->add_admin_notice(__('Nie udało się pobrać ID kategorii nadrzędnej. Sprawdź konfigurację wtyczki.', 'cmu-otomoto-integration'), 'error');
                        wp_safe_redirect(wp_get_referer());
                        exit;
                    }
                }
                $update_result = $cmu_otomoto_sync_manager->update_otomoto_post($post_id, $advert_details, $parent_wp_term_id, true);
                if ($update_result) {
                    delete_post_meta($post_id, '_otomoto_is_edited_manually');
                    update_post_meta($post_id, '_otomoto_last_sync', current_time('mysql'));
                    cmu_otomoto_log('Successfully refreshed post ' . $post_id . ' from Otomoto', 'INFO');
                    $this->add_admin_notice(__('Wpis został pomyślnie odświeżony danymi z Otomoto.', 'cmu-otomoto-integration'), 'success');
                } else {
                    cmu_otomoto_log('Failed to update post ' . $post_id . ' from Otomoto data', 'ERROR');
                    $this->add_admin_notice(__('Nie udało się zaktualizować wpisu danymi z Otomoto.', 'cmu-otomoto-integration'), 'error');
                }
            } catch (Exception $e) {
                cmu_otomoto_log('Exception during post refresh for post ' . $post_id . ': ' . $e->getMessage(), 'ERROR');
                $this->add_admin_notice(sprintf(__('Wystąpił błąd podczas odświeżania: %s', 'cmu-otomoto-integration'), $e->getMessage()), 'error');
            }
            wp_safe_redirect(wp_get_referer());
            exit;
        }

        public function handle_reset_manual_flag()
        {
            if (!$this->user_can_manage_plugin()) {
                wp_die(__('Nie masz uprawnień do wykonywania tej akcji.', 'cmu-otomoto-integration'));
            }
            $post_id = absint($_GET['post_id'] ?? 0);
            $nonce = sanitize_text_field($_GET['_wpnonce'] ?? '');
            if (!wp_verify_nonce($nonce, 'cmu_reset_manual_flag_' . $post_id)) {
                wp_die(__('Błąd bezpieczeństwa. Spróbuj ponownie.', 'cmu-otomoto-integration'));
            }
            $post = get_post($post_id);
            if (!$post || $post->post_type !== 'maszyna-rolnicza') {
                $this->add_admin_notice(__('Nieprawidłowy ID wpisu.', 'cmu-otomoto-integration'), 'error');
                wp_safe_redirect(wp_get_referer());
                exit;
            }
            cmu_otomoto_log('Resetting manual edit flag for post: ' . $post_id, 'INFO');
            $result = delete_post_meta($post_id, '_otomoto_is_edited_manually');
            if ($result) {
                cmu_otomoto_log('Successfully reset manual edit flag for post ' . $post_id, 'INFO');
                $this->add_admin_notice(__('Flaga edycji manualnej została zresetowana.', 'cmu-otomoto-integration'), 'success');
            } else {
                cmu_otomoto_log('Failed to reset manual edit flag for post ' . $post_id . ' (may have been already unset)', 'WARNING');
                $this->add_admin_notice(__('Flaga edycji manualnej była już zresetowana.', 'cmu-otomoto-integration'), 'info');
            }
            wp_safe_redirect(wp_get_referer());
            exit;
        }

        public function handle_manual_sync_actions()
        {
            if (!$this->user_can_manage_plugin()) {
                wp_die(__('Nie masz uprawnień do wykonywania tej akcji.', 'cmu-otomoto-integration'));
            }
            $sync_type = sanitize_text_field($_POST['sync_type'] ?? '');
            $nonce = sanitize_text_field($_POST['cmu_sync_nonce'] ?? '');
            if (!in_array($sync_type, ['batch', 'manual', 'force'])) {
                $this->add_admin_notice(__('Nieprawidłowy typ synchronizacji.', 'cmu-otomoto-integration'), 'error');
                wp_safe_redirect(wp_get_referer());
                exit;
            }
            $nonce_action = 'cmu_manual_sync_' . $sync_type;
            if (!wp_verify_nonce($nonce, $nonce_action)) {
                wp_die(__('Błąd bezpieczeństwa. Spróbuj ponownie.', 'cmu-otomoto-integration'));
            }
            global $cmu_otomoto_api_client, $cmu_otomoto_sync_manager, $cmu_otomoto_cron_instance;
            if (!$cmu_otomoto_api_client instanceof CMU_Otomoto_Api_Client) {
                $this->add_admin_notice(__('Klient API Otomoto nie jest dostępny. Sprawdź konfigurację wtyczki.', 'cmu-otomoto-integration'), 'error');
                wp_safe_redirect(wp_get_referer());
                exit;
            }
            if (!$cmu_otomoto_sync_manager instanceof CMU_Otomoto_Sync_Manager) {
                $this->add_admin_notice(__('Menedżer synchronizacji nie jest dostępny. Sprawdź konfigurację wtyczki.', 'cmu-otomoto-integration'), 'error');
                wp_safe_redirect(wp_get_referer());
                exit;
            }
            cmu_otomoto_log('Manual sync action initiated from admin page: ' . $sync_type, 'INFO');
            try {
                switch ($sync_type) {
                    case 'batch':
                        $this->handle_batch_sync_action();
                        break;
                    case 'manual':
                        $this->handle_manual_sync_action();
                        break;
                    case 'force':
                        $this->handle_force_sync_action();
                        break;
                    default:
                        $this->add_admin_notice(__('Nieznany typ synchronizacji.', 'cmu-otomoto-integration'), 'error');
                        break;
                }
            } catch (Exception $e) {
                cmu_otomoto_log('Exception during manual sync action (' . $sync_type . '): ' . $e->getMessage(), 'ERROR');
                $this->add_admin_notice(sprintf(__('Wystąpił błąd podczas synchronizacji: %s', 'cmu-otomoto-integration'), $e->getMessage()), 'error');
            }
            wp_safe_redirect(wp_get_referer());
            exit;
        }

        private function handle_batch_sync_action()
        {
            global $cmu_otomoto_cron_instance;
            if (!$cmu_otomoto_cron_instance instanceof CMU_Otomoto_Cron) {
                $this->add_admin_notice(__('Instancja CRON nie jest dostępna.', 'cmu-otomoto-integration'), 'error');
                return;
            }
            $batch_status = get_option('_cmu_otomoto_batch_status', array());
            if (!empty($batch_status['cycle_status']) && $batch_status['cycle_status'] === 'running') {
                $this->add_admin_notice(__('Cykl synchronizacji wsadowej jest już w trakcie. Poczekaj na jego zakończenie.', 'cmu-otomoto-integration'), 'warning');
                return;
            }
            $result = $cmu_otomoto_cron_instance->initiate_master_sync_cycle();
            if ($result) {
                cmu_otomoto_log('Batch sync cycle initiated successfully from admin page', 'INFO');
                $this->add_admin_notice(__('Synchronizacja wsadowa została rozpoczęta. Sprawdź status powyżej lub logi wtyczki, aby śledzić postęp.', 'cmu-otomoto-integration'), 'success');
            } else {
                cmu_otomoto_log('Failed to initiate batch sync cycle from admin page', 'ERROR');
                $this->add_admin_notice(__('Nie udało się rozpocząć synchronizacji wsadowej. Sprawdź logi wtyczki.', 'cmu-otomoto-integration'), 'error');
            }
        }

        private function handle_manual_sync_action()
        {
            global $cmu_otomoto_sync_manager;
            if (!defined('CMU_OTOMOTO_DOING_SYNC')) {
                define('CMU_OTOMOTO_DOING_SYNC', true);
            }
            cmu_otomoto_log('Starting manual sync from admin page (non-batch)', 'INFO');
            $sync_results = $cmu_otomoto_sync_manager->sync_adverts(false);
            if ($sync_results['status'] === 'success') {
                update_option('_cmu_otomoto_last_successful_sync', current_time('mysql'));
                $message = __('Synchronizacja manualna zakończona pomyślnie!', 'cmu-otomoto-integration');
                if (isset($sync_results['summary'])) {
                    $summary = $sync_results['summary'];
                    $message .= sprintf(
                        __(' Utworzono: %d, Zaktualizowano: %d, Pominięto: %d', 'cmu-otomoto-integration'),
                        $summary['posts_created'] ?? 0,
                        $summary['posts_updated'] ?? 0,
                        ($summary['posts_skipped_no_changes'] ?? 0) + ($summary['posts_skipped_manual_edit'] ?? 0)
                    );
                }
                cmu_otomoto_log('Manual sync completed successfully from admin page', 'INFO', ['summary' => $sync_results['summary'] ?? []]);
                $this->add_admin_notice($message, 'success');
            } else {
                $error_message = $sync_results['message'] ?? __('Nieznany błąd podczas synchronizacji.', 'cmu-otomoto-integration');
                cmu_otomoto_log('Manual sync failed from admin page: ' . $error_message, 'ERROR');
                $this->add_admin_notice(__('Synchronizacja manualna nieudana: ', 'cmu-otomoto-integration') . $error_message, 'error');
            }
        }

        private function handle_force_sync_action()
        {
            global $cmu_otomoto_sync_manager;
            if (!defined('CMU_OTOMOTO_DOING_SYNC')) {
                define('CMU_OTOMOTO_DOING_SYNC', true);
            }
            cmu_otomoto_log('Starting force sync from admin page (non-batch, force_update=true)', 'INFO');
            $sync_results = $cmu_otomoto_sync_manager->sync_adverts(true);
            if ($sync_results['status'] === 'success') {
                update_option('_cmu_otomoto_last_successful_sync', current_time('mysql'));
                $message = __('Wymuszone pełne odświeżenie zakończone pomyślnie!', 'cmu-otomoto-integration');
                if (isset($sync_results['summary'])) {
                    $summary = $sync_results['summary'];
                    $message .= sprintf(
                        __(' Utworzono: %d, Zaktualizowano: %d, Błędów: %d', 'cmu-otomoto-integration'),
                        $summary['posts_created'] ?? 0,
                        $summary['posts_updated'] ?? 0,
                        $summary['errors_encountered'] ?? 0
                    );
                }
                cmu_otomoto_log('Force sync completed successfully from admin page', 'INFO', ['summary' => $sync_results['summary'] ?? []]);
                $this->add_admin_notice($message, 'success');
            } else {
                $error_message = $sync_results['message'] ?? __('Nieznany błąd podczas synchronizacji.', 'cmu-otomoto-integration');
                cmu_otomoto_log('Force sync failed from admin page: ' . $error_message, 'ERROR');
                $this->add_admin_notice(__('Wymuszone odświeżenie nieudane: ', 'cmu-otomoto-integration') . $error_message, 'error');
            }
        }

        public function enqueue_admin_assets($hook_suffix)
        {
            if (
                $hook_suffix === 'settings_page_cmu-otomoto-settings' ||
                ($hook_suffix === 'post.php' && get_post_type() === 'maszyna-rolnicza') ||
                ($hook_suffix === 'post-new.php' && isset($_GET['post_type']) && $_GET['post_type'] === 'maszyna-rolnicza')
            ) {
                wp_enqueue_style('cmu-otomoto-admin-css', CMU_OTOMOTO_INTEGRATION_URL . 'assets/admin.css', array(), CMU_OTOMOTO_INTEGRATION_VERSION);
                wp_enqueue_script('cmu-otomoto-admin-js', CMU_OTOMOTO_INTEGRATION_URL . 'assets/admin.js', array('jquery'), CMU_OTOMOTO_INTEGRATION_VERSION, true);
                wp_localize_script('cmu-otomoto-admin-js', 'cmu_otomoto_admin', array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('cmu_otomoto_admin_ajax'),
                    'strings' => array(
                        'confirm_batch' => __('Czy na pewno chcesz rozpocząć synchronizację wsadową?', 'cmu-otomoto-integration'),
                        'confirm_manual' => __('Czy na pewno chcesz uruchomić synchronizację manualną?', 'cmu-otomoto-integration'),
                        'confirm_force' => __('UWAGA: To nadpisze wszystkie manualne zmiany!', 'cmu-otomoto-integration'),
                        'confirm_refresh' => __('Czy na pewno chcesz odświeżyć dane tego wpisu?', 'cmu-otomoto-integration')
                    )
                ));
            }
        }

        private function user_can_manage_plugin()
        {
            return current_user_can('manage_options');
        }

        private function add_admin_notice($message, $type = 'info')
        {
            add_settings_error('cmu_otomoto_notices', 'cmu_otomoto_notice', $message, $type);
        }

        public function add_custom_columns($columns)
        {
            $columns['cmu_otomoto_status'] = __('Status edycji', 'cmu-otomoto-integration');
            return $columns;
        }

        public function render_custom_columns($column, $post_id)
        {
            if ($column === 'cmu_otomoto_status') {
                $is_edited_manually = get_post_meta($post_id, '_otomoto_is_edited_manually', true);
                if ($is_edited_manually) {
                    echo '<span style="color: #d63638;">' . __('Edytowany manualnie', 'cmu-otomoto-integration') . '</span>';
                } else {
                    echo '<span style="color: #00a32a;">' . __('Synchronizowany z Otomoto', 'cmu-otomoto-integration') . '</span>';
                }
            }
        }

        public function add_sortable_columns($columns)
        {
            $columns['cmu_otomoto_status'] = 'cmu_otomoto_status';
            return $columns;
        }

        public function handle_custom_column_sorting($query)
        {
            if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'maszyna-rolnicza') {
                return;
            }
            $orderby = $query->get('orderby');
            if ($orderby === 'cmu_otomoto_status') {
                $query->set('meta_key', '_otomoto_is_edited_manually');
                $query->set('orderby', 'meta_value');
            }
        }

        public function ajax_get_status()
        {
            if (!check_ajax_referer('cmu_otomoto_admin_ajax', 'nonce', false)) {
                wp_send_json_error(__('Błąd bezpieczeństwa. Spróbuj ponownie.', 'cmu-otomoto-integration'));
            }
            if (!$this->user_can_manage_plugin()) {
                wp_send_json_error(__('Nie masz uprawnień do wykonywania tej akcji.', 'cmu-otomoto-integration'));
            }
            global $cmu_otomoto_api_client, $cmu_otomoto_sync_manager;
            $status = get_option('_cmu_otomoto_batch_status', array());
            $last_sync_time = get_option('_cmu_otomoto_last_successful_sync', '');
            $posts_count = wp_count_posts('maszyna-rolnicza');
            $parent_term_id = get_option('cmu_otomoto_parent_term_id', null);
            $response = array(
                'batch_status' => $status,
                'status' => $status['cycle_status'] ?? 'idle',
                'progress' => !empty($status['current_page']) || !empty($status['total_pages']) ?
                    sprintf(__('Strona %d z %d', 'cmu-otomoto-integration'), $status['current_page'] ?? 0, $status['total_pages'] ?? 0) :
                    __('Brak aktywnego cyklu', 'cmu-otomoto-integration'),
                'last_sync' => !empty($last_sync_time) ?
                    date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_sync_time)) :
                    __('Nigdy', 'cmu-otomoto-integration'),
                'next_sync' => wp_next_scheduled('cmu_otomoto_master_sync') ?
                    date_i18n(get_option('date_format') . ' ' . get_option('time_format'), wp_next_scheduled('cmu_otomoto_master_sync')) :
                    __('Nie zaplanowano', 'cmu-otomoto-integration'),
                'cycle_completed_time' => !empty($status['cycle_completed_time']) ?
                    date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($status['cycle_completed_time'])) :
                    __('Nigdy', 'cmu-otomoto-integration'),
                'posts_count' => sprintf(
                    __('%d opublikowanych postów', 'cmu-otomoto-integration'),
                    (is_object($posts_count) && isset($posts_count->publish)) ? $posts_count->publish : 0
                ),
                'api_client_status' => ($cmu_otomoto_api_client instanceof CMU_Otomoto_Api_Client) ?
                    __('✓ Dostępny', 'cmu-otomoto-integration') :
                    __('✗ Niedostępny (sprawdź stałe w wp-config.php)', 'cmu-otomoto-integration'),
                'sync_manager_status' => ($cmu_otomoto_sync_manager instanceof CMU_Otomoto_Sync_Manager) ?
                    __('✓ Dostępny', 'cmu-otomoto-integration') :
                    __('✗ Niedostępny', 'cmu-otomoto-integration'),
                'parent_category' => $parent_term_id ?
                    (($term = get_term($parent_term_id)) && is_object($term) && !is_wp_error($term) ?
                        esc_html($term->name) . ' (ID: ' . $parent_term_id . ')' :
                        __('Błędne ID kategorii', 'cmu-otomoto-integration')) :
                    __('Nie skonfigurowano', 'cmu-otomoto-integration')
            );
            wp_send_json_success($response);
        }

        public function send_admin_notification($subject, $message, $error_type_key = 'admin_interface')
        {
            global $cmu_otomoto_cron_instance;
            if (!$cmu_otomoto_cron_instance instanceof CMU_Otomoto_Cron) {
                cmu_otomoto_log('Cannot send admin notification: CRON instance not available', 'ERROR');
                return false;
            }
            try {
                $reflection = new ReflectionClass($cmu_otomoto_cron_instance);
                $method = $reflection->getMethod('send_admin_notification');
                $method->setAccessible(true);
                $method->invoke($cmu_otomoto_cron_instance, $subject, $message, $error_type_key);
                cmu_otomoto_log('Admin notification sent from UI: ' . $subject, 'INFO');
                return true;
            } catch (ReflectionException $e) {
                cmu_otomoto_log('Failed to send admin notification via reflection: ' . $e->getMessage(), 'ERROR');
                return false;
            }
        }

        private function check_and_notify_critical_errors()
        {
            global $cmu_otomoto_api_client, $cmu_otomoto_sync_manager;
            $critical_errors = array();
            if (!$cmu_otomoto_api_client instanceof CMU_Otomoto_Api_Client) {
                $critical_errors[] = __('Klient API Otomoto nie jest dostępny. Sprawdź stałe w wp-config.php (OTOMOTO_CLIENT_ID, OTOMOTO_CLIENT_SECRET, OTOMOTO_EMAIL, OTOMOTO_PASSWORD).', 'cmu-otomoto-integration');
            }
            if (!$cmu_otomoto_sync_manager instanceof CMU_Otomoto_Sync_Manager) {
                $critical_errors[] = __('Menedżer synchronizacji nie jest dostępny.', 'cmu-otomoto-integration');
            }
            $parent_term_id = get_option('cmu_otomoto_parent_term_id', null);
            if (!$parent_term_id || !get_term($parent_term_id)) {
                $critical_errors[] = __('Kategoria nadrzędna nie jest prawidłowo skonfigurowana.', 'cmu-otomoto-integration');
            }
            $batch_status = get_option('_cmu_otomoto_batch_status', array());
            if (!empty($batch_status['errors_in_cycle']) && count($batch_status['errors_in_cycle']) > 5) {
                $critical_errors[] = sprintf(__('Wykryto %d błędów w ostatnim cyklu synchronizacji.', 'cmu-otomoto-integration'), count($batch_status['errors_in_cycle']));
            }
            if (!empty($critical_errors)) {
                $subject = __('CMU Otomoto Sync: Wykryto krytyczne błędy konfiguracji', 'cmu-otomoto-integration');
                $message = __('Wykryto następujące krytyczne błędy w wtyczce CMU Otomoto Integration:', 'cmu-otomoto-integration') . "\n\n";
                foreach ($critical_errors as $error) {
                    $message .= "• " . $error . "\n";
                }
                $message .= "\n" . __('Te błędy mogą uniemożliwić prawidłowe działanie synchronizacji. Proszę o sprawdzenie konfiguracji wtyczki.', 'cmu-otomoto-integration');
                $message .= "\n" . sprintf(__('Sprawdź panel administracyjny: %s', 'cmu-otomoto-integration'), admin_url('options-general.php?page=cmu-otomoto-settings'));
                $this->send_admin_notification($subject, $message, 'critical_config_errors');
                cmu_otomoto_log('Critical configuration errors detected and notification sent', 'WARNING', ['errors_count' => count($critical_errors)]);
            }
        }
    } // End of CMU_Otomoto_Admin class
} // End of if class_exists