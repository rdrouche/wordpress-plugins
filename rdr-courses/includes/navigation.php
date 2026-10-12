<?php
/**
 * Navigation, TOC, Schema.org, widgets
 */

/* ── Helpers structure ───────────────────────────────────── */

/**
 * Une lecon est-elle consultable par l'utilisateur courant ?
 * 'publish' => tout le monde.
 * 'private' => uniquement ceux qui ont le droit de lire ce contenu.
 * Les autres statuts (draft, pending, future) restent masques en front.
 */
function rdrc_lesson_is_viewable( $post ) {
    if ( ! $post ) return false;
    if ( is_numeric( $post ) ) $post = get_post( (int) $post );
    if ( ! $post ) return false;

    if ( $post->post_status === 'publish' ) return true;
    if ( $post->post_status === 'private' ) return current_user_can( 'read_post', $post->ID );

    return false;
}

function rdrc_get_course_structure( $course_id ) {
    $structure = get_post_meta( $course_id, '_rdrc_structure', true );
    if ( ! is_array($structure) ) return [];
    foreach ($structure as &$item) {
        if ( ($item['type']??'') === 'lesson' && !empty($item['lesson_id']) ) {
            $l = get_post( (int)$item['lesson_id'] );
            if ($l) $item['post'] = $l;
        }
    }
    unset($item);
    return $structure;
}

function rdrc_get_lessons_list( $course_id, $published_only = true ) {
    $lessons = [];
    foreach ( rdrc_get_course_structure($course_id) as $item ) {
        if ( ($item['type']??'') !== 'lesson' || !isset($item['post']) ) continue;
        if ( $published_only && ! rdrc_lesson_is_viewable( $item['post'] ) ) continue;
        $lessons[] = $item;
    }
    return $lessons;
}

function rdrc_get_adjacent_lessons( $lesson_id, $course_id ) {
    $lessons = rdrc_get_lessons_list($course_id);
    $found   = -1;
    foreach ($lessons as $i => $item) {
        if ( (int)$item['lesson_id'] === (int)$lesson_id ) { $found = $i; break; }
    }
    return [
        'prev' => $found > 0                     ? $lessons[$found - 1] : null,
        'next' => $found < count($lessons) - 1   ? $lessons[$found + 1] : null,
    ];
}

function rdrc_get_lesson_section( $lesson_id, $course_id ) {
    $current = null;
    foreach ( rdrc_get_course_structure($course_id) as $item ) {
        if ( ($item['type']??'') === 'section' ) $current = $item['title'] ?? '';
        if ( ($item['type']??'') === 'lesson' && (int)($item['lesson_id']??0) === (int)$lesson_id ) return $current;
    }
    return null;
}

function rdrc_lesson_type_icon( $type ) {
    return ['article'=>'📄','video'=>'🎥','tp'=>'🔧','quiz'=>'❓'][$type] ?? '📄';
}

/* ── TOC — sommaire du cours ─────────────────────────────
 * Structure plate : pas de <ol> imbriqués, sections en
 * simples séparateurs. Evite tout problème d'indentation.
 * ──────────────────────────────────────────────────────── */
function rdrc_render_toc( $course_id, $active_lesson_id = 0 ) {
    $structure = rdrc_get_course_structure($course_id);
    if ( empty($structure) ) return '<p class="rdrc-toc-empty">Ce cours n\'a pas encore de contenu.</p>';

    $html    = '<ul class="rdrc-toc-list">';
    $num     = 0;

    foreach ( $structure as $item ) {

        if ( ($item['type']??'') === 'section' ) {
            $html .= '<li class="rdrc-toc-section-title">'
                   . '<span>' . esc_html($item['title'] ?? '') . '</span>'
                   . '</li>';
            continue;
        }

        if ( ($item['type']??'') !== 'lesson' || !isset($item['post']) ) continue;
        if ( ! rdrc_lesson_is_viewable( $item['post'] ) && (int)$item['lesson_id'] !== (int)$active_lesson_id ) continue;

        $num++;
        $l        = $item['post'];
        $active   = (int)$item['lesson_id'] === (int)$active_lesson_id;
        $icon     = rdrc_lesson_type_icon( get_post_meta($l->ID, '_rdrc_lesson_type', true) );
        $dur      = get_post_meta($l->ID, '_rdrc_lesson_duration', true);
        $free     = get_post_meta($l->ID, '_rdrc_lesson_free', true);

        $html .= '<li class="rdrc-toc-lesson' . ($active ? ' rdrc-toc-active' : '') . '" data-lesson-id="' . (int)$l->ID . '">'
               . '<a href="' . esc_url(get_permalink($l->ID)) . '">'
               . '<span class="rdrc-toc-num">' . $num . '</span>'
               . '<span class="rdrc-toc-icon">' . $icon . '</span>'
               . '<span class="rdrc-toc-name">' . esc_html($l->post_title) . '</span>'
               . ( $dur  ? '<span class="rdrc-toc-dur">'  . esc_html($dur) . '</span>' : '' )
               . ( $free === '1' ? '<span class="rdrc-toc-free">Gratuit</span>' : '' )
               . ( $active ? '<span class="rdrc-toc-here">← ici</span>' : '' )
               . '</a></li>';
    }

    $html .= '</ul>';
    return $html;
}

