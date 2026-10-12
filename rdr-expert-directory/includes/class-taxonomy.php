<?php
defined('ABSPATH') || exit;

class RDR_ED_Taxonomy {

    public static function init(): void {
        add_action('init', [self::class, 'register_all']);
    }

    public static function register_all(): void {
        /* ── Compétences / Spécialités ── */
        register_taxonomy('expert_skill', 'expert_profile', [
            'labels' => [
                'name'              => __('Compétences', 'rdr-expert-directory'),
                'singular_name'     => __('Compétence', 'rdr-expert-directory'),
                'search_items'      => __('Rechercher une compétence', 'rdr-expert-directory'),
                'all_items'         => __('Toutes les compétences', 'rdr-expert-directory'),
                'edit_item'         => __('Modifier la compétence', 'rdr-expert-directory'),
                'add_new_item'      => __('Ajouter une compétence', 'rdr-expert-directory'),
                'new_item_name'     => __('Nouvelle compétence', 'rdr-expert-directory'),
                'menu_name'         => __('Compétences', 'rdr-expert-directory'),
            ],
            'hierarchical'      => false,
            'public'            => true,
            'show_ui'           => true,
            'show_in_menu'      => true,
            'show_in_rest'      => false,
            'rewrite'           => ['slug' => 'competence'],
            'show_admin_column' => true,
            'capabilities' => [
                'manage_terms' => RDR_ED_Capabilities::CAP_SKILLS,
                'edit_terms'   => RDR_ED_Capabilities::CAP_SKILLS,
                'delete_terms' => RDR_ED_Capabilities::CAP_SKILLS,
                'assign_terms' => 'edit_expert_profiles',
            ],
        ]);

        /* ── Langues ── */
        register_taxonomy('expert_language', 'expert_profile', [
            'labels' => [
                'name'          => __('Langues', 'rdr-expert-directory'),
                'singular_name' => __('Langue', 'rdr-expert-directory'),
                'all_items'     => __('Toutes les langues', 'rdr-expert-directory'),
                'add_new_item'  => __('Ajouter une langue', 'rdr-expert-directory'),
                'menu_name'     => __('Langues', 'rdr-expert-directory'),
            ],
            'hierarchical'      => false,
            'public'            => true,
            'show_ui'           => true,
            'show_in_menu'      => true,
            'show_in_rest'      => false,
            'rewrite'           => ['slug' => 'langue'],
            'show_admin_column' => true,
        ]);

        /* ── Pays ── */
        register_taxonomy('expert_country', 'expert_profile', [
            'labels' => [
                'name'          => __('Pays', 'rdr-expert-directory'),
                'singular_name' => __('Pays', 'rdr-expert-directory'),
                'all_items'     => __('Tous les pays', 'rdr-expert-directory'),
                'add_new_item'  => __('Ajouter un pays', 'rdr-expert-directory'),
                'menu_name'     => __('Pays', 'rdr-expert-directory'),
            ],
            'hierarchical'      => true,   // Hiérarchique pour organiser par continent si besoin
            'public'            => true,
            'show_ui'           => true,
            'show_in_menu'      => true,
            'show_in_rest'      => false,
            'rewrite'           => ['slug' => 'pays'],
            'show_admin_column' => true,
        ]);

        /* Peupler les langues et pays courants à l'activation (sera fait via activate hook) */
    }

    /**
     * Seeding initial des termes de base.
     * Appelé depuis RDR_ED_Capabilities::activate()
     */
    public static function seed_default_terms(): void {
        /* Langues */
        $languages = ['Français', 'Anglais', 'Espagnol', 'Allemand', 'Italien', 'Portugais', 'Arabe', 'Mandarin'];
        foreach ($languages as $lang) {
            if (!term_exists($lang, 'expert_language')) {
                wp_insert_term($lang, 'expert_language');
            }
        }

        /* Pays (Europe + monde) */
        $countries = [
            'France', 'Belgique', 'Suisse', 'Luxembourg', 'Canada', 'Maroc', 'Tunisie', 'Algérie',
            'Allemagne', 'Espagne', 'Italie', 'Pays-Bas', 'Royaume-Uni', 'États-Unis', 'Portugal',
            'Sénégal', 'Côte d\'Ivoire', 'Cameroun', 'Madagascar',
        ];
        foreach ($countries as $country) {
            if (!term_exists($country, 'expert_country')) {
                wp_insert_term($country, 'expert_country');
            }
        }

        /* Compétences IT courantes */
        $skills = [
            'Linux', 'Windows Server', 'Active Directory', 'Azure', 'AWS', 'Google Cloud',
            'VMware', 'Proxmox', 'Docker', 'Kubernetes', 'Networking', 'Cybersécurité',
            'DevOps', 'CI/CD', 'Python', 'PowerShell', 'Bash', 'Microsoft 365', 'Exchange',
            'Backup & Restore', 'SIEM', 'Firewall', 'VPN', 'WordPress', 'PHP',
            'SQL Server', 'PostgreSQL', 'MySQL', 'ERP', 'ITIL', 'Scrum',
        ];
        foreach ($skills as $skill) {
            if (!term_exists($skill, 'expert_skill')) {
                wp_insert_term($skill, 'expert_skill');
            }
        }
    }
}
