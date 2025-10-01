<?php

date_default_timezone_set('UTC');

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value) {
        if (is_array($value)) {
            return '';
        }

        $value = (string) $value;
        $value = trim($value);

        return preg_replace('/[\r\n\t\0\x0B]/', '', $value);
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        $key = strtolower((string) $key);

        return preg_replace('/[^a-z0-9_\-]/', '', $key);
    }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = array()) {
        return array_merge($defaults, (array) $args);
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) {
        return json_encode($data);
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($value) {
        return $value;
    }
}

if (!function_exists('wp_generate_password')) {
    function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false) {
        return str_repeat('a', max(1, (int) $length));
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit($string) {
        return rtrim((string) $string, '/\\') . '/';
    }
}

if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir() {
        return array('basedir' => '', 'baseurl' => '');
    }
}

class WPDB_Stub {
    public $prefix = 'wp_';
    public $reservations = array();
    public $guests = array();

    public function prepare($query, $args = null) {
        if ($args === null) {
            return $query;
        }

        if (!is_array($args)) {
            $args = array_slice(func_get_args(), 1);
        }

        return array('query' => $query, 'args' => array_values($args));
    }

    public function get_row($prepared, $output_type = ARRAY_A) {
        if (is_array($prepared) && isset($prepared['query'], $prepared['args'])) {
            $query = $prepared['query'];
            $args = $prepared['args'];
        } else {
            $query = (string) $prepared;
            $args = array();
        }

        if (strpos($query, 'gms_reservations') !== false) {
            foreach ($this->reservations as $row) {
                if ($this->matchesPhone($row['guest_phone'] ?? '', $args)) {
                    return $row;
                }
            }
        }

        if (strpos($query, 'gms_guests') !== false) {
            foreach ($this->guests as $row) {
                if ($this->matchesPhone($row['phone'] ?? '', $args)) {
                    return $row;
                }
            }
        }

        return null;
    }

    private function matchesPhone($value, array $params) {
        $raw = (string) $value;
        $digits = preg_replace('/[^0-9]/', '', $raw);
        $trimmed = ltrim($raw, '+');
        $with_plus = $trimmed === '' ? '' : ('+' . $trimmed);

        foreach ($params as $param) {
            if (!is_string($param)) {
                continue;
            }

            if ($param === $raw || ($trimmed !== '' && $param === $trimmed) || ($with_plus !== '' && $param === $with_plus)) {
                return true;
            }

            if (strlen($param) >= 2 && $param[0] === '%' && substr($param, -1) === '%') {
                $needle = substr($param, 1, -1);
                if ($needle !== '' && strpos($digits, $needle) !== false) {
                    return true;
                }
            }
        }

        return false;
    }
}

$wpdb = new WPDB_Stub();
$wpdb->reservations = array(
    array(
        'id' => 42,
        'guest_id' => 7,
        'guest_record_id' => 8,
        'guest_name' => 'Unit Test Guest',
        'guest_phone' => '2223334444',
        'updated_at' => '2024-06-02 12:00:00',
        'checkin_date' => '2024-06-10 15:00:00',
    ),
);
$wpdb->guests = array(
    array(
        'id' => 21,
        'first_name' => 'Unit',
        'last_name' => 'Example',
        'phone' => '2223334444',
        'email' => 'unit@example.com',
        'created_at' => '2024-06-01 00:00:00',
        'updated_at' => '2024-06-02 00:00:00',
    ),
);

$GLOBALS['wpdb'] = $wpdb;

require_once __DIR__ . '/../includes/class-database.php';

$reservation = GMS_Database::findReservationByPhone('+12223334444');
if (!is_array($reservation) || (int) ($reservation['id'] ?? 0) !== 42) {
    throw new RuntimeException('Reservation lookup failed: ' . json_encode($reservation));
}

$guest = GMS_Database::findGuestByPhone('+12223334444');
if (!is_array($guest) || (int) ($guest['id'] ?? 0) !== 21) {
    throw new RuntimeException('Guest lookup failed: ' . json_encode($guest));
}

if (($guest['name'] ?? '') !== 'Unit Example') {
    throw new RuntimeException('Guest name mismatch: ' . json_encode($guest));
}

