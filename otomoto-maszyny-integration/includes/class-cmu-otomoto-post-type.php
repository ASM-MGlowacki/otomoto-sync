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
