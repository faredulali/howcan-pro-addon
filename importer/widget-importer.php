<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Howcan_Widget_Importer {
    public static function import_widgets( $data ) {
        if ( empty($data) ) return;

        foreach ( $data as $sidebar_id => $widgets ) {
            foreach ( $widgets as $widget ) {
                $id_base = preg_replace( '/-[0-9]+$/', '', $widget['id'] );
                $widget_data = $widget['settings'];
                $current = get_option( 'widget_' . $id_base, [] );

                $current[] = $widget_data;
                update_option( 'widget_' . $id_base, $current );

                $sidebars = get_option( 'sidebars_widgets', [] );
                $sidebars[$sidebar_id][] = $id_base . '-' . ( count($current) - 1 );
                update_option( 'sidebars_widgets', $sidebars );
            }
        }
    }
}
