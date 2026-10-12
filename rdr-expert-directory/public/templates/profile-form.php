<?php
/**
 * Template : Formulaire profil expert (front)
 * Variables : $user_id, $post (WP_Post|null), $pending (array|null)
 */
defined('ABSPATH') || exit;

$is_new      = !$post;
$post_id     = $post ? $post->ID : 0;
// has_pending = révision en attente OU première fiche jamais validée (post_status = pending)
$is_first_submission = !$is_new && $post && $post->post_status === 'pending';
$has_pending = !$is_new && ($pending || $is_first_submission);

// Polylang : langue courante
$polylang_active = RDR_ED_Polylang::is_polylang_active();
$current_lang    = $polylang_active ? RDR_ED_Polylang::get_current_lang() : 'fr';
$languages       = $polylang_active ? RDR_ED_Polylang::get_languages() : ['fr'];

// Si Polylang actif et post existant : prendre la traduction dans la bonne langue
if ($polylang_active && $post_id && function_exists('pll_get_post')) {
    $lang_post_id = pll_get_post($post_id, $current_lang);
    if ($lang_post_id && $lang_post_id !== $post_id) {
        $post_id = $lang_post_id;
        $post    = get_post($post_id);
        $pending = RDR_ED_Versioning::get_pending($post_id);
        $has_pending = (bool) $pending;
    }
}

// Données : pending en priorité, sinon données publiées
// Si fiche jamais validée (status pending), les metas WP sont vides :
// les données sont dans META_PENDING (première soumission non encore validée)
if ($pending) {
    $d = $pending;
} elseif ($post) {
    $d = RDR_ED_Versioning::extract_current_data($post_id);
    // Si toutes les metas sont vides (fiche pending jamais validée),
    // essayer de récupérer la dernière soumission depuis les versions archivées
    $firstname = $d['_expert_firstname'] ?? '';
    if (empty($firstname) && $post->post_status === 'pending') {
        $versions = RDR_ED_Versioning::get_versions($post_id);
        if (!empty($versions)) {
            $d = array_merge($d, $versions[0]); // version la plus récente
        }
    }
} else {
    $d = [];
}
$v = fn(string $key, $default = '') => $d[$key] ?? $default;

// Limites et options
$skill_max   = (int) RDR_ED_Settings::get('skill_max', 5);
$allow_free  = (bool) RDR_ED_Settings::get('skill_allow_free_tags');

// Taxonomies disponibles
$all_skills    = get_terms(['taxonomy' => 'expert_skill',    'hide_empty' => false]);
$all_languages = get_terms(['taxonomy' => 'expert_language', 'hide_empty' => false]);
$all_countries = get_terms(['taxonomy' => 'expert_country',  'hide_empty' => false]);
$sel_skills    = array_filter((array) ($d['_skills']    ?? []));
$sel_languages = array_filter((array) ($d['_languages'] ?? []));
$sel_countries = array_filter((array) ($d['_countries'] ?? []));

// Moyens de contact actifs
$contact_methods = array_values(array_filter((array) get_post_meta($post_id, '_expert_contact_methods', true)));
if (empty($contact_methods)) $contact_methods = ['email'];
?>

