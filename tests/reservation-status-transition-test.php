<?php
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

$logged_messages = array();
$captured_request = null;

if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        // no-op for tests
    }
}

if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
        // no-op for tests
    }
}

if (!function_exists('add_shortcode')) {
    function add_shortcode($tag, $callback) {
        // no-op for tests
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

if (!function_exists('esc_attr__')) {
    function esc_attr__($text, $domain = null) {
        return $text;
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return $text;
    }
}

if (!function_exists('esc_html_e')) {
    function esc_html_e($text, $domain = null) {
        // no-op for tests
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) {
        return is_scalar($text) ? trim((string) $text) : '';
    }
}

if (!function_exists('sanitize_email')) {
    function sanitize_email($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        return preg_replace('/[^a-z0-9_]/', '', strtolower((string) $key));
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

        if (!preg_match('/^([adObis]):/', $data)) {
            return false;
        }

        return true;
    }
}

if (!function_exists('is_email')) {
    function is_email($email) {
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
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
        // no-op for tests
    }
}

if (!function_exists('error_log')) {
    function error_log($message) {
        global $logged_messages;
        $logged_messages[] = $message;
    }
}

if (!function_exists('get_option')) {
    function get_option($name, $default = null) {
        if ($name === 'gms_housekeeping_calendar_endpoint') {
            return 'https://housekeeping.example/api/events';
        }

        if ($name === 'gms_housekeeping_calendar_token') {
            return 'token-abc';
        }

        return $default;
    }
}

if (!function_exists('wp_timezone')) {
    function wp_timezone() {
        return new DateTimeZone('America/New_York');
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) {
        return json_encode($data);
    }
}

if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, $args = array()) {
        global $captured_request;
        $captured_request = array('url' => $url, 'args' => $args);

        return array(
            'response' => array('code' => 200),
            'body' => 'OK',
        );
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return $thing instanceof WP_Error;
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        private $message;

        public function __construct($code = '', $message = '') {
            $this->message = $message;
        }

        public function get_error_message() {
            return $this->message;
        }
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) {
        return isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) {
        return isset($response['body']) ? $response['body'] : '';
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) {
        return $value;
    }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook) {
        return false;
    }
}

if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event($timestamp, $recurrence, $hook) {
        // no-op for tests
    }
}

if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook($hook) {
        // no-op for tests
    }
}

if (!function_exists('register_activation_hook')) {
    function register_activation_hook($file, $callback) {
        // no-op for tests
    }
}

if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook($file, $callback) {
        // no-op for tests
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

if (!function_exists('wp_date')) {
    function wp_date($format, $timestamp, $timezone = null) {
        $dt = new DateTimeImmutable('@' . $timestamp);
        if ($timezone instanceof DateTimeZone) {
            $dt = $dt->setTimezone($timezone);
        }

        return $dt->format($format);
    }
}

if (!class_exists('GMS_Database')) {
    class GMS_Database {
        public static function getReservationById($reservation_id) {
            return array(
                'id' => $reservation_id,
                'guest_email' => 'guest@example.com',
                'guest_phone' => '+1234567890',
                'checkout_date' => '2024-07-18 16:00:00',
                'property_name' => 'Ocean View Villa',
                'guest_name' => 'Ada Lovelace',
            );
        }
    }
}

if (!class_exists('GMS_Email_Handler')) {
    class GMS_Email_Handler {
        public function sendReservationApprovedEmail($reservation) {
            // no-op for tests
        }
    }
}

if (!class_exists('GMS_SMS_Handler')) {
    class GMS_SMS_Handler {
        public function sendReservationApprovedSMS($reservation) {
            // no-op for tests
        }
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value) {
        return $value;
    }
}

$wp_rewrite = new class {
    public $front = '';
    public function using_permalinks() {
        return false;
    }
};

require_once __DIR__ . '/../includes/class-housekeeping-integration.php';
require_once __DIR__ . '/../includes/functions.php';

$captured_request = null;

$previous_status = 'confirmed';
$new_status = 'completed';
$reservation_id = 42;

gms_handle_reservation_status_transition($reservation_id, $new_status, $previous_status);

if ($captured_request === null) {
    throw new RuntimeException('Housekeeping request was not dispatched.');
}

$payload = json_decode($captured_request['args']['body'], true);
if (!is_array($payload)) {
    throw new RuntimeException('Unable to decode dispatched payload.');
}

$timezone = new DateTimeZone('America/New_York');
$checkout = new DateTimeImmutable('2024-07-18 16:00:00', $timezone);
$expected_start = $checkout->modify('+30 minutes')->format(DATE_ATOM);
$expected_end = $checkout->modify('+120 minutes')->format(DATE_ATOM);

if (($payload['windowStart'] ?? '') !== $expected_start) {
    throw new RuntimeException('Unexpected windowStart: ' . var_export($payload['windowStart'] ?? null, true));
}

if (($payload['windowEnd'] ?? '') !== $expected_end) {
    throw new RuntimeException('Unexpected windowEnd: ' . var_export($payload['windowEnd'] ?? null, true));
}

if (($captured_request['url'] ?? '') !== 'https://housekeeping.example/api/events') {
    throw new RuntimeException('Unexpected endpoint URL: ' . var_export($captured_request['url'] ?? null, true));
}

$headers = $captured_request['args']['headers'] ?? array();
if (($headers['Authorization'] ?? '') !== 'Bearer token-abc') {
    throw new RuntimeException('Authorization header was not set.');
}

echo "reservation-status-transition-test: OK\n";
