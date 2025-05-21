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

        $all_fetched_adverts_data_sample = []; // Corrected variable name for consistency
        $sync_summary = [
            'total_pages_fetched' => 0,
            'total_adverts_processed_from_api' => 0,
            'posts_created' => 0,
            'posts_updated' => 0,
            // 'posts_skipped_exists' => 0, // This counter is less relevant now.
                                            // Skipped posts are now categorized as:
                                            // posts_skipped_inactive_status, posts_skipped_no_title,
                                            // posts_skipped_not_used, posts_skipped_no_changes, posts_skipped_manual_edit
            'posts_skipped_not_used' => 0,
            'posts_skipped_inactive_status' => 0,
            'posts_skipped_no_title' => 0,
            'posts_skipped_no_changes' => 0,
            'posts_skipped_manual_edit' => 0,
            'categories_created' => 0, 
            'errors_encountered' => 0,
        ];

        // Ensure parent_wp_term_id is initialized for the instance if not already.
        // ensure_parent_wp_term_exists() also updates $this->parent_wp_term_id.
        if (!$this->parent_wp_term_id) {
            $this->parent_wp_term_id = $this->ensure_parent_wp_term_exists();
        }
        
        if (! $this->parent_wp_term_id) {
            $message = 'Synchronization aborted: Could not ensure parent WP term exists.';
            cmu_otomoto_log($message, 'ERROR');
            $sync_summary['errors_encountered']++;
            // Ensure 'adverts_sample' is always part of the returned structure
            $sync_summary['adverts_sample'] = []; 
            return ['status' => 'error', 'message' => $message, 'summary' => $sync_summary, 'adverts_sample' => []];
        }
        // Log the parent term ID being used (it's now fetched/set by constructor or ensure_parent_wp_term_exists)
        cmu_otomoto_log('Using parent WP term ID for categories: ' . $this->parent_wp_term_id, 'INFO');


        $current_page = 1;
        $max_pages_to_fetch = 100; // Safety limit for API pagination

        // DEV_NOTE_LIMIT_ADVERTS: Limit the number of *processed* (created/updated) active adverts.
        // Set to 0 or a very high number for production.
        // FINAL_GOAL: Remove or set to a very high number for production.
        $dev_max_processed_active_adverts_limit = 50; 
        $processed_active_adverts_count = 0;

        while ($current_page <= $max_pages_to_fetch) {
            // DEV_NOTE_LIMIT_ADVERTS_CHECK_BEFORE_FETCH: Stop if limit reached
            if ($dev_max_processed_active_adverts_limit > 0 && $processed_active_adverts_count >= $dev_max_processed_active_adverts_limit) {
                cmu_otomoto_log('Development limit of ' . $dev_max_processed_active_adverts_limit . ' processed active adverts reached. Stopping API fetching.', 'INFO');
                break;
            }

            cmu_otomoto_log('Fetching page ' . $current_page . ' from Otomoto API.', 'INFO');
            // Let's use a configurable items per page from API Client if available, or a default here.
            // Assuming API client has a way to pass this or uses a default.
            // For this example, let's assume 10 items per page is a good test value.
            $api_limit_per_page = apply_filters('cmu_otomoto_api_adverts_per_page', 10); 

            $adverts_page_data = $this->api_client->get_adverts($current_page, $api_limit_per_page);
            $sync_summary['total_pages_fetched'] = $current_page;

            if (is_wp_error($adverts_page_data)) {
                $error_message = 'Error fetching adverts from API on page ' . $current_page . ': ' . $adverts_page_data->get_error_message();
                cmu_otomoto_log($error_message . '. Synchronization stopped.', 'ERROR');
                $sync_summary['errors_encountered']++;
                // Prepare summary for return
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
                // DEV_NOTE_LIMIT_ADVERTS_CHECK_WITHIN_LOOP: Stop if limit reached
                if ($dev_max_processed_active_adverts_limit > 0 && $processed_active_adverts_count >= $dev_max_processed_active_adverts_limit) {
                    cmu_otomoto_log('Development limit of ' . $dev_max_processed_active_adverts_limit . ' processed active adverts reached during page processing. Breaking from this page.', 'INFO');
                    break; // Break from foreach loop for this page
                }
                
                // Pass $this->parent_wp_term_id instead of $parent_wp_term_id from argument
                $result = $this->process_single_advert_data($advert_data, $force_update_all, $this->parent_wp_term_id, $sync_summary);

                // Increment counter for *processed active* adverts
                // Filters (active, title, used) are inside process_single_advert_data
                if (isset($result['status']) && ($result['status'] === 'created' || $result['status'] === 'updated')) {
                    $processed_active_adverts_count++;
                }
            }
             
            // If the dev limit was reached inside the foreach, break the while loop too.
            if ($dev_max_processed_active_adverts_limit > 0 && $processed_active_adverts_count >= $dev_max_processed_active_adverts_limit) {
                cmu_otomoto_log('Development limit for processed active adverts met. Terminating sync loop.', 'INFO');
                break; 
            }

            $current_page++;
        }

        cmu_otomoto_log('Otomoto adverts synchronization process finished.', 'INFO', $sync_summary);
        
        // Ensure 'adverts_sample' is part of the final summary structure returned
        $sync_summary['adverts_sample'] = array_slice($all_fetched_adverts_data_sample, 0, 50);

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
