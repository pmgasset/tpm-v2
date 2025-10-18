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

if (!function_exists('current_time')) {
    function current_time($type, $gmt = false) {
        if ($type === 'mysql') {
            return gmdate('Y-m-d H:i:s');
        }

        return time();
    }
}

class WPDB_Resolve_Message_Context_Stub {
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
            if (strpos($query, 'WHERE id = %d') !== false) {
                $reservation_id = (int) ($args[0] ?? 0);
                foreach ($this->reservations as $row) {
                    if ((int) ($row['id'] ?? 0) === $reservation_id) {
                        return $row;
                    }
                }
            }

            foreach ($this->reservations as $row) {
                if ($this->matchesPhone($row['guest_phone'] ?? '', $args)) {
                    return $row;
                }
            }
        }

        if (strpos($query, 'gms_guests') !== false) {
            if (strpos($query, 'wp_user_id') !== false) {
                $wp_user_id = (int) ($args[0] ?? 0);
                foreach ($this->guests as $row) {
                    if ((int) ($row['wp_user_id'] ?? 0) === $wp_user_id) {
                        $row['name'] = trim(($row['name'] ?? '') !== '' ? $row['name'] : trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));

                        return $row;
                    }
                }
            }

            if (strpos($query, 'WHERE id = %d') !== false) {
                $guest_id = (int) ($args[0] ?? 0);
                foreach ($this->guests as $row) {
                    if ((int) ($row['id'] ?? 0) === $guest_id) {
                        $row['name'] = trim(($row['name'] ?? '') !== '' ? $row['name'] : trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));

                        return $row;
                    }
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

$wpdb = new WPDB_Resolve_Message_Context_Stub();
$wpdb->reservations = array(
    array(
        'id' => 501,
        'guest_id' => 1201,
        'guest_record_id' => 0,
        'guest_name' => 'Legacy Guest',
        'guest_phone' => '+15551234567',
        'updated_at' => '2024-06-02 10:00:00',
        'checkin_date' => '2024-06-05 16:00:00',
    ),
);
$wpdb->guests = array(
    array(
        'id' => 902,
        'first_name' => 'Resolved',
        'last_name' => 'Guest',
        'email' => 'resolved@example.com',
        'phone' => '+15551234567',
        'wp_user_id' => 1201,
        'created_at' => '2024-05-30 00:00:00',
        'updated_at' => '2024-06-01 00:00:00',
    ),
);

$GLOBALS['wpdb'] = $wpdb;

require_once __DIR__ . '/../includes/class-database.php';

$context = GMS_Database::resolveMessageContext('sms', '+1 (555) 123-4567', '+1 (555) 888-0000', 'inbound');

if ((int) ($context['reservation_id'] ?? 0) !== 501) {
    throw new RuntimeException('Expected reservation_id 501, got: ' . json_encode($context));
}

if ((int) ($context['guest_id'] ?? 0) !== 902) {
    throw new RuntimeException('Expected guest_id 902, got: ' . json_encode($context));
}

if ((int) ($context['guest']['id'] ?? 0) !== 902) {
    throw new RuntimeException('Guest profile not attached: ' . json_encode($context));
}

$thread_key = (string) ($context['thread_key'] ?? '');
if (strpos($thread_key, 'guest:902') === false || strpos($thread_key, 'reservation:501') === false) {
    throw new RuntimeException('Thread key missing identifiers: ' . $thread_key);
}

if (($context['matched'] ?? false) !== true) {
    throw new RuntimeException('Expected context to be matched.');
}
