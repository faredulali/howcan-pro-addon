<?php
/**
 * Plugin Name: Howcan Pro Addon
 * Plugin URI: https://demo-howcan.liveblog365.com/howcan-pro-addons
 * Description: go premium features for the Theme of Howcan.
 * Author: Faredul Ali
 * Author URI: https://demo-howcan.liveblog365.com/author
 * Version: 1.0.0.5
 * License: GPLv2 or later
 * Text Domain: howcan-pro-addons
 * GitHub Plugin URI: https://github.com/faredulali/howcan-pro-addon
 * Update URI: false
 */
//Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
class Howcan_Pro_Addon {
    public function __construct() {
        add_action( 'after_setup_theme', array( $this, 'load_pro_features' ) );
    }

    public function load_pro_features() {
      
      //active check 
        require_once plugin_dir_path( __FILE__ ) . 'includes/activation.php';
        
        // import
        require_once plugin_dir_path( __FILE__ ) . 'includes/demo-importer.php';
        
        
    }
}
new Howcan_Pro_Addon();


// ========== GitHub Auto Update Support ==========
require_once plugin_dir_path(__FILE__) . 'plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$updateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/faredulali/howcan-pro-addon/',
    __FILE__,
    'howcan-pro-addons'
);

