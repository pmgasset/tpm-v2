<?php

date_default_timezone_set('UTC');

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/wp-stubs/');
}

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) {
        return $value;
    }
}

if (!function_exists('get_option')) {
    function get_option($name, $default = null) {
        global $gms_test_options;

        return array_key_exists($name, $gms_test_options) ? $gms_test_options[$name] : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($name, $value) {
        global $gms_test_options;

        $gms_test_options[$name] = $value;

        return true;
    }
}

if (!function_exists('add_option')) {
    function add_option($name, $value) {
        global $gms_test_options;

        if (!array_key_exists($name, $gms_test_options)) {
            $gms_test_options[$name] = $value;

            return true;
        }

        return false;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($name) {
        global $gms_test_options;

        if (array_key_exists($name, $gms_test_options)) {
            unset($gms_test_options[$name]);
        }

        return true;
    }
}

if (!function_exists('sanitize_email')) {
    function sanitize_email($email) {
        return trim((string) $email);
    }
}

if (!function_exists('wp_kses_post')) {
    function wp_kses_post($value) {
        return $value;
    }
}

if (!function_exists('wp_html_excerpt')) {
    function wp_html_excerpt($text, $length, $more = '') {
        $text = (string) $text;
        $length = max(0, (int) $length);

        if (function_exists('mb_substr')) {
            $slice = mb_substr($text, 0, $length);
        } else {
            $slice = substr($text, 0, $length);
        }

        if ($slice === $text) {
            return $slice;
        }

        return $slice . (string) $more;
    }
}

if (!function_exists('__')) {
    function __($text, $domain = null) {
        return $text;
    }
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

$gms_test_options = array(
    'gms_db_version' => '1.5.0',
    'gms_voipms_did' => '+15550001111',
);

class WPDB_Phone_Ambiguity_Stub {
    public $prefix = 'wp_';
    public $reservations = array();
    public $guests = array();
    public $communications = array();
    public $existing_tables = array();

    public function __construct() {
        $this->existing_tables = array(
            $this->prefix . 'gms_reservations' => true,
            $this->prefix . 'gms_guests' => true,
            $this->prefix . 'gms_communications' => true,
        );
    }

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

        if (stripos($query, 'SHOW TABLES LIKE') !== false) {
            $needle = (string) ($args[0] ?? '');

            foreach ($this->existing_tables as $table => $exists) {
                if (!$exists) {
                    continue;
                }

                if ($needle === $table || $needle === $this->esc_like($table)) {
                    return $table;
                }
            }

            return null;
        }

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
            if (stripos($query, 'SHOW INDEX') !== false) {
                return array();
            }

            if (stripos($query, 'WHERE id > %d') !== false) {
                $min_id = (int) ($args[0] ?? 0);
                $limit = max(0, (int) ($args[1] ?? 0));

                $results = array();
                foreach ($this->communications as $row) {
                    if ((int) ($row['id'] ?? 0) > $min_id) {
                        $results[] = $row;
                    }
                }

                usort($results, static function ($a, $b) {
                    return (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0);
                });

                if ($limit > 0 && count($results) > $limit) {
                    $results = array_slice($results, 0, $limit);
                }

                return $results;
            }

            if (stripos($query, 'ORDER BY sent_at DESC') !== false) {
                $channel = (string) ($args[0] ?? '');
                $guest_number = (string) ($args[1] ?? '');
                $service_number = (string) ($args[2] ?? '');
                $service_alt = (string) ($args[3] ?? '');
                $guest_alt = (string) ($args[4] ?? '');

                $matches = array();
                foreach ($this->communications as $row) {
                    if (($row['channel'] ?? '') !== $channel) {
                        continue;
                    }

                    $from = (string) ($row['from_number_e164'] ?? '');
                    $to = (string) ($row['to_number_e164'] ?? '');

                    $pair_matches = ($from === $guest_number && $to === $service_number) || ($from === $service_alt && $to === $guest_alt);

                    if ($pair_matches) {
                        $matches[] = array(
                            'reservation_id' => $row['reservation_id'] ?? 0,
                            'guest_id' => $row['guest_id'] ?? 0,
                            'thread_key' => $row['thread_key'] ?? '',
                            'sent_at' => $row['sent_at'] ?? '',
                            'id' => $row['id'] ?? 0,
                        );
                    }
                }

                usort($matches, static function ($a, $b) {
                    $sent_cmp = strcmp((string) ($b['sent_at'] ?? ''), (string) ($a['sent_at'] ?? ''));
                    if ($sent_cmp !== 0) {
                        return $sent_cmp;
                    }

                    return (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0);
                });

                return array_map(static function ($row) {
                    return array(
                        'reservation_id' => $row['reservation_id'],
                        'guest_id' => $row['guest_id'],
                        'thread_key' => $row['thread_key'],
                    );
                }, array_slice($matches, 0, 1));
            }

            if (stripos($query, 'from_number_e164') !== false && stripos($query, 'to_number_e164') !== false && stripos($query, 'channel =') !== false) {
                $channel = (string) ($args[0] ?? '');
                $limit = max(0, (int) ($args[1] ?? 0));

                $results = array();
                foreach ($this->communications as $row) {
                    if ($channel !== '' && ($row['channel'] ?? '') !== $channel) {
                        continue;
                    }

                    $needs_from = ($row['from_number'] ?? '') !== '' && (($row['from_number_e164'] ?? '') === '' || $row['from_number_e164'] === null);
                    $needs_to = ($row['to_number'] ?? '') !== '' && (($row['to_number_e164'] ?? '') === '' || $row['to_number_e164'] === null);

                    if ($needs_from || $needs_to) {
                        $results[] = $row;
                    }
                }

                if ($limit > 0 && count($results) > $limit) {
                    $results = array_slice($results, 0, $limit);
                }

                return $results;
            }

            if (stripos($query, 'thread_key') !== false && stripos($query, 'LIMIT %d') !== false) {
                $limit = max(0, (int) ($args[0] ?? 0));

                $results = array();
                foreach ($this->communications as $row) {
                    $thread = (string) ($row['thread_key'] ?? '');
                    if ($thread === '') {
                        $results[] = $row;
                    }
                }

                if ($limit > 0 && count($results) > $limit) {
                    $results = array_slice($results, 0, $limit);
                }

                return $results;
            }

            return $this->communications;
        }

        return array();
    }

    public function update($table, $data, $where, $format = null, $where_format = null) {
        if (isset($where['id'])) {
            $id = (int) $where['id'];

            if ($table === $this->prefix . 'gms_communications') {
                foreach ($this->communications as $index => $row) {
                    if ((int) ($row['id'] ?? 0) === $id) {
                        foreach ($data as $key => $value) {
                            $this->communications[$index][$key] = $value;
                        }

                        return 1;
                    }
                }
            }

            if ($table === $this->prefix . 'gms_reservations') {
                foreach ($this->reservations as $index => $row) {
                    if ((int) ($row['id'] ?? 0) === $id) {
                        foreach ($data as $key => $value) {
                            $this->reservations[$index][$key] = $value;
                        }

                        return 1;
                    }
                }
            }
        }

        return 0;
    }

    public function query($sql) {
        return 0;
    }

    public function get_charset_collate() {
        return 'DEFAULT CHARSET utf8mb4';
    }

    public function esc_like($text) {
        return addcslashes((string) $text, '_%');
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

            if ($param === $raw || ($trimmed !== '' && $param === $trimmed) || ($with_plus !== '' && $param === $with_plus)) {
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
        'guest_phone' => '+11234567890',
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
        'phone' => '+11234567890',
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
$wpdb->communications = array(
    array(
        'id' => 9001,
        'reservation_id' => 0,
        'guest_id' => 0,
        'channel' => 'sms',
        'direction' => 'inbound',
        'from_number' => '+1 (123) 456-7890',
        'to_number' => '',
        'from_number_e164' => '',
        'to_number_e164' => '',
        'recipient' => '+15550001111',
        'thread_key' => '',
        'response_data' => json_encode(array(
            'provider' => 'legacy',
            'context' => array(
                'matched' => false,
                'status' => 'unmatched',
            ),
        )),
        'sent_at' => '2024-06-12 12:00:00',
    ),
);

$GLOBALS['wpdb'] = $wpdb;

require_once __DIR__ . '/../includes/class-database.php';

GMS_Database::maybeRunMigrations();

$recalculated = $wpdb->communications[0];
if ((int) ($recalculated['reservation_id'] ?? 0) !== 701) {
    throw new RuntimeException('Recalculated reservation mismatch: ' . json_encode($recalculated));
}

if ((int) ($recalculated['guest_id'] ?? 0) !== 8001) {
    throw new RuntimeException('Recalculated guest mismatch: ' . json_encode($recalculated));
}

$thread_key = (string) ($recalculated['thread_key'] ?? '');
if ($thread_key === '') {
    throw new RuntimeException('Recalculated thread key missing: ' . json_encode($recalculated));
}

if ((string) ($recalculated['from_number_e164'] ?? '') !== '+11234567890') {
    throw new RuntimeException('From number normalization failed: ' . json_encode($recalculated));
}

if ((string) ($recalculated['to_number_e164'] ?? '') !== '+15550001111') {
    throw new RuntimeException('To number normalization failed: ' . json_encode($recalculated));
}

$response_payload = json_decode((string) ($recalculated['response_data'] ?? ''), true);
if (!is_array($response_payload) || !isset($response_payload['context'])) {
    throw new RuntimeException('Response context missing: ' . json_encode($recalculated));
}

$reprocessed_context = $response_payload['context'];
if (empty($reprocessed_context['matched'])) {
    throw new RuntimeException('Reprocessed context should be matched: ' . json_encode($reprocessed_context));
}

if (($reprocessed_context['status'] ?? '') !== 'matched') {
    throw new RuntimeException('Reprocessed context status mismatch: ' . json_encode($reprocessed_context));
}

if (($reprocessed_context['guest_number'] ?? '') !== '+11234567890') {
    throw new RuntimeException('Reprocessed guest number mismatch: ' . json_encode($reprocessed_context));
}

if (($reprocessed_context['service_number'] ?? '') !== '+15550001111') {
    throw new RuntimeException('Reprocessed service number mismatch: ' . json_encode($reprocessed_context));
}

if (($gms_test_options[GMS_Database::OPTION_DB_VERSION] ?? '') !== GMS_Database::DB_VERSION) {
    throw new RuntimeException('Database version option was not updated.');
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