/* ── Schema.org ──────────────────────────────────────────── */
add_action( 'wp_head', function() {

    // Course schema sur la page du cours
    if ( is_singular('rdrc_course') ) {
        $course_id  = get_the_ID();
        $structure  = rdrc_get_course_structure($course_id);
        $lessons    = array_filter($structure, fn($i) => ($i['type']??'') === 'lesson' && isset($i['post']));
        $nb         = count($lessons);
        $duration   = get_post_meta($course_id, '_rdrc_duration', true);
        $level      = get_post_meta($course_id, '_rdrc_level', true);
        $free       = get_post_meta($course_id, '_rdrc_free', true);
        $level_map  = ['debutant'=>'Beginner','intermediaire'=>'Intermediate','avance'=>'Advanced'];

        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => 'Course',
            'name'        => get_the_title($course_id),
            'description' => get_the_excerpt($course_id) ?: wp_trim_words(get_post_field('post_content',$course_id), 40),
            'url'         => get_permalink($course_id),
            'provider'    => [
                '@type' => 'Organization',
                'name'  => get_bloginfo('name'),
                'url'   => home_url(),
            ],
            'inLanguage'   => get_bloginfo('language'),
            'isAccessibleForFree' => ($free === '1'),
        ];

        if ($duration) $schema['timeRequired'] = $duration;
        if ($level && isset($level_map[$level])) $schema['educationalLevel'] = $level_map[$level];
        if (has_post_thumbnail($course_id)) $schema['image'] = get_the_post_thumbnail_url($course_id, 'large');

        if ($nb > 0) {
            $schema['hasCourseInstance'] = [[
                '@type'       => 'CourseInstance',
                'courseMode'  => 'online',
                'courseWorkload' => $duration ?: '',
            ]];
            $schema['numberOfCredits'] = $nb;
        }

        echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    }

    // LearningResource schema sur la page leçon
    if ( is_singular('rdrc_lesson') ) {
        $lesson_id = get_the_ID();
        $course_id = get_post_meta($lesson_id, '_rdrc_parent_course', true);
        $type      = get_post_meta($lesson_id, '_rdrc_lesson_type', true) ?: 'article';
        $duration  = get_post_meta($lesson_id, '_rdrc_lesson_duration', true);
        $free      = get_post_meta($lesson_id, '_rdrc_lesson_free', true);

        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => 'LearningResource',
            'name'        => get_the_title($lesson_id),
            'url'         => get_permalink($lesson_id),
            'inLanguage'  => get_bloginfo('language'),
            'isAccessibleForFree' => ($free === '1'),
            'learningResourceType' => ucfirst($type),
        ];

        if ($duration) $schema['timeRequired'] = $duration;
        if ($course_id) {
            $schema['isPartOf'] = [
                '@type' => 'Course',
                'name'  => get_the_title($course_id),
                'url'   => get_permalink($course_id),
            ];
        }

        // BreadcrumbList
        $crumbs = [
            ['@type'=>'ListItem','position'=>1,'name'=>'Accueil','item'=>home_url()],
            ['@type'=>'ListItem','position'=>2,'name'=>'Cours','item'=>get_post_type_archive_link('rdrc_course')],
        ];
        if ($course_id) {
            $crumbs[] = ['@type'=>'ListItem','position'=>3,'name'=>get_the_title($course_id),'item'=>get_permalink($course_id)];
            $section  = rdrc_get_lesson_section($lesson_id, $course_id);
            if ($section) $crumbs[] = ['@type'=>'ListItem','position'=>4,'name'=>$section,'item'=>get_permalink($lesson_id)];
        }

        $breadcrumb = ['@context'=>'https://schema.org','@type'=>'BreadcrumbList','itemListElement'=>$crumbs];

        echo '<script type="application/ld+json">' . wp_json_encode($schema,      JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
        echo '<script type="application/ld+json">' . wp_json_encode($breadcrumb,  JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    }
});

