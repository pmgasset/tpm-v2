<?php
// Minimal WordPress stubs required for including the plugin helpers.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        // no-op for testing
    }
}

if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
        // no-op for testing
    }
}

if (!function_exists('add_shortcode')) {
    function add_shortcode($tag, $callback) {
        // no-op for testing
    }
}

if (!function_exists('shortcode_atts')) {
    function shortcode_atts($pairs, $atts) {
        $atts = (array) $atts;
        return array_merge($pairs, array_intersect_key($atts, $pairs));
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url) {
        return $url;
    }
}

if (!function_exists('home_url')) {
    function home_url($path = '') {
        $base = 'https://example.com';
        $path = (string) $path;

        if ($path === '' || $path === '/') {
            return $base . '/';
        }

        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        return $base . $path;
    }
}

if (!function_exists('user_trailingslashit')) {
    function user_trailingslashit($string) {
        $string = (string) $string;
        return rtrim($string, '/') . '/';
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg($args, $url) {
        $url_parts = parse_url($url);
        $existing = array();

        if (!empty($url_parts['query'])) {
            parse_str($url_parts['query'], $existing);
        }

        $merged = array_merge($existing, (array) $args);
        $query = http_build_query($merged);

        $scheme   = $url_parts['scheme'] ?? 'http';
        $host     = $url_parts['host'] ?? '';
        $port     = isset($url_parts['port']) ? ':' . $url_parts['port'] : '';
        $path     = $url_parts['path'] ?? '';
        $fragment = isset($url_parts['fragment']) ? '#' . $url_parts['fragment'] : '';

        $auth = '';
        if (isset($url_parts['user'])) {
            $auth = $url_parts['user'];
            if (isset($url_parts['pass'])) {
                $auth .= ':' . $url_parts['pass'];
            }
            $auth .= '@';
        }

        $result = $scheme . '://' . $auth . $host . $port . $path;
        if ($query !== '') {
            $result .= '?' . $query;
        }
        $result .= $fragment;

        return $result;
    }
}

// Stub localisation and sanitisation helpers used during inclusion.
if (!function_exists('__')) {
    function __($text, $domain = null) {
        return $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = null) {
        return $text;
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return $text;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) {
        return is_scalar($text) ? (string) $text : '';
    }
}

if (!function_exists('maybe_serialize')) {
    function maybe_serialize($data) {
        if (is_array($data) || is_object($data)) {
            return serialize($data);
        }

        if (is_serialized($data, false)) {
            return serialize($data);
        }

        return $data;
    }
}

if (!function_exists('is_serialized')) {
    function is_serialized($data, $strict = true) {
        if (!is_string($data)) {
            return false;
        }

        $data = trim($data);

        if ($data === 'N;') {
            return true;
        }

        if (!preg_match('/^([adObis]):/', $data, $matches)) {
            return false;
        }

        return true;
    }
}

if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) {
        return '2024-01-01 00:00:00';
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id() {
        return 0;
    }
}

if (!function_exists('do_action')) {
    function do_action($hook, ...$args) {
        // no-op
    }
}

if (!function_exists('error_log')) {
    function error_log($message) {
        // no-op during tests
    }
}

if (!function_exists('get_option')) {
    function get_option($name, $default = null) {
        return $default;
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show = '', $filter = 'raw') {
        return '6.0';
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        return preg_replace('/[^a-z0-9_]/', '', strtolower((string) $key));
    }
}

if (!function_exists('wp_mail')) {
    function wp_mail($to, $subject, $message, $headers = array()) {
        return true;
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($string) {
        return strip_tags($string);
    }
}

if (!function_exists('register_activation_hook')) {
    function register_activation_hook($file, $callback) {
        // no-op for testing
    }
}

if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook($file, $callback) {
        // no-op for testing
    }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook) {
        return false;
    }
}

if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event($timestamp, $recurrence, $hook) {
        // no-op for testing
    }
}

if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook($hook) {
        // no-op for testing
    }
}

if (!function_exists('is_email')) {
    function is_email($email) {
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }
}

require_once __DIR__ . '/../includes/functions.php';

// Ensure permalinks are disabled for this test run.
$wp_rewrite = new class {
    public $front = '';
    public function using_permalinks() {
        return false;
    }
};

$result = gms_build_portal_url('Token With Spaces');
$expected = 'https://example.com/?guest_portal=1&guest_token=Token%2520With%2520Spaces';

if ($result !== $expected) {
    throw new RuntimeException('gms_build_portal_url produced unexpected URL: ' . var_export($result, true));
}

echo "portal-url-helper-test: OK\n";
