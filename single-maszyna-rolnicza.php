<?php
/**
 * Szablon pojedynczej maszyny rolniczej
 * Ścieżka: /maszyny-rolnicze/[slug-kategorii]/[slug-maszyny]/
 */

get_header();

// Sprawdź czy post istnieje
if (have_posts()) :
    while (have_posts()) : the_post();
    
    $post_id = get_the_ID();
    $make = get_post_meta($post_id, '_otomoto_make', true);
    $model = get_post_meta($post_id, '_otomoto_model', true);
    $year = get_post_meta($post_id, '_otomoto_year', true);
    $origin = get_post_meta($post_id, '_otomoto_origin', true);
    $price_value = get_post_meta($post_id, '_otomoto_price_value', true);
    $price_currency = get_post_meta($post_id, '_otomoto_price_currency', true);
    $price_gross_net = get_post_meta($post_id, '_otomoto_price_gross_net', true);
    
    $terms = get_the_terms($post_id, 'kategorie-maszyn');
    $category_term = (!is_wp_error($terms) && !empty($terms)) ? reset($terms) : null;
    
    $gallery_ids = get_post_meta($post_id, '_otomoto_gallery_ids', true);
    $gallery_images = array();
    if (!empty($gallery_ids) && is_array($gallery_ids)) {
        foreach ($gallery_ids as $attachment_id) {
            if (wp_attachment_is_image($attachment_id)) {
                $gallery_images[] = $attachment_id;
            }
        }
    }
    
    $featured_image_id = get_post_thumbnail_id();
    if ($featured_image_id) {
        $gallery_images = array_diff($gallery_images, array($featured_image_id));
        array_unshift($gallery_images, $featured_image_id);
    }

    if (!function_exists('cmu_get_translated_feature_name')) {
        function cmu_get_translated_feature_name($feature_key) {
            $translations = [
                'front-axle-suspension'        => 'Amortyzowana przednia oś',
                'auto-pilot'                   => 'Autopilot',
                'cd'                           => 'Odtwarzacz CD',
                'gps'                          => 'System GPS',
                'cabin'                        => 'Kabina',
                'air-conditioning'             => 'Klimatyzacja',
                'pneumatic-seat'               => 'Fotel pneumatyczny',
                'front-hydraulic-lift'         => 'Przedni podnośnik hydrauliczny (TUZ)',
                'radio'                        => 'Radio',
                'trailer-brake'                => 'Hamulce do przyczep', 
                'hydraulic-accessories-system' => 'Zewnętrzny układ hydrauliczny',
                'automatic-hitch'              => 'Zaczep automatyczny',
                'drawbar'                      => 'Belka polowa',
                'front-weights'                => 'Przeciwwaga przednia',
                'toolbox'                      => 'Skrzynka narzędziowa',
                'trailer-brake-pneumatic'      => 'Hamulce pneumatyczne przyczep', 
                'pto'                          => 'WOM (Wał Odbioru Mocy)',
                'front-pto'                    => 'Przedni WOM',
                'isobus'                       => 'System ISOBUS',
                'led-lighting'                 => 'Oświetlenie LED',
                'work-lights'                  => 'Oświetlenie robocze',
                'power-steering'               => 'Wspomaganie kierownicy',
                'four-wheel-drive'             => 'Napęd 4x4',
                'differential-lock'            => 'Blokada mechanizmu różnicowego',
                'onboard-computer'             => 'Komputer pokładowy',
                'cruise-control'               => 'Tempomat',
                'heated-seat'                  => 'Podgrzewany fotel',
                'electric-mirrors'             => 'Elektrycznie sterowane lusterka',
                'air-brakes'                   => 'Hamulce pneumatyczne (ogólne)',
                'hydraulic-brakes'             => 'Hamulce hydrauliczne',
                'front-loader'                 => 'Ładowacz czołowy',
            ];
            if (isset($translations[$feature_key])) {
                return $translations[$feature_key];
            }
            $readable_key = str_replace(['-', '_'], ' ', $feature_key);
            return ucwords($readable_key); 
        }
    }
