<?php 

function howcan_pro_settings( $wp_customize ) {
  
    $wp_customize-> add_section(
      'howcan_pro', array(
         'title' => __( 'Pro Options', 'howcan-pro-addons' ),
        'priority' => 1,
        'panel' => 'howcan_theme_options_panel', // optional
      ));
      
      
    // setting 
    $wp_customize->add_setting( 'show_bfr_nav', array(
        'default'           => true,
        'sanitize_callback' => 'howcan_sanitize_checkbox',
    ));

    // control 
    $wp_customize->add_control( 'show_bfr_nav', array(
        'label'   => __( 'Hero Image Befor Navigation Menu', 'howcan-pro-addons' ),
        'section' => 'howcan_pro',
        'type'    => 'checkbox',
    ));
      
    // setting 
    $wp_customize->add_setting( 'show_aftr_nav', array(
        'default'           => false,
        'sanitize_callback' => 'howcan_sanitize_checkbox',
    ));

    // control 
    $wp_customize->add_control( 'show_aftr_nav', array(
        'label'   => __( 'Hero Image After Navigation Menu', 'howcan-pro-addons' ),
        'section' => 'howcan_pro',
        'type'    => 'checkbox',
    ));
      
    // setting 
    $wp_customize->add_setting( 'hero_img_text_on', array(
        'default'           => false,
        'sanitize_callback' => 'howcan_sanitize_checkbox',
    ));

    // control 
    $wp_customize->add_control( 'hero_img_text_on', array(
        'label'   => __( 'Hero Image Text', 'howcan-pro-addons' ),
        'section' => 'howcan_pro',
        'type'    => 'checkbox',
    ));
    // sidebar layout left 
    $wp_customize->add_setting( 'show_sidebar_left', array(
        'default'           => false,
        'sanitize_callback' => 'howcan_sanitize_checkbox',
    ));

    // control 
    $wp_customize->add_control( 'show_sidebar_left', array(
        'label'   => __( 'Sidebar On Left ', 'howcan-pro-addons' ),
        'section' => 'howcan_pro',
        'type'    => 'checkbox',
    ));
    
    
    // setting 
    $wp_customize->add_setting( 'adsterra_script_code', array(
        'default'           => '',
        'sanitize_callback' => 'howcan_sanitize_scripts',
    ));

    // control 
    $wp_customize->add_control( 'adsterra_script_code', array(
        'label'   => __( 'Adsterra Script Code', 'howcan-pro-addons' ),
        'section' => 'howcan_pro',
        'type'    => 'textarea',
    ));
    
    
    $wp_customize->add_setting('howcan_footer_text', [
        'default'   => __('Made With Howcan Theme', 'howcan-pro-addons'),
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    
    //footer pro 
    $wp_customize->add_control('howcan_footer_text', [
        'label'   => __('Footer Text', 'howcan-pro-addons'),
        'section' => 'howcan_pro',
        'type'    => 'text',
    ]);
    
    

// index.php Recent Post Text Edit
 
    $wp_customize->add_setting('howcan_recents_text', [
        'default'   => __('Recents Post', 'howcan-pro-addons'),
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    $wp_customize->add_control('howcan_recents_text', [
        'label'   => __('Post Recent Title Change', 'howcan-pro-addons'),
        'section' => 'howcan_pro',
        'type'    => 'text',
    ]);
    
    
    $wp_customize->add_setting('howcan_pdb_text', [
        'default'   => __('See more', 'howcan-pro-addons'),
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    $wp_customize->add_control('howcan_pdb_text', [
        'label'   => __('Post Dettails Button Change', 'howcan-pro-addons'),
        'section' => 'howcan_pro',
        'type'    => 'text',
    ]);
    
    
    

    // Footer bottom Widget Show/Hide Setting
    $wp_customize->add_setting('howcan_footer_top_widgets', array(
        'default'           => false,
        'sanitize_callback' => 'howcan_sanitize_checkbox',
    ));

    // Control (Checkbox)
    $wp_customize->add_control('howcan_footer_top_widgets', array(
        'label'    => __('Show Footer Area', 'howcan-pro-addons'),
        'section'  => 'howcan_pro',
        'settings' => 'howcan_footer_top_widgets',
        'type'     => 'checkbox',
    ));
   
   
    //post view 

    $wp_customize->add_setting( 'howcan_enable_post_views', array(
        'default'           => true,
        'sanitize_callback' => 'howcan_sanitize_checkbox',
    ) );

    $wp_customize->add_control( 'howcan_enable_post_views', array(
        'label'    => __( 'Enable Post View Counter', 'howcan-pro-addons' ),
        'section'  => 'howcan_pro',
        'settings' => 'howcan_enable_post_views',
        'type'     => 'checkbox',
    ) );
    
    
    // box layout
    $wp_customize->add_setting('howcan_box_layout', array(
        'default'           => false,
        'sanitize_callback' => 'howcan_sanitize_checkbox',
    ));
    // Control (Checkbox)
    $wp_customize->add_control('howcan_box_layout', array(
        'label'    => __('Box Layout ', 'howcan-pro-addons'),
        'description'    => __('Enable check / Uncheck to Boxed/Full Layout ', 'howcan-pro-addons'),
        'section'  => 'howcan_pro',
        'settings' => 'howcan_box_layout',
        'type'     => 'checkbox',
    ));
   
   
   
   //share option 
   
    $buttons = [
        'facebook' => __('Enable Facebook Share Button', 'howcan-pro-addons'),
        'twitter' => __('Enable Twitter Share Button', 'howcan-pro-addons'),
        'whatsapp' => __('Enable WhatsApp Share Button', 'howcan-pro-addons'),
        'copylink' => __('Enable Copy Link Button', 'howcan-pro-addons'),
    ];

    foreach ($buttons as $key => $label) {
        $wp_customize->add_setting("howcan_enable_{$key}_share", array(
            'default' => true,
            'sanitize_callback' => 'howcan_sanitize_checkbox',
        ));

        $wp_customize->add_control("howcan_enable_{$key}_share", array(
            'label' => $label,
            'section' => 'howcan-pro',
            'settings' => "howcan_enable_{$key}_share",
            'type' => 'checkbox',
        ));
    }
    


    
}
add_action( 'customize_register', 'howcan_pro_settings' );





