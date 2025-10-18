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
                        $row['name'] = trim(($row['name'] ?? '') !== '' ? $row['name'] : trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
                        $results[] = $row;
                        break;
                    }
                }
            } elseif (strpos($query, 'WHERE id = %d') !== false) {
                $target = (int) ($args[0] ?? 0);
                foreach ($this->guests as $row) {
                    if ((int) ($row['id'] ?? 0) === $target) {
                        $row['name'] = trim(($row['name'] ?? '') !== '' ? $row['name'] : trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
                        $results[] = $row;
                        break;
                    }
                }
            } else {
                foreach ($this->guests as $row) {
                    if ($this->matchesPhone($row['phone'] ?? '', $args)) {
                        $row['name'] = trim(($row['name'] ?? '') !== '' ? $row['name'] : trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
                        $results[] = $row;
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

        return array();
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

if (($context['status'] ?? '') !== 'matched') {
    throw new RuntimeException('Expected context status to be matched: ' . json_encode($context));
}

if (($context['matched'] ?? false) !== true) {
    throw new RuntimeException('Expected context to be matched.');
}
