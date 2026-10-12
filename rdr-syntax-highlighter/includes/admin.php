<?php
/**
 * RDR Syntax Highlighter — Administration v1.5
 * - Choix des CPT où afficher la coloration
 * - Polylang : enregistrement du libellé du switch
 * - Position "sous le titre" avec style glossaire
 * - Shortcode [rdr_sh_switch]
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/* ============================================================
   MENU + OPTIONS
   ============================================================ */
add_action( 'admin_menu', function () {
    add_options_page( 'RDR Syntax Highlighter', 'RDR Syntax Highlighter',
        'manage_options', 'rdr-syntax-highlighter', 'rdr_sh_admin_page' );
} );

add_action( 'admin_init', function () {
    register_setting( 'rdr_sh_options_group', 'rdr_sh_options', 'rdr_sh_sanitize_options' );
} );

function rdr_sh_get_defaults() {
    return array(
        'line_numbers'    => true,
        'switch_label'    => '🎨 Coloration',
        'switch_position' => 'floating-right',
        'cache_ttl'       => 604800,
        'enabled_cpt'     => array( 'post', 'page' ),
    );
}

function rdr_sh_sanitize_options( $input ) {
    $defaults = rdr_sh_get_defaults();
    $output   = array();

    $output['line_numbers']    = ! empty( $input['line_numbers'] );
    $output['switch_label']    = sanitize_text_field( $input['switch_label'] ?? $defaults['switch_label'] );
    $output['switch_position'] = in_array(
        $input['switch_position'] ?? '',
        array( 'floating-right', 'floating-left', 'under-title', 'shortcode-only', 'none' ), true
    ) ? $input['switch_position'] : $defaults['switch_position'];
    $output['cache_ttl']  = max( 60, (int) ( $input['cache_ttl'] ?? $defaults['cache_ttl'] ) );

    // CPT activés
    $all_cpt = array_keys( rdr_sh_get_all_cpt() );
    $enabled = isset( $input['enabled_cpt'] ) && is_array( $input['enabled_cpt'] )
               ? array_intersect( $input['enabled_cpt'], $all_cpt )
               : $defaults['enabled_cpt'];
    $output['enabled_cpt'] = array_values( $enabled );

    // Enregistrer le label dans Polylang si dispo
    if ( function_exists( 'pll_register_string' ) && ! empty( $output['switch_label'] ) ) {
        pll_register_string( 'rdr_sh_switch_label', $output['switch_label'], 'RDR Syntax Highlighter' );
    }

    return $output;
}

/* Retourne tous les CPT publics (post, page + custom) */
function rdr_sh_get_all_cpt() {
    $args = array( 'public' => true );
    $post_types = get_post_types( $args, 'objects' );
    $result = array();
    foreach ( $post_types as $pt ) {
        $result[ $pt->name ] = $pt->labels->singular_name ?: $pt->name;
    }
    return $result;
}

/* ============================================================
   PAGE ADMIN
   ============================================================ */
