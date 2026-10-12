<?php
/**
 * Template : page de profil expert public
 * Intégration complète GeneratePress (parent) :
 *  - Utilise generate_content_template() pour respecter la mise en page GP
 *  - Compatible sidebar (right, left, no-sidebar selon le Customizer GP)
 *  - Hooks generate_before/after_content pour les widgets GP
 *  - Breadcrumb GP natif
 *  - Classes CSS GP : generate-columns-container, entry-content, etc.
 *
 * Priorité de chargement : thème enfant > plugin (filtre single_template)
 */
defined('ABSPATH') || exit;

get_header();

// GeneratePress : ouvrir la structure de contenu correcte
// generate_do_template_part() gère sidebar + content selon les réglages GP
RDR_ED_GP_Compat::before_main_content();
?>

<div <?php RDR_ED_GP_Compat::do_attr('content'); ?>>
    <main <?php RDR_ED_GP_Compat::do_attr('main'); ?>>

        <?php
        // Hook GP : avant le contenu (breadcrumb, titre de page, etc.)
        RDR_ED_GP_Compat::before_content();
        ?>

        <?php while (have_posts()) : the_post(); ?>
        <?php
        $post_id   = get_the_ID();
        $author_id = (int) get_post_field('post_author', $post_id);
        $is_active  = RDR_ED_Versioning::get_status($post_id) === 'active';
        $is_premium = RDR_ED_Premium::is_premium($author_id);
        $is_owner   = is_user_logged_in() && get_current_user_id() === $author_id;
        $is_admin   = current_user_can(RDR_ED_Capabilities::CAP_VALIDATE);

        /* ── Accès refusé : non premium ou inactif ── */
        if ((!$is_premium || !$is_active) && !$is_owner && !$is_admin) :
        ?>
        <article id="post-<?= $post_id; ?>" <?php post_class('rdr-expert-single'); ?>>
            <div class="entry-content">
                <div class="rdr-info-box rdr-info-warning">
                    <span class="rdr-info-icon">🔒</span>
                    <div>
                        <span class="rdr-info-title"><?php _e('Fiche non disponible', 'rdr-expert-directory'); ?></span>
                        <div class="rdr-info-content">
                            <?php _e('Cette fiche expert n\'est pas disponible actuellement.', 'rdr-expert-directory'); ?>
                            <br><a href="<?= esc_url(get_post_type_archive_link('expert_profile')); ?>">
                                ← <?php _e('Retour à l\'annuaire', 'rdr-expert-directory'); ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </article>
        <?php else : ?>
        <?php
        /* ── Données de la fiche ── */
        $photo_id   = (int) get_post_meta($post_id, '_profile_photo_id', true);
        $photo_url  = $photo_id ? wp_get_attachment_image_url($photo_id, 'medium') : get_avatar_url($author_id, ['size' => 200]);
        $banner_id  = (int) get_post_meta($post_id, '_banner_id', true);
        $banner_url = $banner_id ? wp_get_attachment_image_url($banner_id, 'large') : '';
        // Si la fiche est en attente de validation, charger les données depuis pending
        // car apply_data_to_post n'a pas encore été appelé (metas WP vides)
        $_pending_data = RDR_ED_Versioning::get_pending($post_id);
        $_get_meta = function(string $key) use ($post_id, $_pending_data): string {
            if ($_pending_data && isset($_pending_data[$key]) && $_pending_data[$key] !== '') {
                return (string) $_pending_data[$key];
            }
            return (string) get_post_meta($post_id, $key, true);
        };

        $type         = $_get_meta('_expert_type');
        $company      = $_get_meta('_expert_company');
        $expert_title = $_get_meta('_expert_title');
        $references   = $_get_meta('_expert_references');
        $credly     = $_get_meta('_expert_credly');
        $li_profil  = $_get_meta('_expert_linkedin_profil');
        $li_page    = $_get_meta('_expert_linkedin_page');
        $website    = $_get_meta('_expert_website');
        $clicks     = RDR_ED_Shortlinks::get_click_count($post_id);

        // Moyens de contact activés par l'expert
        $contact_methods = array_values(array_filter((array) get_post_meta($post_id, '_expert_contact_methods', true)));
        if (empty($contact_methods)) $contact_methods = ['email'];

        $has_email    = in_array('email', $contact_methods, true);
        $has_phone    = in_array('phone', $contact_methods, true);
        $has_linkedin = in_array('linkedin', $contact_methods, true);

        $email_pub  = get_post_meta($post_id, '_expert_email_public', true);
        $phone_pub  = get_post_meta($post_id, '_expert_phone_public', true);
        // Email/téléphone doit exister ET être dans contact_methods
        $expert_email_val = $_get_meta('_expert_email');
        $expert_phone_val = $_get_meta('_expert_phone');
        $has_email  = $has_email  && !empty($expert_email_val);
        $has_phone  = $has_phone  && !empty($expert_phone_val);

        // Révélation email autorisée globalement ?
        $email_reveal_global = (bool) RDR_ED_Settings::get('contact_email_reveal', true);

        // Formulaire de contact activé ?
        $form_enabled_global = (bool) RDR_ED_Settings::get('contact_form_enabled', true);
        $form_enabled_expert = get_post_meta($post_id, '_expert_form_enabled', true);
        $show_form = $form_enabled_global && ($form_enabled_expert !== '0');

        // Shortlink
        $shortlink  = RDR_ED_Settings::get('shortlink_enabled') ? RDR_ED_Shortlinks::get_shortlink($post_id) : get_permalink($post_id);

        $skills    = wp_get_post_terms($post_id, 'expert_skill');
        $languages = wp_get_post_terms($post_id, 'expert_language');
        $countries = wp_get_post_terms($post_id, 'expert_country');
        if (is_wp_error($skills))    $skills    = [];
        if (is_wp_error($languages)) $languages = [];
        if (is_wp_error($countries)) $countries = [];

        $has_links = $credly || ($li_profil && !in_array('linkedin', $contact_methods, true)) || $li_page || $website;

        // Hreflang si Polylang actif
        if (RDR_ED_Polylang::is_polylang_active()) {
            add_action('wp_head', function() use ($post_id) {
                RDR_ED_Polylang::output_hreflang($post_id);
            }, 5);
        }
        ?>

        <article id="post-<?= $post_id; ?>"
            <?php post_class('rdr-expert-single'); ?>
            itemscope itemtype="https://schema.org/Person">

            <?php
            // Hook GP : avant le titre de l'entrée
            RDR_ED_GP_Compat::before_entry_title();
            ?>

            <?php if ($is_owner || $is_admin) : ?>
            <!-- ── Barre propriétaire/admin ── -->
            <div class="rdr-expert-actions-bar">
                <?php if ($is_owner) : ?>
                <a href="<?= esc_url(home_url('/mon-profil-expert/')); ?>" class="button button-sm button-outline">
                    ✏️ <?php _e('Modifier ma fiche', 'rdr-expert-directory'); ?>
                </a>
                <?php endif; ?>
                <?php if ($is_admin) : ?>
                <a href="<?= esc_url(get_edit_post_link($post_id)); ?>" class="button button-sm button-ghost">
                    🔧 <?php _e('Admin', 'rdr-expert-directory'); ?>
                </a>
                <span class="rdr-ed-type-badge"><?= $is_premium ? '✅ Premium' : '❌ Non premium'; ?></span>
                <span class="rdr-ed-type-badge"><?= $is_active ? '● Active' : '● Inactive'; ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- ── Bannière ── -->
            <div class="rdr-expert-banner">
                <?php if ($banner_url) : ?>
                <img src="<?= esc_url($banner_url); ?>" alt="" loading="eager">
                <?php endif; ?>
            </div>

            <!-- ── En-tête : avatar + nom + meta ── -->
            <div class="rdr-expert-header">
                <div class="rdr-expert-avatar">
                    <img src="<?= esc_url($photo_url); ?>"
                         alt="<?= esc_attr(get_the_title()); ?>"
                         loading="eager"
                         itemprop="image">
                </div>
                <div class="rdr-expert-header-info">
                    <h1 class="rdr-expert-name" itemprop="name"><?php the_title(); ?></h1>
                    <?php if ($expert_title) : ?>
                    <p class="rdr-expert-title-line" itemprop="jobTitle"><?= esc_html($expert_title); ?></p>
                    <?php endif; ?>
                    <?php if ($company && $type === 'entreprise') : ?>
                    <p class="rdr-expert-company" itemprop="worksFor"><?= esc_html($company); ?></p>
                    <?php endif; ?>
                    <div class="rdr-expert-meta-row">
                        <?php if ($type) : ?>
                        <span class="rdr-ed-type-badge">
                            <?= $type === 'independant' ? '👤 ' . __('Indépendant', 'rdr-expert-directory') : '🏢 ' . __('Entreprise', 'rdr-expert-directory'); ?>
                        </span>
                        <?php endif; ?>
                        <?php if (!empty($countries)) : ?>
                        <span itemprop="addressCountry">📍 <?= esc_html(implode(', ', wp_list_pluck($countries, 'name'))); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($languages)) : ?>
                        <span>🗣️ <?= esc_html(implode(', ', wp_list_pluck($languages, 'name'))); ?></span>
                        <?php endif; ?>
                        <?php if ($clicks) : ?>
                        <span title="<?php _e('Vues via shortlink', 'rdr-expert-directory'); ?>">👁️ <?= number_format_i18n($clicks); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ── Compétences ── -->
            <?php if (!empty($skills)) : ?>
            <div class="rdr-expert-skills">
                <?php foreach ($skills as $skill) : ?>
                <a href="<?= esc_url(get_term_link($skill)); ?>"
                   class="rdr-expert-skill-tag"
                   rel="nofollow noopener noreferrer">
                    <?= esc_html($skill->name); ?>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- ── Liens externes (hors contact) ── -->
            <?php if ($has_links) : ?>
            <div class="rdr-expert-links">
                <?php if (!in_array('linkedin', $contact_methods, true) && $li_profil) : ?>
                <a href="<?= esc_url($li_profil); ?>" class="rdr-expert-link-btn" rel="nofollow noopener noreferrer" target="_blank">
                    💼 LinkedIn Profil <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="opacity:.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                </a>
                <?php endif; ?>
                <?php if ($li_page) : ?>
                <a href="<?= esc_url($li_page); ?>" class="rdr-expert-link-btn" rel="nofollow noopener noreferrer" target="_blank">
                    🏢 LinkedIn Entreprise <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="opacity:.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                </a>
                <?php endif; ?>
                <?php if ($credly) : ?>
                <a href="<?= esc_url($credly); ?>" class="rdr-expert-link-btn" rel="nofollow noopener noreferrer" target="_blank">
                    🏅 Credly <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="opacity:.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                </a>
                <?php endif; ?>
                <?php if ($website) : ?>
                <a href="<?= esc_url($website); ?>" class="rdr-expert-link-btn" rel="nofollow noopener noreferrer" target="_blank">
                    🌐 <?php _e('Site web', 'rdr-expert-directory'); ?> <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="opacity:.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                </a>
                <?php endif; ?>
                <?php if ($shortlink && RDR_ED_Settings::get('shortlink_enabled')) : ?>
                <button type="button" class="rdr-expert-link-btn" id="rdr-copy-shortlink" data-url="<?= esc_url($shortlink); ?>">
                    🔗 <?php _e('Partager ce profil', 'rdr-expert-directory'); ?>
                </button>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- ── Présentation ── -->
            <?php
            // Afficher le contenu : depuis pending si post_content vide
            $_display_content = get_the_content();
            if (!$_display_content && $_pending_data && !empty($_pending_data['post_content'])) {
                $_display_content = wp_kses_post($_pending_data['post_content']);
            }
            if ($_display_content) : ?>
            <div class="rdr-expert-section">
                <h2><?php _e('Présentation', 'rdr-expert-directory'); ?></h2>
                <div class="entry-content" itemprop="description">
                    <?= wp_kses_post($_display_content); ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── Références ── -->
            <?php if ($references) : ?>
            <div class="rdr-expert-section">
                <h2><?php _e('Références & Réalisations', 'rdr-expert-directory'); ?></h2>
                <div class="entry-content">
                    <?= wp_kses_post($references); ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── Bloc Contact ── -->
            <?php if ($has_email || $has_phone || $has_linkedin) : ?>
            <div class="rdr-expert-contact-box" id="rdr-contact-section">
                <h3>📬 <?php _e('Contacter cet expert', 'rdr-expert-directory'); ?></h3>

                <div class="rdr-contact-methods-grid">

                    <?php if ($has_phone && $phone_pub) : ?>
                    <!-- Téléphone : révélation sécurisée -->
                    <div class="rdr-contact-method-item">
                        <div class="rdr-contact-reveal-wrap">
                            <button class="rdr-contact-reveal-btn"
                                data-post="<?= $post_id; ?>"
                                data-type="phone"
                                aria-label="<?php esc_attr_e('Afficher le numéro de téléphone', 'rdr-expert-directory'); ?>">
                                📞 <?php _e('Afficher le téléphone', 'rdr-expert-directory'); ?>
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($has_email && $email_pub && $email_reveal_global) : ?>
                    <!-- Email : révélation sécurisée (click to reveal) -->
                    <div class="rdr-contact-method-item">
                        <div class="rdr-contact-reveal-wrap">
                            <button class="rdr-contact-reveal-btn"
                                data-post="<?= $post_id; ?>"
                                data-type="email"
                                aria-label="<?php esc_attr_e('Afficher l\'adresse email', 'rdr-expert-directory'); ?>">
                                📧 <?php _e('Afficher l\'email', 'rdr-expert-directory'); ?>
                            </button>
                        </div>
                        <p class="rdr-contact-hint">
                            <?php _e('Cliquez pour révéler — votre navigateur vous permettra de copier ou d\'ouvrir votre client email.', 'rdr-expert-directory'); ?>
                        </p>
                    </div>
                    <?php elseif ($has_email && (!$email_pub || !$email_reveal_global) && $show_form) : ?>
                    <!-- Email non révélable ou révélation désactivée → formulaire uniquement -->
                    <div class="rdr-contact-method-item">
                        <p class="rdr-contact-hint">📧 <?php _e('Utilisez le formulaire ci-dessous pour contacter cet expert.', 'rdr-expert-directory'); ?></p>
                    </div>
                    <?php endif; ?>

                    <?php if ($has_linkedin && $li_profil) : ?>
                    <!-- LinkedIn : lien direct sécurisé -->
                    <div class="rdr-contact-method-item">
                        <div class="rdr-contact-reveal-wrap">
                            <button class="rdr-contact-reveal-btn"
                                data-post="<?= $post_id; ?>"
                                data-type="linkedin">
                                💼 <?php _e('Voir le profil LinkedIn', 'rdr-expert-directory'); ?>
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>

                </div><!-- .rdr-contact-methods-grid -->

                <!-- Formulaire de contact (optionnel) -->
                <?php if ($show_form && $has_email) : ?>
                <div class="rdr-contact-form-toggle" style="margin-top:20px;">
                    <button type="button" class="button button-outline" id="rdr-contact-toggle">
                        📩 <?php _e('Envoyer un message', 'rdr-expert-directory'); ?>
                    </button>
                    <div id="rdr-contact-form-body" style="display:none;margin-top:16px;">
                        <div id="rdr-contact-msg" class="rdr-form-message" style="display:none;"></div>
                        <form id="rdr-contact-form" novalidate>
                            <!-- Honeypot -->
                            <div style="display:none!important;" aria-hidden="true">
                                <input type="text" name="website_confirm" tabindex="-1" autocomplete="off" value="">
                            </div>
                            <input type="hidden" name="post_id"   value="<?= $post_id; ?>">
                            <!-- _msg_time injecté par JS au moment de la soumission -->
                            <div class="rdr-form-grid rdr-grid-2" style="margin-bottom:12px;">
                                <div class="rdr-form-group">
                                    <label for="contact_name"><?php _e('Votre nom *', 'rdr-expert-directory'); ?></label>
                                    <input type="text" id="contact_name" name="contact_name" required autocomplete="name">
                                </div>
                                <div class="rdr-form-group">
                                    <label for="contact_email"><?php _e('Votre email *', 'rdr-expert-directory'); ?></label>
                                    <input type="email" id="contact_email" name="contact_email" required autocomplete="email">
                                </div>
                            </div>
                            <div class="rdr-form-group" style="margin-bottom:12px;">
                                <label for="contact_subject"><?php _e('Sujet *', 'rdr-expert-directory'); ?></label>
                                <input type="text" id="contact_subject" name="contact_subject" required>
                            </div>
                            <div class="rdr-form-group" style="margin-bottom:16px;">
                                <label for="contact_message"><?php _e('Message *', 'rdr-expert-directory'); ?></label>
                                <textarea id="contact_message" name="contact_message" rows="5" required
                                    placeholder="<?php esc_attr_e('Décrivez votre besoin…', 'rdr-expert-directory'); ?>"></textarea>
                            </div>
                            <button type="submit" class="button"><?php _e('Envoyer', 'rdr-expert-directory'); ?></button>
                            <p class="description" style="margin-top:8px;">
                                🔒 <?php _e('Votre message est transmis directement à l\'expert. Vos données ne sont pas stockées.', 'rdr-expert-directory'); ?>
                            </p>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

            <!-- ── Messagerie interne ── -->
            <?php if (($has_email || $has_phone) && !$is_owner) : ?>
            <div style="margin-top:16px;border-top:1px solid var(--rdr-border);padding-top:16px;">
                <button type="button" id="rdr-internal-msg-toggle"
                    data-label-open="<?php esc_attr_e('Envoyer un message', 'rdr-expert-directory'); ?>"
                    class="button button-outline">
                    💬 <?php _e('Envoyer un message', 'rdr-expert-directory'); ?>
                </button>
                <div id="rdr-internal-msg-form" style="display:none;margin-top:12px;">
                    <div class="rdr-internal-message-form">
                        <h4>📨 <?php _e('Message interne', 'rdr-expert-directory'); ?></h4>
                        <div id="rdr-internal-msg-result" class="rdr-form-message" style="display:none;"></div>
                        <form id="rdr-internal-contact-form" novalidate>
                            <div style="display:none!important;" aria-hidden="true">
                                <input type="text" name="website_internal" tabindex="-1" autocomplete="off" value="">
                            </div>
                            <input type="hidden" name="post_id" value="<?= $post_id; ?>">
                            <div class="rdr-form-grid rdr-grid-2" style="margin-bottom:12px;">
                                <div class="rdr-form-group">
                                    <label for="msg_lastname"><?php _e('Nom *', 'rdr-expert-directory'); ?></label>
                                    <input type="text" id="msg_lastname" name="msg_lastname" required autocomplete="family-name">
                                </div>
                                <div class="rdr-form-group">
                                    <label for="msg_firstname"><?php _e('Prénom *', 'rdr-expert-directory'); ?></label>
                                    <input type="text" id="msg_firstname" name="msg_firstname" required autocomplete="given-name">
                                </div>
                                <div class="rdr-form-group">
                                    <label for="msg_email"><?php _e('Email *', 'rdr-expert-directory'); ?></label>
                                    <input type="email" id="msg_email" name="msg_email" required autocomplete="email">
                                </div>
                                <div class="rdr-form-group">
                                    <label for="msg_phone"><?php _e('Téléphone', 'rdr-expert-directory'); ?></label>
                                    <input type="tel" id="msg_phone" name="msg_phone" autocomplete="tel">
                                </div>
                            </div>
                            <div class="rdr-form-group" style="margin-bottom:16px;">
                                <label for="msg_message"><?php _e('Message *', 'rdr-expert-directory'); ?></label>
                                <textarea id="msg_message" name="msg_message" rows="4" required
                                    placeholder="<?php esc_attr_e('Votre message…', 'rdr-expert-directory'); ?>"></textarea>
                            </div>
                            <button type="submit" class="button">
                                <?php _e('Envoyer', 'rdr-expert-directory'); ?>
                            </button>
                            <p class="description" style="margin-top:8px;">
                                🔒 <?php _e('Vos données sont conservées de manière sécurisée.', 'rdr-expert-directory'); ?>
                            </p>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            </div><!-- .rdr-expert-contact-box -->
            <?php endif; ?>

            <!-- ── Retour annuaire ── -->
            <div class="rdr-expert-back" style="margin-top:24px;">
                <a href="<?= esc_url(get_post_type_archive_link('expert_profile')); ?>" class="button button-ghost button-sm">
                    ← <?php _e('Retour à l\'annuaire', 'rdr-expert-directory'); ?>
                </a>
            </div>

            <?php
            // Hook GP : pied de l'entrée (navigation entre posts, etc.)
            RDR_ED_GP_Compat::after_entry_content();
            ?>

        </article>

        <?php endif; // is_premium / is_owner ?>
        <?php endwhile; ?>

        <?php
        // Hook GP : après le contenu
        RDR_ED_GP_Compat::after_content();
        ?>

    </main><!-- #main -->
</div><!-- #primary -->

<?php
// GeneratePress : sidebar droite ou gauche selon les réglages Customizer GP
RDR_ED_GP_Compat::after_primary_content_area();

// GP affiche la sidebar ici si sidebar droite configurée
RDR_ED_GP_Compat::construct_sidebars();

// Fermer la structure GP
RDR_ED_GP_Compat::after_main_content();
?>

<?php get_footer(); ?>
