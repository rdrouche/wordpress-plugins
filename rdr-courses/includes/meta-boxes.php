<?php
/**
 * Meta-boxes simples : paramètres cours + infos leçon
 * La structure est gérée sur la page dédiée /wp-admin/admin.php?page=rdrc-builder
 */

add_action( 'add_meta_boxes', function() {
    add_meta_box( 'rdrc_course_settings', '⚙️ Paramètres du cours', 'rdrc_mb_course_settings', 'rdrc_course', 'side', 'high' );
    add_meta_box( 'rdrc_course_structure_link', '📋 Structure du cours', 'rdrc_mb_structure_link', 'rdrc_course', 'side', 'default' );
    add_meta_box( 'rdrc_lesson_info', '📖 Infos leçon', 'rdrc_mb_lesson_info', 'rdrc_lesson', 'side', 'high' );
});

function rdrc_mb_structure_link( $post ) {
    $url = admin_url( 'admin.php?page=rdrc-builder&course_id=' . $post->ID );
    $structure = get_post_meta( $post->ID, '_rdrc_structure', true ) ?: [];
    $nb = count( array_filter( $structure, fn($i) => ($i['type']??'') === 'lesson' ) );
    $ns = count( array_filter( $structure, fn($i) => ($i['type']??'') === 'section' ) );
    ?>
    <p style="margin:0 0 10px;font-size:13px;color:#555;">
        <?php if ($nb || $ns): ?>
            📖 <strong><?php echo $nb; ?></strong> leçon<?php echo $nb!=1?'s':''; ?>,
            📁 <strong><?php echo $ns; ?></strong> section<?php echo $ns!=1?'s':''; ?>
        <?php else: ?>
            Aucune structure définie.
        <?php endif; ?>
    </p>
    <a href="<?php echo esc_url($url); ?>" class="button button-primary" style="width:100%;text-align:center;box-sizing:border-box;">
        📋 Gérer la structure du cours
    </a>
    <?php
}

function rdrc_mb_course_settings( $post ) {
    wp_nonce_field( 'rdrc_save_settings', 'rdrc_settings_nonce' );
    $duration    = get_post_meta( $post->ID, '_rdrc_duration',    true );
    $level       = get_post_meta( $post->ID, '_rdrc_level',       true );
    $free        = get_post_meta( $post->ID, '_rdrc_free',        true );
    $badge_color = get_post_meta( $post->ID, '_rdrc_badge_color', true ) ?: '#3b82f6';
    $icon        = get_post_meta( $post->ID, '_rdrc_icon',        true ) ?: '📚';
    ?>
    <table class="form-table" style="margin:0;">
        <tr><th style="padding:6px 0;width:80px;"><label for="rdrc_icon">Icône</label></th>
            <td style="padding:6px 0;"><input type="text" id="rdrc_icon" name="rdrc_icon" value="<?php echo esc_attr($icon); ?>" style="width:60px;font-size:20px;text-align:center;" maxlength="4"></td></tr>
        <tr><th style="padding:6px 0;"><label for="rdrc_duration">Durée</label></th>
            <td style="padding:6px 0;"><input type="text" id="rdrc_duration" name="rdrc_duration" value="<?php echo esc_attr($duration); ?>" placeholder="ex: 3h30" class="small-text"></td></tr>
        <tr><th style="padding:6px 0;"><label for="rdrc_level">Niveau</label></th>
            <td style="padding:6px 0;"><select id="rdrc_level" name="rdrc_level" class="widefat">
                <option value="">— Choisir —</option>
                <?php foreach (['debutant'=>'Débutant','intermediaire'=>'Intermédiaire','avance'=>'Avancé'] as $v=>$l): ?>
                <option value="<?php echo esc_attr($v); ?>"<?php selected($level,$v); ?>><?php echo esc_html($l); ?></option>
                <?php endforeach; ?>
            </select></td></tr>
        <tr><th style="padding:6px 0;">Accès</th>
            <td style="padding:6px 0;"><label><input type="checkbox" name="rdrc_free" value="1"<?php checked($free,'1'); ?>> Gratuit</label></td></tr>
        <tr><th style="padding:6px 0;"><label for="rdrc_badge_color">Couleur</label></th>
            <td style="padding:6px 0;"><input type="color" id="rdrc_badge_color" name="rdrc_badge_color" value="<?php echo esc_attr($badge_color); ?>"></td></tr>
    </table>
    <?php
}

