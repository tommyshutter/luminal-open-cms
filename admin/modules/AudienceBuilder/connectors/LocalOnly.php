<?php
/**
 * AudienceBuilder — Local-Only Output Connector
 *
 * Default connector that stores leads locally (no external push).
 * Implements the connector interface pattern for future connectors
 * (GHL.php, Mailchimp.php, Webhook.php, etc.)
 */
declare(strict_types=1);

class AB_Connector_LocalOnly {

    public static function id(): string {
        return 'local_only';
    }

    public static function name(): string {
        return 'Local Storage Only';
    }

    /**
     * Push a lead to this connector. No-op for local storage.
     */
    public static function push(array $lead, array $config): array {
        return ['ok' => true];
    }

    /**
     * Test the connector. Local storage is always available.
     */
    public static function test(array $config): array {
        return ['ok' => true, 'message' => 'Local storage always available'];
    }
}
