<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

$GLOBALS['test_wp_options'] = $GLOBALS['test_wp_options'] ?? array(
    'date_format' => 'Y-m-d',
    'time_format' => 'H:i',
);

$GLOBALS['test_wp_timezone_string'] = $GLOBALS['test_wp_timezone_string'] ?? 'UTC';

if (!class_exists('WP_List_Table')) {
    class WP_List_Table {
        public function __construct($args = array())
        {
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

if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
    {
        // no-op
    }
}

if (!function_exists('__')) {
    function __($text, $domain = null)
    {
        return $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = null)
    {
        return $text;
    }
}

if (!function_exists('esc_html_e')) {
    function esc_html_e($text, $domain = null)
    {
        echo $text;
    }
}

if (!function_exists('esc_attr__')) {
    function esc_attr__($text, $domain = null)
    {
        return $text;
    }
}

if (!function_exists('esc_attr_e')) {
    function esc_attr_e($text, $domain = null)
    {
        echo $text;
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return (string) $text;
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text)
    {
        return (string) $text;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text)
    {
        return is_scalar($text) ? (string) $text : '';
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key)
    {
        $key = strtolower((string) $key);

        return preg_replace('/[^a-z0-9_\-]/', '', $key);
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return $value;
    }
}

if (!function_exists('absint')) {
    function absint($maybeint)
    {
        return (int) max(0, (int) $maybeint);
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path = '')
    {
        $path = ltrim((string) $path, '?');

        return 'admin.php' . ($path !== '' ? '?' . $path : '');
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1)
    {
        return 'nonce';
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value)
    {
        return $value;
    }
}

if (!function_exists('get_locale')) {
    function get_locale()
    {
        return 'en_US';
    }
}

if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style()
    {
        // no-op
    }
}

if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script()
    {
        // no-op
    }
}

if (!function_exists('wp_localize_script')) {
    function wp_localize_script()
    {
        // no-op
    }
}

if (!function_exists('wp_enqueue_media')) {
    function wp_enqueue_media()
    {
        // no-op
    }
}

if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect()
    {
        // no-op
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action)
    {
        return true;
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg($args, $url)
    {
        return $url;
    }
}

$GLOBALS['gms_test_menu_pages'] = array();
$GLOBALS['gms_test_submenu_pages'] = array();

if (!function_exists('add_menu_page')) {
    function add_menu_page($page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null)
    {
        $GLOBALS['gms_test_menu_pages'][] = array(
            'page_title' => $page_title,
            'menu_title' => $menu_title,
            'capability' => $capability,
            'menu_slug' => $menu_slug,
            'callback' => $callback,
            'icon_url' => $icon_url,
            'position' => $position,
        );

        return 'guest-management_page_' . $menu_slug;
    }
}

if (!function_exists('add_submenu_page')) {
    function add_submenu_page($parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '')
    {
        $hook_suffix = 'guest-management_page_' . $menu_slug;
        $GLOBALS['gms_test_submenu_pages'][] = array(
            'parent_slug' => $parent_slug,
            'page_title' => $page_title,
            'menu_title' => $menu_title,
            'capability' => $capability,
            'menu_slug' => $menu_slug,
            'callback' => $callback,
            'hook_suffix' => $hook_suffix,
        );

        return $hook_suffix;
    }
}

require_once __DIR__ . '/../includes/class-admin.php';

$admin = new GMS_Admin();
$admin->add_admin_menu();

$submenu_by_slug = array();
foreach ($GLOBALS['gms_test_submenu_pages'] as $submenu) {
    $submenu_by_slug[$submenu['menu_slug']] = $submenu;
}

if (!isset($submenu_by_slug['guest-management-messaging'])) {
    throw new RuntimeException('Messaging submenu not registered: ' . json_encode($submenu_by_slug));
}

if (!isset($submenu_by_slug['guest-management-logs'])) {
    throw new RuntimeException('Logs submenu not registered: ' . json_encode($submenu_by_slug));
}

if (isset($submenu_by_slug['guest-management-communications'])) {
    throw new RuntimeException('Legacy communications submenu is still registered.');
}

$messaging = $submenu_by_slug['guest-management-messaging'];
$logs = $submenu_by_slug['guest-management-logs'];

if (!is_array($messaging['callback']) || ($messaging['callback'][1] ?? '') !== 'render_communications_page') {
    throw new RuntimeException('Messaging submenu callback is incorrect: ' . json_encode($messaging['callback']));
}

if (!is_array($logs['callback']) || ($logs['callback'][1] ?? '') !== 'render_logs_page') {
    throw new RuntimeException('Logs submenu callback is incorrect: ' . json_encode($logs['callback']));
}

if (($messaging['hook_suffix'] ?? '') !== 'guest-management_page_guest-management-messaging') {
    throw new RuntimeException('Unexpected messaging hook suffix: ' . ($messaging['hook_suffix'] ?? ''));
}

if (($logs['hook_suffix'] ?? '') !== 'guest-management_page_guest-management-logs') {
    throw new RuntimeException('Unexpected logs hook suffix: ' . ($logs['hook_suffix'] ?? ''));
}

echo "Admin navigation slug test passed\n";
