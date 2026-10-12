<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/* $subscribers fourni par RDR_Admin_CTB::page_subscribers() */

$total    = count($subscribers);
$daily    = count(array_filter($subscribers, fn($s) => $s['frequency']==='daily'));
$weekly   = count(array_filter($subscribers, fn($s) => $s['frequency']==='weekly'));
$confirmed= count(array_filter($subscribers, fn($s) => $s['confirmed']==1));
?>
<div class="wrap rdr-admin-wrap">
    <div class="rdr-admin-header">
        <h1 class="rdr-admin-title"><span style="color:var(--rdr-accent,#2ea8ff)">⬡</span> Abonnés email</h1>
        <a href="<?php echo admin_url('admin.php?page=rdr-cyber-dashboard'); ?>" class="button">← Vue d'ensemble</a>
    </div>

    <!-- Mini stats -->
    <div class="rdr-stats-bar" style="margin-bottom:20px">
        <div class="rdr-stat-card rdr-accent" style="padding:14px 16px">
            <span class="rdr-stat-label">Total</span>
            <span class="rdr-stat-number"><?php echo $total; ?></span>
        </div>
        <div class="rdr-stat-card rdr-green" style="padding:14px 16px">
            <span class="rdr-stat-label">Confirmés</span>
            <span class="rdr-stat-number"><?php echo $confirmed; ?></span>
        </div>
        <div class="rdr-stat-card" style="padding:14px 16px">
            <span class="rdr-stat-label">Quotidien</span>
            <span class="rdr-stat-number"><?php echo $daily; ?></span>
        </div>
        <div class="rdr-stat-card" style="padding:14px 16px">
            <span class="rdr-stat-label">Hebdo</span>
            <span class="rdr-stat-number"><?php echo $weekly; ?></span>
        </div>
    </div>

    <?php if (empty($subscribers)): ?>
    <div class="rdr-admin-card">
        <p style="color:var(--rdr-text-secondary);font-size:13px;text-align:center;padding:20px 0">
            Aucun abonné pour le moment. Ajoutez le shortcode <code>[rdr_cyber_subscribe]</code> sur une page pour permettre les inscriptions.
        </p>
    </div>
    <?php else: ?>
    <div class="rdr-admin-card" style="padding:0;overflow:hidden">
        <table class="widefat" style="background:transparent;border:none;border-collapse:collapse">
            <thead>
                <tr>
                    <?php foreach (array('Email','Nom','Fréquence','Statut','Inscription','Dernier envoi','Action') as $h): ?>
                    <th style="background:var(--rdr-bg-surface);color:var(--rdr-text-secondary);border-color:var(--rdr-border);font-size:11px;text-transform:uppercase;letter-spacing:.5px;padding:10px 14px"><?php echo esc_html($h); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($subscribers as $i => $sub): ?>
                <?php $bg = $i%2===0 ? 'var(--rdr-bg-card)' : 'var(--rdr-bg-surface)'; ?>
                <tr style="background:<?php echo $bg; ?>">
                    <td style="color:var(--rdr-text-primary);border-color:var(--rdr-border);padding:10px 14px;font-weight:600"><?php echo esc_html($sub['email']); ?></td>
                    <td style="color:var(--rdr-text-secondary);border-color:var(--rdr-border);padding:10px 14px"><?php echo esc_html($sub['name'] ?: '—'); ?></td>
                    <td style="border-color:var(--rdr-border);padding:10px 14px">
                        <span style="background:var(--rdr-bg-accent);color:var(--rdr-accent);border:1px solid rgba(46,168,255,.2);border-radius:4px;padding:2px 8px;font-size:10px;font-weight:700;text-transform:uppercase">
                            <?php echo $sub['frequency']==='daily' ? 'Quotidien' : 'Hebdo'; ?>
                        </span>
                    </td>
                    <td style="border-color:var(--rdr-border);padding:10px 14px">
                        <?php if ($sub['confirmed']): ?>
                        <span style="color:#2ecc71;font-size:11px;font-weight:700">✓ Confirmé</span>
                        <?php else: ?>
                        <span style="color:#f5a623;font-size:11px;font-weight:700">⏳ En attente</span>
                        <?php endif; ?>
                    </td>
                    <td style="color:var(--rdr-text-muted);border-color:var(--rdr-border);padding:10px 14px;font-size:12px"><?php echo $sub['created'] ? date_i18n('d/m/Y', strtotime($sub['created'])) : '—'; ?></td>
                    <td style="color:var(--rdr-text-muted);border-color:var(--rdr-border);padding:10px 14px;font-size:12px"><?php echo $sub['last_sent'] ? date_i18n('d/m/Y H:i', strtotime($sub['last_sent'])) : '—'; ?></td>
                    <td style="border-color:var(--rdr-border);padding:10px 14px">
                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=rdr-cyber-subscribers&delete_sub='.$sub['id']), 'rdr_delete_sub'); ?>"
                           style="color:#ff4757;font-size:12px"
                           onclick="return confirm('Supprimer cet abonné ?')">Supprimer</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
