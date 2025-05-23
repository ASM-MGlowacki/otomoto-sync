<?php
/**
 * Template part dla wyświetlania karty produktu (maszyny rolniczej)
 * Używany w: archive-maszyna-rolnicza.php, taxonomy-kategorie-maszyn.php
 */

// Pobierz ID aktualnego postu
$post_id = get_the_ID();

// Pobierz dane meta
$make = get_post_meta($post_id, '_otomoto_make', true);
$model = get_post_meta($post_id, '_otomoto_model', true);
$year = get_post_meta($post_id, '_otomoto_year', true);
$origin = get_post_meta($post_id, '_otomoto_origin', true);
$price_value = get_post_meta($post_id, '_otomoto_price_value', true);
$price_currency = get_post_meta($post_id, '_otomoto_price_currency', true);
$price_gross_net = get_post_meta($post_id, '_otomoto_price_gross_net', true);

// Pobierz kategorię (pierwszą przypisaną)
$terms = get_the_terms($post_id, 'kategorie-maszyn');
$category_term = (!is_wp_error($terms) && !empty($terms)) ? reset($terms) : null;
?>

<article class="cmu-product-card">
    <!-- Zdjęcie produktu -->
    <div class="cmu-product-image">
        <a href="<?php echo esc_url(get_permalink()); ?>" aria-label="<?php echo esc_attr('Przejdź do ' . get_the_title()); ?>">
            <?php if (has_post_thumbnail()) : ?>
                <?php the_post_thumbnail('medium', array(
                    'alt' => esc_attr(get_the_title()),
                    'class' => 'cmu-product-thumbnail',
                    'loading' => 'lazy'
                )); ?>
            <?php else : ?>
                <div class="cmu-product-placeholder">
                    <span>Brak zdjęcia</span>
                </div>
            <?php endif; ?>
        </a>
    </div>

    <!-- Treść karty -->
    <div class="cmu-product-content">
        <!-- Nazwa produktu -->
        <h3 class="cmu-product-title">
            <a href="<?php echo esc_url(get_permalink()); ?>">
                <?php the_title(); ?>
            </a>
        </h3>

        <!-- Kategoria -->
        <?php if ($category_term) : ?>
            <div class="cmu-product-category">
                <a href="<?php echo esc_url(get_term_link($category_term)); ?>">
                    <?php echo esc_html($category_term->name); ?>
                </a>
            </div>
        <?php endif; ?>

        <!-- Kluczowe dane -->
        <div class="cmu-product-details">
            <?php if (!empty($make)) : ?>
                <div class="cmu-detail-item">
                    <span class="cmu-detail-label">Marka:</span>
                    <span class="cmu-detail-value"><?php echo esc_html($make); ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($model)) : ?>
                <div class="cmu-detail-item">
                    <span class="cmu-detail-label">Model:</span>
                    <span class="cmu-detail-value"><?php echo esc_html($model); ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($year)) : ?>
                <div class="cmu-detail-item">
                    <span class="cmu-detail-label">Rok produkcji:</span>
                    <span class="cmu-detail-value"><?php echo esc_html($year); ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($origin)) : ?>
                <div class="cmu-detail-item">
                    <span class="cmu-detail-label">Pochodzenie:</span>
                    <span class="cmu-detail-value"><?php echo esc_html(cmu_map_origin_to_polish($origin)); ?></span>
                </div>
            <?php endif; ?>
        </div>

        <!-- Cena -->
        <?php if (!empty($price_value)) : ?>
            <div class="cmu-product-price">
                <?php echo esc_html(cmu_format_price($price_value, $price_currency, $price_gross_net)); ?>
            </div>
        <?php endif; ?>

        <!-- Przycisk CTA -->
        <div class="cmu-product-cta">
            <a href="<?php echo esc_url(get_permalink()); ?>" 
               class="cmu-btn cmu-btn-primary">
                Dowiedz się więcej
            </a>
        </div>
    </div>
</article> 