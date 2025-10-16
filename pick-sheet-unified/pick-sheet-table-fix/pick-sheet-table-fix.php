<?php

//

// Only operate on front-end single pick sheet posts (post type slug is 'pick_sheets').
add_filter('the_content', function ($content) {
    if (!is_admin() && is_singular('pick_sheets')) {
        // If the [pick_sheet_table] shortcode is missing, insert it at the top of the content.
        if (strpos($content, '[pick_sheet_table]') === false) {
            return '[pick_sheet_table]' . "\n\n" . $content;
        }
    }
    return $content;
});
