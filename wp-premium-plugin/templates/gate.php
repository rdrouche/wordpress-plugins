<?php defined('ABSPATH') || exit; ?>
<div class="wpa-gate" data-wpa-theme>
    <span class="wpa-gate__icon">&#9733;</span>
    <div class="wpa-gate__body">
        <p class="wpa-gate__message">
            <?php esc_html_e('This content is reserved for premium members.', WPA_TEXT_DOMAIN); ?>
        </p>
        <a href="<?php echo esc_url(home_url('/connexion-premium/')); ?>" class="wpa-btn wpa-btn--primary">
            <?php esc_html_e('Access my account', WPA_TEXT_DOMAIN); ?>
        </a>
    </div>
</div>