function rdrc_mb_lesson_info( $post ) {
    wp_nonce_field( 'rdrc_save_lesson', 'rdrc_lesson_nonce' );
    $course_id = get_post_meta( $post->ID, '_rdrc_parent_course',   true );
    $duration  = get_post_meta( $post->ID, '_rdrc_lesson_duration', true );
    $is_free   = get_post_meta( $post->ID, '_rdrc_lesson_free',     true );
    $video_url = get_post_meta( $post->ID, '_rdrc_video_url',       true );
    $type      = get_post_meta( $post->ID, '_rdrc_lesson_type',     true ) ?: 'article';
    $courses   = get_posts(['post_type'=>'rdrc_course','posts_per_page'=>-1,'post_status'=>'any','orderby'=>'title','order'=>'ASC']);
    ?>
    <table class="form-table" style="margin:0;">
        <tr><th style="padding:6px 0;width:90px;"><label>Cours</label></th>
            <td style="padding:6px 0;"><select name="rdrc_parent_course" class="widefat">
                <option value="">— Aucun —</option>
                <?php foreach ($courses as $c): ?>
                <option value="<?php echo $c->ID; ?>"<?php selected($course_id,$c->ID); ?>><?php echo esc_html($c->post_title); ?></option>
                <?php endforeach; ?>
            </select></td></tr>
        <tr><th style="padding:6px 0;"><label>Type</label></th>
            <td style="padding:6px 0;"><select name="rdrc_lesson_type" class="widefat">
                <option value="article"<?php selected($type,'article'); ?>>📄 Article</option>
                <option value="video"  <?php selected($type,'video');   ?>>🎥 Vidéo</option>
                <option value="tp"     <?php selected($type,'tp');      ?>>🔧 TP</option>
                <option value="quiz"   <?php selected($type,'quiz');    ?>>❓ Quiz</option>
            </select></td></tr>
        <tr><th style="padding:6px 0;"><label>Durée</label></th>
            <td style="padding:6px 0;"><input type="text" name="rdrc_lesson_duration" value="<?php echo esc_attr($duration); ?>" class="small-text" placeholder="15 min"></td></tr>
        <tr><th style="padding:6px 0;"><label>URL vidéo</label></th>
            <td style="padding:6px 0;"><input type="url" name="rdrc_video_url" value="<?php echo esc_attr($video_url); ?>" class="widefat" placeholder="https://youtube.com/..."></td></tr>
        <tr><th style="padding:6px 0;">Accès</th>
            <td style="padding:6px 0;"><label><input type="checkbox" name="rdrc_lesson_free" value="1"<?php checked($is_free,'1'); ?>> Gratuit</label></td></tr>
    </table>
    <?php if ($course_id): ?>
    <p style="margin:10px 0 0;"><a href="<?php echo esc_url(admin_url('admin.php?page=rdrc-builder&course_id='.$course_id)); ?>" class="button" style="width:100%;text-align:center;box-sizing:border-box;">📋 Structure du cours</a></p>
    <?php endif; ?>
    <?php
}

add_action( 'save_post', function( $post_id ) {
    if ( get_post_type($post_id) === 'rdrc_course' ) {
        if ( !isset($_POST['rdrc_settings_nonce']) || !wp_verify_nonce($_POST['rdrc_settings_nonce'],'rdrc_save_settings') ) return;
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
        if ( !current_user_can('edit_post',$post_id) ) return;
        update_post_meta( $post_id, '_rdrc_duration',    sanitize_text_field($_POST['rdrc_duration']    ?? '') );
        update_post_meta( $post_id, '_rdrc_level',       sanitize_key(       $_POST['rdrc_level']       ?? '') );
        update_post_meta( $post_id, '_rdrc_free',        isset($_POST['rdrc_free']) ? '1' : '0' );
        update_post_meta( $post_id, '_rdrc_badge_color', sanitize_hex_color( $_POST['rdrc_badge_color'] ?? '#3b82f6') );
        update_post_meta( $post_id, '_rdrc_icon',        sanitize_text_field($_POST['rdrc_icon']        ?? '📚') );
    }
    if ( get_post_type($post_id) === 'rdrc_lesson' ) {
        if ( !isset($_POST['rdrc_lesson_nonce']) || !wp_verify_nonce($_POST['rdrc_lesson_nonce'],'rdrc_save_lesson') ) return;
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
        if ( !current_user_can('edit_post',$post_id) ) return;
        update_post_meta( $post_id, '_rdrc_parent_course',   (int)($_POST['rdrc_parent_course']   ?? 0) );
        update_post_meta( $post_id, '_rdrc_lesson_type',     sanitize_key($_POST['rdrc_lesson_type']     ?? 'article') );
        update_post_meta( $post_id, '_rdrc_video_url',       esc_url_raw( $_POST['rdrc_video_url']       ?? '') );
        update_post_meta( $post_id, '_rdrc_lesson_duration', sanitize_text_field($_POST['rdrc_lesson_duration'] ?? '') );
        update_post_meta( $post_id, '_rdrc_lesson_free',     isset($_POST['rdrc_lesson_free']) ? '1' : '0' );
    }
});