function rdr_sh_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $opts  = wp_parse_args( get_option( 'rdr_sh_options', array() ), rdr_sh_get_defaults() );
    $stats = RDR_SH_Cache::stats();
    $nonce = wp_create_nonce( 'rdr_sh_admin_nonce' );
    $all_cpt = rdr_sh_get_all_cpt();
    ?>
    <div class="wrap">
    <h1>⚡ RDR Syntax Highlighter</h1>
    <?php if ( isset( $_GET['settings-updated'] ) ) : ?>
    <div class="notice notice-success is-dismissible"><p><strong>Réglages enregistrés.</strong></p></div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:1fr 300px;gap:24px;margin-top:20px;">
    <div>
    <form method="post" action="options.php">
    <?php settings_fields( 'rdr_sh_options_group' ); ?>
    <table class="form-table" role="presentation">

      <!-- Numéros de ligne -->
      <tr>
        <th><label for="rdr_ln">Numéros de ligne</label></th>
        <td>
          <input type="checkbox" id="rdr_ln" name="rdr_sh_options[line_numbers]" value="1"
            <?php checked( $opts['line_numbers'] ); ?>>
        </td>
      </tr>

      <!-- Label switch + Polylang -->
      <tr>
        <th><label for="rdr_label">Libellé du switch</label></th>
        <td>
          <input type="text" id="rdr_label" name="rdr_sh_options[switch_label]"
            value="<?php echo esc_attr( $opts['switch_label'] ); ?>" class="regular-text">
          <?php if ( function_exists( 'pll_register_string' ) ) : ?>
          <p class="description">
            ✅ Polylang actif — traduisible dans <strong>Langues &gt; Chaînes de traduction</strong>,
            groupe <em>RDR Syntax Highlighter</em>, clé <code>rdr_sh_switch_label</code>.
          </p>
          <?php endif; ?>
        </td>
      </tr>

      <!-- Position -->
      <tr>
        <th>Position du switch</th>
        <td>
          <fieldset><?php
          $positions = array(
              'floating-right' => 'Flottant — bas <strong>droite</strong> (masqué sur mobile)',
              'floating-left'  => 'Flottant — bas <strong>gauche</strong> (masqué sur mobile)',
              'under-title'    => 'Sous le titre de l\'article <em style="color:#888">(style glossaire)</em>',
              'shortcode-only' => 'Shortcode uniquement — <code>[rdr_sh_switch]</code>',
              'none'           => 'Désactivé (API JS uniquement)',
          );
          foreach ( $positions as $val => $lbl ) : ?>
            <label style="display:block;margin-bottom:5px;">
              <input type="radio" name="rdr_sh_options[switch_position]"
                value="<?php echo esc_attr( $val ); ?>"
                <?php checked( $opts['switch_position'], $val ); ?>>
              <?php echo wp_kses( $lbl, array( 'strong'=>array(),'em'=>array('style'=>array()),'code'=>array() ) ); ?>
            </label>
          <?php endforeach; ?></fieldset>
        </td>
      </tr>

      <!-- CPT activés -->
      <tr>
        <th>Types de contenu</th>
        <td>
          <fieldset>
            <p class="description" style="margin-bottom:8px;">
              Afficher la coloration et le switch uniquement sur ces types de contenu :
            </p>
            <?php foreach ( $all_cpt as $cpt_name => $cpt_label ) : ?>
            <label style="display:inline-block;margin-right:18px;margin-bottom:6px;">
              <input type="checkbox" name="rdr_sh_options[enabled_cpt][]"
                value="<?php echo esc_attr( $cpt_name ); ?>"
                <?php checked( in_array( $cpt_name, (array) $opts['enabled_cpt'], true ) ); ?>>
              <?php echo esc_html( $cpt_label ); ?>
              <code style="font-size:11px;color:#888">(<?php echo esc_html( $cpt_name ); ?>)</code>
            </label>
            <?php endforeach; ?>
          </fieldset>
        </td>
      </tr>

      <!-- Cache TTL -->
      <tr>
        <th><label for="rdr_ttl">Durée cache (s)</label></th>
        <td>
          <input type="number" id="rdr_ttl" name="rdr_sh_options[cache_ttl]"
            value="<?php echo esc_attr( $opts['cache_ttl'] ); ?>" min="60" class="small-text">
          <p class="description">604800 = 7 jours.</p>
        </td>
      </tr>

    </table>
    <?php submit_button( 'Enregistrer' ); ?>
    </form>
    </div><!-- col 1 -->

    <div>
      <div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:16px;margin-bottom:16px;">
        <h3 style="margin-top:0">📊 Cache</h3>
        <p><strong><?php echo esc_html( $stats['count'] ); ?></strong> entrées &mdash;
           <strong><?php echo esc_html( $stats['size'] ); ?></strong></p>
        <button type="button" id="rdr-flush-btn" class="button button-secondary">🗑️ Vider</button>
        <p id="rdr-flush-msg" style="color:green;display:none;margin-top:6px"></p>
      </div>

      <div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:16px;margin-bottom:16px;">
        <h3 style="margin-top:0">📋 Shortcode</h3>
        <p>Placer un switch n'importe où :</p>
        <pre style="background:#f6f7f7;padding:8px;font-size:12px">[rdr_sh_switch]
[rdr_sh_switch label="Colorer"]
[rdr_sh_switch style="minimal"]</pre>
        <p style="font-size:12px;color:#666">Se synchronise avec le switch principal.</p>
      </div>

      <div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:16px;">
        <h3 style="margin-top:0">📖 API JS</h3>
        <pre style="background:#f6f7f7;padding:8px;font-size:11px;overflow:auto">window.rdrSH.enable();
window.rdrSH.disable();
window.rdrSH.isEnabled();

// Événement post-colorisation :
document.addEventListener(
  'rdr_sh_highlighted',
  e => console.log(e.detail.block)
);</pre>
      </div>
    </div><!-- col 2 -->
    </div><!-- grid -->
    </div><!-- wrap -->

    <script>
    document.getElementById('rdr-flush-btn').addEventListener('click',function(){
        var btn=this,msg=document.getElementById('rdr-flush-msg');
        btn.disabled=true; btn.textContent='…';
        var fd=new FormData();
        fd.append('action','rdr_sh_flush_cache');
        fd.append('nonce','<?php echo esc_js($nonce); ?>');
        fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>',{method:'POST',body:fd})
            .then(r=>r.json()).then(d=>{
                btn.disabled=false; btn.textContent='🗑️ Vider';
                if(d.success){msg.textContent='✅ '+d.data.message;msg.style.display='block';
                    setTimeout(()=>msg.style.display='none',3000);}
            });
    });
    </script>
    <?php
}

