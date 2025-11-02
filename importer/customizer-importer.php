<?php
if (!defined('ABSPATH')) exit;

class Howcan_Customizer_Importer {

    public static function import_customizer($file) {
        if (!file_exists($file)) return;

        try {
            // Read file content
            $data = file_get_contents($file);

            // Remove non-printable characters (UTF-8 safe)
            $data = preg_replace('/[\x00-\x1F\x7F]/u', '', $data);

            // Safely unserialize
            $data = maybe_unserialize($data);

            if (is_array($data)) {
                // Optional: clear existing theme mods before import
                remove_theme_mods();

                // Apply all theme mods
                foreach ($data as $key => $value) {
                    set_theme_mod($key, $value);
                }
            }

        } catch (Exception $e) {
            // Log error if import fails
            error_log('Customizer import failed: ' . $e->getMessage());
        }
    }

}
