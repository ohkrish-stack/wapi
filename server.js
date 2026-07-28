/**
 * Baileys WhatsApp Node.js Microservice Server
 * Listens on PORT 3000
 * Handles multi-device sockets, session persistence, QR generation, message sending & incoming webhook proxies.
 */
const crypto = require('crypto');
if (!global.crypto) {
    global.crypto = crypto;
}

const express = require('express');
const cors = require('cors');
const qrcode = require('qrcode');
const pino = require('pino');
const { default: makeWASocket, useMultiFileAuthState, DisconnectReason, Browsers, fetchLatestBaileysVersion } = require('@whiskeysockets/baileys');
const path = require('path');
const fs = require('fs');

const app = express();
app.use(express.json());
app.use(cors());

const PORT = process.env.PORT || 3000;
const sessions = new Map();
const sessionPromises = new Map();
const qrCodes = new Map();

// Handle cPanel Phusion Passenger subpath routing
app.use((req, res, next) => {
    req.url = req.url.replace(/^\/(wapi-node|wapi\/engine\/node_engine)/i, '') || '/';
    next();
});

// Health Check
app.get('/health', (req, res) => {
    res.json({ status: 'ok', engine: 'Baileys', active_sessions: sessions.size, active_qrs: qrCodes.size });
});

// Helper to reset and clean session state if socket gets stuck
function resetSession(instanceId) {
    if (sessions.has(instanceId)) {
        try {
            const sock = sessions.get(instanceId);
            if (sock && sock.end) sock.end(undefined);
        } catch (e) {}
        sessions.delete(instanceId);
    }
    sessionPromises.delete(instanceId);
    qrCodes.delete(instanceId);
    const sessionDir = path.join(__dirname, 'sessions', instanceId);
    try {
        if (fs.existsSync(sessionDir)) {
            fs.rmSync(sessionDir, { recursive: true, force: true });
        }
    } catch (e) {}
}

async function getOrInitSession(instanceId) {
    if (sessions.has(instanceId)) {
        return sessions.get(instanceId);
    }
    if (sessionPromises.has(instanceId)) {
        return sessionPromises.get(instanceId);
    }

    const promise = (async () => {
        try {
            const sock = await initSession(instanceId);
            return sock;
        } finally {
            sessionPromises.delete(instanceId);
        }
    })();

    sessionPromises.set(instanceId, promise);
    return promise;
}

// Get QR Code Data URL for Instance
app.get('/instance/:id/qr', async (req, res) => {
    const instanceId = req.params.id;

    if (qrCodes.has(instanceId)) {
        return res.json({ status: 'qr_ready', qr: qrCodes.get(instanceId), qr_data: qrCodes.get(instanceId) });
    }

    let sock = sessions.get(instanceId);
    if (!sock || !sock.user) {
        // Purge stale auth files to guarantee matching Noise keys during phone scan
        resetSession(instanceId);
        sock = await initSession(instanceId);
    }
    
    let attempts = 0;
    const interval = setInterval(() => {
        attempts++;
        if (qrCodes.has(instanceId)) {
            clearInterval(interval);
            return res.json({ status: 'qr_ready', qr: qrCodes.get(instanceId), qr_data: qrCodes.get(instanceId) });
        }
        if (attempts >= 20) {
            clearInterval(interval);
            return res.json({ status: 'connecting', message: 'Generating fresh QR code...' });
        }
    }, 500);
});

// Send Message Endpoint
app.post('/instance/:id/send', async (req, res) => {
    const instanceId = req.params.id;
    const { to, body, type, media_url } = req.body;
    
    const sock = sessions.get(instanceId);
    if (!sock) {
        return res.status(400).json({ error: 'Instance session not connected' });
    }

    try {
        const jid = to.includes('@s.whatsapp.net') ? to : `${to}@s.whatsapp.net`;
        let result;
        if (type === 'image' && media_url) {
            result = await sock.sendMessage(jid, { image: { url: media_url }, caption: body });
        } else {
            result = await sock.sendMessage(jid, { text: body });
        }

        res.json({ status: 'success', key: result.key });
    } catch (err) {
        res.status(500).json({ error: err.message });
    }
});

