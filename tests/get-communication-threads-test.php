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

if (!function_exists('sanitize_email')) {
    function sanitize_email($email) {
        $email = (string) $email;
        $email = trim($email);

        return filter_var($email, FILTER_SANITIZE_EMAIL);
    }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = array()) {
        return array_merge($defaults, (array) $args);
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($value) {
        return $value;
    }
}

if (!function_exists('wp_html_excerpt')) {
    function wp_html_excerpt($text, $length, $more = '') {
        $text = (string) $text;
        if ($length <= 0) {
            return '';
        }

        if (strlen($text) <= $length) {
            return $text;
        }

        return substr($text, 0, $length) . (string) $more;
    }
}

if (!function_exists('__')) {
    function __($text) {
        return $text;
    }
}

class WPDB_Get_Communication_Threads_Stub {
    public $prefix = 'wp_';
    public $communications = array();
    public $reservations = array();
    public $guests = array();

    public function esc_like($text) {
        return addcslashes((string) $text, '_%');
    }

    public function prepare($query, $args = null) {
        if ($args === null) {
            return $query;
        }

        if (!is_array($args)) {
            $args = array_slice(func_get_args(), 1);
        }

        $query = (string) $query;
        $replacements = array();

        foreach ($args as $value) {
            if (is_int($value) || is_float($value)) {
                $replacements[] = $value;
            } else {
                $escaped = str_replace("'", "''", (string) $value);
                $replacements[] = "'{$escaped}'";
            }
        }

        return vsprintf($query, $replacements);
    }

    public function get_var($query) {
        if (strpos($query, 'gms_communications') !== false) {
            return $this->calculateThreadCount();
        }

        return null;
    }

    public function get_results($query, $output_type = ARRAY_A) {
        if (strpos($query, 'gms_communications') !== false) {
            return $this->calculateThreads($query);
        }

        return array();
    }

    private function calculateThreadCount() {
        $threads = array();

        foreach ($this->communications as $row) {
            $thread_key = $row['thread_key'] ?? '';
            if ($thread_key === '') {
                continue;
            }

            $threads[$thread_key] = true;
        }

        return count($threads);
    }

    private function calculateThreads($query) {
        $threads = array();

        foreach ($this->communications as $row) {
            $thread_key = $row['thread_key'] ?? '';
            if ($thread_key === '') {
                continue;
            }

            if (!isset($threads[$thread_key])) {
                $threads[$thread_key] = array();
            }

            $threads[$thread_key][] = $row;
        }

        $results = array();

        foreach ($threads as $thread_key => $messages) {
            usort($messages, static function ($a, $b) {
                $sentA = $a['sent_at'] ?? '';
                $sentB = $b['sent_at'] ?? '';
                if ($sentA === $sentB) {
                    return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
                }

                return $sentA <=> $sentB;
            });

            $canonical = end($messages);
            $canonical_id = (int) ($canonical['id'] ?? 0);
            $reservation = $this->findReservation($canonical['reservation_id'] ?? 0);
            $guest = $this->findGuest($canonical['guest_id'] ?? 0);

            $guest_name = '';
            $guest_email = '';
            $guest_phone = '';

            if ($guest) {
                $guest_name = trim(trim(($guest['first_name'] ?? '') . ' ' . ($guest['last_name'] ?? '')));
                if ($guest_name === '') {
                    $guest_name = $guest['name'] ?? '';
                }
                $guest_email = $guest['email'] ?? '';
                $guest_phone = $guest['phone'] ?? '';
            }

            if ($guest_name === '' && $reservation) {
                $guest_name = $reservation['guest_name'] ?? '';
            }

            if ($guest_email === '' && $reservation) {
                $guest_email = $reservation['guest_email'] ?? '';
            }

            if ($guest_phone === '' && $reservation) {
                $guest_phone = $reservation['guest_phone'] ?? '';
            }

            $unread_count = 0;
            foreach ($messages as $message) {
                if (($message['direction'] ?? '') === 'inbound' && self::isUnread($message)) {
                    $unread_count++;
                }
            }

            $service_number = ($canonical['direction'] ?? '') === 'outbound'
                ? ($canonical['from_number'] ?? '')
                : ($canonical['to_number'] ?? '');
            $service_number_e164 = ($canonical['direction'] ?? '') === 'outbound'
                ? ($canonical['from_number_e164'] ?? '')
                : ($canonical['to_number_e164'] ?? '');
            $guest_number = ($canonical['direction'] ?? '') === 'outbound'
                ? ($canonical['to_number'] ?? '')
                : ($canonical['from_number'] ?? '');
            $guest_number_e164 = ($canonical['direction'] ?? '') === 'outbound'
                ? ($canonical['to_number_e164'] ?? '')
                : ($canonical['from_number_e164'] ?? '');

            $results[] = array(
                'thread_key' => $thread_key,
                'channel' => $canonical['channel'] ?? '',
                'reservation_id' => $canonical['reservation_id'] ?? 0,
                'guest_id' => $canonical['guest_id'] ?? 0,
                'property_name' => $reservation['property_name'] ?? '',
                'reservation_guest_name' => $reservation['guest_name'] ?? '',
                'reservation_guest_email' => $reservation['guest_email'] ?? '',
                'reservation_guest_phone' => $reservation['guest_phone'] ?? '',
                'guest_name' => $guest_name,
                'guest_email' => $guest_email,
                'guest_phone' => $guest_phone,
                'unread_count' => $unread_count,
                'last_message_at' => $canonical['sent_at'] ?? '',
                'last_message' => $canonical['message'] ?? '',
                'service_number' => $service_number,
                'service_number_e164' => $service_number_e164,
                'guest_number' => $guest_number,
                'guest_number_e164' => $guest_number_e164,
                'direction' => $canonical['direction'] ?? '',
                '_canonical_id' => $canonical_id,
            );
        }

        usort($results, static function ($a, $b) {
            $dateA = $a['last_message_at'] ?? '';
            $dateB = $b['last_message_at'] ?? '';
            if ($dateA === $dateB) {
                return ($b['_canonical_id'] ?? 0) <=> ($a['_canonical_id'] ?? 0);
            }

            return $dateB <=> $dateA;
        });

        if (preg_match('/LIMIT\s+(\d+)\s+OFFSET\s+(\d+)/i', $query, $matches)) {
            $limit = (int) $matches[1];
            $offset = (int) $matches[2];
            $results = array_slice($results, $offset, $limit);
        }

        foreach ($results as &$row) {
            unset($row['_canonical_id']);
        }

        return $results;
    }

