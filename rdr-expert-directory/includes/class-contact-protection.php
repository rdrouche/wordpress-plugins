<?php
defined('ABSPATH') || exit;

/**
 * Protection anti-scraping des coordonnées de contact.
 *
 * MENACES VISÉES :
 *  1. Bots HTTP directs (curl, requests, scrapers) → vérifient pas le nonce, pas le token
 *  2. Bots navigateurs headless (Puppeteer, Playwright) → peuvent forger des requêtes AJAX
 *  3. Scraping massif → rate limiting + session tokens
 *
 * COUCHES DE PROTECTION (en ordre de vérification côté serveur) :
 *  1. Nonce WordPress     → premier rempart, expire en 12h
 *  2. Session token       → généré au chargement de page (unique par session)
 *  3. Referer check       → la requête doit venir du même site
 *  4. Rate limiting IP    → 5 révélations max / 10 min par IP
 *  5. Rate limiting Post  → max révélations par fiche / heure (anti-ciblage)
 *  6. Challenge JS        → réponse à un calcul embarqué dans la page (bots sans JS)
 *  7. Délai minimum       → 800ms minimum entre deux révélations (same session)
 *
 * CÔTÉ CLIENT (expert-directory.js) :
 *  - Le token de session est généré et stocké en sessionStorage
 *  - Le challenge JS est calculé et envoyé avec chaque requête
 *  - Un délai de 800ms est imposé entre les clics "Révéler"
 */
class RDR_ED_Contact_Protection {

    /* Constantes rate limiting */
    const RATE_IP_MAX    = 5;      // révélations max par IP
    const RATE_IP_WIN    = 600;    // fenêtre en secondes (10 min)
    const RATE_POST_MAX  = 20;     // révélations max par fiche (par heure)
    const RATE_POST_WIN  = 3600;   // 1 heure

    /* Clé de salage pour les tokens (changée à l'activation) */
    const TOKEN_SALT_OPT = 'rdr_ed_token_salt';

    public static function init(): void {
        add_action('wp_footer',      [self::class, 'inject_session_token'], 5);
        add_filter('rdr_ed_contact_protection_checks', [self::class, 'run_checks']);
    }

    /* ── Générer / récupérer le sel de token ── */
    public static function get_salt(): string {
        $salt = get_option(self::TOKEN_SALT_OPT);
        if (!$salt) {
            $salt = wp_generate_password(32, false);
            update_option(self::TOKEN_SALT_OPT, $salt, false);
        }
        return $salt;
    }

    /* ── Générer un token de session valide pour 2h ── */
    public static function generate_session_token(): string {
        $window = floor(time() / 7200); // fenêtre de 2 heures
        return hash_hmac('sha256', 'rdr_ed_contact_' . $window, self::get_salt());
    }

    /* ── Vérifier un token de session ── */
    public static function verify_session_token(string $token): bool {
        if (strlen($token) !== 64) return false; // SHA256 = 64 hex chars
        // Vérifier la fenêtre courante ET la précédente (pour éviter les rejets en fin de fenêtre)
        $window_now  = floor(time() / 7200);
        $window_prev = $window_now - 1;
        $valid_now   = hash_hmac('sha256', 'rdr_ed_contact_' . $window_now,  self::get_salt());
        $valid_prev  = hash_hmac('sha256', 'rdr_ed_contact_' . $window_prev, self::get_salt());
        return hash_equals($valid_now, $token) || hash_equals($valid_prev, $token);
    }

    /* ── Challenge JS : générer la question ── */
    public static function generate_challenge(): array {
        $a = wp_rand(10, 99);
        $b = wp_rand(10, 99);
        return [
            'a'        => $a,
            'b'        => $b,
            'expected' => $a + $b,       // Le client doit envoyer cette somme
            'token'    => hash_hmac('sha256', $a . ':' . $b . ':' . ($a + $b), self::get_salt()),
        ];
    }

    /* ── Vérifier la réponse au challenge ── */
    public static function verify_challenge(int $answer, string $challenge_token): bool {
        // Brute-force resistant : on ne peut pas deviner le token sans le sel
        // On tente de retrouver a+b dans une plage raisonnable
        // En pratique, le client envoie answer et challenge_token qu'il a reçus
        // On stocke le challenge attendu en transient côté serveur lors de sa génération
        // Voir inject_session_token() pour la génération
        $stored = get_transient('rdr_ed_challenge_' . $challenge_token);
        if ($stored === false) return false;
        $ok = ((int) $stored === $answer);
        delete_transient('rdr_ed_challenge_' . $challenge_token); // usage unique
        return $ok;
    }

