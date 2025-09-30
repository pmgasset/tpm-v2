<?php
require_once __DIR__ . '/../includes/class-webhook-handler.php';

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

$sample_email_body = <<<EOT
<html>
<body>
<p>New Booking Received</p>
<p>Guest Name: Jane Guest</p>
<p>Email: jane.guest@example.com</p>
<p>Arrival: 2024-07-15 (3:00 PM)</p>
<p>Departure: 2024-07-18 (11:00 AM)</p>
<p>Guests: 2</p>
<p>Phone: +1 (555) 123-4567</p>
</body>
</html>
EOT;

$handler = new Testable_GMS_Webhook_Handler();

$reflection = new ReflectionMethod(GMS_Webhook_Handler::class, 'parseBookingEmail');
$reflection->setAccessible(true);
$reflection->invoke($handler, $sample_email_body);

if ($handler->lastEmailData === null) {
    throw new RuntimeException('parseBookingEmail did not dispatch parsed data.');
}

$parsed = $handler->lastEmailData['data'];

if (($parsed['guest_name'] ?? null) !== 'Jane Guest') {
    throw new RuntimeException('Guest name was not parsed correctly: ' . var_export($parsed['guest_name'] ?? null, true));
}

if (($parsed['checkin_date'] ?? null) !== '2024-07-15 15:00:00') {
    throw new RuntimeException('Check-in date was not parsed correctly: ' . var_export($parsed['checkin_date'] ?? null, true));
}

echo "parse-booking-email-test: OK\n";
