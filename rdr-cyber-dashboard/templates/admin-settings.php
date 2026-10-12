<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$interval    = get_option('rdr_cyber_interval',   'twicedaily');
$nvd_key     = get_option('rdr_cyber_nvd_key',    '');
$purge_days  = get_option('rdr_cyber_purge_days', 90);
$per_page    = get_option('rdr_cyber_per_page',   12);
$from_email  = get_option('rdr_cyber_from_email', get_option('admin_email'));
$from_name   = get_option('rdr_cyber_from_name',  get_bloginfo('name'));
$push_enabled     = get_option('rdr_cyber_push_enabled', 0);
$push_on_collect  = get_option('rdr_cyber_push_on_collect', 1);
$vapid_public     = get_option('rdr_cyber_vapid_public', '');
$push_subs_count  = RDR_Database::count_push_subscriptions();

$intervals   = array(
    'rdr_hourly'  => 'Toutes les heures',
    'rdr_4hours'  => 'Toutes les 4 heures',
    'twicedaily'  => '2× par jour (défaut)',
    'daily'       => '1× par jour',
);
$next_cron   = wp_next_scheduled(RDR_Cron::HOOK_COLLECT);
?>
<div class="wrap rdr-admin-wrap">
    <div class="rdr-admin-header">
        <h1 class="rdr-admin-title"><span style="color:var(--rdr-accent,#2ea8ff)">⬡</span> Paramètres</h1>
        <a href="<?php echo admin_url('admin.php?page=rdr-cyber-dashboard'); ?>" class="button">← Vue d'ensemble</a>
    </div>

    <?php if (isset($_GET['settings-updated'])): ?>
    <div class="rdr-notice rdr-notice-success">Paramètres enregistrés.</div>
    <?php endif; ?>

    <form method="post" action="options.php" class="rdr-settings-form">
        <?php settings_fields('rdr_cyber_options'); ?>

        <!-- Collecte -->
        <div class="rdr-admin-card" style="margin-bottom:16px">
            <h3 class="rdr-admin-card-title">Collecte automatique</h3>
            <table class="form-table">
                <tr>
                    <th><label for="rdr_cyber_interval">Fréquence</label></th>
                    <td>
                        <select name="rdr_cyber_interval" id="rdr_cyber_interval">
                            <?php foreach ($intervals as $val => $label): ?>
                            <option value="<?php echo esc_attr($val); ?>" <?php selected($interval,$val); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Prochaine collecte : <?php echo $next_cron ? date_i18n('d/m/Y à H:i', $next_cron) : 'Non planifiée'; ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="rdr_cyber_nvd_key">Clé API NVD <small>(optionnel)</small></label></th>
                    <td>
                        <input type="text" name="rdr_cyber_nvd_key" id="rdr_cyber_nvd_key" value="<?php echo esc_attr($nvd_key); ?>" class="regular-text" placeholder="Laissez vide pour l'accès public (limité)">
                        <p class="description">Obtenez une clé gratuite sur <a href="https://nvd.nist.gov/developers/request-an-api-key" target="_blank">nvd.nist.gov</a> pour lever les limites de taux.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="rdr_cyber_per_page">Cards par page</label></th>
                    <td>
                        <input type="number" name="rdr_cyber_per_page" id="rdr_cyber_per_page" value="<?php echo esc_attr($per_page); ?>" min="4" max="48" style="width:80px">
                    </td>
                </tr>
            </table>
        </div>

        <!-- Emails -->
        <div class="rdr-admin-card" style="margin-bottom:16px">
            <h3 class="rdr-admin-card-title">Emails (digests & abonnements)</h3>
            <table class="form-table">
                <tr>
                    <th><label for="rdr_cyber_from_name">Nom d'expéditeur</label></th>
                    <td>
                        <input type="text" name="rdr_cyber_from_name" id="rdr_cyber_from_name" value="<?php echo esc_attr($from_name); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th><label for="rdr_cyber_from_email">Email expéditeur</label></th>
                    <td>
                        <input type="email" name="rdr_cyber_from_email" id="rdr_cyber_from_email" value="<?php echo esc_attr($from_email); ?>" class="regular-text">
                        <p class="description">Digest quotidien envoyé chaque jour à 8h. Digest hebdo envoyé chaque jeudi à 8h.</p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Web Push VAPID -->
        <div class="rdr-admin-card" style="margin-bottom:16px">
            <h3 class="rdr-admin-card-title">🔔 Notifications Web Push (VAPID)</h3>
            <?php if (!extension_loaded('openssl')): ?>
            <div style="background:rgba(255,71,87,.08);border:1px solid rgba(255,71,87,.3);border-radius:8px;padding:12px 16px;color:#ff4757;font-size:13px;margin-bottom:12px">
                ⚠️ L'extension PHP <strong>OpenSSL</strong> n'est pas disponible sur ce serveur. Les notifications push nécessitent OpenSSL avec support de la courbe P-256.
            </div>
            <?php else: ?>
            <table class="form-table">
                <tr>
                    <th>Clés VAPID</th>
                    <td>
                        <?php if ($vapid_public): ?>
                        <div style="background:var(--rdr-bg-primary);border:1px solid var(--rdr-border);border-radius:6px;padding:8px 12px;font-family:monospace;font-size:11px;color:var(--rdr-accent-2);word-break:break-all;margin-bottom:10px">
                            <span style="color:var(--rdr-text-muted);font-size:10px;text-transform:uppercase;letter-spacing:.5px">Clé publique : </span><br>
                            <?php echo esc_html(substr($vapid_public,0,40).'…'); ?>
                        </div>
                        <button type="button" id="rdr-vapid-regen" class="button" style="font-size:12px">↺ Régénérer les clés</button>
                        <span style="color:#f5a623;font-size:11px;margin-left:8px">⚠ Régénérer invalide tous les abonnements push existants</span>
                        <?php else: ?>
                        <button type="button" id="rdr-vapid-generate" class="button button-primary" style="background:var(--rdr-accent);border-color:var(--rdr-accent)">⚡ Générer les clés VAPID</button>
                        <p class="description" style="margin-top:8px">Requis pour activer les notifications push. Génération instantanée via OpenSSL.</p>
                        <?php endif; ?>
                        <div id="rdr-vapid-msg" style="margin-top:8px;font-size:13px;display:none"></div>
                    </td>
                </tr>
                <?php if ($vapid_public): ?>
                <tr>
                    <th><label for="rdr_cyber_push_on_collect">Push à la collecte</label></th>
                    <td>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                            <input type="checkbox" name="rdr_cyber_push_on_collect" id="rdr_cyber_push_on_collect" value="1" <?php checked($push_on_collect,1); ?>>
                            <span style="font-size:13px;color:var(--rdr-text-secondary)">Envoyer une notification push lors d'une collecte avec de nouvelles alertes critiques</span>
                        </label>
                    </td>
                </tr>
                <?php endif; ?>
            </table>
            <?php endif; ?>
        </div>

        <?php submit_button('Enregistrer les paramètres'); ?>
    </form>

    <!-- Flux RSS info -->
    <div class="rdr-admin-card" style="margin-bottom:16px">
        <h3 class="rdr-admin-card-title">Flux RSS des alertes</h3>
        <p style="color:var(--rdr-text-secondary);font-size:13px">URL du flux :</p>
        <code style="display:block;padding:10px 14px;background:var(--rdr-bg-primary);border:1px solid var(--rdr-border);border-radius:8px;font-size:13px;color:var(--rdr-accent-2)"><?php echo esc_html(home_url('/feed/'.RDR_Feed::FEED_SLUG.'/')) ?></code>
        <p style="color:var(--rdr-text-muted);font-size:12px;margin-top:8px">Paramètres GET disponibles : <code>source</code>, <code>severity</code>, <code>limit</code> (max 50)</p>
    </div>

    <!-- Email de test -->
    <div class="rdr-admin-card" style="margin-bottom:16px">
        <h3 class="rdr-admin-card-title">📧 Email de test</h3>
        <p style="color:var(--rdr-text-secondary);font-size:13px;margin-bottom:12px">Envoie un digest de test avec les 5 dernières alertes collectées.</p>
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <input type="email" id="rdr-test-email-to" value="<?php echo esc_attr(get_option('admin_email')); ?>"
                   style="background:var(--rdr-bg-card);border:1px solid var(--rdr-border-strong);color:var(--rdr-text-primary);border-radius:6px;padding:7px 12px;font-size:13px;width:280px">
            <button type="button" id="rdr-send-test-email" class="button">Envoyer l'email de test</button>
        </div>
        <div id="rdr-test-email-msg" style="margin-top:10px;font-size:13px;display:none"></div>
    </div>

    <!-- Push de test -->
    <?php if (RDR_Push::is_available()): ?>
    <div class="rdr-admin-card" style="margin-bottom:16px">
        <h3 class="rdr-admin-card-title">🔔 Notification push de test</h3>
        <p style="color:var(--rdr-text-secondary);font-size:13px;margin-bottom:12px">
            Envoie une notification push de test à tous les abonnés (<strong><?php echo $push_subs_count; ?></strong>).
        </p>
        <button type="button" id="rdr-send-test-push" class="button" <?php echo $push_subs_count === 0 ? 'disabled title="Aucun abonné push"' : ''; ?>>Envoyer le push de test</button>
        <div id="rdr-test-push-msg" style="margin-top:10px;font-size:13px;display:none"></div>
    </div>
    <?php endif; ?>

    <!-- Purge -->
    <div class="rdr-admin-card" style="border-color:rgba(255,71,87,.25);margin-bottom:16px">
        <h3 class="rdr-admin-card-title" style="color:#ff4757">Zone dangereuse — Purge</h3>
        <form method="post" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
            <?php wp_nonce_field('rdr_cyber_purge_nonce'); ?>
            <label style="color:var(--rdr-text-secondary);font-size:13px">
                Supprimer les entrées antérieures à
                <input type="number" name="rdr_cyber_purge_days" value="<?php echo esc_attr($purge_days); ?>" min="7" max="365" style="width:70px;margin:0 6px;background:var(--rdr-bg-card);border:1px solid var(--rdr-border-strong);color:var(--rdr-text-primary);border-radius:4px;padding:4px 8px">
                jours
            </label>
            <button type="submit" name="rdr_cyber_purge" class="button" style="background:rgba(255,71,87,.1);color:#ff4757;border-color:rgba(255,71,87,.4)" onclick="return confirm('Confirmer la purge ?')">Purger</button>
        </form>
    </div>
</div>
