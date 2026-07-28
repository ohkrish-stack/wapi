<?php
/**
 * Asynchronous Webhook Dispatcher
 * Sends webhook HTTP POST events to external client target URLs
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';

class WebhookDispatcher {
    /**
     * Dispatch event webhook to target URL
     */
    public static function dispatch($instance_id, $event, $payload_data) {
        try {
            $db = db();
            // Fetch instance webhook configuration
            $stmt = $db->prepare("SELECT webhook_url, webhook_secret, webhook_events FROM instances WHERE instance_id = ?");
            $stmt->execute([$instance_id]);
            $instance = $stmt->fetch();

            if (!$instance || empty($instance['webhook_url'])) {
                return false; // Webhook URL not configured
            }

            // Check if this event type is enabled
            $enabled_events = array_map('trim', explode(',', strtolower($instance['webhook_events'])));
            if (!in_array(strtolower($event), $enabled_events) && !in_array('*', $enabled_events)) {
                return false; // Event not subscribed
            }

            $target_url = $instance['webhook_url'];
            $secret = $instance['webhook_secret'] ?? '';

            // Construct standardized payload wrapper (UltraMsg standard format)
            $wrapper = [
                'event'       => $event,
                'instance_id' => $instance_id,
                'timestamp'   => time(),
                'data'        => $payload_data
            ];

            $json_payload = json_encode($wrapper, JSON_UNESCAPED_SLASHES);

            // Generate signature header if secret set
            $headers = [
                'Content-Type: application/json',
                'User-Agent: UltraWAPI-Webhook/1.0',
                'X-WAPI-Event: ' . $event,
                'X-WAPI-Instance: ' . $instance_id
            ];

            if (!empty($secret)) {
                $signature = hash_hmac('sha256', $json_payload, $secret);
                $headers[] = 'X-WAPI-Signature: sha256=' . $signature;
            }

            // Send HTTP POST request via cURL
            $start_time = microtime(true);
            $ch = curl_init($target_url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $json_payload,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ]);

            $response_body = curl_exec($ch);
            $http_code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error    = curl_error($ch);
            curl_close($ch);

            $execution_time = round((microtime(true) - $start_time) * 1000);
            $status = ($http_code >= 200 && $http_code < 300) ? 'success' : 'failed';

            if ($curl_error && empty($response_body)) {
                $response_body = "cURL Error: " . $curl_error;
            }

            // Record webhook log entry
            $logStmt = $db->prepare("
                INSERT INTO webhooks (instance_id, event, target_url, http_code, request_payload, response_body, status, execution_time_ms, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $logStmt->execute([
                $instance_id,
                $event,
                $target_url,
                $http_code,
                $json_payload,
                substr($response_body ?? '', 0, 5000),
                $status,
                $execution_time
            ]);

            return [
                'status'    => $status,
                'http_code' => $http_code,
                'time_ms'   => $execution_time,
                'response'  => $response_body
            ];

        } catch (Exception $e) {
            error_log("Webhook Dispatch Error: " . $e->getMessage());
            return false;
        }
    }
}