/* Zones widgets déplacées dans widgets.php */

/* ── Shortcode : sommaire de section (ou cours complet) ──
 * [rdrc_section_toc] — sur une page leçon, affiche les leçons
 *   de la section courante. Si pas de section, affiche le cours.
 * [rdrc_section_toc course_id="123"] — force un cours.
 * ────────────────────────────────────────────────────────── */
add_shortcode( 'rdrc_section_toc', function( $atts ) {
    $atts = shortcode_atts(['course_id' => 0, 'show_title' => 1], $atts);

    $course_id = (int)$atts['course_id'];
    $lesson_id = get_the_ID();

    if ( !$course_id ) {
        $course_id = get_post_meta($lesson_id, '_rdrc_parent_course', true);
    }
    if ( !$course_id ) return '';

    $structure    = rdrc_get_course_structure($course_id);
    $cur_section  = rdrc_get_lesson_section($lesson_id, $course_id);

    // Collecter les leçons de la section courante
    $section_lessons = [];
    $in_section      = false;
    $section_title   = '';

    if ( $cur_section !== null ) {
        foreach ( $structure as $item ) {
            if ( ($item['type']??'') === 'section' ) {
                if ( ($item['title']??'') === $cur_section ) {
                    $in_section    = true;
                    $section_title = $item['title'];
                } elseif ( $in_section ) {
                    break; // sortie de la section
                }
                continue;
            }
            if ( $in_section && ($item['type']??'') === 'lesson' && isset($item['post']) ) {
                $section_lessons[] = $item;
            }
        }
    }

    // Si pas de section ou section vide : afficher tout le cours
    if ( empty($section_lessons) ) {
        $title = intval($atts['show_title']) ? get_the_title($course_id) : '';
        $toc   = rdrc_render_toc($course_id, $lesson_id);
        $out   = '<div class="rdrc-section-toc rdrc-full-toc">';
        if ($title) $out .= '<h4 class="rdrc-toc-heading">' . esc_html($title) . '</h4>';
        $out .= $toc . '</div>';
        return $out;
    }

    // Afficher les leçons de la section
    $num = 0;
    // Calculer le numéro de départ de cette section dans le cours complet
    foreach ( rdrc_get_lessons_list($course_id) as $item ) {
        $num++;
        if ( $item['lesson_id'] == $section_lessons[0]['lesson_id'] ) break;
    }
    $start_num = $num - 1; // on repart de là

    $out = '<div class="rdrc-section-toc">';
    if ( intval($atts['show_title']) && $section_title ) {
        $out .= '<h4 class="rdrc-toc-heading">📁 ' . esc_html($section_title) . '</h4>';
    }
    $out .= '<ul class="rdrc-toc-list">';
    $n = $start_num;
    foreach ( $section_lessons as $item ) {
        if ( !isset($item['post']) || ! rdrc_lesson_is_viewable( $item['post'] ) ) continue;
        $n++;
        $l      = $item['post'];
        $active = (int)$item['lesson_id'] === (int)$lesson_id;
        $icon   = rdrc_lesson_type_icon(get_post_meta($l->ID,'_rdrc_lesson_type',true));
        $dur    = get_post_meta($l->ID,'_rdrc_lesson_duration',true);
        $out .= '<li class="rdrc-toc-lesson' . ($active?' rdrc-toc-active':'') . '" data-lesson-id="' . (int)$l->ID . '">'
              . '<a href="' . esc_url(get_permalink($l->ID)) . '">'
              . '<span class="rdrc-toc-num">' . $n . '</span>'
              . '<span class="rdrc-toc-icon">' . $icon . '</span>'
              . '<span class="rdrc-toc-name">' . esc_html($l->post_title) . '</span>'
              . ($dur ? '<span class="rdrc-toc-dur">' . esc_html($dur) . '</span>' : '')
              . ($active ? '<span class="rdrc-toc-here">← ici</span>' : '')
              . '</a></li>';
    }
    $out .= '</ul></div>';
    return $out;
});
