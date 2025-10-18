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
            if (preg_match('/FROM\s*\(\s*SELECT/i', $query)) {
                return $this->calculateThreadCount($query);
            }

            return $this->calculateLogCount($query);
        }

        return null;
    }

    public function get_results($query, $output_type = ARRAY_A) {
        if (strpos($query, 'gms_communications') === false) {
            return array();
        }

        if (preg_match('/FROM\s*\(\s*SELECT/i', $query)) {
            return $this->calculateThreads($query);
        }

        return $this->calculateLogs($query);
    }

    private function extractChannelFilter($query, $defaultChannels = array(), $defaultNegate = false) {
        $channels = array();
        $negate = $defaultNegate;

        if (preg_match('/channel\s+(NOT\s+)?IN\s*\(([^)]+)\)/i', $query, $matches)) {
            $negate = trim($matches[1] ?? '') !== '';
            $raw = explode(',', $matches[2]);
            foreach ($raw as $value) {
                $normalized = sanitize_key(trim($value, "' \""));
                if ($normalized !== '') {
                    $channels[$normalized] = $normalized;
                }
            }
        } else {
            foreach ((array) $defaultChannels as $value) {
                $normalized = sanitize_key($value);
                if ($normalized !== '') {
                    $channels[$normalized] = $normalized;
                }
            }
        }

        return array(array_values($channels), $negate);
    }

    private function filterRowsByChannels($rows, $channels, $negate) {
        if (empty($channels)) {
            return $rows;
        }

        $allowed = array_flip($channels);
        $filtered = array();

        foreach ($rows as $row) {
            $channel = sanitize_key($row['channel'] ?? '');
            $in = isset($allowed[$channel]);

            if (($negate && !$in) || (!$negate && $in)) {
                $filtered[] = $row;
            }
        }

        return $filtered;
    }

    private function parseLimitOffset($query) {
        $limit = 0;
        $offset = 0;

        if (preg_match('/LIMIT\s+(\d+)/i', $query, $limit_match)) {
            $limit = (int) $limit_match[1];
        }

        if (preg_match('/OFFSET\s+(\d+)/i', $query, $offset_match)) {
            $offset = (int) $offset_match[1];
        }

        return array($limit, $offset);
    }

    private function calculateThreadCount($query) {
        list($channels, $negate) = $this->extractChannelFilter($query, GMS_Database::getConversationalChannels(), false);

        $threads = array();

        foreach ($this->communications as $row) {
            $thread_key = $row['thread_key'] ?? '';
            if ($thread_key === '') {
                continue;
            }

            $channel = sanitize_key($row['channel'] ?? '');
            $in_channels = empty($channels) || in_array($channel, $channels, true);
            if (($negate && $in_channels) || (!$negate && !$in_channels)) {
                continue;
            }

            $threads[$thread_key] = true;
        }

        return count($threads);
    }

    private function calculateThreads($query) {
        list($channels, $negate) = $this->extractChannelFilter($query, GMS_Database::getConversationalChannels(), false);

        $threads = array();

        foreach ($this->communications as $row) {
            $thread_key = $row['thread_key'] ?? '';
            if ($thread_key === '') {
                continue;
            }

            $channel = sanitize_key($row['channel'] ?? '');
            $in_channels = empty($channels) || in_array($channel, $channels, true);
            if (($negate && $in_channels) || (!$negate && !$in_channels)) {
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

        list($limit, $offset) = $this->parseLimitOffset($query);
        if ($limit > 0) {
            $results = array_slice($results, $offset, $limit);
        }

        foreach ($results as &$row) {
            unset($row['_canonical_id']);
        }

        return $results;
    }

    private function calculateLogCount($query) {
        list($channels, $negate) = $this->extractChannelFilter($query, GMS_Database::getConversationalChannels(), true);

        $count = 0;
        $conversational = array_flip(GMS_Database::getConversationalChannels());
        foreach ($this->communications as $row) {
            $channel = sanitize_key($row['channel'] ?? '');
            if ($channel === '') {
                continue;
            }

            if (isset($conversational[$channel])) {
                continue;
            }

            $in_channels = empty($channels) || in_array($channel, $channels, true);
            if (($negate && $in_channels) || (!$negate && !$in_channels)) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    private function calculateLogs($query) {
        list($channels, $negate) = $this->extractChannelFilter($query, GMS_Database::getConversationalChannels(), true);

        $rows = array();
        $conversational = array_flip(GMS_Database::getConversationalChannels());

        foreach ($this->communications as $row) {
            $channel = sanitize_key($row['channel'] ?? '');
            if ($channel === '') {
                continue;
            }

            if (isset($conversational[$channel])) {
                continue;
            }

            $in_channels = empty($channels) || in_array($channel, $channels, true);
            if (($negate && $in_channels) || (!$negate && !$in_channels)) {
                continue;
            }

            $rows[] = $this->buildLogRow($row);
        }

        usort($rows, static function ($a, $b) {
            $dateA = $a['sent_at'] ?? '';
            $dateB = $b['sent_at'] ?? '';
            if ($dateA === $dateB) {
                return ($b['id'] ?? 0) <=> ($a['id'] ?? 0);
            }

            return $dateB <=> $dateA;
        });

        list($limit, $offset) = $this->parseLimitOffset($query);
        if ($limit > 0) {
            $rows = array_slice($rows, $offset, $limit);
        }

        return $rows;
    }

    private function buildLogRow($row) {
        $reservation = $this->findReservation($row['reservation_id'] ?? 0);
        $guest = $this->findGuest($row['guest_id'] ?? 0);

        if ($reservation) {
            $row['property_name'] = $reservation['property_name'] ?? '';
            $row['reservation_guest_name'] = $reservation['guest_name'] ?? '';
            $row['reservation_guest_email'] = $reservation['guest_email'] ?? '';
            $row['reservation_guest_phone'] = $reservation['guest_phone'] ?? '';
            $row['booking_reference'] = $reservation['booking_reference'] ?? '';
        }

        if ($guest) {
            $row['guest_name'] = isset($row['guest_name']) && $row['guest_name'] !== ''
                ? $row['guest_name']
                : trim(trim(($guest['first_name'] ?? '') . ' ' . ($guest['last_name'] ?? '')));
            $row['guest_email'] = isset($row['guest_email']) && $row['guest_email'] !== ''
                ? $row['guest_email']
                : ($guest['email'] ?? '');
            $row['guest_phone'] = isset($row['guest_phone']) && $row['guest_phone'] !== ''
                ? $row['guest_phone']
                : ($guest['phone'] ?? '');
        }

        return $row;
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
    array(
        'id' => 12,
        'thread_key' => 'email-thread',
        'reservation_id' => 501,
        'guest_id' => 902,
        'channel' => 'email',
        'direction' => 'outbound',
        'message' => 'Email delivered',
        'sent_at' => '2024-06-03 09:00:00',
    ),
    array(
        'id' => 20,
        'thread_key' => '',
        'reservation_id' => 501,
        'guest_id' => 902,
        'channel' => 'portal',
        'direction' => 'outbound',
        'message' => 'Portal update logged',
        'delivery_status' => 'completed',
        'sent_at' => '2024-06-04 10:00:00',
    ),
    array(
        'id' => 21,
        'thread_key' => 'portal-thread',
        'reservation_id' => 501,
        'guest_id' => 902,
        'channel' => 'portal',
        'direction' => 'outbound',
        'message' => 'Door code synced',
        'delivery_status' => 'completed',
        'sent_at' => '2024-06-05 14:30:00',
    ),
);

$GLOBALS['wpdb'] = $wpdb;

require_once __DIR__ . '/../includes/class-database.php';

$result = GMS_Database::getCommunicationThreads();
if (!is_array($result) || ($result['total'] ?? 0) !== 2) {
    throw new RuntimeException('Unexpected total: ' . json_encode($result));
}

$threads = array();
foreach ($result['items'] as $thread) {
    if (!is_array($thread)) {
        continue;
    }
    $threads[$thread['thread_key'] ?? ''] = $thread;
}

$smsThread = $threads['thread-1'] ?? null;
if (!is_array($smsThread)) {
    throw new RuntimeException('SMS thread missing: ' . json_encode($threads));
}

if ((int) ($smsThread['guest_id'] ?? 0) !== 902) {
    throw new RuntimeException('Guest ID mismatch: ' . json_encode($smsThread));
}

if (($smsThread['guest_name'] ?? '') !== 'Canonical Guest') {
    throw new RuntimeException('Guest name mismatch: ' . json_encode($smsThread));
}

if (($smsThread['guest_email'] ?? '') !== 'canonical@example.com') {
    throw new RuntimeException('Guest email mismatch: ' . json_encode($smsThread));
}

if (($smsThread['guest_phone'] ?? '') !== '+15557654321') {
    throw new RuntimeException('Guest phone mismatch: ' . json_encode($smsThread));
}

if (($smsThread['reservation_guest_name'] ?? '') !== 'Legacy Guest') {
    throw new RuntimeException('Reservation guest mismatch: ' . json_encode($smsThread));
}

if ((int) ($smsThread['unread_count'] ?? 0) !== 1) {
    throw new RuntimeException('Unread count mismatch: ' . json_encode($smsThread));
}

$emailThread = $threads['email-thread'] ?? null;
if (!is_array($emailThread)) {
    throw new RuntimeException('Email thread missing: ' . json_encode($threads));
}

if (($emailThread['channel'] ?? '') !== 'email') {
    throw new RuntimeException('Email channel mismatch: ' . json_encode($emailThread));
}

$smsOnly = GMS_Database::getCommunicationThreads(array('channels' => array('sms')));
if (($smsOnly['total'] ?? 0) !== 1 || (($smsOnly['items'][0]['thread_key'] ?? '') !== 'thread-1')) {
    throw new RuntimeException('SMS-only filter failed: ' . json_encode($smsOnly));
}

$emailOnly = GMS_Database::getCommunicationThreads(array('channels' => array('email')));
if (($emailOnly['total'] ?? 0) !== 1 || (($emailOnly['items'][0]['thread_key'] ?? '') !== 'email-thread')) {
    throw new RuntimeException('Email-only filter failed: ' . json_encode($emailOnly));
}

$logs = GMS_Database::getOperationalLogs();
if (!is_array($logs) || ($logs['total'] ?? 0) !== 2) {
    throw new RuntimeException('Unexpected log total: ' . json_encode($logs));
}

foreach ($logs['items'] as $log) {
    if (($log['channel'] ?? '') !== 'portal') {
        throw new RuntimeException('Non-portal log included: ' . json_encode($log));
    }
}

if (($logs['items'][0]['message'] ?? '') !== 'Door code synced') {
    throw new RuntimeException('Logs not sorted correctly: ' . json_encode($logs['items']));
}

$portalOnly = GMS_Database::getOperationalLogs(array('channels' => array('portal')));
if (($portalOnly['total'] ?? 0) !== 2) {
    throw new RuntimeException('Portal filter failed: ' . json_encode($portalOnly));
}

$noLogs = GMS_Database::getOperationalLogs(array('channels' => array('sms')));
if (($noLogs['total'] ?? 0) !== 0) {
    throw new RuntimeException('SMS logs should be empty: ' . json_encode($noLogs));
}

