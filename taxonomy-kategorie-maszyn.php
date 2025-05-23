<?php
/**
 * Szablon archiwum kategorii dla taksonomii kategorie-maszyn
 * Ścieżka: /maszyny-rolnicze/[slug-kategorii]/
 */

get_header(); 

$current_term = get_queried_object();
?>

<div class="cmu-otomoto-content">
    <!-- Breadcrumbs -->
    <section class="cmu-breadcrumbs-section">
        <div class="cmu-container">
            <nav class="cmu-breadcrumbs">
                <a href="<?php echo esc_url(home_url('/')); ?>">Strona główna</a>
                <span class="cmu-breadcrumb-separator"> &gt; </span>
                <a href="<?php echo esc_url(get_post_type_archive_link('maszyna-rolnicza')); ?>">Maszyny Używane</a>
                <span class="cmu-breadcrumb-separator"> &gt; </span>
                <span class="cmu-breadcrumb-current"><?php echo esc_html($current_term->name); ?></span>
            </nav>
        </div>
    </section>

    <!-- Nagłówek kategorii -->
    <section class="cmu-category-header-section">
        <div class="cmu-container">
            <div class="cmu-category-header">
                <h1 class="cmu-category-title"><?php echo esc_html($current_term->name); ?></h1>
                <?php if (!empty($current_term->description)) : ?>
                    <p class="cmu-category-description"><?php echo esc_html($current_term->description); ?></p>
                <?php endif; ?>
                <div class="cmu-category-stats">
                    <span class="cmu-machine-count">
                        <?php 
                        echo sprintf(
                            _n('Znaleziono %d maszynę', 'Znaleziono %d maszyn', $wp_query->found_posts, 'twentytwentyfive-child'),
                            $wp_query->found_posts
                        );
                        ?>
                    </span>
                </div>
            </div>
        </div>
    </section>

    <!-- Przyciski kategorii (inne kategorie) -->
    <section class="cmu-categories-section">
        <div class="cmu-container">
            <div class="cmu-categories-buttons">
                <!-- Link do wszystkich maszyn -->
                <a href="<?php echo esc_url(get_post_type_archive_link('maszyna-rolnicza')); ?>" 
                   class="cmu-category-button">
                    Wszystkie maszyny
                </a>

                <?php
                $all_categories = get_terms(array(
                    'taxonomy' => 'kategorie-maszyn',
                    'hide_empty' => false,
                ));
                
                if (!is_wp_error($all_categories) && !empty($all_categories)) :
                    foreach ($all_categories as $category) : 
                        $is_current = ($category->term_id === $current_term->term_id);
                        $button_class = $is_current ? 'cmu-category-button cmu-category-button--active' : 'cmu-category-button';
                        ?>
                        <a href="<?php echo esc_url(get_term_link($category)); ?>" 
                           class="<?php echo esc_attr($button_class); ?>">
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
                    <p>Nie znaleziono maszyn w tej kategorii.</p>
                    <a href="<?php echo esc_url(get_post_type_archive_link('maszyna-rolnicza')); ?>" 
                       class="cmu-btn cmu-btn-primary">
                        Zobacz wszystkie maszyny
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php get_footer(); ?>