/* ============================================================
   POLYLANG — enregistrement initial du label
   ============================================================ */
add_action( 'init', function () {
    if ( ! function_exists( 'pll_register_string' ) ) return;
    $opts  = get_option( 'rdr_sh_options', array() );
    $label = $opts['switch_label'] ?? '🎨 Coloration';
    pll_register_string( 'rdr_sh_switch_label', $label, 'RDR Syntax Highlighter' );
} );

/* Helper : retourner le label traduit si PLL actif */
function rdr_sh_get_label() {
    $opts  = get_option( 'rdr_sh_options', array() );
    $label = $opts['switch_label'] ?? '🎨 Coloration';
    if ( function_exists( 'pll__' ) ) {
        $t = pll__( $label );
        if ( $t && $t !== $label ) return $t;
    }
    return $label;
}

/* Helper : est-on sur un CPT activé ? */
function rdr_sh_is_enabled_cpt() {
    if ( ! is_singular() ) return false;
    $opts        = get_option( 'rdr_sh_options', array() );
    $enabled_cpt = $opts['enabled_cpt'] ?? array( 'post', 'page' );
    return in_array( get_post_type(), (array) $enabled_cpt, true );
}

/* ============================================================
   HOOK SOUS LE TITRE — style identique au glossaire
   Injecté via generate_after_entry_title uniquement sur les
   CPT activés. Le rendu reprend exactement le style du toggle
   dark/light du thème (pastille + pill).
   ============================================================ */
add_action( 'template_redirect', function () {
    $opts = get_option( 'rdr_sh_options', array() );
    if ( ( $opts['switch_position'] ?? '' ) !== 'under-title' ) return;
    if ( ! rdr_sh_is_enabled_cpt() ) return;
    add_action( 'generate_after_entry_title', 'rdr_sh_render_under_title', 15 );
    // Fallback thèmes sans ce hook
    add_filter( 'the_content', 'rdr_sh_prepend_to_content', 5 );
} );

function rdr_sh_render_under_title() {
    if ( ! is_singular() ) return;
    /*
     * PHP injecte uniquement le placeholder vide.
     * Le JS (rdr-sh.js) construit le switch et l'insère dans ce div.
     * Avantage : pas de switch non-bindé visible, async-safe.
     */
    echo '<div class="rdr-sh-under-title-wrap" id="rdr-sh-under-title"></div>' . "
";
}

function rdr_sh_prepend_to_content( $content ) {
    // Ne pas injecter si le hook GP a déjà injecté
    if ( did_action( 'generate_after_entry_title' ) ) return $content;
    if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) return $content;
    ob_start();
    rdr_sh_render_under_title();
    return ob_get_clean() . $content;
}

/* ============================================================
   SHORTCODE [rdr_sh_switch]
   ============================================================ */
add_shortcode( 'rdr_sh_switch', 'rdr_sh_shortcode_switch' );

function rdr_sh_shortcode_switch( $atts ) {
    $atts = shortcode_atts( array(
        'label'  => '',
        'style'  => 'full',
        'class'  => '',
    ), $atts, 'rdr_sh_switch' );

    $label   = ! empty( $atts['label'] ) ? sanitize_text_field( $atts['label'] ) : rdr_sh_get_label();
    $minimal = ( $atts['style'] === 'minimal' );
    $extra   = sanitize_html_class( $atts['class'] );
    $uid     = 'rdr-sh-sc-' . wp_unique_id();

    ob_start();
    ?>
    <div id="<?php echo esc_attr( $uid ); ?>"
         class="rdr-sh-switch rdr-sh-switch--shortcode rdr-sh-switch--glossaire<?php echo $extra ? ' '.esc_attr($extra) : ''; ?>"
         style="display:inline-flex;align-items:center;gap:10px;">
        <button class="rdr-sh-switch__toggle rdr-sh-glossaire-btn"
                role="switch" aria-checked="false" type="button"
                aria-label="<?php esc_attr_e('Activer la coloration syntaxique','rdr-syntax-highlighter'); ?>">
            <span class="rdr-sh-switch__knob"></span>
        </button>
        <?php if ( ! $minimal ) : ?>
        <span class="rdr-sh-switch__label"><?php echo esc_html( $label ); ?></span>
        <?php endif; ?>
    </div>
    <script>
    (function(){
        var el=document.getElementById('<?php echo esc_js($uid); ?>');
        if(!el)return;
        function reg(){
            if(window.rdrSH&&window.rdrSH.registerShortcodeSwitch){
                window.rdrSH.registerShortcodeSwitch(el);
            }else{ setTimeout(reg,50); }
        }
        document.readyState==='loading'
            ? document.addEventListener('DOMContentLoaded',reg)
            : reg();
    })();
    </script>
    <?php
    return ob_get_clean();
}
