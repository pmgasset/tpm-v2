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

if (!function_exists('substr_compare')) {
    function substr_compare($main_str, $str, $offset, $length = null, $case_insensitivity = false) {
        $main_sub = $length !== null ? substr($main_str, $offset, $length) : substr($main_str, $offset);
        if ($case_insensitivity) {
            $main_sub = strtolower($main_sub);
            $str = strtolower($str);
        }

        return strcmp($main_sub, $str);
    }
}

class WPDB_Phone_Ambiguity_Stub {
    public $prefix = 'wp_';
    public $reservations = array();
    public $guests = array();
    public $communications = array();

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
        $results = $this->get_results($prepared, $output_type);

        return $results[0] ?? null;
    }

    public function get_var($prepared) {
        list($query, $args) = $this->parsePrepared($prepared);

        if (strpos($query, 'COUNT(DISTINCT') !== false) {
            $source = array();
            $column = '';

            if (strpos($query, 'gms_reservations') !== false) {
                $source = $this->reservations;
                $column = 'guest_phone';
            } elseif (strpos($query, 'gms_guests') !== false) {
                $source = $this->guests;
                $column = 'phone';
            }

            if ($column !== '') {
                $unique = array();

                foreach ($source as $row) {
                    if ($this->matchesPhone($row[$column] ?? '', $args)) {
                        $digits = preg_replace('/[^0-9]/', '', (string) ($row[$column] ?? ''));
                        $unique[$digits] = true;
                    }
                }

                return count($unique);
            }
        }

        return null;
    }

    public function get_results($prepared, $output_type = ARRAY_A) {
        list($query, $args) = $this->parsePrepared($prepared);

        if (strpos($query, 'gms_reservations') !== false) {
            $results = array();

            if (strpos($query, 'WHERE id = %d') !== false) {
                $target = (int) ($args[0] ?? 0);
                foreach ($this->reservations as $row) {
                    if ((int) ($row['id'] ?? 0) === $target) {
                        $results[] = $row;
                        break;
                    }
                }
            } else {
                foreach ($this->reservations as $row) {
                    if ($this->matchesPhone($row['guest_phone'] ?? '', $args)) {
                        $results[] = $row;
                    }
                }

                usort($results, static function ($a, $b) {
                    $a_updated = $a['updated_at'] ?? '';
                    $b_updated = $b['updated_at'] ?? '';

                    if ($a_updated !== $b_updated) {
                        return strcmp($b_updated, $a_updated);
                    }

                    $a_checkin = $a['checkin_date'] ?? '';
                    $b_checkin = $b['checkin_date'] ?? '';

                    return strcmp($b_checkin, $a_checkin);
                });
            }

            return $results;
        }

        if (strpos($query, 'gms_guests') !== false) {
            $results = array();

            if (strpos($query, 'wp_user_id') !== false) {
                $target = (int) ($args[0] ?? 0);
                foreach ($this->guests as $row) {
                    if ((int) ($row['wp_user_id'] ?? 0) === $target) {
                        $results[] = $this->formatGuestRow($row);
                        break;
                    }
                }
            } elseif (strpos($query, 'WHERE id = %d') !== false) {
                $target = (int) ($args[0] ?? 0);
                foreach ($this->guests as $row) {
                    if ((int) ($row['id'] ?? 0) === $target) {
                        $results[] = $this->formatGuestRow($row);
                        break;
                    }
                }
            } else {
                foreach ($this->guests as $row) {
                    if ($this->matchesPhone($row['phone'] ?? '', $args)) {
                        $results[] = $this->formatGuestRow($row);
                    }
                }

                usort($results, static function ($a, $b) {
                    $a_updated = $a['updated_at'] ?? '';
                    $b_updated = $b['updated_at'] ?? '';

                    if ($a_updated !== $b_updated) {
                        return strcmp($b_updated, $a_updated);
                    }

                    $a_created = $a['created_at'] ?? '';
                    $b_created = $b['created_at'] ?? '';

                    return strcmp($b_created, $a_created);
                });
            }

            return $results;
        }

        if (strpos($query, 'gms_communications') !== false) {
            return array();
        }

        return array();
    }

    private function formatGuestRow(array $row) {
        if (!isset($row['name']) || trim($row['name']) === '') {
            $row['name'] = trim(trim($row['first_name'] ?? '') . ' ' . trim($row['last_name'] ?? ''));
        }

        return $row;
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

            if (
                $param === $raw
                || ($trimmed !== '' && $param === $trimmed)
                || ($with_plus !== '' && $param === $with_plus)
                || ($digits !== '' && $param === $digits)
            ) {
                return true;
            }

            if (strlen($param) >= 1 && $param[0] === '%') {
                if (substr($param, -1) === '%') {
                    $needle = substr($param, 1, -1);
                    if ($needle !== '' && strpos($digits, $needle) !== false) {
                        return true;
                    }
                } else {
                    $needle = substr($param, 1);
                    if ($needle !== '' && substr_compare($digits, $needle, -strlen($needle)) === 0) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function parsePrepared($prepared) {
        if (is_array($prepared) && isset($prepared['query'], $prepared['args'])) {
            return array($prepared['query'], $prepared['args']);
        }

        return array((string) $prepared, array());
    }
}

$wpdb = new WPDB_Phone_Ambiguity_Stub();
$wpdb->reservations = array(
    array(
        'id' => 701,
        'guest_id' => 9001,
        'guest_record_id' => 8001,
        'guest_name' => 'North America Guest',
        'guest_phone' => '1 (123) 456-7890',
        'updated_at' => '2024-06-10 12:00:00',
        'checkin_date' => '2024-06-15 15:00:00',
    ),
    array(
        'id' => 702,
        'guest_id' => 9002,
        'guest_record_id' => 8002,
        'guest_name' => 'UK Guest',
        'guest_phone' => '+441234567890',
        'updated_at' => '2024-06-11 12:00:00',
        'checkin_date' => '2024-06-16 15:00:00',
    ),
);
$wpdb->guests = array(
    array(
        'id' => 8001,
        'first_name' => 'North',
        'last_name' => 'America',
        'email' => 'north@example.com',
        'phone' => '1 (123) 456-7890',
        'wp_user_id' => 9001,
        'created_at' => '2024-05-01 00:00:00',
        'updated_at' => '2024-06-01 00:00:00',
    ),
    array(
        'id' => 8002,
        'first_name' => 'United',
        'last_name' => 'Kingdom',
        'email' => 'uk@example.com',
        'phone' => '+441234567890',
        'wp_user_id' => 9002,
        'created_at' => '2024-05-02 00:00:00',
        'updated_at' => '2024-06-02 00:00:00',
    ),
);

$GLOBALS['wpdb'] = $wpdb;

require_once __DIR__ . '/../includes/class-database.php';

$us_reservation = GMS_Database::findReservationByPhone('+11234567890');
if ((int) ($us_reservation['id'] ?? 0) !== 701) {
    throw new RuntimeException('US reservation lookup mismatch: ' . json_encode($us_reservation));
}

$us_guest = GMS_Database::findGuestByPhone('+11234567890');
if ((int) ($us_guest['id'] ?? 0) !== 8001) {
    throw new RuntimeException('US guest lookup mismatch: ' . json_encode($us_guest));
}

$uk_reservation = GMS_Database::findReservationByPhone('+441234567890');
if ((int) ($uk_reservation['id'] ?? 0) !== 702) {
    throw new RuntimeException('UK reservation lookup mismatch: ' . json_encode($uk_reservation));
}

$uk_guest = GMS_Database::findGuestByPhone('+441234567890');
if ((int) ($uk_guest['id'] ?? 0) !== 8002) {
    throw new RuntimeException('UK guest lookup mismatch: ' . json_encode($uk_guest));
}

$us_context = GMS_Database::resolveMessageContext('sms', '+11234567890', '+15550001111', 'inbound');
if ((int) ($us_context['reservation_id'] ?? 0) !== 701) {
    throw new RuntimeException('US reservation mismatch: ' . json_encode($us_context));
}

if ((int) ($us_context['guest_id'] ?? 0) !== 8001) {
    throw new RuntimeException('US guest mismatch: ' . json_encode($us_context));
}

if (($us_context['status'] ?? '') !== 'matched' || empty($us_context['matched'])) {
    throw new RuntimeException('US context should be matched: ' . json_encode($us_context));
}

$uk_context = GMS_Database::resolveMessageContext('sms', '+441234567890', '+15550001111', 'inbound');
if ((int) ($uk_context['reservation_id'] ?? 0) !== 702) {
    throw new RuntimeException('UK reservation mismatch: ' . json_encode($uk_context));
}

if ((int) ($uk_context['guest_id'] ?? 0) !== 8002) {
    throw new RuntimeException('UK guest mismatch: ' . json_encode($uk_context));
}

if (($uk_context['status'] ?? '') !== 'matched' || empty($uk_context['matched'])) {
    throw new RuntimeException('UK context should be matched: ' . json_encode($uk_context));
}

if (GMS_Database::findReservationByPhone('1234567890') !== null) {
    throw new RuntimeException('Ambiguous reservation lookup should return null.');
}

if (GMS_Database::findGuestByPhone('1234567890') !== null) {
    throw new RuntimeException('Ambiguous guest lookup should return null.');
}

echo "phone-ambiguity-resolution-test: OK\n";