    private static function isUnread($message) {
        if (($message['direction'] ?? '') !== 'inbound') {
            return false;
        }

        $read_at = $message['read_at'] ?? '';
        if ($read_at === null) {
            return true;
        }

        $read_at = (string) $read_at;
        $read_at = trim($read_at);

        return $read_at === '' || $read_at === '0000-00-00 00:00:00';
    }

    private function findReservation($reservation_id) {
        $reservation_id = (int) $reservation_id;

        foreach ($this->reservations as $row) {
            if ((int) ($row['id'] ?? 0) === $reservation_id) {
                return $row;
            }
        }

        return null;
    }

    private function findGuest($guest_id) {
        $guest_id = (int) $guest_id;

        foreach ($this->guests as $row) {
            if ((int) ($row['id'] ?? 0) === $guest_id) {
                return $row;
            }
        }

        return null;
    }
}

$wpdb = new WPDB_Get_Communication_Threads_Stub();
$wpdb->reservations = array(
    array(
        'id' => 501,
        'guest_id' => 1201,
        'guest_record_id' => 902,
        'guest_name' => 'Legacy Guest',
        'guest_email' => 'legacy@example.com',
        'guest_phone' => '+15551234567',
        'property_name' => 'Unit Test Property',
    ),
);
$wpdb->guests = array(
    array(
        'id' => 902,
        'first_name' => 'Canonical',
        'last_name' => 'Guest',
        'email' => 'canonical@example.com',
        'phone' => '+15557654321',
        'wp_user_id' => 1201,
    ),
);
$wpdb->communications = array(
    array(
        'id' => 10,
        'thread_key' => 'thread-1',
        'reservation_id' => 501,
        'guest_id' => 1201,
        'channel' => 'sms',
        'direction' => 'outbound',
        'from_number' => '+15550001111',
        'from_number_e164' => '+15550001111',
        'to_number' => '+15551234567',
        'to_number_e164' => '+15551234567',
        'message' => 'Legacy message',
        'sent_at' => '2024-06-01 12:00:00',
        'read_at' => '2024-06-01 12:05:00',
    ),
    array(
        'id' => 11,
        'thread_key' => 'thread-1',
        'reservation_id' => 501,
        'guest_id' => 902,
        'channel' => 'sms',
        'direction' => 'inbound',
        'from_number' => '+15557654321',
        'from_number_e164' => '+15557654321',
        'to_number' => '+15550001111',
        'to_number_e164' => '+15550001111',
        'message' => 'New guest message',
        'sent_at' => '2024-06-02 08:00:00',
        'read_at' => null,
    ),
);

$GLOBALS['wpdb'] = $wpdb;

require_once __DIR__ . '/../includes/class-database.php';

$result = GMS_Database::getCommunicationThreads();
if (!is_array($result) || ($result['total'] ?? 0) !== 1) {
    throw new RuntimeException('Unexpected total: ' . json_encode($result));
}

$item = $result['items'][0] ?? null;
if (!is_array($item)) {
    throw new RuntimeException('Missing thread item: ' . json_encode($result));
}

if ((int) ($item['guest_id'] ?? 0) !== 902) {
    throw new RuntimeException('Guest ID mismatch: ' . json_encode($item));
}

if (($item['guest_name'] ?? '') !== 'Canonical Guest') {
    throw new RuntimeException('Guest name mismatch: ' . json_encode($item));
}

if (($item['guest_email'] ?? '') !== 'canonical@example.com') {
    throw new RuntimeException('Guest email mismatch: ' . json_encode($item));
}

if (($item['guest_phone'] ?? '') !== '+15557654321') {
    throw new RuntimeException('Guest phone mismatch: ' . json_encode($item));
}

if (($item['reservation_guest_name'] ?? '') !== 'Legacy Guest') {
    throw new RuntimeException('Reservation guest mismatch: ' . json_encode($item));
}

if ((int) ($item['unread_count'] ?? 0) !== 1) {
    throw new RuntimeException('Unread count mismatch: ' . json_encode($item));
}

