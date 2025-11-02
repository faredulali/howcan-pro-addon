<?php


function howcan_license_settings_init() {
   
   
   $add =  add_option('howcan_license_key', '');
    add_option('howcan_license_key', '');
    
    register_setting('general', 'howcan_license_key', 'sanitize_text_field');
    add_settings_field(
        'howcan_license_key',
        'Howcan License Key',
        function() {
           
            $value = get_option('howcan_license_key', '');
           
          
            echo '<input type="text" id="howcan_license_key" name="howcan_license_key" value="' . esc_attr($value) . '" class="regular-text" />';
       

if(howcan_is_license_valid() ){
   echo '<strong style="color:green; margin-left:5px;">PRO ACTIVATED</strong>';
}else{
echo '<strong style="color:red; margin-left:5px;">Licence Not ACTIVATED</strong>';
}
        },
        'general'
    );
}
add_action('admin_init', 'howcan_license_settings_init');
function howcan_is_license_valid() {
    $key = get_option('howcan_license_key', '');
    if ( empty($key) ) {
        return false;
    }

    // তোমার Google Script Web App URL
    $url = 'https://script.google.com/macros/s/AKfycbwoCAblrFO15KdEyKQkP77NxjGVicLvBQ_wbpJQDhJAynN-l3M16yL_rYnoDyc2PBmoVA/exec?key=' . urlencode($key);

    // Remote check
    $response = wp_remote_get( $url, array( 'timeout' => 10 ) );

    if ( is_wp_error( $response ) ) {
        return false;
    }

    $body = wp_remote_retrieve_body( $response );
    return ( trim($body) === 'valid' );
}

if ( function_exists('howcan_is_license_valid') && howcan_is_license_valid() ) {
    
    add_action('admin_notices', function() {
      

        echo '<div class="notice notice-success is-dismissible">
            <p><strong>Howcan Pro:</strong> Is Activeted Successfully.</p>
        </div>';
    });
    // ✅ Pro features enabled
     // 
     require_once plugin_dir_path( __FILE__ ) . 'options/pro-customizer.php';
} else {
    // 🔒 Show upgrade notice
    add_action('admin_notices', function() { ?>
         <div class="notice notice-warning">
            <p><strong>Howcan Pro:</strong> Please  <a href="<?php echo esc_url(home_url()); ?>/wp-admin/options-general.php" target="_blank">Active</a> your license to unlock premium features.</p>
        </div>
        
        <?php
    });
}
 
