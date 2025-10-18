<?php
require_once __DIR__ . '/../includes/class-webhook-handler.php';

if (!function_exists('get_option')) {
    function get_option($name, $default = null) {
        if ($name === 'timezone_string') {
            return 'America/New_York';
        }

        if ($name === 'gmt_offset') {
            return -4;
        }

        return $default;
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
            $offset = (float) get_option('gmt_offset', 0);
            $timezone = timezone_name_from_abbr('', (int) ($offset * 3600), 0);
            $timezone = $timezone !== false ? new DateTimeZone($timezone) : new DateTimeZone('UTC');
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

if (!class_exists('Testable_GMS_Webhook_Handler')) {
    class Testable_GMS_Webhook_Handler extends GMS_Webhook_Handler {
        public $lastEmailData = null;

        public function __construct() {
            // Override to avoid WordPress hooks during tests
        }

        protected function handleParsedEmailData($data, $platform) {
            $this->lastEmailData = array(
                'data' => $data,
                'platform' => $platform,
            );
        }
    }
}

$payload = array(
    'reservation' => array(
        'guest_email' => 'guest@example.com',
        'checkin_time' => '3:00 PM',
        'guest_name' => 'Jane Guest',
        'checkin_date' => '2024-07-15',
        'checkout_time' => '11:00 AM',
        'checkout_date' => '2024-07-18',
        'booking_reference' => 'ABC123',
    ),
    'meta' => array(
        'source' => 'cloudflare-worker',
    ),
);

$handler = new Testable_GMS_Webhook_Handler();

$reflection = new ReflectionMethod(GMS_Webhook_Handler::class, 'parseGenericData');
$reflection->setAccessible(true);
$parsed = $reflection->invoke($handler, $payload);

if (($parsed['guest_name'] ?? null) !== 'Jane Guest') {
    throw new RuntimeException('Guest name was not preserved: ' . var_export($parsed['guest_name'] ?? null, true));
}

if (($parsed['checkin_date'] ?? null) !== '2024-07-15 00:00:00') {
    throw new RuntimeException('Check-in date was not preserved: ' . var_export($parsed['checkin_date'] ?? null, true));
}

if (($parsed['guest_email'] ?? null) !== 'guest@example.com') {
    throw new RuntimeException('Guest email was not parsed correctly: ' . var_export($parsed['guest_email'] ?? null, true));
}

$gmt_payload = array(
    'booking_reference' => 'GMT123',
    'guest_name' => 'GMT Guest',
    'guest_email' => 'gmt@example.com',
    'checkin_date' => '2024-07-15T18:00:00Z',
    'checkout_date' => '2024-07-18T16:00:00Z',
);

$parsed_gmt = $reflection->invoke($handler, $gmt_payload);

if (($parsed_gmt['checkin_date'] ?? null) !== '2024-07-15 14:00:00') {
    throw new RuntimeException('GMT check-in date was not converted: ' . var_export($parsed_gmt['checkin_date'] ?? null, true));
}

if (($parsed_gmt['checkout_date'] ?? null) !== '2024-07-18 12:00:00') {
    throw new RuntimeException('GMT check-out date was not converted: ' . var_export($parsed_gmt['checkout_date'] ?? null, true));
}

$booking_payload = array(
    'reservation_id' => 'BOOK-001',
    'guest_name' => 'Booking Guest',
    'guest_email' => 'booking@example.com',
    'check_in' => '2024-07-20T15:00:00Z',
    'check_out' => '2024-07-22T11:00:00Z',
);

$booking_reflection = new ReflectionMethod(GMS_Webhook_Handler::class, 'parseBookingData');
$booking_reflection->setAccessible(true);
$parsed_booking = $booking_reflection->invoke($handler, $booking_payload);

if (!is_array($parsed_booking)) {
    throw new RuntimeException('Booking payload did not parse correctly');
}

if (($parsed_booking['checkin_date'] ?? null) !== '2024-07-20 11:00:00') {
    throw new RuntimeException('Booking check-in date was not converted: ' . var_export($parsed_booking['checkin_date'] ?? null, true));
}

if (($parsed_booking['checkout_date'] ?? null) !== '2024-07-22 07:00:00') {
    throw new RuntimeException('Booking check-out date was not converted: ' . var_export($parsed_booking['checkout_date'] ?? null, true));
}

echo "parse-generic-webhook-test: OK\n";