?>

<div class="cmu-otomoto-content">
    <section class="cmu-breadcrumbs-section">
        <div class="cmu-container">
            <nav class="cmu-breadcrumbs">
                <a href="<?php echo esc_url(home_url('/')); ?>">Strona główna</a>
                <span class="cmu-breadcrumb-separator"> > </span>
                <a href="<?php echo esc_url(get_post_type_archive_link('maszyna-rolnicza')); ?>">Maszyny Używane</a>
                <?php if ($category_term) : ?>
                    <span class="cmu-breadcrumb-separator"> > </span>
                    <a href="<?php echo esc_url(get_term_link($category_term)); ?>"><?php echo esc_html($category_term->name); ?></a>
                <?php endif; ?>
                <span class="cmu-breadcrumb-separator"> > </span>
                <span class="cmu-breadcrumb-current"><?php the_title(); ?></span>
            </nav>
        </div>
    </section>

    <section class="cmu-single-main-section">
        <div class="cmu-container">
            <div class="cmu-single-main-grid">
                <div class="cmu-single-gallery-column">
                    <div class="cmu-gallery-wrapper">
                        <?php if (!empty($gallery_images)) : ?>
                            <div class="cmu-main-image-container">
                                <?php 
                                $main_image_id = $gallery_images[0];
                                $main_image_url = wp_get_attachment_image_url($main_image_id, 'large');
                                $main_image_alt = get_post_meta($main_image_id, '_wp_attachment_image_alt', true);
                                ?>
                                <img id="cmu-main-product-image" src="<?php echo esc_url($main_image_url); ?>" alt="<?php echo esc_attr($main_image_alt ?: get_the_title()); ?>" class="cmu-main-product-image">
                                <div class="cmu-lightbox-trigger" onclick="cmuOpenLightbox('<?php echo esc_url($main_image_url); ?>')"><span class="cmu-zoom-icon">🔍</span></div>
                            </div>
                            <?php if (count($gallery_images) > 1) : ?>
                                <div class="cmu-thumbnails-container">
                                    <div class="cmu-thumbnails-grid">
                                        <?php foreach ($gallery_images as $index => $image_id) : 
                                            $thumb_url = wp_get_attachment_image_url($image_id, 'thumbnail');
                                            $large_url = wp_get_attachment_image_url($image_id, 'large');
                                            $thumb_alt = get_post_meta($image_id, '_wp_attachment_image_alt', true);
                                            $is_active = $index === 0 ? 'cmu-thumbnail--active' : '';
                                        ?>
                                            <div class="cmu-thumbnail-item <?php echo esc_attr($is_active); ?>" onclick="cmuChangeMainImage('<?php echo esc_url($large_url); ?>', this)">
                                                <img src="<?php echo esc_url($thumb_url); ?>" alt="<?php echo esc_attr($thumb_alt ?: get_the_title()); ?>" loading="lazy">
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php else : ?>
                            <div class="cmu-main-image-container"><div class="cmu-image-placeholder"><span>Brak zdjęć</span></div></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="cmu-single-info-column">
                    <div class="cmu-product-info-wrapper">
                        <h1 class="cmu-single-title"><?php the_title(); ?></h1>
                        <?php if ($category_term) : ?>
                            <div class="cmu-single-category"><a href="<?php echo esc_url(get_term_link($category_term)); ?>"><?php echo esc_html($category_term->name); ?></a></div>
                        <?php endif; ?>
                        <div class="cmu-single-details-block">
                            <?php if (!empty($make)) : ?><div class="cmu-single-detail-item"><span class="cmu-detail-label">Marka:</span><span class="cmu-detail-value"><?php echo esc_html($make); ?></span></div><?php endif; ?>
                            <?php if (!empty($model)) : ?><div class="cmu-single-detail-item"><span class="cmu-detail-label">Model:</span><span class="cmu-detail-value"><?php echo esc_html($model); ?></span></div><?php endif; ?>
                            <?php if (!empty($year)) : ?><div class="cmu-single-detail-item"><span class="cmu-detail-label">Rok produkcji:</span><span class="cmu-detail-value"><?php echo esc_html($year); ?></span></div><?php endif; ?>
                            <?php if (!empty($origin)) : ?><div class="cmu-single-detail-item"><span class="cmu-detail-label">Pochodzenie:</span><span class="cmu-detail-value"><?php echo esc_html(cmu_map_origin_to_polish($origin)); ?></span></div><?php endif; ?>
                        </div>
                        <?php if (!empty($price_value)) : ?>
                            <div class="cmu-single-price-block"><div class="cmu-single-price"><?php echo esc_html(cmu_format_price($price_value, $price_currency, $price_gross_net)); ?></div></div>
                        <?php endif; ?>
                        <div class="cmu-single-cta-block">
                            <a href="#cmu-contact-form" class="cmu-btn cmu-btn-primary cmu-btn-large">Skontaktuj się z Nami!</a>
                            <p class="cmu-cta-note">Zapytaj o szczegóły i możliwość obejrzenia</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Sekcja 2: Pas z Ikonami - Kluczowe Parametry -->
    <section class="cmu-specs-section">
        <div class="cmu-container">
            <div class="cmu-specs-grid">
                <?php
                $hours = get_post_meta($post_id, '_otomoto_hours', true);
                $fuel_type = get_post_meta($post_id, '_otomoto_fuel_type', true);
                $engine_capacity = get_post_meta($post_id, '_otomoto_engine_capacity_display', true);
                $engine_power = get_post_meta($post_id, '_otomoto_engine_power_display', true);
                $gearbox = get_post_meta($post_id, '_otomoto_gearbox_display', true);
                $icons_path = get_stylesheet_directory_uri() . '/assets/img/';

                $specs_to_display = [
                    ['label' => 'Liczba godzin', 'value' => $hours, 'icon' => 'clock.png', 'alt' => 'Liczba godzin'],
                    ['label' => 'Rodzaj paliwa', 'value' => $fuel_type, 'icon' => 'fuel.png', 'alt' => 'Rodzaj paliwa'],
                    ['label' => 'Silnik', 'value' => $engine_capacity, 'icon' => 'engine.png', 'alt' => 'Pojemność silnika'],
                    ['label' => 'Moc', 'value' => $engine_power, 'icon' => 'power.png', 'alt' => 'Moc'],
                    ['label' => 'Skrzynia', 'value' => $gearbox, 'icon' => 'gear.png', 'alt' => 'Skrzynia biegów'],
                ];
                ?>

                <?php foreach ($specs_to_display as $spec) : ?>
                    <?php if (!empty(trim($spec['value'])) && trim($spec['value']) !== '—') : ?>
                        <div class="cmu-spec-item">
                            <div class="cmu-spec-icon">
                                <img src="<?php echo esc_url($icons_path . $spec['icon']); ?>" alt="<?php echo esc_attr($spec['alt']); ?>" />
                            </div>
                            <div class="cmu-spec-content">
                                <span class="cmu-spec-label"><?php echo esc_html($spec['label']); ?></span>
                                <span class="cmu-spec-value"><?php echo esc_html($spec['value']); ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>

            </div>
        </div>
    </section>

    <section class="cmu-more-info-section">
        <div class="cmu-container">
            <div class="cmu-more-info-header"><h2 class="cmu-section-title">Więcej informacji:</h2></div>
            <div class="cmu-more-info-grid">
                <div class="cmu-more-info-content">
                    <h3 class="cmu-info-title"><?php the_title(); ?></h3>
                    <div class="cmu-description-or-features-section">
                        <?php
                        $features_list = get_post_meta($post_id, '_otomoto_features_list', true);
                        if (!empty($features_list) && is_array($features_list)) :
                        ?>
                            <h4 class="cmu-features-title" style="margin-top: 20px; margin-bottom: 10px; font-size: 1.2em;">Wyposażenie:</h4>
                            <ul class="cmu-features-list" style="list-style: none; padding-left: 0;">
                                <?php foreach ($features_list as $feature_key) : ?>
                                    <li style="margin-bottom: 8px; display: flex; align-items: center;">
                                        <span class="cmu-feature-icon" style="color: green; margin-right: 8px; font-size: 1.2em;">✓</span> 
                                        <?php echo esc_html(cmu_get_translated_feature_name(sanitize_text_field($feature_key))); ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else : ?>
                            <?php
                            $content = get_the_content(); 
                            if (!empty(trim($content))) {
                                echo '<div class="cmu-description-content">' . apply_filters('the_content', $content) . '</div>';
                            } else {
                                echo '<p><em>Szczegółowy opis lub lista wyposażenia będą dostępne wkrótce.</em></p>';
                            }
                            ?>
                        <?php endif; ?>
                    </div>
                    <?php 
                    $highlight_text = get_post_meta($post_id, '_cmu_highlight_text', true); 
                    if (!empty($highlight_text)) : ?>
                        <div class="cmu-highlight-box"><div class="cmu-highlight-icon">⭐</div><div class="cmu-highlight-content"><?php echo wp_kses_post($highlight_text); ?></div></div>
                    <?php endif; ?>
                    <?php
                    $extended_warranty_info = get_post_meta($post_id, '_otomoto_extended_warranty_info', true); 
                    if (!empty($extended_warranty_info)) : ?>
                        <div class="cmu-extended-warranty-info" style="margin-top: 20px; padding: 10px; border: 1px solid #4CAF50; background-color: #e8f5e9; color: #2e7d32;"><p style="margin: 0;"><?php echo esc_html($extended_warranty_info); ?></p></div>
                    <?php endif; ?>
                    <div class="cmu-legal-disclaimer" style="margin-top: 20px; font-size: 0.9em; color: #757575;"><p><em>Niniejsze ogłoszenie jest wyłącznie informacją handlową i nie stanowi oferty w myśl art. 6, par.1 Kodeksu Cywilnego. Sprzedający nie odpowiada za ewentualne błędy lub nieaktualności ogłoszenia.</em></p></div>
                    <div class="cmu-contact-info" style="margin-top: 30px;">
                        <h4>Informacje kontaktowe:</h4>
                        <div class="cmu-contact-details">
                            <p><strong>Centrum Maszyn Używanych</strong></p>
                            <p>📧 Email: <a href="mailto:kontakt@cmu24.pl">kontakt@cmu24.pl</a></p>
                            <p>📞 Telefon: <a href="tel:+48123456789">+48 123 456 789</a></p>
                            <p>📍 Lokalizacja: Sprawdź szczegóły lokalizacji maszyny</p>
                        </div>
                    </div>
                </div>
                <div class="cmu-more-info-image">
                    <?php if (!empty($gallery_images) && count($gallery_images) > 1) : 
                        $side_image_id = $gallery_images[1];
                        $side_image_url = wp_get_attachment_image_url($side_image_id, 'large');
                        $side_image_alt = get_post_meta($side_image_id, '_wp_attachment_image_alt', true);
                    ?>
                        <div class="cmu-side-image-container"><img src="<?php echo esc_url($side_image_url); ?>" alt="<?php echo esc_attr($side_image_alt ?: get_the_title()); ?>" class="cmu-side-image"></div>
                    <?php elseif (has_post_thumbnail()) : 
                        $main_image_url = get_the_post_thumbnail_url($post_id, 'large');
                    ?>
                        <div class="cmu-side-image-container"><img src="<?php echo esc_url($main_image_url); ?>" alt="<?php echo esc_attr(get_the_title()); ?>" class="cmu-side-image cmu-side-image--grayscale"></div>
                    <?php else : ?>
                        <div class="cmu-side-image-placeholder"><div class="cmu-placeholder-content"><span class="cmu-placeholder-icon">📷</span><span class="cmu-placeholder-text">Dodatkowe zdjęcia wkrótce</span></div></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <section id="cmu-contact-form" class="cmu-contact-section">
        <div class="cmu-container">
            <div class="cmu-contact-header"><h2 class="cmu-section-title">Skontaktuj się z nami</h2><p class="cmu-contact-subtitle">Zapytaj o szczegóły maszyny lub umów się na obejrzenie</p></div>
            <div class="cmu-contact-placeholder">
                <div class="cmu-contact-main-box">
                    <div class="cmu-contact-icon">📞</div>
                    <h3 class="cmu-contact-title">Zadzwoń lub napisz do nas</h3>
                    <div class="cmu-contact-methods">
                        <div class="cmu-contact-method"><span class="cmu-method-icon">📞</span><div class="cmu-method-content"><strong>Telefon:</strong><a href="tel:+48123456789" class="cmu-phone-link">+48 123 456 789</a></div></div>
                        <div class="cmu-contact-method"><span class="cmu-method-icon">📧</span><div class="cmu-method-content"><strong>Email:</strong><a href="mailto:kontakt@cmu24.pl" class="cmu-email-link">kontakt@cmu24.pl</a></div></div>
                        <div class="cmu-contact-method"><span class="cmu-method-icon">💬</span><div class="cmu-method-content"><strong>Formularz kontaktowy:</strong><a href="/kontakt/" class="cmu-form-link">Przejdź do formularza</a></div></div>
                    </div>
                    <div class="cmu-working-hours"><h4>Godziny pracy:</h4><p><strong>Pon-Pt:</strong> 8:00 - 17:00<br><strong>Sobota:</strong> 8:00 - 14:00<br><strong>Niedziela:</strong> nieczynne</p></div>
                </div>
                
            </div>
        </div>
    </section>

    <div id="cmu-lightbox" class="cmu-lightbox" onclick="cmuCloseLightbox()"><span class="cmu-lightbox-close">×</span><img id="cmu-lightbox-image" class="cmu-lightbox-content"></div>