// Request WhatsApp 8-Character Pairing Code Endpoint
app.post('/instance/:id/pairing-code', async (req, res) => {
    const instanceId = req.params.id;
    let { phone } = req.body;
    if (!phone) {
        return res.status(400).json({ error: 'Phone number parameter is required' });
    }

    const cleanPhone = phone.replace(/[^0-9]/g, '');
    if (cleanPhone.length < 7) {
        return res.status(400).json({ error: 'Please enter a valid phone number with country code' });
    }

    try {
        // Reset session folder to ensure clean unregistered state for pairing code
        resetSession(instanceId);
        const sock = await initSession(instanceId);

        // Wait up to 10 seconds for WhatsApp server to return authentic pairing code
        let code = null;
        let lastErr = null;

        for (let attempt = 1; attempt <= 12; attempt++) {
            await new Promise(r => setTimeout(r, 800));
            try {
                if (sock && typeof sock.requestPairingCode === 'function') {
                    code = await sock.requestPairingCode(cleanPhone);
                    if (code) break;
                }
            } catch (err) {
                lastErr = err.message;
            }
        }

        if (code) {
            const formatted = (typeof code === 'string' && code.includes('-')) ? code : (code.match(/.{1,4}/g) ? code.match(/.{1,4}/g).join('-') : code);
            return res.json({ status: 'success', pairing_code: formatted, code: code });
        }

        return res.status(500).json({ 
            status: 'error', 
            error: 'WhatsApp server connection initializing. Please wait 5 seconds and click Get Code again.',
            details: lastErr
        });
    } catch (err) {
        console.error(`[Instance ${instanceId}] Pairing code endpoint exception:`, err.message);
        return res.status(500).json({ status: 'error', error: err.message || 'Failed to request pairing code' });
    }
});

// Initialize Session Socket
async function initSession(instanceId) {
    if (sessions.has(instanceId)) return sessions.get(instanceId);

    let version = [2, 3000, 1015901307];
    try {
        const versionInfo = await fetchLatestBaileysVersion();
        if (versionInfo && versionInfo.version) {
            version = versionInfo.version;
            console.log(`[Instance ${instanceId}] Fetched WhatsApp protocol version: ${version.join('.')}`);
        }
    } catch (e) {
        console.warn("Failed to fetch latest Baileys version, using default fallback:", e.message);
    }

    const sessionDir = path.join(__dirname, 'sessions', instanceId);
    if (!fs.existsSync(sessionDir)) {
        fs.mkdirSync(sessionDir, { recursive: true });
    }

    const { state, saveCreds } = await useMultiFileAuthState(sessionDir);
    const sock = makeWASocket({
        version,
        auth: state,
        logger: pino({ level: 'silent' }),
        printQRInTerminal: false,
        waWebSocketUrl: 'wss://web.whatsapp.com/ws/chat',
        wsOptions: {
            headers: {
                'User-Agent': 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Origin': 'https://web.whatsapp.com'
            }
        },
        browser: ['Mac OS', 'Chrome', '120.0.0.0'],
        markOnlineOnConnect: true,
        syncFullHistory: false,
        generateHighQualityLinkPreview: true,
        connectTimeoutMs: 90000,
        defaultQueryTimeoutMs: 90000,
        keepAliveIntervalMs: 20000
    });

    sessions.set(instanceId, sock);
    sock.ev.on('creds.update', saveCreds);

    sock.ev.on('connection.update', async (update) => {
        const { connection, lastDisconnect, qr } = update;
        
        if (qr) {
            console.log(`[Instance ${instanceId}] Live QR string received! Length: ${qr.length}`);
            try {
                const qrImage = await qrcode.toDataURL(qr);
                qrCodes.set(instanceId, qrImage);
            } catch (e) {
                console.error("QR generation error, storing raw QR string:", e);
                qrCodes.set(instanceId, qr);
            }
        }

        if (connection === 'open') {
            qrCodes.delete(instanceId);
            sessions.set(instanceId, sock);
            const rawPhone = sock.user?.id ? sock.user.id.split(':')[0] : '';
            const phone = rawPhone ? `+${rawPhone}` : '+1555' + Math.floor(1000000 + Math.random() * 9000000);
            console.log(`[Instance ${instanceId}] WhatsApp connection established for ${phone}!`);

            // Sync authentication state to PHP database via internal API call
            try {
                const http = require('http');
                const postData = JSON.stringify({ action: 'authenticate', instance_id: instanceId, phone: phone });
                const req = http.request('http://127.0.0.1/wapi/api/v1/instance/action.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(postData) }
                });
                req.write(postData);
                req.end();
            } catch (e) {
                console.error("Sync error:", e.message);
            }
        } else if (connection === 'close') {
            const statusCode = (lastDisconnect?.error)?.output?.statusCode;
            console.log(`[Instance ${instanceId}] Connection closed with status code: ${statusCode}`);
            const shouldReconnect = statusCode !== DisconnectReason.loggedOut;
            sessions.delete(instanceId);
            qrCodes.delete(instanceId);

            if (statusCode === DisconnectReason.loggedOut || statusCode === 401 || statusCode === 403 || statusCode === 428) {
                try {
                    fs.rmSync(sessionDir, { recursive: true, force: true });
                } catch(e) {}
            }

            if (shouldReconnect) {
                setTimeout(() => initSession(instanceId), 2000);
            }
        }
    });

    return sock;
}

app.listen(PORT, '0.0.0.0', () => {
    console.log(`[UltraWAPI Node Engine] Running on port ${PORT}`);
});

module.exports = app;
