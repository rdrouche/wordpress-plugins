<?php
/**
 * Internationalisation — Polylang + pll_register_string
 *
 * Toutes les chaînes front visibles sont centralisées ici.
 * Dans Polylang > Traductions de chaînes, elles apparaissent
 * sous le groupe "RDR-IT Courses".
 *
 * Usage dans le plugin : rdrc__('Commencer le cours')
 */

/* ── Enregistrer les chaînes pour Polylang ─────────────── */
add_action( 'init', function() {
    if ( ! function_exists( 'pll_register_string' ) ) return;

    $group   = 'RDR-IT Courses';
    $strings = rdrc_i18n_strings();

    foreach ( $strings as $key => $default ) {
        pll_register_string( $key, $default, $group );
    }
}, 1 );

/* ── Dictionnaire des chaînes traduisibles ─────────────── */
function rdrc_i18n_strings() {
    return [
        // Navigation leçon
        'btn_start_course'    => 'Commencer le cours',
        'btn_prev_lesson'     => 'Précédente',
        'btn_next_lesson'     => 'Suivante',
        'btn_back_to_course'  => 'Retour au cours',
        'btn_end_course'      => 'Fin du cours',
        'btn_back_to_summary' => 'Retour au sommaire',
        'btn_summary'         => 'Sommaire',
        'label_courses'       => 'Cours',
        // Badges
        'badge_free'          => 'Gratuit',
        'badge_article'       => 'Article',
        'badge_video'         => 'Vidéo',
        'badge_tp'            => 'TP',
        'badge_quiz'          => 'Quiz',
        // Niveaux
        'level_debutant'      => 'Débutant',
        'level_intermediaire' => 'Intermédiaire',
        'level_avance'        => 'Avancé',
        // Progression
        'progress_label'      => 'Progression',
        'progress_db'         => '☁️ Synchronisé avec votre compte',
        'progress_local'      => '💾 Sauvegardé localement',
        // TOC
        'toc_title_prefix'    => 'Sommaire :',
        // Syllabus
        'syllabus_start'      => '🚀 Commencer le cours',
        'syllabus_lessons'    => 'leçon',
        'syllabus_lessons_pl' => 'leçons',
        'syllabus_sections'   => 'section',
        'syllabus_sections_pl'=> 'sections',
        'syllabus_free'       => 'Gratuit',
        // Barre de suivi [rdrc_progress_bar]
        'pbar_mark'           => 'Marquer comme lu',
        'pbar_marked'         => 'Marque comme lu',
        'pbar_module'         => 'Module',
        'pbar_course'         => 'Parcours',
        'pbar_start'          => 'Debut du parcours',
        'pbar_end'            => 'Fin du parcours',
        'pbar_reset'          => 'Reinitialiser ma progression',
        // Vue d'ensemble [rdrc_my_courses]
        'mycourses_empty'       => 'Aucun parcours disponible pour le moment.',
        'mycourses_none_filter' => 'Aucun parcours dans cette categorie.',
        'mycourses_started'     => 'parcours commences',
        'mycourses_completed'   => 'parcours termines',
        'mycourses_lessons_done'=> 'lecons terminees',
        'mycourses_global'      => 'progression globale',
        'mycourses_f_all'       => 'Tous',
        'mycourses_f_progress'  => 'En cours',
        'mycourses_f_done'      => 'Termines',
        'mycourses_f_todo'      => 'Non commences',
        'mycourses_cta_start'   => 'Commencer',
        'mycourses_cta_resume'  => 'Reprendre',
        'mycourses_cta_review'  => 'Revoir',
    ];
}

/* ── Fonction helper ────────────────────────────────────── */
function rdrc__( $key ) {
    $strings = rdrc_i18n_strings();
    $default = $strings[ $key ] ?? $key;

    // Polylang actif → traduction
    if ( function_exists( 'pll__' ) ) {
        return pll__( $default );
    }

    // Fallback standard WP (si .po/.mo présents)
    return __( $default, 'rdr-courses' );
}

function rdrc_e( $key ) {
    echo esc_html( rdrc__( $key ) );
}
