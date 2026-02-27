<?php
/**
 * Plugin Name: Cal Google Shortcode
 * Description: Renderiza un calendario de Google Calendar (ICS) en acordeones mensuales mediante shortcode.
 * Version: 1.0.0
 * Author: Codex
 */

if (! defined('ABSPATH')) {
    exit;
}

$calGoogleFiles = [
    __DIR__ . '/includes/contracts.php',
    __DIR__ . '/includes/config.php',
    __DIR__ . '/includes/event.php',
    __DIR__ . '/includes/ics-parser.php',
    __DIR__ . '/includes/ics-fetcher.php',
    __DIR__ . '/includes/calendar-renderer.php',
    __DIR__ . '/includes/plugin.php',
];

foreach ($calGoogleFiles as $calGoogleFile) {
    if (file_exists($calGoogleFile)) {
        require_once $calGoogleFile;
    }
}

new Cal_Google_Shortcode_Plugin();
