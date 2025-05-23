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
    const INTER_BATCH_SCHEDULE_DELAY = 1 * MINUTE_IN_SECONDS;
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
        // if (class_exists('CMU_Otomoto_Post_Type')) {
        //     $post_type_handler = new CMU_Otomoto_Post_Type();
        //     $post_type_handler->create_initial_terms();
        // }
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

            // Metoda ensure_parent_wp_term_exists() jest publiczna w CMU_Otomoto_Sync_Manager
            // i jest idempotentna (można ją bezpiecznie wywołać wielokrotnie).
            // Jest też wywoływana w sync_adverts, ale dla pewności, że jest przed pierwszym użyciem
            // (np. w process_api_page_for_batch, które może być wywołane przed pełnym sync_adverts)
            // można ją tutaj wywołać.
            // Konstruktor SyncManagera już pobiera parent_wp_term_id z opcji,
            // a ensure_parent_wp_term_exists tworzy go, jeśli go nie ma.
            // Wywołanie jej tutaj zapewni, że $this->sync_manager->parent_wp_term_id będzie ustawione,
            // jeśli nie było w opcjach i musiało zostać utworzone.
            $this->sync_manager->ensure_parent_wp_term_exists(); // <--- TO JEST KLUCZOWE
            cmu_otomoto_log('Ensured parent WP term exists during CRON service initialization.', 'DEBUG', ['class' => __CLASS__, 'method' => __FUNCTION__]);
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
            'errors_encountered' => 0,
            'posts_deleted_as_inactive_in_otomoto' => 0,
        ];
    }

    /**
     * Sends an email notification.
     * Uses CMU_OTOMOTO_NOTIFICATION_EMAIL constant if defined, otherwise falls back to admin_email.
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

        $recipient_email = null;
        $wordpress_admin_email = get_option('admin_email');

        if (defined('CMU_OTOMOTO_NOTIFICATION_EMAIL') && !empty(CMU_OTOMOTO_NOTIFICATION_EMAIL)) {
            if (is_email(CMU_OTOMOTO_NOTIFICATION_EMAIL)) {
                $recipient_email = CMU_OTOMOTO_NOTIFICATION_EMAIL;
                cmu_otomoto_log('Using dedicated notification email from CMU_OTOMOTO_NOTIFICATION_EMAIL constant: ' . $recipient_email, 'INFO');
            } else {
                cmu_otomoto_log('CMU_OTOMOTO_NOTIFICATION_EMAIL constant is defined but contains an invalid email address: "' . CMU_OTOMOTO_NOTIFICATION_EMAIL . '". Attempting to use WordPress admin_email as fallback.', 'WARNING');
                // Spróbuj użyć admin_email jako fallback, jeśli CMU_... jest niepoprawne
                if (is_email($wordpress_admin_email)) {
                    $recipient_email = $wordpress_admin_email;
                    cmu_otomoto_log('Fallback to WordPress admin_email: ' . $recipient_email, 'INFO');
                }
            }
        } else {
            // CMU_OTOMOTO_NOTIFICATION_EMAIL nie jest zdefiniowana lub jest pusta, użyj admin_email
            if (is_email($wordpress_admin_email)) {
                $recipient_email = $wordpress_admin_email;
                cmu_otomoto_log('Using WordPress admin_email for notifications: ' . $recipient_email, 'INFO');
            }
        }

        if (empty($recipient_email)) { // Ten warunek jest teraz bardziej precyzyjny
            cmu_otomoto_log('Cannot send admin notification: No valid recipient email could be determined (checked CMU_OTOMOTO_NOTIFICATION_EMAIL and WordPress admin_email).', 'ERROR');
            return;
        }

        $headers = ['Content-Type: text/html; charset=UTF-8']; // Użyj UTF-8 dla polskich znaków
        $full_message_html = "<p>This is an automated notification from the CMU Otomoto Integration plugin.</p>";
        $full_message_html .= "<p>" . nl2br(esc_html($message)) . "</p>"; // esc_html dla bezpieczeństwa, nl2br dla formatowania
        $full_message_html .= "<hr>";
        $full_message_html .= "<p><strong>Details:</strong></p>";
        $full_message_html .= "<ul>";
        $full_message_html .= "<li><strong>Subject:</strong> " . esc_html($subject) . "</li>";
        $full_message_html .= "<li><strong>Error Type Key:</strong> " . esc_html($error_type_key) . "</li>";
        $full_message_html .= "<li><strong>Timestamp:</strong> " . current_time('mysql') . " (Server Time)</li>";
        $full_message_html .= "<li><strong>Site:</strong> " . esc_url(home_url()) . "</li>";
        $full_message_html .= "</ul>";
        $full_message_html .= "<p>Please check the plugin logs for more details: <code>" . esc_html(CMU_OTOMOTO_LOGS_DIR . '/otomoto_sync.log') . "</code></p>";


        $email_subject_prefix = apply_filters('cmu_otomoto_email_subject_prefix', '[CMU Otomoto Sync]');
        $final_subject = trim($email_subject_prefix . ' ' . $subject);

        $mail_sent = wp_mail($recipient_email, $final_subject, $full_message_html, $headers);

        if ($mail_sent) {
            set_transient($throttle_transient_name, true, self::EMAIL_THROTTLE_DURATION);
            cmu_otomoto_log('Admin notification successfully sent: ' . $final_subject, 'INFO', ['to' => $recipient_email]);
        } else {
            cmu_otomoto_log('Failed to send admin notification using wp_mail(): ' . $final_subject, 'ERROR', ['to' => $recipient_email, 'wp_mail_error_data' => $GLOBALS['phpmailer']->ErrorInfo ?? 'N/A (enable WP_DEBUG for details)']);
            // W przypadku błędu wp_mail, nie ustawiamy transientu throttlingu, aby ponowić próbę później
        }
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
