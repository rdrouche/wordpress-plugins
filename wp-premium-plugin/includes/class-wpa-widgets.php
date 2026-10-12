<?php
defined('ABSPATH') || exit;

/**
 * WP Premium Access — Sidebar Widgets
 * All widgets also work as shortcodes for block editor / page builders.
 *
 * Widgets:
 *   WPA_Widget_LoginBox     — Compact magic link form
 *   WPA_Widget_PremiumCTA   — Upgrade call-to-action for guests
 *   WPA_Widget_AccountBox   — Mini account status panel
 */
class WPA_Widgets {

    public static function init(): void {
        add_action('widgets_init', function () {
            register_widget('WPA_Widget_LoginBox');
            register_widget('WPA_Widget_PremiumCTA');
            register_widget('WPA_Widget_AccountBox');
        });
    }
}

// =============================================================================
// Widget: Login Box
// =============================================================================

class WPA_Widget_LoginBox extends \WP_Widget {

    public function __construct() {
        parent::__construct(
            'wpa_login_box',
            __('Premium — Sign In', WPA_TEXT_DOMAIN),
            ['description' => __('Compact magic link sign-in form.', WPA_TEXT_DOMAIN)]
        );
    }

    public function widget($args, $instance): void {
        echo $args['before_widget'];

        $title = !empty($instance['title'])
            ? apply_filters('widget_title', $instance['title'])
            : __('Premium access', WPA_TEXT_DOMAIN);

        echo $args['before_title'] . esc_html($title) . $args['after_title'];

        // Reuse the shortcode output
        echo WPA_Shortcodes::login_form([]);

        echo $args['after_widget'];
    }

    public function form($instance): void {
        $title = $instance['title'] ?? __('Premium access', WPA_TEXT_DOMAIN);
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>">
                <?php esc_html_e('Title:', WPA_TEXT_DOMAIN); ?>
            </label>
            <input
                class="widefat"
                id="<?php echo esc_attr($this->get_field_id('title')); ?>"
                name="<?php echo esc_attr($this->get_field_name('title')); ?>"
                type="text"
                value="<?php echo esc_attr($title); ?>"
            />
        </p>
        <?php
    }

    public function update($new_instance, $old_instance): array {
        return ['title' => sanitize_text_field($new_instance['title'] ?? '')];
    }
}

// =============================================================================
// Widget: Premium CTA (shown to guests only)
// =============================================================================

class WPA_Widget_PremiumCTA extends \WP_Widget {

    public function __construct() {
        parent::__construct(
            'wpa_premium_cta',
            __('Premium — CTA for guests', WPA_TEXT_DOMAIN),
            ['description' => __('Upgrade call-to-action. Hidden for premium users.', WPA_TEXT_DOMAIN)]
        );
    }

    public function widget($args, $instance): void {
        // Hide for premium users
        $user_id = WPA_Sessions::current_user_id();
        if ($user_id && WPA_Roles::is_premium($user_id)) return;

        echo $args['before_widget'];

        $title    = $instance['title']    ?? __('Support the site', WPA_TEXT_DOMAIN);
        $text     = $instance['text']     ?? __('Get early access, no ads, and more by supporting us on Ko-fi.', WPA_TEXT_DOMAIN);
        $btn_text = $instance['btn_text'] ?? __('Become a supporter', WPA_TEXT_DOMAIN);
        $btn_url  = $instance['btn_url']  ?? 'https://ko-fi.com/yourpage';

        echo $args['before_title'] . esc_html($title) . $args['after_title'];
        ?>
        <div class="wpa-cta" data-wpa-theme>
            <p class="wpa-cta__text"><?php echo esc_html($text); ?></p>
            <a href="<?php echo esc_url($btn_url); ?>"
               class="wpa-btn wpa-btn--primary wpa-cta__btn"
               target="_blank"
               rel="noopener noreferrer">
                <?php echo esc_html($btn_text); ?>
            </a>
        </div>
        <?php
        echo $args['after_widget'];
    }

    public function form($instance): void {
        $fields = [
            'title'    => __('Title', WPA_TEXT_DOMAIN),
            'text'     => __('Text', WPA_TEXT_DOMAIN),
            'btn_text' => __('Button label', WPA_TEXT_DOMAIN),
            'btn_url'  => __('Ko-fi URL', WPA_TEXT_DOMAIN),
        ];
        foreach ($fields as $key => $label) : ?>
            <p>
                <label for="<?php echo esc_attr($this->get_field_id($key)); ?>">
                    <?php echo esc_html($label); ?>:
                </label>
                <input
                    class="widefat"
                    id="<?php echo esc_attr($this->get_field_id($key)); ?>"
                    name="<?php echo esc_attr($this->get_field_name($key)); ?>"
                    type="text"
                    value="<?php echo esc_attr($instance[$key] ?? ''); ?>"
                />
            </p>
        <?php endforeach;
    }

    public function update($new_instance, $old_instance): array {
        return [
            'title'    => sanitize_text_field($new_instance['title']    ?? ''),
            'text'     => sanitize_text_field($new_instance['text']     ?? ''),
            'btn_text' => sanitize_text_field($new_instance['btn_text'] ?? ''),
            'btn_url'  => esc_url_raw($new_instance['btn_url']          ?? ''),
        ];
    }
}

// =============================================================================
// Widget: Account Box (mini panel for connected users)
// =============================================================================

class WPA_Widget_AccountBox extends \WP_Widget {

    public function __construct() {
        parent::__construct(
            'wpa_account_box',
            __('Premium — Account Box', WPA_TEXT_DOMAIN),
            ['description' => __('Shows account status. Hidden for guests.', WPA_TEXT_DOMAIN)]
        );
    }

    public function widget($args, $instance): void {
        $user_id = WPA_Sessions::current_user_id();
        if (!$user_id || !WPA_Roles::is_premium($user_id)) return;

        echo $args['before_widget'];

        $title = $instance['title'] ?? __('My account', WPA_TEXT_DOMAIN);
        echo $args['before_title'] . esc_html($title) . $args['after_title'];

        $roles = WPA_Roles::get_roles($user_id);
        $user  = get_user_by('ID', $user_id);
        ?>
        <div class="wpa-account-box" data-wpa-theme>
            <p class="wpa-account-box__email"><?php echo esc_html($user->user_email ?? ''); ?></p>
            <div class="wpa-account-box__roles">
                <?php foreach ($roles as $role) : ?>
                    <span class="wpa-badge"><?php echo esc_html($role); ?></span>
                <?php endforeach; ?>
            </div>
            <button class="wpa-btn wpa-btn--ghost wpa-btn--sm" id="wpa-logout-widget">
                <?php esc_html_e('Sign out', WPA_TEXT_DOMAIN); ?>
            </button>
        </div>
        <?php
        echo $args['after_widget'];
    }

    public function form($instance): void {
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>">
                <?php esc_html_e('Title:', WPA_TEXT_DOMAIN); ?>
            </label>
            <input
                class="widefat"
                id="<?php echo esc_attr($this->get_field_id('title')); ?>"
                name="<?php echo esc_attr($this->get_field_name('title')); ?>"
                type="text"
                value="<?php echo esc_attr($instance['title'] ?? __('My account', WPA_TEXT_DOMAIN)); ?>"
            />
        </p>
        <?php
    }

    public function update($new_instance, $old_instance): array {
        return ['title' => sanitize_text_field($new_instance['title'] ?? '')];
    }
}
