<?php
if (!defined('ABSPATH')) exit;

class Howcan_Pro_Demo_Importer {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_page']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('wp_ajax_howcan_import_demo', [$this, 'import_demo']);
        add_action('wp_ajax_howcan_reset_all_demos', [$this, 'reset_all_demos']);
        add_action('wp_ajax_howcan_get_active_demo', [$this, 'get_active_demo']);
    }

    /**
     * ✅ Return active demo via AJAX
     */
    public function get_active_demo() {
        check_ajax_referer('howcan_demo_nonce', 'nonce');
        $demos = ['landing-demo', 'newspaper-demo'];
        $active = '';

        foreach ($demos as $demo) {
            if (get_option('howcan_demo_imported_' . $demo) == '1' || get_option('howcan_demo_imported_' . $demo) === true) {
                $active = $demo;
                break;
            }
        }

        wp_send_json_success(['active' => $active]);
    }

    /**
     * 1️⃣ Add Admin Page
     */
    public function add_admin_page() {
        add_theme_page(
            __('Howcan Demo Import', 'howcan-pro-addons'),
            __('Demo Import', 'howcan-pro-addons'),
            'manage_options',
            'howcan-demo-import',
            [$this, 'render_admin_page']
        );
    }

    /**
     * 2️⃣ Enqueue Styles & Scripts
     */
    public function admin_assets($hook) {
        if ($hook !== 'appearance_page_howcan-demo-import') return;

        wp_enqueue_style('howcan-demo-import', plugin_dir_url(__DIR__) . 'assets/demo-import.css');
        wp_enqueue_script('howcan-demo-import', plugin_dir_url(__DIR__) . 'assets/demo-import.js', ['jquery'], null, true);

        wp_localize_script('howcan-demo-import', 'howcan_demo_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('howcan_demo_nonce'),
        ]);
    }

    /**
     * 3️⃣ Render Admin Page
     */
    public function render_admin_page() {
        $demos = [
            ['slug' => 'landing-demo', 'name' => 'Landing Demo', 'preview' => plugin_dir_url(__DIR__) . 'assets/landing-demo.jpg'],
            ['slug' => 'newspaper-demo', 'name' => 'Newspaper Demo', 'preview' => plugin_dir_url(__DIR__) . 'assets/newspaper-demo.jpg'],
        ];

        // Find which demo is active
        $active_demo = '';
        foreach ($demos as $demo) {
            if (get_option('howcan_demo_imported_' . $demo['slug']) == '1' || get_option('howcan_demo_imported_' . $demo['slug']) === true) {
                $active_demo = $demo['slug'];
                break;
            }
        }
        ?>
        <div class="wrap howcan-demo-import">
            <h1>Howcan — One Click Demo Import</h1>
            <div class="howcan-demos-grid">
                <?php foreach ($demos as $demo): ?>
                    <?php $is_active = ($active_demo === $demo['slug']); ?>
                    <div class="howcan-demo-card <?php echo $is_active ? 'already-imported' : ''; ?>" data-demo="<?php echo esc_attr($demo['slug']); ?>">
                        <img src="<?php echo esc_url($demo['preview']); ?>" alt="<?php echo esc_attr($demo['name']); ?>" />
                        <h2><?php echo esc_html($demo['name']); ?></h2>

                        <?php if ($is_active): ?>
                            <button class="button disabled" disabled>✅ Activated</button>
                        <?php else: ?>
                            <button class="button button-primary howcan-import-btn" data-demo="<?php echo esc_attr($demo['slug']); ?>">
                                ⚡ Import <?php echo esc_html($demo['name']); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div style="text-align:center; margin-top:40px;">
                <button id="howcan-reset-all" class="button button-secondary" style="background:#e74c3c;color:#fff;padding:10px 25px;border-radius:5px;">⚠️ Reset All Demos</button>
            </div>

            <div id="howcan-import-result" style="margin-top:20px;"></div>
        </div>
        <?php
    }

    /**
     * 4️⃣ Import Demo
     */
    public function import_demo() {
        if (!current_user_can('manage_options')) wp_send_json_error('Permission denied');
        check_ajax_referer('howcan_demo_nonce', 'nonce');

        $demo = sanitize_text_field($_POST['demo'] ?? '');
        if (empty($demo)) wp_send_json_error('No demo selected');

        // Reset all demo flags before import
        $this->reset_demo_flags();
        update_option('howcan_demo_imported_' . $demo, 'importing');

        try {
            $this->clear_old_demo_data();

            $demo_path = plugin_dir_path(__DIR__) . 'demos/' . $demo . '/';
            $xml_file  = $demo_path . 'content.xml';
            $widget_file = $demo_path . 'widget.wie';
            $customizer_file = $demo_path . 'customizer.dat';

            // --- Import XML ---
            if (file_exists($xml_file)) {
                if (!defined('WP_LOAD_IMPORTERS')) define('WP_LOAD_IMPORTERS', true);
                require_once plugin_dir_path(__DIR__) . 'importer/wordpress-importer.php';
                if (class_exists('WP_Import')) {
                    ob_start();
                    $importer = new WP_Import();
                    $importer->fetch_attachments = true;
                    $importer->import($xml_file);
                    ob_end_clean();
                }
            }

            // --- Import Widgets ---
            if (file_exists($widget_file)) {
                require_once plugin_dir_path(__DIR__) . 'importer/widget-importer.php';
                $widget_data = file_get_contents($widget_file);
                Howcan_Widget_Importer::import_widgets(json_decode($widget_data, true));
            }

            // --- Import Customizer ---
            if (file_exists($customizer_file)) {
                require_once plugin_dir_path(__DIR__) . 'importer/customizer-importer.php';
                Howcan_Customizer_Importer::import_customizer($customizer_file);
            }

            // --- Assign homepage & menus ---
            $this->assign_menus_and_frontpage($demo);

            // ✅ Mark this demo as imported
            update_option('howcan_demo_imported_' . $demo, '1');

            wp_send_json_success([
                'message'   => ucfirst($demo) . ' demo imported successfully and activated!',
                'site_url'  => home_url('/')
            ]);

        } catch (Exception $e) {
            delete_option('howcan_demo_imported_' . $demo);
            wp_send_json_error('Import failed: ' . $e->getMessage());
        }
    }

    private function reset_demo_flags() {
        $flags = ['landing-demo', 'newspaper-demo'];
        foreach ($flags as $flag) {
            delete_option('howcan_demo_imported_' . $flag);
        }
    }

    private function assign_menus_and_frontpage($demo) {
        $main_menu = get_term_by('name', 'Main Menu', 'nav_menu');
        if ($main_menu) {
            set_theme_mod('nav_menu_locations', ['primary' => $main_menu->term_id]);
        }

        $template_map = [
            'Front Page' => 'pages/custom-home.php',
            'News Page'  => 'pages/news-home.php',
        ];

        $demo_pages = [
            'landing-demo' => [
                'home' => ['title' => 'Landing Page', 'template' => 'Front Page'],
                'news' => ['title' => 'News', 'template' => 'News Page'],
            ],
            'newspaper-demo' => [
                'home' => ['title' => 'Newspaper Home', 'template' => 'News Page'],
            ],
        ];

        $pages_to_create = $demo_pages[$demo] ?? [];
        $created_pages = [];

        foreach ($pages_to_create as $key => $page_data) {
            $page = get_page_by_title($page_data['title']);
            if (!$page) {
                $page_id = wp_insert_post([
                    'post_title'   => $page_data['title'],
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                    'post_content' => $page_data['title'] . ' content',
                ]);
            } else {
                $page_id = $page->ID;
            }

            $template_file = $template_map[$page_data['template']] ?? '';
            if ($template_file) {
                update_post_meta($page_id, '_wp_page_template', $template_file);
            }

            $created_pages[$key] = $page_id;
        }

        if (!empty($created_pages['home'])) {
            update_option('show_on_front', 'page');
            update_option('page_on_front', $created_pages['home']);
        }

        flush_rewrite_rules();
    }

    private function clear_old_demo_data() {
        global $wpdb;

        $all_posts = get_posts(['numberposts' => -1, 'post_type' => 'any', 'post_status' => 'any']);
        foreach ($all_posts as $post) wp_delete_post($post->ID, true);

        $attachments = get_posts(['post_type' => 'attachment', 'numberposts' => -1]);
        foreach ($attachments as $attach) wp_delete_attachment($attach->ID, true);

        $menus = wp_get_nav_menus();
        foreach ($menus as $menu) wp_delete_nav_menu($menu->term_id);

        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'widget_%'");
    }

    public function reset_all_demos() {
        if (!current_user_can('manage_options')) wp_send_json_error('Permission denied');
        check_ajax_referer('howcan_demo_nonce', 'nonce');

        $this->clear_old_demo_data();
        $this->reset_demo_flags();

        wp_send_json_success('✅ All demos reset successfully.');
    }
}

new Howcan_Pro_Demo_Importer();
