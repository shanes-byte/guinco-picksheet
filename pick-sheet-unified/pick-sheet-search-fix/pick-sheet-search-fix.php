<?php

//

// Only enqueue our fix on pick sheet pages (singular pick_sheets post type)
add_action('wp_enqueue_scripts', function() {
    if (is_singular('pick_sheets')) {
        wp_enqueue_script(
            'pick-sheet-search-fix',
            plugin_dir_url(__FILE__) . 'search-fix.js',
            array('jquery'),
            '1.0.1',
            true
        );
    }
});
