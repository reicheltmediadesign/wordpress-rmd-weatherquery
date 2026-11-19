<?php
if (!defined('ABSPATH')) {
    exit;
}

// Hand-maintained (no build step): script dependencies and version for index.js.
return array(
    'dependencies' => array(
        'wp-api-fetch',
        'wp-block-editor',
        'wp-blocks',
        'wp-components',
        'wp-element',
        'wp-i18n',
        'wp-server-side-render'
    ),
    'version' => RMD_WQ_VERSION
);
