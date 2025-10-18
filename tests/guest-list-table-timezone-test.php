<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

$GLOBALS['test_wp_options'] = [
    'date_format' => 'Y-m-d',
    'time_format' => 'H:i',
];

$GLOBALS['test_wp_timezone_string'] = 'America/New_York';

if (!class_exists('WP_List_Table')) {
    class WP_List_Table {
        public function __construct($args = array()) {
        }
    }
}

if (!function_exists('wp_timezone')) {
    function wp_timezone(): DateTimeZone
    {
        return new DateTimeZone($GLOBALS['test_wp_timezone_string'] ?? 'UTC');
    }
}

if (!function_exists('get_option')) {
    function get_option(string $option, $default = false)
    {
        return $GLOBALS['test_wp_options'][$option] ?? $default;
    }
}

if (!function_exists('wp_date')) {
    function wp_date(string $format, int $timestamp, ?DateTimeZone $timezone = null): string
    {
        $timezone = $timezone ?: wp_timezone();
        $date = new DateTimeImmutable('@' . $timestamp);

        return $date->setTimezone($timezone)->format($format);
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

require_once __DIR__ . '/../includes/class-admin.php';

$table = new GMS_Guests_List_Table();

$item = ['created_at' => '2023-03-10 15:00:00+00:00'];
$result = $table->column_default($item, 'created_at');
if ($result !== '2023-03-10 10:00') {
    fwrite(STDERR, sprintf("Unexpected formatted datetime: %s\n", $result));
    exit(1);
}

$itemInvalid = ['created_at' => 'not-a-date'];
$resultInvalid = $table->column_default($itemInvalid, 'created_at');
if ($resultInvalid !== '&mdash;') {
    fwrite(STDERR, "Invalid datetime should fall back to an em dash.\n");
    exit(1);
}

echo "Guest list table timezone test passed\n";
