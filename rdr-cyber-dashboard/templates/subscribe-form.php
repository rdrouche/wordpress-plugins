<?php
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="rdr-subscribe-wrap" id="rdr-subscribe-wrap">

    <div class="rdr-subscribe-card">
        <div class="rdr-subscribe-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
            </svg>
        </div>
        <h3 class="rdr-subscribe-title">Alertes cyber par email</h3>
        <p class="rdr-subscribe-desc">Recevez un récapitulatif des alertes et vulnérabilités directement dans votre boîte mail.</p>

        <form id="rdr-subscribe-form" class="rdr-sform" novalidate>
            <div class="rdr-sform-row">
                <input type="text"
                       id="rdr-sub-name"
                       name="name"
                       placeholder="Votre prénom (optionnel)"
                       class="rdr-sinput"
                       autocomplete="given-name">
            </div>
            <div class="rdr-sform-row">
                <input type="email"
                       id="rdr-sub-email"
                       name="email"
                       placeholder="votre@email.com"
                       class="rdr-sinput"
                       required
                       autocomplete="email">
            </div>
            <div class="rdr-sform-row rdr-sform-freq">
                <span class="rdr-sform-label">Fréquence :</span>
                <label class="rdr-radio-label">
                    <input type="radio" name="frequency" value="daily" id="rdr-freq-daily">
                    <span class="rdr-radio-box">
                        <span class="rdr-radio-title">Quotidien</span>
                        <span class="rdr-radio-sub">Chaque matin à 8h</span>
                    </span>
                </label>
                <label class="rdr-radio-label">
                    <input type="radio" name="frequency" value="weekly" id="rdr-freq-weekly" checked>
                    <span class="rdr-radio-box">
                        <span class="rdr-radio-title">Hebdomadaire</span>
                        <span class="rdr-radio-sub">Chaque jeudi à 8h</span>
                    </span>
                </label>
            </div>

            <div id="rdr-sub-message" class="rdr-sub-message" style="display:none"></div>

            <button type="submit" class="rdr-btn rdr-sbtn" id="rdr-sub-submit">
                <span id="rdr-sub-btn-text">S'abonner gratuitement</span>
                <span id="rdr-sub-btn-spinner" style="display:none">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="animation:rdr-spin .7s linear infinite"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
                    Envoi…
                </span>
            </button>

            <p class="rdr-subscribe-notice">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                Un email de confirmation vous sera envoyé. Désabonnement en un clic.
            </p>
        </form>
    </div>

</div>

<style>
.rdr-subscribe-wrap { max-width: 480px; margin: 0 auto; }
.rdr-subscribe-card {
    background: var(--rdr-bg-card, #1c2128);
    border: 1px solid var(--rdr-border, rgba(255,255,255,.07));
    border-radius: 16px;
    padding: 32px 28px;
    text-align: center;
    box-shadow: var(--rdr-shadow-card, 0 2px 8px rgba(0,0,0,.3));
}
.rdr-subscribe-icon {
    width: 60px; height: 60px;
    background: var(--rdr-bg-accent, rgba(46,168,255,.08));
    border: 1px solid rgba(46,168,255,.2);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 16px;
    color: var(--rdr-accent, #2ea8ff);
}
.rdr-subscribe-title {
    font-family: 'Syne', sans-serif;
    font-size: 20px; font-weight: 800;
    color: var(--rdr-text-primary, #e6edf3);
    margin: 0 0 8px;
}
.rdr-subscribe-desc {
    font-size: 13px;
    color: var(--rdr-text-secondary, #8b949e);
    margin: 0 0 24px;
    line-height: 1.6;
}
.rdr-sform { text-align: left; }
.rdr-sform-row { margin-bottom: 12px; }
.rdr-sinput {
    width: 100%; box-sizing: border-box;
    padding: 10px 14px;
    background: var(--rdr-bg-surface, #161b22);
    border: 1px solid var(--rdr-border-strong, rgba(255,255,255,.15));
    border-radius: 8px;
    color: var(--rdr-text-primary, #e6edf3);
    font-family: 'DM Sans', sans-serif; font-size: 14px;
    outline: none; transition: border-color .2s;
}
.rdr-sinput:focus { border-color: var(--rdr-accent, #2ea8ff); }
.rdr-sinput::placeholder { color: var(--rdr-text-muted, #484f58); }

.rdr-sform-freq { display: flex; flex-direction: column; gap: 8px; }
.rdr-sform-label { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: var(--rdr-text-muted, #484f58); margin-bottom: 2px; }

.rdr-radio-label { cursor: pointer; display: flex; }
.rdr-radio-label input[type="radio"] { display: none; }
.rdr-radio-box {
    flex: 1; padding: 10px 14px;
    background: var(--rdr-bg-surface, #161b22);
    border: 1px solid var(--rdr-border-strong, rgba(255,255,255,.15));
    border-radius: 8px; display: flex; flex-direction: column;
    transition: border-color .2s, background .2s;
}
.rdr-radio-label input:checked + .rdr-radio-box {
    border-color: var(--rdr-accent, #2ea8ff);
    background: var(--rdr-bg-accent, rgba(46,168,255,.08));
}
.rdr-radio-title { font-size: 13px; font-weight: 600; color: var(--rdr-text-primary, #e6edf3); }
.rdr-radio-sub   { font-size: 11px; color: var(--rdr-text-muted, #484f58); margin-top: 2px; }

.rdr-sbtn {
    width: 100%; padding: 12px;
    font-size: 14px; font-weight: 700;
    margin-top: 16px; margin-bottom: 12px;
    display: flex; align-items: center; justify-content: center; gap: 8px;
}
.rdr-subscribe-notice {
    font-size: 11px; color: var(--rdr-text-muted, #484f58);
    display: flex; align-items: center; gap: 5px;
    justify-content: center; margin: 0;
}
.rdr-sub-message {
    padding: 10px 14px; border-radius: 8px;
    font-size: 13px; margin-bottom: 12px;
}
.rdr-sub-message.success { background: rgba(46,204,113,.1); border: 1px solid rgba(46,204,113,.3); color: #2ecc71; }
.rdr-sub-message.error   { background: rgba(255,71,87,.1);  border: 1px solid rgba(255,71,87,.3);  color: #ff4757; }

/* Light mode */
[data-theme="light"] .rdr-subscribe-card { background: #fff; border-color: rgba(0,0,0,.08); }
[data-theme="light"] .rdr-sinput, [data-theme="light"] .rdr-radio-box { background: #f5f7fa; border-color: rgba(0,0,0,.12); color: #1a1a2e; }
[data-theme="light"] .rdr-subscribe-title { color: #1a1a2e; }
</style>
