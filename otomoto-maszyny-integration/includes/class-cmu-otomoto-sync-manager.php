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
        $this->parent_wp_term_id = get_option(self::PARENT_TERM_OPTION_NAME, null);
    }

    /**
     * Finds an existing post by Otomoto ID.
     *
     * @param string|int $otomoto_id The Otomoto advert ID.
     * @return int|null Post ID if found, null otherwise.
     */
    private function find_existing_post_by_otomoto_id($otomoto_id)
    {
        $args = [
            'post_type'      => 'maszyna-rolnicza',
            'meta_key'       => '_otomoto_id',
            'meta_value'     => $otomoto_id,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'post_status'    => 'any', 
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
        if ($this->parent_wp_term_id && term_exists((int) $this->parent_wp_term_id, 'kategorie-maszyn')) {
            return $this->parent_wp_term_id;
        }

        $parent_term_slug = 'maszyny-rolnicze'; 
        if (defined('OTOMOTO_MAIN_CATEGORY_SLUG_WP')) {
            $parent_term_slug_defined = constant('OTOMOTO_MAIN_CATEGORY_SLUG_WP');
            if (!empty($parent_term_slug_defined) && is_string($parent_term_slug_defined)) {
                $parent_term_slug = sanitize_title($parent_term_slug_defined);
            }
        }

        $parent_term_name = 'Używane Maszyny Rolnicze'; 
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
                delete_option(self::PARENT_TERM_OPTION_NAME); 
                return null;
            } else {
                $this->parent_wp_term_id = $term_data['term_id'];
                update_option(self::PARENT_TERM_OPTION_NAME, $this->parent_wp_term_id);
                cmu_otomoto_log('Successfully created parent term "' . $parent_term_name . '" with ID: ' . $this->parent_wp_term_id . '. Option set.', 'INFO');
                return $this->parent_wp_term_id;
            }
        }
    }

    public function get_or_create_default_fallback_category($parent_wp_term_id)
    {
        $log_parent_text = ($parent_wp_term_id > 0) ? 'under parent ID: ' . $parent_wp_term_id : 'as a top-level category';

        if ($parent_wp_term_id < 0) { 
            cmu_otomoto_log('Cannot create fallback category: Invalid Parent WP term ID provided: ' . $parent_wp_term_id, 'ERROR');
            return null;
        }

        $fallback_term_name = 'Inne maszyny rolnicze';
        $fallback_term_slug = 'inne-maszyny-rolnicze';
        $taxonomy = 'kategorie-maszyn';

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
    public function get_or_create_wp_term_for_otomoto_category($otomoto_category_id, $parent_wp_term_id)
    {
        $actual_parent_id_for_wp_term = 0; 

        if (empty($otomoto_category_id)) {
            cmu_otomoto_log('Cannot get/create WP term: Otomoto category ID is missing. Attempting to use default fallback (top-level).', 'ERROR');
            return $this->get_or_create_default_fallback_category(0);
        }

        $taxonomy = 'kategorie-maszyn';

        $args = [
            'taxonomy'   => $taxonomy,
            'parent'     => $actual_parent_id_for_wp_term,
            'hide_empty' => false,
            'meta_query' => [
                [
                    'key'   => '_otomoto_category_id',
                    'value' => (string) $otomoto_category_id, 
                ],
            ],
        ];
        $existing_terms = get_terms($args);

        if (!empty($existing_terms) && !is_wp_error($existing_terms)) {
            $term_id = $existing_terms[0]->term_id;
            return $term_id;
        }

        cmu_otomoto_log('Top-level term for Otomoto category ID ' . $otomoto_category_id . ' not found. Fetching details from API.', 'INFO');
        $cat_details = $this->api_client->get_otomoto_category_details($otomoto_category_id);

        if (is_wp_error($cat_details) || empty($cat_details['names']['pl'])) {
            $error_message = is_wp_error($cat_details) ? $cat_details->get_error_message() : 'empty or missing Polish name';
            cmu_otomoto_log('Failed to fetch category details or Polish name missing for Otomoto category ID ' . $otomoto_category_id . '. Details: ' . $error_message . '. Using fallback category (top-level).', 'WARNING');
            return $this->get_or_create_default_fallback_category(0);
        }

        $term_name = sanitize_text_field($cat_details['names']['pl']);
        $otomoto_category_code = !empty($cat_details['code']) ? (string) $cat_details['code'] : '';

        if (!empty($otomoto_category_code)) {
            $slug_base_candidate = str_replace('_', '-', $otomoto_category_code);
        } else {
            $slug_base_candidate = $term_name;
        }

        $term_slug = sanitize_title($slug_base_candidate);
        $term_slug = apply_filters('cmu_otomoto_category_slug', $term_slug, $otomoto_category_id, $term_name, $cat_details);

        $unique_term_slug = $term_slug;
        $counter = 1;
        while (term_exists($unique_term_slug, $taxonomy, $actual_parent_id_for_wp_term)) {
            $existing_term_with_slug = get_term_by('slug', $unique_term_slug, $taxonomy);
            if ($existing_term_with_slug) {
                $existing_otomoto_id = get_term_meta($existing_term_with_slug->term_id, '_otomoto_category_id', true);
                if ((string) $existing_otomoto_id === (string) $otomoto_category_id) {
                    cmu_otomoto_log('Term with slug "' . $unique_term_slug . '" and SAME Otomoto ID ' . $otomoto_category_id . ' already exists. This should not happen if meta query works. Returning existing term ID: ' . $existing_term_with_slug->term_id, 'WARNING');
                    return $existing_term_with_slug->term_id;
                }
            }
            $unique_term_slug = $term_slug . '-' . $counter++;
        }
        $term_slug = $unique_term_slug;


        cmu_otomoto_log('Attempting to create new top-level WP term "' . $term_name . '" (slug: ' . $term_slug . ') for Otomoto category ID ' . $otomoto_category_id . '.', 'INFO');
        $term_data = wp_insert_term(
            $term_name,
            $taxonomy,
            [
                'slug'   => $term_slug,
                'parent' => $actual_parent_id_for_wp_term,
            ]
        );

        if (is_wp_error($term_data)) {
            cmu_otomoto_log('Failed to create top-level WP term "' . $term_name . '" (slug: ' . $term_slug . '): ' . $term_data->get_error_message() . '. Using fallback category (top-level).', 'ERROR', ['otomoto_category_id' => $otomoto_category_id]);
            return $this->get_or_create_default_fallback_category(0);
        } else {
            $term_id = $term_data['term_id'];
            update_term_meta($term_id, '_otomoto_category_id', (string) $otomoto_category_id); 
            $log_message = 'Successfully created top-level WP term "' . $term_name . '" with ID: ' . $term_id . ' for Otomoto category ID ' . $otomoto_category_id . '. Meta _otomoto_category_id set to ' . $otomoto_category_id;

            if (!empty($otomoto_category_code)) { 
                update_term_meta($term_id, '_otomoto_category_code', $otomoto_category_code);
                $log_message .= ', Meta _otomoto_category_code set to ' . $otomoto_category_code;
            }
            $log_message .= '.';
            cmu_otomoto_log($log_message, 'INFO');
            return $term_id;
        }
    }

    public function assign_wp_category_and_state($post_id, $otomoto_category_id, $new_used_status, $parent_wp_term_id)
    {
        if (! $post_id) {
            cmu_otomoto_log('Cannot assign category/state: Post ID is missing.', 'ERROR');
            return;
        }

        $wp_term_id = $this->get_or_create_wp_term_for_otomoto_category($otomoto_category_id, $parent_wp_term_id);
        if ($wp_term_id) {
            $result = wp_set_object_terms($post_id, (int) $wp_term_id, 'kategorie-maszyn', false); 
            if (is_wp_error($result)) {
                cmu_otomoto_log('Failed to assign WP category ID ' . $wp_term_id . ' to post ID ' . $post_id . ': ' . $result->get_error_message(), 'ERROR');
            } else {
                cmu_otomoto_log('Successfully assigned WP category ID ' . $wp_term_id . ' to post ID ' . $post_id . '.', 'INFO');
            }
        } else {
            cmu_otomoto_log('Could not assign WP category to post ID ' . $post_id . ' as term ID was not obtained for Otomoto category ID ' . $otomoto_category_id . '.', 'WARNING');
        }

        $stan_taxonomy = 'stan-maszyny';
        $stan_term_slug = (strtolower((string) $new_used_status) === 'used') ? 'uzywana' : 'nowa';

        $term_exists = term_exists($stan_term_slug, $stan_taxonomy);
        if (! $term_exists) {
            cmu_otomoto_log('Term slug "' . $stan_term_slug . '" does not exist in taxonomy "' . $stan_taxonomy . '". Cannot assign state to post ID ' . $post_id . '.', 'ERROR');
        } else {
            $term_id_to_assign = is_array($term_exists) ? $term_exists['term_id'] : $term_exists;
            $result = wp_set_object_terms($post_id, (int) $term_id_to_assign, $stan_taxonomy, false); 
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
    private function prepare_post_and_meta_data($advert_data, $post_id = null)
    {
        $otomoto_advert_id = $advert_data['id'];
        $post_title        = sanitize_text_field($advert_data['title']);

        // START OF MODIFICATION
        // Assign API description to post_content
        $post_content_for_wp = isset($advert_data['description']) ? wp_kses_post($advert_data['description']) : '';
        // END OF MODIFICATION

        $post_args = [
            'post_title'   => $post_title,
            'post_content' => $post_content_for_wp, // This will now hold the description from API
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
            // No longer saving _otomoto_original_description here, it's in post_content
        ];

        $params = $advert_data['params'] ?? [];

        if (isset($params['make'])) $meta_input['_otomoto_make'] = sanitize_text_field($params['make']);
        if (isset($params['model'])) $meta_input['_otomoto_model'] = sanitize_text_field($params['model']);
        if (isset($params['year'])) $meta_input['_otomoto_year'] = intval($params['year']);

        if (isset($params['price']) && is_array($params['price'])) {
            $price_details = $params['price'];
            if (isset($price_details[1])) $meta_input['_otomoto_price_value'] = filter_var(str_replace(',', '.', (string)$price_details[1]), FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            if (isset($price_details['currency'])) $meta_input['_otomoto_price_currency'] = sanitize_text_field($price_details['currency']);
            if (isset($price_details['gross_net'])) $meta_input['_otomoto_price_gross_net'] = sanitize_text_field($price_details['gross_net']);
            if (isset($price_details[0])) $meta_input['_otomoto_price_type'] = sanitize_text_field($price_details[0]);
        }

        if (isset($params['lifetime'])) {
            $meta_input['_otomoto_hours'] = sanitize_text_field($params['lifetime']) . ' mth';
        } elseif (isset($params['mileage']) && !empty($params['mileage']) && is_numeric($params['mileage'])) {
            $meta_input['_otomoto_hours'] = sanitize_text_field($params['mileage']) . ' mth';
        }

        $power_value_from_api = null;
        if (isset($params['power'])) {
            $power_value_from_api = $params['power'];
        } elseif (isset($params['engine_power'])) {
            cmu_otomoto_log('Using fallback key "engine_power" for power for advert ID: ' . $otomoto_advert_id, 'DEBUG');
            $power_value_from_api = $params['engine_power'];
        }
        if ($power_value_from_api !== null) {
            $power_str = preg_replace('/[^0-9.]/', '', (string) $power_value_from_api);
            if (is_numeric($power_str) && floatval($power_str) > 0) {
                $meta_input['_otomoto_engine_power_display'] = round(floatval($power_str)) . ' KM';
                cmu_otomoto_log('Set _otomoto_engine_power_display to: ' . $meta_input['_otomoto_engine_power_display'] . ' for advert ID: ' . $otomoto_advert_id, 'DEBUG');
            } else {
                $meta_input['_otomoto_engine_power_display'] = sanitize_text_field((string) $power_value_from_api);
                cmu_otomoto_log('Set _otomoto_engine_power_display (raw) to: ' . $meta_input['_otomoto_engine_power_display'] . ' for advert ID: ' . $otomoto_advert_id . ' due to non-numeric or zero value after preg_replace.', 'DEBUG');
            }
        } else {
            cmu_otomoto_log('No power or engine_power key found in params for advert ID: ' . $otomoto_advert_id, 'DEBUG');
        }

        if (isset($params['gearbox'])) {
            $gearbox_map = [
                'manual' => 'Manualna',
                'automatic' => 'Automatyczna',
                'semi-automatic' => 'Półautomatyczna',
            ];
            $gearbox_key = strtolower(sanitize_text_field($params['gearbox']));
            $meta_input['_otomoto_gearbox_display'] = $gearbox_map[$gearbox_key] ?? ucfirst($gearbox_key);
        }

        if (isset($params['country_origin'])) {
            $meta_input['_otomoto_origin'] = sanitize_text_field($params['country_origin']);
        }

        if (isset($params['fuel_type'])) {
            $fuel_type_map = [
                'diesel' => 'Diesel',
                'petrol' => 'Benzyna',
                'lpg' => 'LPG',
                'petrol-lpg' => 'Benzyna+LPG',
                'hybrid' => 'Hybryda',
                'electric' => 'Elektryczny',
            ];
            $fuel_type_key = strtolower(sanitize_text_field($params['fuel_type']));
            $meta_input['_otomoto_fuel_type'] = $fuel_type_map[$fuel_type_key] ?? ucfirst($fuel_type_key);
        }

        if (isset($params['engine_capacity'])) {
            $capacity_input_str = (string) $params['engine_capacity'];
            $capacity_str = str_replace([' ', 'cm3', 'cc'], '', $capacity_input_str);
            $capacity_str = str_replace(',', '.', $capacity_str);

            if (is_numeric($capacity_str)) {
                $capacity = floatval($capacity_str);
                $display_value = '';
                if ($capacity > 0) {
                    if (stripos($capacity_input_str, 'l') !== false || (strpos($capacity_input_str, '.') !== false && $capacity < 20)) {
                        $display_value = number_format_i18n($capacity, 1) . ' l';
                    } elseif ($capacity >= 1000) {
                        $display_value = number_format_i18n($capacity / 1000, 1) . ' l';
                    } else {
                        $display_value = round($capacity) . ' cm³';
                    }
                } else {
                    $display_value = sanitize_text_field($capacity_input_str);
                }
                $meta_input['_otomoto_engine_capacity_display'] = $display_value;
                cmu_otomoto_log('Set _otomoto_engine_capacity_display to: ' . $display_value . ' for advert ID: ' . $otomoto_advert_id . ' from input: ' . $capacity_input_str, 'DEBUG');
            } else {
                $meta_input['_otomoto_engine_capacity_display'] = sanitize_text_field($capacity_input_str);
                cmu_otomoto_log('Set _otomoto_engine_capacity_display (raw) to: ' . $meta_input['_otomoto_engine_capacity_display'] . ' for advert ID: ' . $otomoto_advert_id . ' as input was not numeric after cleaning.', 'DEBUG');
            }
        } else {
            cmu_otomoto_log('No "engine_capacity" key found in params for advert ID: ' . $otomoto_advert_id, 'DEBUG');
        }

        if (isset($params['features']) && is_array($params['features'])) {
            $sanitized_features = array_map('sanitize_text_field', $params['features']);
            $meta_input['_otomoto_features_list'] = $sanitized_features;
            cmu_otomoto_log('Saving features list to _otomoto_features_list for advert ID: ' . $otomoto_advert_id, 'DEBUG', ['features' => $sanitized_features]);
        } else {
             $meta_input['_otomoto_features_list'] = []; // Ensure the meta field is set, even if empty, for easier template logic
            cmu_otomoto_log('No "features" array found or not an array in params for advert ID: ' . $otomoto_advert_id . '. Setting _otomoto_features_list to empty array.', 'DEBUG');
        }

        if (isset($params['transmission'])) $meta_input['_otomoto_transmission'] = sanitize_text_field($params['transmission']);
        if (isset($params['damaged'])) $meta_input['_otomoto_damaged'] = sanitize_text_field($params['damaged']); 
        if (isset($params['financial_option'])) $meta_input['_otomoto_financial_option'] = sanitize_text_field($params['financial_option']);
        if (isset($params['vat'])) $meta_input['_otomoto_vat_info'] = sanitize_text_field($params['vat']); 
        if (isset($params['registered'])) $meta_input['_otomoto_registered_pl'] = sanitize_text_field($params['registered']); 
        if (isset($params['original_owner'])) $meta_input['_otomoto_original_owner'] = sanitize_text_field($params['original_owner']); 
        if (isset($params['no_accident'])) $meta_input['_otomoto_no_accident'] = sanitize_text_field($params['no_accident']); 

        return ['post_args' => $post_args, 'meta_input' => $meta_input];
    }

    // ... (rest of the class remains the same as provided previously)
    /**
     * Generates HTML for additional parameters to be appended to post content.
     *
     * @param array $api_params Parameters from Otomoto API.
     * @return string HTML string of additional parameters.
     */
    private function generate_additional_params_html($api_params)
    {
        $other_params_for_content = [];
        $handled_param_keys = apply_filters('cmu_otomoto_handled_param_keys_for_meta', [
            'make',
            'model',
            'year',
            'price',
            'lifetime',
            'mileage',
            'fuel_type',
            'engine_capacity',
            'engine_power',
            'power',
            'gearbox',
            'country_origin',
            'vin',
            'color',
            'door_count',
            'generation',
            'version',
            'body_type',
            'cepik_authorization',
            'video',
            'features', // Added features here as it's handled separately for meta
            'description' // Added description as it's now in post_content
        ]);

        foreach ($api_params as $key => $value) {
            if (in_array(strtolower($key), array_map('strtolower', $handled_param_keys))) {
                continue;
            }
            if (is_array($value) && $key === 'price') continue; 

            $param_label = ucfirst(str_replace('_', ' ', sanitize_key($key)));
            $param_value_str = '';

            if (is_array($value)) {
                $param_value_str = implode(', ', array_map('sanitize_text_field', $value));
            } else if (is_bool($value)) {
                $param_value_str = $value ? __('Tak', 'cmu-otomoto-integration') : __('Nie', 'cmu-otomoto-integration');
            } else if (!empty($value) || $value === 0 || $value === '0') { 
                $param_value_str = sanitize_text_field((string) $value);
            } else {
                continue; 
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

    public function create_otomoto_post($advert_data, $parent_wp_term_id)
    {
        $otomoto_advert_id = $advert_data['id']; 
        cmu_otomoto_log('Preparing to insert new post for Otomoto advert ID: ' . $otomoto_advert_id, 'INFO', ['post_title' => $advert_data['title']]);

        $prepared_data = $this->prepare_post_and_meta_data($advert_data);
        $post_args = $prepared_data['post_args'];
        $meta_input = $prepared_data['meta_input'];
        $post_args['meta_input'] = $meta_input; 

        $post_id = wp_insert_post($post_args, true);

        if (is_wp_error($post_id)) {
            cmu_otomoto_log('Failed to insert post for Otomoto advert ID: ' . $otomoto_advert_id . '. Error: ' . $post_id->get_error_message(), 'ERROR', ['post_args_keys' => array_keys($post_args)]);
            return $post_id;
        }

        cmu_otomoto_log('Successfully inserted post ID: ' . $post_id . ' for Otomoto advert ID: ' . $otomoto_advert_id, 'INFO');

        if (isset($advert_data['photos']) && !empty($advert_data['photos'])) {
            $this->handle_advert_images($post_id, $advert_data['photos'], $otomoto_advert_id, $post_args['post_title']);
        }

        if (isset($advert_data['category_id']) && isset($advert_data['new_used'])) {
            $this->assign_wp_category_and_state($post_id, $advert_data['category_id'], $advert_data['new_used'], $this->parent_wp_term_id); 
        }

        return $post_id;
    }

    public function update_otomoto_post($post_id, $advert_data, $parent_wp_term_id, $force_update = false)
    {
        cmu_otomoto_log('Preparing to update post ID: ' . $post_id . ' for Otomoto advert ID: ' . $advert_data['id'], 'INFO', ['force_update' => $force_update]);

        $is_manually_edited = get_post_meta($post_id, '_otomoto_is_edited_manually', true);
        if ($is_manually_edited && !$force_update) {
            cmu_otomoto_log('Update skipped for post ID ' . $post_id . ' (Otomoto ID: ' . $advert_data['id'] . '): Manually edited, not forcing update.', 'INFO');
            return 'skipped_manual_edit';
        }

        $otomoto_last_modified_api = $advert_data['last_update_date'] ?? null;
        $wp_last_sync = get_post_meta($post_id, '_otomoto_last_sync', true);

        $needs_update = $force_update;
        $current_post_data_prepared = $this->prepare_post_and_meta_data($advert_data, $post_id); // Prepare new data once for comparisons
        $current_wp_post = get_post($post_id);

        if (!$needs_update && $otomoto_last_modified_api && $wp_last_sync) {
            if (strtotime($otomoto_last_modified_api) > strtotime($wp_last_sync)) {
                $needs_update = true;
                cmu_otomoto_log("Post ID $post_id: Needs update based on last_update_date from API.", 'INFO');
            }
        }
        
        if (!$needs_update) { // Only check content if not already needing update by date or force
            if ($current_wp_post->post_title !== $current_post_data_prepared['post_args']['post_title']) {
                $needs_update = true;
                cmu_otomoto_log("Post ID $post_id: Title changed.", 'INFO');
            }
            // Compare current post_content (which should be API description) with new API description
            if ($current_wp_post->post_content !== $current_post_data_prepared['post_args']['post_content']) {
                $needs_update = true;
                cmu_otomoto_log("Post ID $post_id: Post content (description) changed.", 'INFO');
            }

            // Check meta fields only if still no update signaled
            if (!$needs_update) {
                $meta_fields_to_check = ['_otomoto_price_value', '_otomoto_year', '_otomoto_make', '_otomoto_model', '_otomoto_hours', '_otomoto_price_currency', '_otomoto_price_gross_net', '_otomoto_price_type', '_otomoto_features_list'];
                foreach ($meta_fields_to_check as $meta_key) {
                    $current_meta_value = get_post_meta($post_id, $meta_key, true);
                    $new_meta_value = $current_post_data_prepared['meta_input'][$meta_key] ?? null;

                    if ($meta_key === '_otomoto_features_list') { // Special comparison for arrays
                        $current_meta_value = (array) $current_meta_value;
                        $new_meta_value = (array) $new_meta_value;
                        sort($current_meta_value); // Sort for consistent comparison
                        sort($new_meta_value);
                        if ($current_meta_value !== $new_meta_value) {
                             $needs_update = true;
                             cmu_otomoto_log("Post ID $post_id: Meta field '$meta_key' (array) changed.", 'INFO');
                             break;
                        }
                    } elseif (is_numeric($new_meta_value) && is_numeric($current_meta_value)) {
                        if (abs((float)$current_meta_value - (float)$new_meta_value) > 0.001) { 
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
        }


        if (!$needs_update) {
            if (empty($wp_last_sync)) {
                update_post_meta($post_id, '_otomoto_last_sync', current_time('mysql'));
                cmu_otomoto_log('Post ID ' . $post_id . ': No changes detected, but updated missing _otomoto_last_sync.', 'INFO');
            }
            return 'skipped_no_changes';
        }

        // If $needs_update is true, proceed with update
        // $prepared_data was already calculated if we checked content. If not, calculate it now.
        // For safety, let's ensure it's always using the $current_post_data_prepared
        $post_args = $current_post_data_prepared['post_args'];
        $meta_input = $current_post_data_prepared['meta_input'];

        $existing_meta = get_post_meta($post_id);
        foreach ($existing_meta as $key => $value) {
            if (strpos($key, '_otomoto_') === 0) { 
                if (!array_key_exists($key, $meta_input)) { 
                    delete_post_meta($post_id, $key);
                    cmu_otomoto_log("Post ID $post_id: Removed old meta field '$key' as it's not in new API data.", 'INFO');
                }
            }
        }

        $post_args['meta_input'] = $meta_input; 
        $updated_post_id = wp_update_post($post_args, true);

        if (is_wp_error($updated_post_id)) {
            cmu_otomoto_log('Failed to update post ID: ' . $post_id . '. Error: ' . $updated_post_id->get_error_message(), 'ERROR', ['post_args_keys' => array_keys($post_args)]);
            return $updated_post_id;
        }
        cmu_otomoto_log('Successfully updated post core data & meta for post ID: ' . $post_id, 'INFO');

        if ($force_update || !$is_manually_edited) {
            delete_post_meta($post_id, '_otomoto_is_edited_manually');
            cmu_otomoto_log("Post ID $post_id: Cleared manual edit flag after successful sync update.", 'INFO');
        }

        $existing_gallery_ids = get_post_meta($post_id, '_otomoto_gallery_ids', true);
        if (is_array($existing_gallery_ids)) {
            foreach ($existing_gallery_ids as $att_id) {
                wp_delete_attachment($att_id, true); 
            }
        }
        delete_post_meta($post_id, '_otomoto_gallery_ids'); 
        delete_post_thumbnail($post_id); 

        if (isset($advert_data['photos']) && !empty($advert_data['photos'])) {
            $this->handle_advert_images($post_id, $advert_data['photos'], $advert_data['id'], $post_args['post_title']);
        }

        if (isset($advert_data['category_id']) && isset($advert_data['new_used'])) {
            $this->assign_wp_category_and_state($post_id, $advert_data['category_id'], $advert_data['new_used'], $this->parent_wp_term_id); 
        }

        return $post_id;
    }
    
    public function process_single_advert_data($advert_data, $force_update_all, $parent_wp_term_id, &$sync_summary_ref)
    {
        $otomoto_advert_id = $advert_data['id'] ?? null;
        $original_title = $advert_data['title'] ?? null; 

        if (empty($otomoto_advert_id)) {
            cmu_otomoto_log('Skipping advert: Advert ID is missing.', 'ERROR', ['advert_data_sample' => array_slice((array) $advert_data, 0, 5, true)]);
            if (isset($sync_summary_ref['errors_encountered'])) {
                $sync_summary_ref['errors_encountered']++;
            }
            return ['status' => 'error_missing_id', 'otomoto_id' => null];
        }

        if (!(isset($advert_data['status']) && $advert_data['status'] === 'active')) {
            cmu_otomoto_log('Skipping advert ID ' . $otomoto_advert_id . ': Status is not "active". Status: ' . ($advert_data['status'] ?? 'N/A'), 'INFO', ['title' => $original_title ?? 'N/A']);
            if (isset($sync_summary_ref['posts_skipped_inactive_status'])) {
                $sync_summary_ref['posts_skipped_inactive_status']++;
            }
            return ['status' => 'skipped_inactive', 'otomoto_id' => $otomoto_advert_id];
        }

        $current_title = trim($original_title ?? '');
        if (empty($current_title)) {
            $make = $advert_data['params']['make'] ?? '';
            $model = $advert_data['params']['model'] ?? '';

            if (!empty($make) && !empty($model)) {
                $formatted_make = ucwords(str_replace('-', ' ', sanitize_text_field($make)));
                $generated_title = $formatted_make . ' ' . sanitize_text_field($model);

                cmu_otomoto_log('Title was missing or empty for Otomoto ID ' . $otomoto_advert_id . '. Generated title: "' . $generated_title . '" from make and model.', 'WARNING');
                $advert_data['title'] = $generated_title; 
            } else {
                cmu_otomoto_log('Skipping advert ID ' . $otomoto_advert_id . ': Title is missing, and make/model params are also insufficient to generate a title.', 'ERROR', ['otomoto_id' => $otomoto_advert_id, 'make' => $make, 'model' => $model]);
                if (isset($sync_summary_ref['posts_skipped_no_title'])) {
                    $sync_summary_ref['posts_skipped_no_title']++;
                }
                if (isset($sync_summary_ref['errors_encountered'])) {
                    $sync_summary_ref['errors_encountered']++;
                }
                return ['status' => 'error_missing_title_and_make_model', 'otomoto_id' => $otomoto_advert_id];
            }
        }

        $new_used_status = strtolower((string) ($advert_data['new_used'] ?? ''));
        if ($new_used_status !== 'used') {
            cmu_otomoto_log('Skipping advert ID ' . $otomoto_advert_id . ' (not marked as "used"): ' . ($advert_data['title'] ?? '[No Title]'), 'INFO'); 
            if (isset($sync_summary_ref['posts_skipped_not_used'])) {
                $sync_summary_ref['posts_skipped_not_used']++;
            }
            return ['status' => 'skipped_not_used', 'otomoto_id' => $otomoto_advert_id];
        }

        $existing_post_id = $this->find_existing_post_by_otomoto_id($otomoto_advert_id);

        if ($existing_post_id) {
            cmu_otomoto_log('Post for Otomoto advert ID ' . $otomoto_advert_id . ' exists (WP Post ID: ' . $existing_post_id . '). Attempting update.', 'INFO');

            $update_status = $this->update_otomoto_post(
                $existing_post_id,
                $advert_data, 
                $this->parent_wp_term_id,
                $force_update_all
            );

            if (is_wp_error($update_status)) {
                if (isset($sync_summary_ref['errors_encountered'])) {
                    $sync_summary_ref['errors_encountered']++;
                }
                cmu_otomoto_log('Error updating post ID ' . $existing_post_id . ': ' . $update_status->get_error_message(), 'ERROR', ['otomoto_id' => $otomoto_advert_id]);
                return ['status' => 'error_updating', 'otomoto_id' => $otomoto_advert_id, 'post_id' => $existing_post_id];
            } elseif ($update_status === 'skipped_no_changes') {
                if (isset($sync_summary_ref['posts_skipped_no_changes'])) {
                    $sync_summary_ref['posts_skipped_no_changes']++;
                }
                return ['status' => 'skipped_no_changes', 'otomoto_id' => $otomoto_advert_id, 'post_id' => $existing_post_id];
            } elseif ($update_status === 'skipped_manual_edit') {
                if (isset($sync_summary_ref['posts_skipped_manual_edit'])) {
                    $sync_summary_ref['posts_skipped_manual_edit']++;
                }
                return ['status' => 'skipped_manual_edit', 'otomoto_id' => $otomoto_advert_id, 'post_id' => $existing_post_id];
            } else {
                if (isset($sync_summary_ref['posts_updated'])) {
                    $sync_summary_ref['posts_updated']++;
                }
                return ['status' => 'updated', 'otomoto_id' => $otomoto_advert_id, 'post_id' => $existing_post_id];
            }
        } else {
            cmu_otomoto_log('Creating new post for Otomoto advert ID: ' . $otomoto_advert_id . ' (Title: ' . ($advert_data['title'] ?? '[No Title]') . ')', 'INFO'); 

            $created_post_id = $this->create_otomoto_post(
                $advert_data, 
                $this->parent_wp_term_id
            );

            if (is_wp_error($created_post_id)) {
                if (isset($sync_summary_ref['errors_encountered'])) {
                    $sync_summary_ref['errors_encountered']++;
                }
                cmu_otomoto_log('Error creating post for Otomoto ID ' . $otomoto_advert_id . ': ' . $created_post_id->get_error_message(), 'ERROR');
                return ['status' => 'error_creating', 'otomoto_id' => $otomoto_advert_id];
            } else {
                if (isset($sync_summary_ref['posts_created'])) {
                    $sync_summary_ref['posts_created']++;
                }
                return ['status' => 'created', 'otomoto_id' => $otomoto_advert_id, 'post_id' => $created_post_id];
            }
        }
    }

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
        $max_pages_to_fetch = 100; 

        $dev_max_processed_active_adverts_limit = 0;
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

            if (count($all_fetched_adverts_data_sample) < 50) {
                $needed_sample_items = 50 - count($all_fetched_adverts_data_sample);
                $all_fetched_adverts_data_sample = array_merge($all_fetched_adverts_data_sample, array_slice($adverts_page_data, 0, $needed_sample_items));
            }
            $sync_summary['total_adverts_processed_from_api'] += count($adverts_page_data);


            foreach ($adverts_page_data as $advert_data) {
                if ($dev_max_processed_active_adverts_limit > 0 && $processed_active_adverts_count >= $dev_max_processed_active_adverts_limit) {
                    cmu_otomoto_log('Development limit of ' . $dev_max_processed_active_adverts_limit . ' processed active adverts reached during page processing. Breaking from this page.', 'INFO');
                    break; 
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
                    if (!defined('CMU_OTOMOTO_DOING_SYNC') || !CMU_OTOMOTO_DOING_SYNC) {
                        cmu_otomoto_log("KRYTYCZNE OSTRZEŻENIE: Próba usunięcia posta ID $wp_post_id (Otomoto ID: $otomoto_id_of_wp_post) bez aktywnej stałej CMU_OTOMOTO_DOING_SYNC. Może to wpłynąć na inne hooki.", 'ERROR');
                    }

                    $delete_result = wp_delete_post($wp_post_id, false); 

                    if ($delete_result !== false && $delete_result !== null) { 
                        cmu_otomoto_log("Post ID $wp_post_id (Otomoto ID: $otomoto_id_of_wp_post) został PRZENIESIONY DO KOSZA, ponieważ nie jest już aktywny w Otomoto.", 'INFO');
                        $deleted_posts_count++;
                    } else {
                        cmu_otomoto_log("Nie udało się przenieść do kosza posta ID $wp_post_id (Otomoto ID: $otomoto_id_of_wp_post).", 'ERROR', ['wp_delete_post_result' => $delete_result]);
                        $sync_summary['errors_encountered']++; 
                    }
                }
            }
        } else {
            cmu_otomoto_log('Nie znaleziono żadnych opublikowanych postów maszyn rolniczych w WP do weryfikacji (lub wszystkie znalezione są nadal aktywne w Otomoto).', 'INFO');
        }
        $sync_summary['posts_deleted_as_inactive_in_otomoto'] = $deleted_posts_count;
        cmu_otomoto_log("Zakończono proces usuwania nieaktywnych postów. Przeniesiono do kosza: $deleted_posts_count.", 'INFO');

        cmu_otomoto_log('Otomoto adverts synchronization process finished.', 'INFO', $sync_summary);

        return [
            'status' => ($sync_summary['errors_encountered'] > 0) ? 'partial_error' : 'success',
            'message' => ($sync_summary['errors_encountered'] > 0) ? 'Synchronization completed with some errors.' : 'Synchronization completed successfully.',
            'summary' => $sync_summary,
            'adverts_sample' => $sync_summary['adverts_sample'] 
        ];
    }

    public function handle_advert_images($post_id, $photos_api_data, $otomoto_advert_id, $post_title = '')
    {
        if (empty($photos_api_data) || ! is_array($photos_api_data)) {
            cmu_otomoto_log('No photos data provided or data is not an array for post ID: ' . $post_id . ', Otomoto ID: ' . $otomoto_advert_id, 'INFO');
            return;
        }

        if (! function_exists('media_handle_sideload')) { 
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $gallery_attachment_ids = [];
        $featured_image_set = false;
        $sideloaded_image_count = 0;
        $max_images_to_sideload = 1;

        if (is_array($photos_api_data) && count($photos_api_data) > 0) {
            $first_key = array_key_first($photos_api_data);
            if (is_numeric($first_key)) {
                ksort($photos_api_data, SORT_NUMERIC);
            }
        }

        $current_image_index_for_naming = 1; 

        foreach ($photos_api_data as $photo_order_key => $photo_urls_obj) {
            if ($max_images_to_sideload > 0 && $sideloaded_image_count >= $max_images_to_sideload) {
                cmu_otomoto_log('Image sideload limit (' . $max_images_to_sideload . ') reached for post ID: ' . $post_id . '. Stopping further image processing.', 'INFO');
                break;
            }

            $image_url_to_download = null;
            $photo_urls = (array) $photo_urls_obj; 
            $quality_preference = ['2048x1360', '1280x800', '1080x720', 'original', '732x488', '800x600', '640x480'];


            foreach ($quality_preference as $quality_key) {
                if (isset($photo_urls[$quality_key]) && filter_var($photo_urls[$quality_key], FILTER_VALIDATE_URL)) {
                    $image_url_to_download = $photo_urls[$quality_key];
                    cmu_otomoto_log("Post ID $post_id: Selected image URL '$image_url_to_download' with quality key '$quality_key'.", "DEBUG");
                    break;
                }
            }

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

            $tmp_file = download_url($image_url_to_download, 30); 

            if (is_wp_error($tmp_file)) {
                cmu_otomoto_log('Failed to download image: ' . $tmp_file->get_error_message(), 'ERROR', ['post_id' => $post_id, 'image_url' => $image_url_to_download]);
            } else {
                $file_array = ['tmp_name' => $tmp_file];
                $file_type_info = wp_check_filetype_and_ext($tmp_file, basename($image_url_to_download)); 
                $ext = !empty($file_type_info['ext']) ? $file_type_info['ext'] : pathinfo(parse_url($image_url_to_download, PHP_URL_PATH), PATHINFO_EXTENSION);
                if (empty($ext) || !in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif', 'webp'])) { 
                    $ext = 'jpg'; 
                }

                $base_filename = sanitize_title($post_title ?: ('otomoto-' . $otomoto_advert_id));
                if (empty($base_filename)) $base_filename = 'image-' . $otomoto_advert_id; 
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
                    $sideloaded_image_count++; 
                }
            }
            $current_image_index_for_naming++; 
        }

        if (!empty($gallery_attachment_ids)) {
            update_post_meta($post_id, '_otomoto_gallery_ids', $gallery_attachment_ids);
            cmu_otomoto_log('Updated _otomoto_gallery_ids meta for post ID: ' . $post_id . ' with ' . count($gallery_attachment_ids) . ' images.', 'INFO');
        } elseif (get_post_meta($post_id, '_otomoto_gallery_ids', true)) {
            delete_post_meta($post_id, '_otomoto_gallery_ids');
            cmu_otomoto_log('Cleared _otomoto_gallery_ids meta for post ID: ' . $post_id . ' as no new images were successfully sideloaded.', 'INFO');
        }
    }

    public function process_api_page_for_batch(
        int $api_page_number,
        int $limit_per_page,
        bool $force_update_all,
        array &$cycle_summary_ref,
        array &$processed_active_otomoto_ids_in_cycle_ref
    ) {
        cmu_otomoto_log("SyncManager: START processing API page $api_page_number, limit $limit_per_page.", 'INFO', ['force_update_all' => $force_update_all]);

        if (!$this->parent_wp_term_id) {
            $this->parent_wp_term_id = $this->ensure_parent_wp_term_exists();
        }
        if (!$this->parent_wp_term_id) {
            $error_message = 'SyncManager: Critical - Parent WP term ID could not be ensured. Aborting page processing for page ' . $api_page_number;
            cmu_otomoto_log($error_message, 'ERROR');
            if (isset($cycle_summary_ref['errors_encountered'])) { 
                $cycle_summary_ref['errors_encountered']++;
            } elseif (isset($cycle_summary_ref['errors_processing_adverts'])) { 
                $cycle_summary_ref['errors_processing_adverts']++;
            }
            return [
                'status' => 'api_error', 
                'message' => $error_message,
                'adverts_on_page_count' => 0,
            ];
        }
        cmu_otomoto_log("SyncManager: Parent WP term ID: " . $this->parent_wp_term_id, 'DEBUG');


        $adverts_page_data = $this->api_client->get_adverts($api_page_number, $limit_per_page);

        if (is_wp_error($adverts_page_data)) {
            $error_message = 'SyncManager: Error fetching adverts from API on page ' . $api_page_number . ': ' . $adverts_page_data->get_error_message();
            cmu_otomoto_log($error_message, 'ERROR');
            if (isset($cycle_summary_ref['errors_processing_adverts'])) {
                $cycle_summary_ref['errors_processing_adverts']++;
            }
            return [
                'status' => 'api_error',
                'message' => $error_message,
                'adverts_on_page_count' => 0,
            ];
        }

        if (empty($adverts_page_data)) {
            cmu_otomoto_log("SyncManager: No more adverts found on page $api_page_number. This is the end of the API results.", 'INFO');
            return [
                'status' => 'no_more_adverts',
                'message' => 'No more adverts on API page ' . $api_page_number . '.',
                'adverts_on_page_count' => 0,
            ];
        }

        $adverts_actually_on_this_page = count($adverts_page_data);
        $cycle_summary_ref['total_adverts_processed_from_api'] += $adverts_actually_on_this_page;
        cmu_otomoto_log("SyncManager: Fetched $adverts_actually_on_this_page adverts for page $api_page_number. Starting processing loop.", 'INFO');

        foreach ($adverts_page_data as $advert_data) {
            if (!isset($advert_data['id']) || empty($advert_data['id'])) {
                cmu_otomoto_log('SyncManager: Skipping advert due to missing ID on page ' . $api_page_number . '.', 'WARNING', ['advert_data_sample' => array_slice((array)$advert_data, 0, 3)]);
                if (isset($cycle_summary_ref['errors_processing_adverts'])) { 
                    $cycle_summary_ref['errors_processing_adverts']++;
                }
                continue; 
            }

            cmu_otomoto_log('SyncManager: Processing advert ID ' . $advert_data['id'] . ' from page ' . $api_page_number, 'DEBUG');

            $result = $this->process_single_advert_data(
                $advert_data,
                $force_update_all,
                $this->parent_wp_term_id, 
                $cycle_summary_ref      
            );
            if (isset($result['otomoto_id']) && !empty($result['otomoto_id'])) {
                if (in_array($result['status'], ['created', 'updated'])) {
                    if (!in_array((string) $result['otomoto_id'], $processed_active_otomoto_ids_in_cycle_ref, true)) {
                        $processed_active_otomoto_ids_in_cycle_ref[] = (string) $result['otomoto_id'];
                    }
                }
            }
        }

        cmu_otomoto_log("SyncManager: FINISHED processing API page $api_page_number. Processed $adverts_actually_on_this_page adverts from this page.", 'INFO');

        return [
            'status' => 'success',
            'message' => "Page $api_page_number processed successfully.",
            'adverts_on_page_count' => $adverts_actually_on_this_page, 
        ];
    }
}