</div>

<script>
function cmuChangeMainImage(imageUrl, thumbnailElement) {
    const mainImage = document.getElementById('cmu-main-product-image');
    if (mainImage) {
        mainImage.src = imageUrl;
        const lightboxTrigger = mainImage.closest('.cmu-main-image-container').querySelector('.cmu-lightbox-trigger');
        if (lightboxTrigger) {
            lightboxTrigger.setAttribute('onclick', `cmuOpenLightbox('${imageUrl}')`);
        }
    }
    document.querySelectorAll('.cmu-thumbnail-item').forEach(thumb => {thumb.classList.remove('cmu-thumbnail--active');});
    if (thumbnailElement) {thumbnailElement.classList.add('cmu-thumbnail--active');}
}
function cmuOpenLightbox(imageUrl) {
    const lightbox = document.getElementById('cmu-lightbox');
    const lightboxImage = document.getElementById('cmu-lightbox-image');
    if (lightbox && lightboxImage) {
        lightboxImage.src = imageUrl;
        lightbox.style.display = 'block';
        document.body.style.overflow = 'hidden'; 
    }
}
function cmuCloseLightbox() {
    const lightbox = document.getElementById('cmu-lightbox');
    if (lightbox) {
        lightbox.style.display = 'none';
        document.body.style.overflow = 'auto'; 
    }
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' || e.keyCode === 27) { 
        if (document.getElementById('cmu-lightbox') && document.getElementById('cmu-lightbox').style.display === 'block') {
            cmuCloseLightbox();
        }
    }
});
</script>

<?php 
    endwhile;
endif;
get_footer();
?>