<?php

/**
 * WP-CLI command to fetch adverts from Otomoto API based on criteria.
 * File: class-cmu-otomoto-cli-fetch-adverts.php
 */

// Exit if accessed directly or not in WP-CLI context.
if (! defined('ABSPATH') || ! (defined('WP_CLI') && WP_CLI)) {
    exit;
}

if (! class_exists('CMU_Otomoto_Fetch_Adverts_Command')) {
    /**
     * Fetches adverts from the Otomoto API based on specified criteria and saves them to a JSON file.
     */
    class CMU_Otomoto_Fetch_Adverts_Command
    {
        /**
         * Fetches adverts from the Otomoto API.
         *
         * ## OPTIONS
         *
         * [<output_file>]
         * : The path to the output JSON file.
         *   Default: wp-content/uploads/otomoto_adverts_raw_filtered.json
         *
         * [--limit_per_page=<number>]
         * : Number of adverts to fetch per API page.
         *   ---
         *   default: 10
         *   ---
         *
         * [--max_pages=<number>]
         * : Maximum number of API pages to fetch (safety limit).
         *   ---
         *   default: 100
         *   ---
         *
         * [--sleep=<seconds>]
         * : Seconds to wait between API calls to avoid rate limiting.
         *   ---
         *   default: 1
         *   ---
         *
         * [--condition=<condition>]
         * : Filter by advert condition.
         *   ---
         *   default: used
         *   options:
         *     - new
         *     - used
         *     - all
         *   ---
         *
         * [--status=<status>]
         * : Filter by advert status (e.g., 'active', 'inactive', 'all').
         *   API typically returns various statuses; this filters them client-side.
         *   ---
         *   default: active
         *   ---
         *
         * [--exclude_fields=<fields>]
         * : Comma-separated string of top-level fields to exclude from the output (e.g., 'photos,description').
         *   ---
         *   default: ''
         *   ---
         *
         * ## EXAMPLES
         *
         *     # Fetch active, used adverts and save to default location
         *     wp cmuotomoto fetch_adverts
         *
         *     # Fetch all new adverts (regardless of their API status), exclude photos, save to custom file
         *     wp cmuotomoto fetch_adverts --output_file=/tmp/new_adverts.json --condition=new --status=all --exclude_fields='photos,description'
         *
         *     # Fetch active used adverts, exclude description and contact details
         *     wp cmuotomoto fetch_adverts --condition=used --status=active --exclude_fields='description,contact'
         *
         * @when after_wp_load
         */
        public function __invoke($args, $assoc_args)
        {
            $default_filename = 'otomoto_adverts_raw_filtered.json';
            $upload_dir_info  = wp_upload_dir();
            $default_filepath = trailingslashit($upload_dir_info['basedir']) . $default_filename;

            // Get arguments and associate arguments with defaults
            $output_file        = $args[0] ?? $default_filepath;
            $limit_per_page     = (int)WP_CLI\Utils\get_flag_value($assoc_args, 'limit_per_page', 10);
            $max_pages_to_fetch = (int)WP_CLI\Utils\get_flag_value($assoc_args, 'max_pages', 100);
            $sleep_duration     = (int)WP_CLI\Utils\get_flag_value($assoc_args, 'sleep', 1);
            $condition_filter   = strtolower(WP_CLI\Utils\get_flag_value($assoc_args, 'condition', 'used'));
            $status_filter      = strtolower(WP_CLI\Utils\get_flag_value($assoc_args, 'status', 'active'));
            $exclude_fields_str = WP_CLI\Utils\get_flag_value($assoc_args, 'exclude_fields', '');

            // Validate condition_filter
            if (! in_array($condition_filter, ['new', 'used', 'all'])) {
                WP_CLI::warning("Invalid --condition value '{$condition_filter}'. Using default 'used'.");
                $condition_filter = 'used';
            }

            // Najpierw usuń potencjalne otaczające cudzysłowy z całego stringa
            $cleaned_exclude_fields_str = trim($exclude_fields_str, " \t\n\r\0\x0B'\"");
            // Następnie podziel i przytnij każdy element
            $fields_to_exclude  = array_filter(array_map('trim', explode(',', $cleaned_exclude_fields_str)));


            if ($limit_per_page <= 0 || $limit_per_page > 100) { // API might have its own max limit
                $limit_per_page = 10;
                WP_CLI::warning("Invalid limit_per_page. Using default: {$limit_per_page}.");
            }
            if ($max_pages_to_fetch <= 0) {
                $max_pages_to_fetch = 100; // Ensure max_pages is at least 1 for progress bar
                WP_CLI::warning("Invalid max_pages. Using default: {$max_pages_to_fetch}.");
            }
            if ($sleep_duration < 0) {
                $sleep_duration = 1;
            }

            WP_CLI::line("Starting to fetch adverts from Otomoto API.");
            WP_CLI::line("Output file: " . $output_file);
            WP_CLI::line("Adverts per page: " . $limit_per_page);
            WP_CLI::line("Max pages to fetch: " . $max_pages_to_fetch);
            WP_CLI::line("Sleep between calls: " . $sleep_duration . "s");
            WP_CLI::line("Condition filter: " . $condition_filter);
            WP_CLI::line("Status filter: " . $status_filter);
            WP_CLI::line("Fields to exclude: " . (empty($fields_to_exclude) ? "None" : implode(', ', $fields_to_exclude)));


            global $cmu_otomoto_api_client;

            if (! $cmu_otomoto_api_client) {
                if (defined('OTOMOTO_CLIENT_ID') && defined('OTOMOTO_CLIENT_SECRET') && defined('OTOMOTO_EMAIL') && defined('OTOMOTO_PASSWORD')) {
                    if (! class_exists('CMU_Otomoto_Api_Client')) {
                        if (!defined('CMU_OTOMOTO_INTEGRATION_PATH')) {
                            WP_CLI::error("CMU_OTOMOTO_INTEGRATION_PATH constant is not defined. Cannot load API client.");
                            return;
                        }
                        $client_path = CMU_OTOMOTO_INTEGRATION_PATH . 'includes/class-cmu-otomoto-api-client.php';
                        if (file_exists($client_path)) {
                            require_once $client_path;
                        } else {
                            WP_CLI::error("CMU_Otomoto_Api_Client class file not found at {$client_path}. Cannot proceed.");
                            return;
                        }
                    }
                    $cmu_otomoto_api_client = new CMU_Otomoto_Api_Client();
                    WP_CLI::line("Instantiated CMU_Otomoto_Api_Client locally for this command.");
                } else {
                    WP_CLI::error("CMU_Otomoto_Api_Client is not available and API constants are not defined in wp-config.php. Cannot proceed.");
                    return;
                }
            }

            $token = $cmu_otomoto_api_client->get_access_token();
            if (! $token) {
                WP_CLI::error('Failed to get API access token. Check credentials and API client logs.');
                return;
            }
            WP_CLI::debug('Access token obtained successfully (first few chars): ' . substr($token, 0, 10));


            $all_filtered_adverts_data = [];
            $current_page              = 1;
            $total_api_adverts_fetched = 0;
            $filtered_and_saved_count  = 0;

            // Initialize progress bar with the maximum number of pages we might fetch
            $progress_bar_total = ($max_pages_to_fetch > 0) ? $max_pages_to_fetch : 1;
            $progress           = WP_CLI\Utils\make_progress_bar('Fetching pages', $progress_bar_total);

            try {
                while ($current_page <= $max_pages_to_fetch) {
                    WP_CLI::debug("Fetching page {$current_page}...");
                    $adverts_page_data = $cmu_otomoto_api_client->get_adverts($current_page, $limit_per_page);

                    if (is_wp_error($adverts_page_data)) {
                        WP_CLI::error("Failed to fetch adverts on page {$current_page}: " . $adverts_page_data->get_error_message());
                        $error_data = $adverts_page_data->get_error_data();
                        if (is_array($error_data) && isset($error_data['response'])) {
                            WP_CLI::warning("Raw API error response: " . $error_data['response']);
                        }
                        break;
                    }

                    if (empty($adverts_page_data)) {
                        WP_CLI::line("No more adverts found on page {$current_page}. Reached end of API results.");
                        // If we break early, advance progress bar to its end for a clean finish
                        // $progress->finish() will handle this if called outside loop.
                        // To make it more explicit, we can do:
                        // while($progress->get_current() < $progress_bar_total) { $progress->tick(); }
                        break;
                    }

                    $page_api_adverts_count = count($adverts_page_data);
                    $total_api_adverts_fetched += $page_api_adverts_count;
                    WP_CLI::debug("Fetched {$page_api_adverts_count} adverts on page {$current_page}.");

                    foreach ($adverts_page_data as $advert_raw) {
                        $advert = $advert_raw;

                        if ($condition_filter !== 'all') {
                            if (! isset($advert['new_used']) || strtolower((string) $advert['new_used']) !== $condition_filter) {
                                continue;
                            }
                        }

                        if ($status_filter !== 'all') {
                            if (! isset($advert['status']) || strtolower((string) $advert['status']) !== $status_filter) {
                                continue;
                            }
                        }

                        if (! empty($fields_to_exclude)) {
                            foreach ($fields_to_exclude as $field_to_exclude) {
                                if (array_key_exists($field_to_exclude, $advert)) {
                                    unset($advert[$field_to_exclude]);
                                }
                            }
                        }

                        $all_filtered_adverts_data[] = $advert;
                        $filtered_and_saved_count++;
                    }

                    $progress->tick(); // Increment progress for the page processed
                    $current_page++;

                    if ($current_page <= $max_pages_to_fetch && $sleep_duration > 0 && !empty($adverts_page_data)) {
                        sleep($sleep_duration);
                    }
                }
            } catch (Exception $e) {
                WP_CLI::error("An exception occurred: " . $e->getMessage());
            }

            $progress->finish(); // Ensures the progress bar UI is cleaned up

            WP_CLI::line("Total adverts fetched from API across all pages: {$total_api_adverts_fetched}");
            WP_CLI::line("Total adverts matching criteria and to be saved: {$filtered_and_saved_count}");

            if (! empty($all_filtered_adverts_data)) {
                $json_data = wp_json_encode($all_filtered_adverts_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (false === $json_data) {
                    WP_CLI::error("Failed to encode adverts data to JSON. Error: " . json_last_error_msg());
                    return;
                }

                $output_dir = dirname($output_file);
                if (!is_dir($output_dir)) {
                    if (!wp_mkdir_p($output_dir)) {
                        WP_CLI::error("Failed to create directory: {$output_dir}");
                        return;
                    }
                    WP_CLI::line("Created directory: {$output_dir}");
                }

                if (file_put_contents($output_file, $json_data) !== false) {
                    WP_CLI::success("Successfully saved {$filtered_and_saved_count} adverts to: " . $output_file);
                } else {
                    WP_CLI::error("Failed to write adverts to file: " . $output_file);
                }
            } else {
                WP_CLI::warning("No adverts matching the specified criteria were found to save.");
            }
        }
    }
    // The command is registered in the main plugin file
}
