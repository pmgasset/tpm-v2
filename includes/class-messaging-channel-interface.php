<?php
/**
 * Messaging channel interface for inbound/outbound providers.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!interface_exists('GMS_Messaging_Channel_Interface')) {
    interface GMS_Messaging_Channel_Interface {
        public function syncProviderInbox($args = array());

        public function ingestWebhookPayload(array $payload, $request = null);
    }
}
