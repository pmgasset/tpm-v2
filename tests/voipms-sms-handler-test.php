<?php

date_default_timezone_set('UTC');

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value) {
        if (is_array($value)) {
            return '';
        }

        $value = (string) $value;
        $value = trim($value);
        $value = preg_replace('/[\r\n\t\0\x0B]/', '', $value);

        return $value;
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        $key = strtolower((string) $key);
        $key = preg_replace('/[^a-z0-9_\-]/', '', $key);

        return $key;
    }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = array()) {
        return array_merge($defaults, $args);
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) {
        return json_encode($data);
    }
}

if (!function_exists('wp_kses_post')) {
    function wp_kses_post($value) {
        return $value;
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($string) {
        return trim(strip_tags((string) $string));
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

if (!function_exists('get_option')) {
    $GLOBALS['__gms_test_options'] = array(
        'gms_voipms_did' => '+15550001111',
        'gms_sms_template' => 'Welcome {guest_name}! Check-in {checkin_date} at {checkin_time}. Check-out {checkout_date} at {checkout_time}. Link: {portal_link} - {company_name}',
        'gms_approved_sms_template' => 'Reservation approved for {property_name}! Arrive {checkin_date} at {checkin_time}, depart {checkout_date} at {checkout_time}. {company_name}',
        'gms_company_name' => 'Test Company',
        'timezone_string' => 'America/Los_Angeles',
    );

    function get_option($key, $default = false) {
        return $GLOBALS['__gms_test_options'][$key] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($key, $value) {
        $GLOBALS['__gms_test_options'][$key] = $value;
    }
}

if (!function_exists('wp_timezone')) {
    function wp_timezone() {
        static $timezone = null;

        if ($timezone instanceof DateTimeZone) {
            return $timezone;
        }

        $timezone_string = get_option('timezone_string');

        if (!empty($timezone_string)) {
            $timezone = new DateTimeZone($timezone_string);
        } else {
            $timezone = new DateTimeZone('UTC');
        }

        return $timezone;
    }
}

if (!function_exists('wp_date')) {
    function wp_date($format, $timestamp = null, $timezone = null) {
        if ($timestamp === null) {
            $timestamp = time();
        }

        if ($timezone === null) {
            $timezone = wp_timezone();
        } elseif (!$timezone instanceof DateTimeZone) {
            $timezone = new DateTimeZone((string) $timezone);
        }

        $datetime = new DateTimeImmutable('@' . $timestamp);
        $datetime = $datetime->setTimezone($timezone);

        return $datetime->format($format);
    }
}

if (!function_exists('gms_build_portal_url')) {
    function gms_build_portal_url($token) {
        if (empty($token)) {
            return false;
        }

        return 'https://portal.test/' . $token;
    }
}

if (!function_exists('gms_shorten_url')) {
    function gms_shorten_url($url) {
        return $url;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) {
        return $value;
    }
}

if (!class_exists('GMS_Database')) {
    class GMS_Database {
        public static $last_log = null;
        public static $mock_context = null;

        public static function normalizePhoneNumber($number) {
            $number = preg_replace('/[^0-9+]/', '', (string) $number);

            if ($number === '') {
                return '';
            }

            if ($number[0] !== '+') {
                $number = '+' . ltrim($number, '+');
            }

            return $number;
        }

        public static function communicationExists($external_id, $channel) {
            return 0;
        }

        public static function resolveMessageContext($channel, $from, $to, $direction) {
            if (is_array(self::$mock_context)) {
                return self::$mock_context;
            }

            return array(
                'matched' => true,
                'status' => 'matched',
                'guest_number_e164' => self::normalizePhoneNumber($direction === 'inbound' ? $from : $to),
                'service_number_e164' => self::normalizePhoneNumber($direction === 'inbound' ? $to : $from),
                'reservation_id' => 0,
                'guest_id' => 0,
                'reservation' => null,
                'guest' => null,
                'thread_key' => 'test-thread',
            );
        }

        public static function logCommunication($data) {
            self::$last_log = $data;

            return 101;
        }
    }
}

if (!interface_exists('GMS_Messaging_Channel_Interface')) {
    interface GMS_Messaging_Channel_Interface {}
}

require_once __DIR__ . '/../includes/class-sms-handler.php';

if (!class_exists('Testable_GMS_SMS_Handler')) {
    class Testable_GMS_SMS_Handler extends GMS_SMS_Handler {
        public function __construct() {
            // Skip WordPress hook registration during tests
        }

        public $sent_messages = array();

        public function sendSMS($to, $message) {
            $this->sent_messages[] = array(
                'to' => $to,
                'message' => $message,
            );

            return true;
        }
    }
}

function invoke_sms_handler_private($object, $method, array $args = array()) {
    $reflection = new ReflectionMethod(get_class($object), $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($object, $args);
}

$payload = array(
    'body' => array(
        'payload' => array(
            'messages' => array(
                array(
                    'id' => 'sms-123',
                    'direction' => 'inbound',
                    'from' => array(
                        'phone_number' => '+12223334444',
                        'name' => 'Alice Example',
                    ),
                    'to' => array(
                        array(
                            'phone_number' => '+15550001111',
                            'name' => 'Office Line',
                        ),
                        array(
                            'phone_number' => '+15550002222',
                            'name' => 'Fallback',
                        ),
                    ),
                    'text' => 'Sample webhook message',
                    'received_at' => '2024-05-30T17:12:34-04:00',
                ),
            ),
        ),
    ),
);

$handler = new Testable_GMS_SMS_Handler();

$messages = invoke_sms_handler_private($handler, 'extractMessagesFromPayload', array($payload));

if (!is_array($messages) || count($messages) !== 1) {
    throw new RuntimeException('Expected a single message from payload: ' . json_encode($messages));
}

$extracted = $messages[0];

if (($extracted['text'] ?? null) !== 'Sample webhook message') {
    throw new RuntimeException('Message text was not preserved: ' . json_encode($extracted));
}

$normalized = invoke_sms_handler_private($handler, 'normalizeVoipmsMessage', array($extracted));

if (!is_string($normalized['from']) || $normalized['from'] !== '+12223334444') {
    throw new RuntimeException('Normalized "from" was not flattened: ' . json_encode($normalized['from']));
}

if (!is_string($normalized['to']) || $normalized['to'] !== '+15550001111') {
    throw new RuntimeException('Normalized "to" was not flattened: ' . json_encode($normalized['to']));
}

if ($normalized['from_e164'] !== '+12223334444') {
    throw new RuntimeException('from_e164 mismatch: ' . json_encode($normalized));
}

if ($normalized['to_e164'] !== '+15550001111') {
    throw new RuntimeException('to_e164 mismatch: ' . json_encode($normalized));
}

$expected_timestamp = gmdate('Y-m-d H:i:s', strtotime('2024-05-30T17:12:34-04:00'));

if ($normalized['timestamp'] !== $expected_timestamp) {
    throw new RuntimeException(sprintf('Timestamp mismatch. Expected %s, got %s', $expected_timestamp, $normalized['timestamp']));
}

GMS_Database::$last_log = null;
GMS_Database::$mock_context = null;

$persist = invoke_sms_handler_private($handler, 'persistNormalizedMessage', array($normalized));

if (empty($persist['stored'])) {
    throw new RuntimeException('Message was not marked as stored.');
}

if (!is_array(GMS_Database::$last_log)) {
    throw new RuntimeException('Log data was not captured.');
}

if (!is_string(GMS_Database::$last_log['from_number']) || GMS_Database::$last_log['from_number'] !== '+12223334444') {
    throw new RuntimeException('from_number not logged as string: ' . json_encode(GMS_Database::$last_log));
}

if (!is_string(GMS_Database::$last_log['to_number']) || GMS_Database::$last_log['to_number'] !== '+15550001111') {
    throw new RuntimeException('to_number not logged as string: ' . json_encode(GMS_Database::$last_log));
}

if (GMS_Database::$last_log['sent_at'] !== $expected_timestamp) {
    throw new RuntimeException('sent_at did not match expected timestamp.');
}

if (($persist['context']['status'] ?? '') !== 'matched') {
    throw new RuntimeException('Persisted context missing matched status: ' . json_encode($persist['context']));
}

$logged_context = GMS_Database::$last_log['response_data']['context'] ?? array();
if (($logged_context['status'] ?? '') !== 'matched') {
    throw new RuntimeException('Log context status mismatch: ' . json_encode($logged_context));
}

GMS_Database::$mock_context = array(
    'matched' => false,
    'status' => 'unmatched',
    'guest_number_e164' => '+12223334444',
    'service_number_e164' => '+15550001111',
    'reservation_id' => 0,
    'guest_id' => 0,
    'reservation' => null,
    'guest' => null,
    'thread_key' => 'test-thread',
);

GMS_Database::$last_log = null;

$persist_unmatched = invoke_sms_handler_private($handler, 'persistNormalizedMessage', array($normalized));

if (!empty($persist_unmatched['context']['matched'])) {
    throw new RuntimeException('Unmatched context reported as matched: ' . json_encode($persist_unmatched['context']));
}

$unmatched_context = $persist_unmatched['context'];
if (($unmatched_context['status'] ?? '') !== 'unmatched') {
    throw new RuntimeException('Unmatched context missing status: ' . json_encode($unmatched_context));
}

if ($unmatched_context['reservation'] !== null || $unmatched_context['guest'] !== null) {
    throw new RuntimeException('Unmatched context should not include guest or reservation data.');
}

$logged_unmatched = GMS_Database::$last_log['response_data']['context'] ?? array();
if (($logged_unmatched['status'] ?? '') !== 'unmatched') {
    throw new RuntimeException('Log context should record unmatched status: ' . json_encode($logged_unmatched));
}

if ((int) (GMS_Database::$last_log['reservation_id'] ?? -1) !== 0 || (int) (GMS_Database::$last_log['guest_id'] ?? -1) !== 0) {
    throw new RuntimeException('Reservation/guest IDs should be zero when unmatched: ' . json_encode(GMS_Database::$last_log));
}

GMS_Database::$mock_context = null;

$handler->sent_messages = array();

$reservation = array(
    'id' => 501,
    'guest_id' => 321,
    'guest_phone' => '(555) 123-4567',
    'guest_name' => 'Timezone Tester',
    'property_name' => 'Sunset Villa',
    'booking_reference' => 'TZ-42',
    'portal_token' => 'welcome-token',
    'checkin_date' => '2024-07-10 18:00:00',
    'checkout_date' => '2024-07-15 16:30:00',
);

if (!$handler->sendWelcomeSMS($reservation)) {
    throw new RuntimeException('sendWelcomeSMS should return true under test conditions.');
}

if (count($handler->sent_messages) !== 1) {
    throw new RuntimeException('Expected exactly one welcome SMS to be sent.');
}

$welcome_message = $handler->sent_messages[0];

if ($welcome_message['to'] !== '5551234567') {
    throw new RuntimeException('Sanitized phone number was not used for welcome SMS: ' . var_export($welcome_message['to'], true));
}

$timezone = wp_timezone();
$checkin_dt = new DateTimeImmutable($reservation['checkin_date'], new DateTimeZone('UTC'));
$checkout_dt = new DateTimeImmutable($reservation['checkout_date'], new DateTimeZone('UTC'));
$expected_fragments = array(
    'checkin_date' => wp_date('M j', $checkin_dt->getTimestamp(), $timezone),
    'checkin_time' => wp_date('g:i A', $checkin_dt->getTimestamp(), $timezone),
    'checkout_date' => wp_date('M j', $checkout_dt->getTimestamp(), $timezone),
    'checkout_time' => wp_date('g:i A', $checkout_dt->getTimestamp(), $timezone),
);

foreach ($expected_fragments as $fragment => $value) {
    if (strpos($welcome_message['message'], $value) === false) {
        throw new RuntimeException(sprintf('Welcome SMS missing %s fragment (%s): %s', $fragment, $value, $welcome_message['message']));
    }
}

$handler->sent_messages = array();

if (!$handler->sendReservationApprovedSMS($reservation)) {
    throw new RuntimeException('sendReservationApprovedSMS should return true under test conditions.');
}

if (count($handler->sent_messages) !== 1) {
    throw new RuntimeException('Expected exactly one approval SMS to be sent.');
}

$approval_message = $handler->sent_messages[0]['message'];

foreach ($expected_fragments as $fragment => $value) {
    if (strpos($approval_message, $value) === false) {
        throw new RuntimeException(sprintf('Approval SMS missing %s fragment (%s): %s', $fragment, $value, $approval_message));
    }
}

echo "voipms-sms-handler-test: OK\n";
