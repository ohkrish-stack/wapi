<?php
/**
 * WhatsApp Core Bridge Engine
 * Handles instance QR generation, authentication state, message routing,
 * and proxying requests to Baileys Node Engine or local Engine simulation.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/webhook_dispatcher.php';

class WhatsAppBridge {
    
    private static $activeNodeUrl = null;

    /**
     * Check if Node.js Baileys engine is active
     */
    /**
     * Check if Node.js Baileys engine is active
     */
    public static function getNodeUrl() {
        if (self::$activeNodeUrl !== null) {
            return self::$activeNodeUrl;
        }

        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $testUrls = [
            NODE_ENGINE_URL,
            'http://127.0.0.1:3000',
            'http://localhost:3000',
            'https://127.0.0.1:3000',
            'https://localhost:3000',
            rtrim(BASE_URL, '/') . '/wapi-node',
            rtrim(BASE_URL, '/') . '/engine/node_engine',
            'https://' . $host . '/wapi-node',
            'https://' . $host . '/engine/node_engine',
            'http://' . $host . '/wapi-node',
            'http://' . $host . '/engine/node_engine'
        ];

        foreach ($testUrls as $url) {
            $target = rtrim($url, '/') . '/health';
            $ch = @curl_init($target);
            if (!$ch) continue;
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_TIMEOUT        => 2,
                CURLOPT_CONNECTTIMEOUT => 1,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ]);
            $res = @curl_exec($ch);
            $code = @curl_getinfo($ch, CURLINFO_HTTP_CODE);
            @curl_close($ch);
            if ($code === 200) {
                self::$activeNodeUrl = rtrim($url, '/');
                return self::$activeNodeUrl;
            }
        }
        return false;
    }

    public static function isNodeEngineOnline() {
        return (self::getNodeUrl() !== false);
    }

    /**
     * Get or Generate QR Code for an Instance
     */
    public static function getQRCode($instance_id) {
        $db = db();
        $stmt = $db->prepare("SELECT * FROM instances WHERE instance_id = ?");
        $stmt->execute([$instance_id]);
        $instance = $stmt->fetch();

        if (!$instance) {
            return ['error' => 'Instance not found'];
        }

        if ($instance['status'] === 'authenticated') {
            return [
                'status' => 'authenticated',
                'phone'  => $instance['phone_number'],
                'message' => 'WhatsApp already authenticated & paired'
            ];
        }

        // Always query Node Engine for fresh live Baileys QR code
        $nodeUrl = self::getNodeUrl();
        if ($nodeUrl !== false) {
            $ch = curl_init($nodeUrl . "/instance/{$instance_id}/qr");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ]);
            $res = curl_exec($ch);
            curl_close($ch);
            $data = json_decode($res, true);

            if ($data && !empty($data['qr'])) {
                // Save latest live QR to DB
                $up = $db->prepare("UPDATE instances SET status = 'qr_ready', qr_code_data = ? WHERE instance_id = ?");
                $up->execute([$data['qr'], $instance_id]);
                return ['status' => 'qr_ready', 'qr' => $data['qr'], 'qr_data' => $data['qr']];
            }

            if ($data && isset($data['status']) && $data['status'] === 'connecting') {
                return ['status' => 'connecting', 'message' => 'Connecting to WhatsApp... Generating fresh QR code'];
            }
        }

        // Clear stale cached QR from DB if Node is offline / re-initializing
        $up = $db->prepare("UPDATE instances SET qr_code_data = NULL WHERE instance_id = ?");
        $up->execute([$instance_id]);

        return [
            'status'  => 'connecting',
            'message' => 'Connecting to WhatsApp Engine... Please wait a few seconds.'
        ];
    }

    /**
     * Request 8-Character Pairing Code for WhatsApp Phone Link
     */
    public static function requestPairingCode($instance_id, $phone_number) {
        $clean_phone = preg_replace('/[^0-9]/', '', $phone_number);
        if (empty($clean_phone) || strlen($clean_phone) < 7) {
            return ['status' => 'error', 'message' => 'Valid phone number with country code is required (e.g. +919214304508)'];
        }

        $nodeUrl = self::getNodeUrl();
        if ($nodeUrl !== false) {
            $ch = curl_init($nodeUrl . "/instance/{$instance_id}/pairing-code");
            $payload = json_encode(['phone' => $clean_phone]);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ]);
            $res = curl_exec($ch);
            curl_close($ch);
            $data = json_decode($res, true);

            if ($data && (isset($data['pairing_code']) || isset($data['code']))) {
                $code = $data['pairing_code'] ?? $data['code'];
                $cleanCode = str_replace('-', '', $code);
                if (strlen($cleanCode) === 8 && strpos($code, '-') === false) {
                    $code = substr($cleanCode, 0, 4) . '-' . substr($cleanCode, 4);
                }
                return [
                    'status'       => 'success',
                    'pairing_code' => strtoupper($code),
                    'message'      => 'Enter this 8-character code in WhatsApp -> Linked Devices -> Link with phone number instead'
                ];
            }

            if ($data && (isset($data['error']) || isset($data['message']))) {
                return ['status' => 'error', 'message' => $data['error'] ?? $data['message']];
            }
        }

        return [
            'status'  => 'error',
            'message' => 'Node.js Baileys engine is offline or connecting. Please ensure Node engine is started: cd engine/node_engine && npm start'
        ];
    }

    /**
     * Simulate QR Code Scanning / Authenticate WhatsApp Session
     */
    public static function authenticateSession($instance_id, $phone_number = null) {
        $db = db();
        if (!$phone_number) {
            $phone_number = '+1555' . rand(1000000, 9999999);
        }

        $stmt = $db->prepare("UPDATE instances SET status = 'authenticated', phone_number = ?, qr_code_data = NULL WHERE instance_id = ?");
        $stmt->execute([$phone_number, $instance_id]);

        // Dispatch device.status webhook
        WebhookDispatcher::dispatch($instance_id, 'device.status', [
            'status' => 'authenticated',
            'phone'  => $phone_number,
            'time'   => date('Y-m-d H:i:s')
        ]);

        return [
            'status' => 'authenticated',
            'phone'  => $phone_number,
            'message' => 'WhatsApp account linked successfully!'
        ];
    }

    /**
     * Logout / Disconnect WhatsApp Session
     */
    public static function logoutSession($instance_id) {
        $db = db();
        $stmt = $db->prepare("UPDATE instances SET status = 'disconnected', phone_number = NULL, qr_code_data = NULL WHERE instance_id = ?");
        $stmt->execute([$instance_id]);

        // Dispatch device.status webhook
        WebhookDispatcher::dispatch($instance_id, 'device.status', [
            'status' => 'disconnected',
            'time'   => date('Y-m-d H:i:s')
        ]);

        return [
            'status'  => 'disconnected',
            'message' => 'Logged out of WhatsApp account successfully'
        ];
    }

    /**
     * Send Message (Chat / Text, Image, Document)
     */
    public static function sendMessage($instance_id, $to, $body, $type = 'chat', $media_url = null, $filename = null) {
        $db = db();
        
        // Clean phone number (strip non-numeric except +)
        $clean_to = preg_replace('/[^0-9]/', '', $to);
        if (empty($clean_to)) {
            return ['status' => 'error', 'message' => 'Invalid recipient phone number'];
        }

        // Generate WhatsApp Message ID (WAMID)
        $msg_id = 'WAMID_' . strtoupper(bin2hex(random_bytes(10)));

        // Save initial message record
        $stmt = $db->prepare("
            INSERT INTO messages (instance_id, msg_id, to_number, direction, type, body, media_url, filename, status, created_at)
            VALUES (?, ?, ?, 'outbound', ?, ?, ?, ?, 'sent', NOW())
        ");
        $stmt->execute([$instance_id, $msg_id, $clean_to, $type, $body, $media_url, $filename]);

        // Send via Node engine if online
        $nodeUrl = self::getNodeUrl();
        if ($nodeUrl !== false) {
            $ch = curl_init($nodeUrl . "/instance/{$instance_id}/send");
            $payload = json_encode([
                'to'        => $clean_to,
                'type'      => $type,
                'body'      => $body,
                'media_url' => $media_url,
                'filename'  => $filename
            ]);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ]);
            curl_exec($ch);
            curl_close($ch);
        }

        // Trigger Webhook Event: messages.ack (Sent & Delivered)
        $ackPayload = [
            'id'        => $msg_id,
            'to'        => $clean_to,
            'status'    => 'delivered',
            'timestamp' => time()
        ];
        WebhookDispatcher::dispatch($instance_id, 'messages.ack', $ackPayload);

        return [
            'sent'      => 'true',
            'message'   => 'Message sent successfully',
            'id'        => $msg_id,
            'to'        => $clean_to,
            'status'    => 'sent'
        ];
    }

    /**
     * Simulate an incoming WhatsApp message (For testing automation & webhooks)
     */
    public static function simulateIncomingMessage($instance_id, $from_number, $body, $type = 'chat') {
        $db = db();
        $clean_from = preg_replace('/[^0-9]/', '', $from_number);
        $msg_id = 'WAMID_IN_' . strtoupper(bin2hex(random_bytes(8)));

        // Record incoming message in DB
        $stmt = $db->prepare("
            INSERT INTO messages (instance_id, msg_id, to_number, from_number, direction, type, body, status, created_at)
            VALUES (?, ?, 'ME', ?, 'inbound', ?, ?, 'received', NOW())
        ");
        $stmt->execute([$instance_id, $msg_id, $clean_from, $type, $body]);

        // Dispatch messages.upsert / received webhook
        $webhookData = [
            'id'          => $msg_id,
            'from'        => $clean_from . '@s.whatsapp.net',
            'phone'       => $clean_from,
            'type'        => $type,
            'body'        => $body,
            'timestamp'   => time(),
            'instance_id' => $instance_id
        ];

        $webhookResult = WebhookDispatcher::dispatch($instance_id, 'messages.upsert', $webhookData);

        return [
            'status'  => 'success',
            'msg_id'  => $msg_id,
            'webhook' => $webhookResult
        ];
    }
}
