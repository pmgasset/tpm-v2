<?php
require_once __DIR__ . '/../includes/class-webhook-handler.php';

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

echo "parse-generic-webhook-test: OK\n";
