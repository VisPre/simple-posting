<?php

if (!defined('WP_UNINSTALL_PLUGIN'))
    exit;

$options = array(
    'simple_posting_settings',
);
foreach ($options as $option) {
    if (get_option($option))
        delete_option($option);
}