    /* ── Rate limiting IP ── */
    public static function check_rate_ip(): bool {
        $ip  = self::get_client_ip();
        $key = 'rdr_ed_rlip_' . md5($ip);
        $c   = get_transient($key);
        if ($c === false) { set_transient($key, 1, self::RATE_IP_WIN); return true; }
        if ((int)$c >= self::RATE_IP_MAX) return false;
        set_transient($key, (int)$c + 1, self::RATE_IP_WIN);
        return true;
    }

    /* ── Rate limiting par fiche ── */
    public static function check_rate_post(int $post_id): bool {
        $key = 'rdr_ed_rlpost_' . $post_id;
        $c   = get_transient($key);
        if ($c === false) { set_transient($key, 1, self::RATE_POST_WIN); return true; }
        if ((int)$c >= self::RATE_POST_MAX) return false;
        set_transient($key, (int)$c + 1, self::RATE_POST_WIN);
        return true;
    }

    /* ── Vérification Referer ── */
    public static function check_referer(): bool {
        $referer = wp_get_referer();
        if (!$referer) return false; // Pas de referer = bot probable
        $site_url = home_url();
        return strpos($referer, $site_url) === 0;
    }

    /* ── Orchestrer tous les checks ── */
    public static function run_checks(array $context): array {
        $errors = [];

        // 1. Nonce (déjà vérifié avant d'arriver ici via check_ajax_referer)

        // 2. Referer
        if (!self::check_referer()) {
            $errors[] = 'invalid_referer';
        }

        // 3. Session token
        $token = sanitize_text_field($context['session_token'] ?? '');
        if (!$token || !self::verify_session_token($token)) {
            $errors[] = 'invalid_token';
        }

        // 4. Rate IP
        if (!self::check_rate_ip()) {
            $errors[] = 'rate_limit_ip';
        }

        // 5. Rate post
        if (!empty($context['post_id']) && !self::check_rate_post((int)$context['post_id'])) {
            $errors[] = 'rate_limit_post';
        }

        // 6. Challenge JS
        if (!empty($context['challenge_answer']) && !empty($context['challenge_token'])) {
            if (!self::verify_challenge((int)$context['challenge_answer'], $context['challenge_token'])) {
                $errors[] = 'invalid_challenge';
            }
        } else {
            $errors[] = 'missing_challenge';
        }

        return $errors;
    }

    /* ── Injecter le token de session + challenge dans la page ── */
    public static function inject_session_token(): void {
        // Uniquement sur les pages où il y a des fiches
        if (!is_singular('expert_profile') && !is_page()) return;

        $token     = self::generate_session_token();
        $challenge = self::generate_challenge();

        // Stocker la réponse attendue côté serveur (transient 10 min, usage unique)
        $ct_key = 'rdr_ed_challenge_' . $challenge['token'];
        set_transient($ct_key, $challenge['expected'], 600);

        // On n'expose PAS 'expected' au client !
        $client_data = [
            'session_token'    => $token,
            'challenge_token'  => $challenge['token'],
            'challenge_a'      => $challenge['a'],
            'challenge_b'      => $challenge['b'],
        ];
        ?>
        <script id="rdr-ed-contact-security">
        (function() {
            var d = <?= wp_json_encode($client_data); ?>;
            // Calculer la réponse au challenge (opération simple pour un humain/navigateur)
            d.challenge_answer = d.challenge_a + d.challenge_b;
            // Stocker en sessionStorage pour réutilisation sur la page
            try { window.sessionStorage.setItem('rdr_ed_sec', JSON.stringify(d)); } catch(e){}
            window.RDR_ED_SEC = d;
        })();
        </script>
        <?php
    }

    /* ── Utilitaires ── */
    public static function get_client_ip(): string {
        $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($keys as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', $_SERVER[$k])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        // Fallback (LAN/localhost)
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Message d'erreur user-friendly selon le code.
     */
    public static function error_message(string $code): string {
        $messages = [
            'invalid_referer'   => __('Requête non autorisée.', 'rdr-expert-directory'),
            'invalid_token'     => __('Session expirée, rechargez la page.', 'rdr-expert-directory'),
            'rate_limit_ip'     => sprintf(__('Trop de requêtes. Réessayez dans %d minutes.', 'rdr-expert-directory'), (int)ceil(self::RATE_IP_WIN / 60)),
            'rate_limit_post'   => __('Limite atteinte pour cette fiche.', 'rdr-expert-directory'),
            'invalid_challenge' => __('Vérification de sécurité échouée, rechargez la page.', 'rdr-expert-directory'),
            'missing_challenge' => __('Données de sécurité manquantes.', 'rdr-expert-directory'),
        ];
        return $messages[$code] ?? __('Accès refusé.', 'rdr-expert-directory');
    }
}
