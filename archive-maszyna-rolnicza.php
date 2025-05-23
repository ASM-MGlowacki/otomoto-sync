<?php
/**
 * Szablon archiwum dla CPT maszyna-rolnicza
 * Główne archiwum maszyn (/maszyny-rolnicze/)
 */

get_header(); ?>

<div class="cmu-otomoto-content">
    <!-- Sekcja Hero -->
    <section class="cmu-hero-section">
        <div class="cmu-hero-container">
            <div class="cmu-hero-content">
                <h1 class="cmu-hero-title">Używane maszyny rolnicze</h1>
                <p class="cmu-hero-subtitle">Sprzedajemy używane maszyny z drugiej ręki</p>
            </div>
        </div>
    </section>

    <!-- Przyciski Kategorii -->
    <section class="cmu-categories-section">
        <div class="cmu-container">
            <div class="cmu-categories-buttons">
                <?php
                // Sprawdzamy dostępne taksonomie dla maszyna-rolnicza
                $taxonomies = get_object_taxonomies('maszyna-rolnicza');
                $taxonomy_name = in_array('kategorie-maszyn', $taxonomies) ? 'kategorie-maszyn' : 'kategoria_maszyny';
                
                $categories = get_terms(array(
                    'taxonomy' => $taxonomy_name,
                    'hide_empty' => false,
                ));
                
                if (!is_wp_error($categories) && !empty($categories)) :
                    foreach ($categories as $category) : ?>
                        <a href="<?php echo esc_url(get_term_link($category)); ?>" 
                           class="cmu-category-button">
                            <?php echo esc_html($category->name); ?>
                        </a>
                    <?php endforeach;
                endif; ?>
            </div>
        </div>
    </section>

    <!-- Siatka Produktów -->
    <section class="cmu-products-section">
        <div class="cmu-container">
            <?php if (have_posts()) : ?>
                <div class="cmu-products-grid">
                    <?php while (have_posts()) : the_post(); ?>
                        <?php get_template_part('template-parts/content', 'maszyna-rolnicza'); ?>
                    <?php endwhile; ?>
                </div>

                <!-- Paginacja -->
                <div class="cmu-pagination">
                    <?php
                    the_posts_pagination(array(
                        'mid_size' => 2,
                        'prev_text' => '&laquo; Poprzednia',
                        'next_text' => 'Następna &raquo;',
                    ));
                    ?>
                </div>

            <?php else : ?>
                <div class="cmu-no-products">
                    <p>Nie znaleziono żadnych maszyn.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php get_footer(); ?>