<div class="rdr-profile-form-wrap" id="rdr-profile-form-wrap">

    <?php if ($polylang_active && count($languages) > 1):
        include __DIR__ . '/profile-form-i18n-section.php';
    endif; ?>

    <?php if ($has_pending): ?>
    <div class="rdr-info-box rdr-info-warning" id="rdr-pending-notice">
        <span class="rdr-info-icon">⏳</span>
        <div>
            <?php if ($is_first_submission && !$pending): ?>
                <span class="rdr-info-title"><?php _e('Profil en cours de validation', 'rdr-expert-directory'); ?></span>
                <div class="rdr-info-content">
                    <?php _e('Votre profil a ete soumis et est en cours de validation par notre equipe. Vous pouvez le modifier avant qu\'il soit valide.', 'rdr-expert-directory'); ?>
                </div>
            <?php else: ?>
                <span class="rdr-info-title"><?php _e('Revision en attente de validation', 'rdr-expert-directory'); ?></span>
                <div class="rdr-info-content">
                    <?php printf(
                        __('Modifications soumises le %s. Vous pouvez les modifier ou les annuler ci-dessous.', 'rdr-expert-directory'),
                        date_i18n(get_option('date_format') . ' H:i', strtotime($pending['submitted_at'] ?? 'now'))
                    ); ?>
                    <br>
                    <button type="button" class="button button-sm button-danger" id="rdr-cancel-pending"
                        data-post="<?= $post_id; ?>" style="margin-top:8px;">
                        <?php _e('Annuler cette revision', 'rdr-expert-directory'); ?>
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div id="rdr-form-message" class="rdr-form-message" style="display:none;"></div>

    <form id="rdr-expert-profile-form" class="rdr-expert-form" novalidate
        data-upload-nonce="<?= esc_attr(wp_create_nonce('rdr_ed_nonce')); ?>">
        <?php wp_nonce_field('rdr_ed_nonce', '_wpnonce', false); ?>
        <input type="hidden" name="action"       value="rdr_ed_save_profile">
        <input type="hidden" name="nonce"        value="<?= wp_create_nonce('rdr_ed_nonce'); ?>">
        <input type="hidden" name="_form_time"   value="<?= time(); ?>">
        <input type="hidden" name="current_lang" value="<?= esc_attr($current_lang); ?>">
        <?php if ($post_id): ?>
        <input type="hidden" name="post_id" value="<?= $post_id; ?>">
        <?php endif; ?>
        <!-- Honeypot anti-bot -->
        <div style="display:none!important;" aria-hidden="true">
            <input type="text" name="website_url" tabindex="-1" autocomplete="off" value="">
        </div>

        <!-- ══ IDENTITÉ ══ -->
        <div class="rdr-form-section">
            <h3 class="rdr-form-section-title"><span class="rdr-form-section-icon">👤</span><?php _e('Identité', 'rdr-expert-directory'); ?></h3>
            <div class="rdr-form-grid rdr-grid-2">
                <div class="rdr-form-group">
                    <label for="expert_firstname"><?php _e('Prénom *', 'rdr-expert-directory'); ?></label>
                    <input type="text" id="expert_firstname" name="expert_firstname"
                        value="<?= esc_attr($v('_expert_firstname')); ?>" required>
                </div>
                <div class="rdr-form-group">
                    <label for="expert_lastname"><?php _e('Nom *', 'rdr-expert-directory'); ?></label>
                    <input type="text" id="expert_lastname" name="expert_lastname"
                        value="<?= esc_attr($v('_expert_lastname')); ?>" required>
                </div>
                <?php /* Titre : champ TRADUIT */ ?>
                <div class="rdr-form-group rdr-translated-field">
                    <label for="expert_title">
                        <?php _e('Titre / Poste', 'rdr-expert-directory'); ?>
                        <?php if ($polylang_active): ?>
                        <span class="rdr-lang-badge"><?= esc_html(strtoupper($current_lang)); ?></span>
                        <?php endif; ?>
                    </label>
                    <input type="text" id="expert_title" name="expert_title"
                        placeholder="<?php esc_attr_e('Ex: Architecte Cloud Senior', 'rdr-expert-directory'); ?>"
                        value="<?= esc_attr($v('_expert_title')); ?>">
                </div>
                <div class="rdr-form-group">
                    <label><?php _e('Type *', 'rdr-expert-directory'); ?></label>
                    <div class="rdr-radio-group">
                        <label class="rdr-radio-label">
                            <input type="radio" name="expert_type" value="independant"
                                <?= ($v('_expert_type', 'independant') === 'independant') ? 'checked' : ''; ?>>
                            <span><?php _e('Indépendant / Freelance', 'rdr-expert-directory'); ?></span>
                        </label>
                        <label class="rdr-radio-label">
                            <input type="radio" name="expert_type" value="entreprise"
                                <?= ($v('_expert_type') === 'entreprise') ? 'checked' : ''; ?>>
                            <span><?php _e('Entreprise', 'rdr-expert-directory'); ?></span>
                        </label>
                    </div>
                </div>
                <div class="rdr-form-group" id="rdr-company-wrap"
                    style="<?= ($v('_expert_type') !== 'entreprise') ? 'display:none;' : ''; ?>">
                    <label for="expert_company"><?php _e('Nom de l\'entreprise', 'rdr-expert-directory'); ?></label>
                    <input type="text" id="expert_company" name="expert_company"
                        value="<?= esc_attr($v('_expert_company')); ?>">
                </div>
            </div>
        </div>

        <!-- ══ PHOTOS ══ -->
        <div class="rdr-form-section">
            <h3 class="rdr-form-section-title"><span class="rdr-form-section-icon">🖼️</span><?php _e('Photos', 'rdr-expert-directory'); ?></h3>
            <div class="rdr-form-grid rdr-grid-2">
                <div class="rdr-form-group">
                    <label><?php _e('Photo de profil', 'rdr-expert-directory'); ?></label>
                    <?php $photo_id = (int) $v('_profile_photo_id'); $photo_url = $photo_id ? wp_get_attachment_image_url($photo_id, 'thumbnail') : ''; ?>
                    <div class="rdr-media-upload">
                        <div class="rdr-media-preview" id="profile-photo-preview">
                            <?php if ($photo_url): ?><img src="<?= esc_url($photo_url); ?>" alt="">
                            <?php else: ?><span class="rdr-media-placeholder">👤<br><?php _e('Choisir', 'rdr-expert-directory'); ?></span><?php endif; ?>
                        </div>
                        <div class="rdr-media-btn-row">
                            <button type="button" class="button button-sm rdr-media-btn"
                                data-field="profile_photo_id"
                                data-media-type="photo">
                                <?= $photo_id ? __('Modifier', 'rdr-expert-directory') : __('Uploader', 'rdr-expert-directory'); ?>
                            </button>
                            <span class="rdr-upload-spinner"></span>
                            <?php if ($photo_id): ?>
                            <button type="button" class="button button-sm button-ghost rdr-media-remove" data-field="profile_photo_id"><?php _e('Supprimer', 'rdr-expert-directory'); ?></button>
                            <?php endif; ?>
                        </div>
                        <input type="hidden" name="profile_photo_id" id="profile_photo_id" value="<?= $photo_id; ?>">
                    </div>
                    <p class="description"><?php _e('Carré recommandé. JPG/PNG, max 2 Mo.', 'rdr-expert-directory'); ?></p>
                </div>
                <div class="rdr-form-group">
                    <label><?php _e('Bannière (optionnel)', 'rdr-expert-directory'); ?></label>
                    <?php $banner_id = (int) $v('_banner_id'); $banner_url = $banner_id ? wp_get_attachment_image_url($banner_id, 'medium') : ''; ?>
                    <div class="rdr-media-upload">
                        <div class="rdr-media-preview rdr-banner-preview" id="banner-preview">
                            <?php if ($banner_url): ?><img src="<?= esc_url($banner_url); ?>" alt="">
                            <?php else: ?><span class="rdr-media-placeholder">🖼️<br><?php _e('1200×300 recommandé', 'rdr-expert-directory'); ?></span><?php endif; ?>
                        </div>
                        <div class="rdr-media-btn-row">
                            <button type="button" class="button button-sm rdr-media-btn"
                                data-field="banner_id"
                                data-media-type="banner">
                                <?= $banner_id ? __('Modifier', 'rdr-expert-directory') : __('Uploader', 'rdr-expert-directory'); ?>
                            </button>
                            <span class="rdr-upload-spinner"></span>
                            <?php if ($banner_id): ?>
                            <button type="button" class="button button-sm button-ghost rdr-media-remove" data-field="banner_id"><?php _e('Supprimer', 'rdr-expert-directory'); ?></button>
                            <?php endif; ?>
                        </div>
                        <input type="hidden" name="banner_id" id="banner_id" value="<?= $banner_id; ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- ══ COMPÉTENCES ══ -->
        <div class="rdr-form-section">
            <h3 class="rdr-form-section-title"><span class="rdr-form-section-icon">⚡</span><?php _e('Compétences & Spécialités', 'rdr-expert-directory'); ?></h3>
            <div class="rdr-form-group">
                <label>
                    <?php printf(_n('Sélectionnez vos compétences * (max %d)', 'Sélectionnez vos compétences * (max %d)', $skill_max, 'rdr-expert-directory'), $skill_max); ?>
                </label>
                <div class="rdr-tag-cloud" id="rdr-skills-cloud" data-max="<?= $skill_max; ?>">
                    <?php foreach ((array) $all_skills as $term): ?>
                    <label class="rdr-tag-check <?= in_array($term->term_id, $sel_skills) ? 'is-active' : ''; ?>">
                        <input type="checkbox" name="expert_skills[]" value="<?= $term->term_id; ?>"
                            <?= in_array($term->term_id, $sel_skills) ? 'checked' : ''; ?>>
                        <span><?= esc_html($term->name); ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div id="rdr-skill-count" class="rdr-skill-counter">
                    <span id="rdr-skill-count-num"><?= count(array_intersect($sel_skills, wp_list_pluck((array)$all_skills, 'term_id'))); ?></span> / <?= $skill_max; ?>
                </div>
            </div>

            <?php if ($allow_free): ?>
            <!-- Proposer une nouvelle compétence -->
            <div class="rdr-form-group rdr-free-skill-group">
                <label><?php _e('Proposer une compétence non listée', 'rdr-expert-directory'); ?></label>
                <div class="rdr-free-skill-input-row">
                    <input type="text" id="rdr-free-skill-input"
                        placeholder="<?php esc_attr_e('Ex: Ansible, Terraform…', 'rdr-expert-directory'); ?>"
                        maxlength="50" autocomplete="off">
                    <button type="button" class="button button-sm" id="rdr-free-skill-btn">
                        <?php _e('Proposer', 'rdr-expert-directory'); ?>
                    </button>
                </div>
                <div id="rdr-free-skill-result" style="margin-top:6px;font-size:13px;"></div>
                <p class="description">
                    <?php
                    $review = (bool) RDR_ED_Settings::get('skill_free_tags_review');
                    echo $review
                        ? __('Les nouvelles compétences sont soumises à validation avant d\'apparaître dans votre fiche.', 'rdr-expert-directory')
                        : __('Les nouvelles compétences sont ajoutées immédiatement.', 'rdr-expert-directory');
                    ?>
                </p>
                <!-- Tags libres en attente (hidden inputs, gérés par JS) -->
                <div id="rdr-free-skills-pending"></div>
            </div>
            <?php endif; ?>

            <div class="rdr-form-grid rdr-grid-2" style="margin-top:16px;">
                <div class="rdr-form-group">
                    <label><?php _e('Langues parlées', 'rdr-expert-directory'); ?></label>
                    <div class="rdr-tag-cloud rdr-tag-cloud-sm">
                        <?php foreach ((array) $all_languages as $term): ?>
                        <label class="rdr-tag-check <?= in_array($term->term_id, $sel_languages) ? 'is-active' : ''; ?>">
                            <input type="checkbox" name="expert_languages[]" value="<?= $term->term_id; ?>"
                                <?= in_array($term->term_id, $sel_languages) ? 'checked' : ''; ?>>
                            <span><?= esc_html($term->name); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="rdr-form-group">
                    <label for="expert_countries"><?php _e('Pays / Zone géographique', 'rdr-expert-directory'); ?></label>
                    <select name="expert_countries[]" id="expert_countries" multiple class="rdr-select-multi">
                        <?php foreach ((array) $all_countries as $term): ?>
                        <option value="<?= $term->term_id; ?>" <?= in_array($term->term_id, $sel_countries) ? 'selected' : ''; ?>>
                            <?= esc_html($term->name); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php _e('Ctrl+clic pour plusieurs.', 'rdr-expert-directory'); ?></p>
                </div>
            </div>
        </div>

        <!-- ══ PRÉSENTATION (champ TRADUIT) ══ -->
        <div class="rdr-form-section">
            <h3 class="rdr-form-section-title">
                <span class="rdr-form-section-icon">📝</span>
                <?php _e('Présentation & Expérience', 'rdr-expert-directory'); ?>
                <?php if ($polylang_active): ?><span class="rdr-lang-badge rdr-lang-badge-lg"><?= esc_html(strtoupper($current_lang)); ?></span><?php endif; ?>
            </h3>
            <div class="rdr-form-group">
                <label for="expert_description"><?php _e('Description (texte enrichi)', 'rdr-expert-directory'); ?></label>
                <?php wp_editor(
                    wp_kses_post($d['post_content'] ?? ''),
                    'expert_description',
                    ['media_buttons' => false, 'textarea_name' => 'expert_description', 'textarea_rows' => 10,
                     'teeny' => false, 'tinymce' => ['toolbar1' => 'bold,italic,underline,strikethrough,bullist,numlist,undo,redo', 'toolbar2' => '', 'plugins' => 'lists,paste,wordpress'], 'quicktags' => true]
                ); ?>
            </div>
            <div class="rdr-form-group">
                <label>
                    <?php _e('Références clients / Réalisations', 'rdr-expert-directory'); ?>
                    <?php if ($polylang_active): ?><span class="rdr-lang-badge"><?= esc_html(strtoupper($current_lang)); ?></span><?php endif; ?>
                </label>
                <?php wp_editor(
                    wp_kses_post($v('_expert_references')),
                    'expert_references',
                    ['media_buttons' => false, 'textarea_name' => 'expert_references', 'textarea_rows' => 8,
                     'teeny' => false,
                     'tinymce' => ['toolbar1' => 'bold,italic,underline,strikethrough,bullist,numlist,undo,redo', 'toolbar2' => '', 'plugins' => 'lists,paste,wordpress'],
                     'quicktags' => true]
                ); ?>
            </div>
        </div>

        <!-- ══ MOYENS DE CONTACT ══ -->
        <div class="rdr-form-section">
            <h3 class="rdr-form-section-title"><span class="rdr-form-section-icon">📬</span><?php _e('Moyens de contact', 'rdr-expert-directory'); ?></h3>
            <p class="description" style="margin-bottom:16px;">
                <?php _e('Choisissez comment les visiteurs peuvent vous contacter. Les coordonnées ne sont jamais affichées en clair.', 'rdr-expert-directory'); ?>
            </p>

            <!-- Sélecteur des méthodes de contact -->
            <div class="rdr-contact-methods-selector">
                <?php
                $available_methods = [
                    'email'    => ['icon' => '📧', 'label' => __('Email', 'rdr-expert-directory'),     'desc' => __('Les visiteurs peuvent voir votre email ou vous envoyer un formulaire de contact', 'rdr-expert-directory')],
                    'phone'    => ['icon' => '📞', 'label' => __('Téléphone', 'rdr-expert-directory'), 'desc' => __('Les visiteurs peuvent révéler votre numéro de téléphone', 'rdr-expert-directory')],
                    'linkedin' => ['icon' => '💼', 'label' => 'LinkedIn',                              'desc' => __('Les visiteurs peuvent accéder directement à votre profil LinkedIn', 'rdr-expert-directory')],
                ];
                foreach ($available_methods as $method_key => $method): ?>
                <label class="rdr-contact-method-card <?= in_array($method_key, $contact_methods) ? 'is-active' : ''; ?>">
                    <input type="checkbox" name="expert_contact_methods[]"
                        value="<?= $method_key; ?>"
                        <?= in_array($method_key, $contact_methods) ? 'checked' : ''; ?>
                        class="rdr-contact-method-check">
                    <div class="rdr-contact-method-inner">
                        <span class="rdr-contact-method-icon"><?= $method['icon']; ?></span>
                        <div>
                            <strong class="rdr-contact-method-label"><?= $method['label']; ?></strong>
                            <span class="rdr-contact-method-desc"><?= $method['desc']; ?></span>
                        </div>
                        <span class="rdr-contact-method-check-icon">✓</span>
                    </div>
                </label>
                <?php endforeach; ?>
            </div>

            <!-- Champs affichés dynamiquement selon les méthodes sélectionnées -->
            <div id="rdr-contact-fields" style="margin-top:20px;">

                <div class="rdr-contact-field-group" id="rdr-email-fields"
                    style="<?= !in_array('email', $contact_methods) ? 'display:none;' : ''; ?>">
                    <div class="rdr-form-grid rdr-grid-2">
                        <div class="rdr-form-group">
                            <label for="expert_email"><?php _e('Email de contact *', 'rdr-expert-directory'); ?></label>
                            <input type="email" id="expert_email" name="expert_email"
                                placeholder="contact@example.com"
                                value="<?= esc_attr($v('_expert_email')); ?>">
                        </div>
                        <div class="rdr-form-group" style="justify-content:flex-end;padding-bottom:4px;">
                            <label class="rdr-checkbox-label">
                                <input type="checkbox" name="expert_email_public" value="1"
                                    <?= $v('_expert_email_public') === '1' ? 'checked' : ''; ?>>
                                <span><?php _e('Permettre la révélation directe de l\'email', 'rdr-expert-directory'); ?></span>
                            </label>
                            <?php if ((bool) RDR_ED_Settings::get('contact_form_enabled', true)): ?>
                            <p class="description"><?php _e('Si non coché : formulaire de contact uniquement.', 'rdr-expert-directory'); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="rdr-contact-field-group" id="rdr-phone-fields"
                    style="<?= !in_array('phone', $contact_methods) ? 'display:none;' : ''; ?>">
                    <div class="rdr-form-grid rdr-grid-2">
                        <div class="rdr-form-group">
                            <label for="expert_phone"><?php _e('Téléphone *', 'rdr-expert-directory'); ?></label>
                            <input type="tel" id="expert_phone" name="expert_phone"
                                placeholder="+33 6 XX XX XX XX"
                                value="<?= esc_attr($v('_expert_phone')); ?>">
                        </div>
                        <div class="rdr-form-group" style="justify-content:flex-end;padding-bottom:4px;">
                            <label class="rdr-checkbox-label">
                                <input type="checkbox" name="expert_phone_public" value="1"
                                    <?= $v('_expert_phone_public') === '1' ? 'checked' : ''; ?>>
                                <span><?php _e('Permettre la révélation du numéro', 'rdr-expert-directory'); ?></span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="rdr-contact-field-group" id="rdr-linkedin-fields"
                    style="<?= !in_array('linkedin', $contact_methods) ? 'display:none;' : ''; ?>">
                    <div class="rdr-form-group">
                        <label for="expert_linkedin_profil_contact"><?php _e('Profil LinkedIn *', 'rdr-expert-directory'); ?></label>
                        <input type="url" id="expert_linkedin_profil_contact" name="expert_linkedin_profil"
                            placeholder="https://linkedin.com/in/votre-profil"
                            value="<?= esc_url($v('_expert_linkedin_profil')); ?>">
                        <p class="description"><?php _e('Les visiteurs seront redirigés directement vers votre profil LinkedIn.', 'rdr-expert-directory'); ?></p>
                    </div>
                </div>

            </div>

            <?php if ((bool) RDR_ED_Settings::get('contact_form_enabled', true)) : ?>
            <!-- Option formulaire de contact -->
            <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--rdr-border);">
                <label class="rdr-checkbox-label">
                    <input type="checkbox" name="expert_form_enabled" value="1"
                        <?= ($v('_expert_form_enabled', '1') !== '0') ? 'checked' : ''; ?>>
                    <span><?php _e('Activer le formulaire de contact sur ma fiche', 'rdr-expert-directory'); ?></span>
                </label>
                <p class="description" style="margin-left:24px;">
                    <?php _e('Si désactivé, seule la révélation d\'email ou le lien LinkedIn seront disponibles.', 'rdr-expert-directory'); ?>
                </p>
            </div>
            <?php endif; ?>
        </div>

        <!-- ══ LIENS ══ -->
        <div class="rdr-form-section">
            <h3 class="rdr-form-section-title"><span class="rdr-form-section-icon">🔗</span><?php _e('Liens & Certifications', 'rdr-expert-directory'); ?></h3>
            <div class="rdr-form-grid rdr-grid-2">
                <div class="rdr-form-group">
                    <label for="expert_linkedin_page">💼 <?php _e('Page LinkedIn entreprise', 'rdr-expert-directory'); ?></label>
                    <input type="url" id="expert_linkedin_page" name="expert_linkedin_page"
                        placeholder="https://linkedin.com/company/ma-societe"
                        value="<?= esc_url($v('_expert_linkedin_page')); ?>">
                </div>
                <div class="rdr-form-group">
                    <label for="expert_credly">🏅 Credly / Badges</label>
                    <input type="url" id="expert_credly" name="expert_credly"
                        placeholder="https://www.credly.com/users/votre-profil"
                        value="<?= esc_url($v('_expert_credly')); ?>">
                </div>
                <div class="rdr-form-group">
                    <label for="expert_website">🌐 <?php _e('Site web', 'rdr-expert-directory'); ?></label>
                    <input type="url" id="expert_website" name="expert_website"
                        placeholder="https://www.monsite.com"
                        value="<?= esc_url($v('_expert_website')); ?>">
                </div>
            </div>
        </div>

        <!-- ══ RÉFÉRENCEMENT ══ -->
        <div class="rdr-form-section">
            <h3 class="rdr-form-section-title"><span class="rdr-form-section-icon">🔍</span><?php _e('Référencement', 'rdr-expert-directory'); ?></h3>
            <label class="rdr-checkbox-label">
                <input type="checkbox" name="expert_noindex" value="1"
                    <?= $v('_expert_noindex') === '1' ? 'checked' : ''; ?>>
                <span><?php _e('Ne pas indexer ma fiche dans les moteurs de recherche', 'rdr-expert-directory'); ?></span>
            </label>
        </div>

        <!-- ══ ACTIONS ══ -->
        <div class="rdr-form-actions">
            <button type="submit" class="button button-lg" id="rdr-submit-btn">
                <span class="rdr-submit-text">
                    <?php echo $is_new ? __('Soumettre ma fiche', 'rdr-expert-directory') : __('Soumettre les modifications', 'rdr-expert-directory'); ?>
                </span>
                <span class="rdr-submit-spinner" style="display:none;">
                    <svg class="rdr-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
                    </svg>
                    <?php _e('Envoi…', 'rdr-expert-directory'); ?>
                </span>
            </button>
            <p class="description rdr-form-legal">
                🔒 <?php _e('Vos données restent votre propriété. Elles ne sont jamais revendues.', 'rdr-expert-directory'); ?>
            </p>
        </div>
    </form>


        <!-- ── Zone de danger : suppression du profil ── -->
    <?php if (!$is_new) : ?>
    <div class="rdr-form-section rdr-danger-zone" style="margin-top:32px;padding:20px;border:1px solid rgba(229,62,62,0.4);border-radius:10px;background:rgba(229,62,62,0.05);">
        <h3 style="color:#e53e3e;margin:0 0 8px;font-size:15px;">⚠️ <?php _e('Zone de danger', 'rdr-expert-directory'); ?></h3>
        <p class="description" style="margin-bottom:12px;">
            <?php _e('La suppression de votre profil est immédiate et irréversible. Toutes vos données seront effacées : fiche, photos, messages reçus, historique.', 'rdr-expert-directory'); ?>
        </p>
        <button type="button" id="rdr-delete-profile-btn" class="button button-danger">
            🗑️ <?php _e('Supprimer définitivement mon profil', 'rdr-expert-directory'); ?>
        </button>
    </div>

    <!-- Modal confirmation suppression -->
    <div id="rdr-delete-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:99999;align-items:center;justify-content:center;">
        <div style="background:var(--rdr-bg-surface);border:1px solid rgba(229,62,62,0.5);border-radius:12px;padding:32px;max-width:480px;width:90%;margin:auto;">
            <h3 style="color:#e53e3e;margin:0 0 16px;">⚠️ <?php _e('Suppression définitive', 'rdr-expert-directory'); ?></h3>
            <p><?php _e('Cette action supprimera immédiatement et sans possibilité de restauration :', 'rdr-expert-directory'); ?></p>
            <ul style="margin:8px 0 16px;padding-left:20px;color:var(--rdr-text-secondary);">
                <li><?php _e('Votre fiche expert et toutes ses données', 'rdr-expert-directory'); ?></li>
                <li><?php _e('Vos photos et bannières', 'rdr-expert-directory'); ?></li>
                <li><?php _e('Tous les messages reçus', 'rdr-expert-directory'); ?></li>
                <li><?php _e('Tout votre historique de modifications', 'rdr-expert-directory'); ?></li>
            </ul>
            <p style="font-weight:600;margin-bottom:8px;">
                <?php printf(__('Pour confirmer, tapez exactement : %s', 'rdr-expert-directory'), '<code>DeleteMyProfil</code>'); ?>
            </p>
            <input type="text" id="rdr-delete-confirm-input"
                placeholder="DeleteMyProfil" autocomplete="off"
                style="width:100%;padding:8px 12px;border:1px solid var(--rdr-border-strong);border-radius:6px;background:var(--rdr-bg-card);color:var(--rdr-text-primary);margin-bottom:12px;box-sizing:border-box;">
            <div id="rdr-delete-result" class="rdr-form-message" style="display:none;margin-bottom:12px;"></div>
            <div style="display:flex;gap:10px;">
                <button type="button" id="rdr-delete-confirm" class="button button-danger">
                    🗑️ <?php _e('Confirmer la suppression', 'rdr-expert-directory'); ?>
                </button>
                <button type="button" id="rdr-delete-cancel" class="button button-ghost">
                    <?php _e('Annuler', 'rdr-expert-directory'); ?>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>
