// ========================
// Cloudflare Worker for Astroyogi Voice OTP
// ========================

// Helper: base64url encode (no padding)
function base64urlEncode(str) {
    return btoa(str)
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '');
}

// Generate JWT token
function generateAstroyogiToken() {
    const header = { alg: "none", typ: "JWT" };
    const payload = {
        UserType: "TtaAppUser",
        EntityId: "29426901",
        SourceUserType: "TtaAppUser",
        SourceEntityId: "29426901",
        nbf: Math.floor(Date.now() / 1000),
        exp: Math.floor(Date.now() / 1000) + 7776000
    };
    const encodedHeader = base64urlEncode(JSON.stringify(header));
    const encodedPayload = base64urlEncode(JSON.stringify(payload));
    return `${encodedHeader}.${encodedPayload}.`;
}

// Generate random device ID
function randomDeviceId() {
    const buf = new Uint8Array(8);
    crypto.getRandomValues(buf);
    return Array.from(buf)
        .map(b => b.toString(16).padStart(2, '0'))
        .join('');
}

// Generate random IP
function generateRandomIP() {
    return Math.floor(Math.random() * 255) + 1 + '.' +
           Math.floor(Math.random() * 255) + '.' +
           Math.floor(Math.random() * 255) + '.' +
           (Math.floor(Math.random() * 254) + 1);
}

// Send OTP function
async function sendOTP(mobile) {
    const cleanPhone = mobile.replace(/\D/g, '');
    if (cleanPhone.length !== 10) {
        throw new Error('Mobile number must be exactly 10 digits');
    }

    const token = generateAstroyogiToken();
    const deviceId = randomDeviceId();
    const ipAddress = generateRandomIP();

    const url = 'https://comm.astroyogi.com/api/OtpComm/SendOtp';
    
    const headers = {
        'Host': 'comm.astroyogi.com',
        'User-Agent': 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36',
        'Accept': 'application/json, text/plain, */*',
        'Accept-Encoding': 'gzip, deflate, br, zstd',
        'Content-Type': 'application/json',
        'sec-ch-ua-platform': '"Android"',
        'authorization': 'Bearer ' + token,
        'accept-language': 'en-US',
        'sec-ch-ua': '"Not;A=Brand";v="8", "Chromium";v="150", "Google Chrome";v="150"',
        'sec-ch-ua-mobile': '?1',
        'origin': 'https://www.astroyogi.com',
        'sec-fetch-site': 'same-site',
        'sec-fetch-mode': 'cors',
        'sec-fetch-dest': 'empty',
        'referer': 'https://www.astroyogi.com/registration/login.aspx',
        'priority': 'u=1, i',
        'X-Forwarded-For': ipAddress,
        'Client-IP': ipAddress
    };

    const body = JSON.stringify({
        phoneCode: '91',
        countryCode: 'IN',
        mobileNumber: cleanPhone,
        platform: 'Web',
        IpAddress: ipAddress,
        requestType: 'call',
        countryCodeByHeader: 'IN',
        phoneDeviceId: deviceId
    });

    const response = await fetch(url, {
        method: 'POST',
        headers: headers,
        body: body
    });

    const data = await response.json();
    
    return {
        success: response.status === 200,
        status: response.status,
        data: data,
        mobile: cleanPhone,
        deviceId: deviceId,
        ipAddress: ipAddress
    };
}

// Serve HTML page
function serveHTML() {
    return `<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Astroyogi Voice OTP</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        h1 {
            text-align: center;
            color: #333;
            margin-bottom: 10px;
            font-size: 2.5em;
        }
        .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 30px;
        }
        .input-group {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        input[type="text"] {
            flex: 1;
            padding: 15px;
            border: 2px solid #ddd;
            border-radius: 10px;
            font-size: 1.1em;
            transition: 0.3s;
        }
        input[type="text"]:focus {
            outline: none;
            border-color: #764ba2;
            box-shadow: 0 0 20px rgba(118, 75, 162, 0.2);
        }
        button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 10px;
            font-size: 1.1em;
            font-weight: 600;
            cursor: pointer;
            transition: 0.3s;
            white-space: nowrap;
        }
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(118, 75, 162, 0.4);
        }
        button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        .message {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: 600;
            display: none;
        }
        .message.success {
            display: block;
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message.error {
            display: block;
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .message.info {
            display: block;
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        .loader {
            display: none;
            text-align: center;
            padding: 20px;
        }
        .loader.active {
            display: block;
        }
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #764ba2;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .result-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-top: 15px;
            border-left: 4px solid #764ba2;
        }
        .result-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
        }
        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
        }
        .status-success {
            background: #d4edda;
            color: #155724;
        }
        .status-failed {
            background: #f8d7da;
            color: #721c24;
        }
        .response-body {
            display: none;
            background: white;
            padding: 10px;
            border-radius: 5px;
            margin-top: 10px;
            overflow-x: auto;
        }
        .response-body.active {
            display: block;
        }
        .response-body pre {
            margin: 0;
            font-size: 0.85em;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .arrow {
            display: inline-block;
            transition: 0.3s;
            margin-left: 8px;
        }
        .arrow.rotated {
            transform: rotate(180deg);
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        .stat-item {
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        .stat-value {
            font-size: 2em;
            font-weight: 700;
            color: #764ba2;
        }
        .stat-label {
            color: #666;
            font-size: 0.85em;
            margin-top: 5px;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            color: #666;
            font-size: 0.9em;
        }
        @media (max-width: 600px) {
            .container { padding: 20px; }
            .input-group { flex-direction: column; }
            button { width: 100%; }
            h1 { font-size: 1.8em; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📞 Voice OTP Sender</h1>
        <p class="subtitle">Send voice OTP to any Indian mobile number</p>
        
        <div class="input-group">
            <input type="text" id="mobileInput" placeholder="Enter 10-digit mobile number" maxlength="10">
            <button id="sendBtn" onclick="sendOTP()">🚀 Send OTP</button>
        </div>
        
        <div id="message" class="message"></div>
        <div id="loader" class="loader">
            <div class="spinner"></div>
            <p style="margin-top: 10px; color: #666;">Sending OTP request...</p>
        </div>
        <div id="resultContainer"></div>
        
        <div class="footer">⚡ Powered by Astroyogi API</div>
    </div>

    <script>
        async function sendOTP() {
            const mobile = document.getElementById('mobileInput').value.replace(/\\D/g, '');
            const messageDiv = document.getElementById('message');
            const loader = document.getElementById('loader');
            const sendBtn = document.getElementById('sendBtn');
            const resultContainer = document.getElementById('resultContainer');
            
            resultContainer.innerHTML = '';
            messageDiv.className = 'message';
            messageDiv.textContent = '';
            
            if (mobile.length !== 10) {
                showMessage('Please enter a valid 10-digit mobile number', 'error');
                return;
            }

            sendBtn.disabled = true;
            loader.classList.add('active');
            showMessage('Sending OTP to ' + mobile + '...', 'info');

            try {
                const response = await fetch('/api/send-otp?mobile=' + mobile);
                const result = await response.json();
                
                displayResult(result);
                
                if (result.success) {
                    showMessage('✅ Voice OTP sent successfully!', 'success');
                } else {
                    showMessage('❌ Failed to send OTP. Status: ' + result.status, 'error');
                }
            } catch (error) {
                showMessage('❌ Error: ' + error.message, 'error');
            } finally {
                sendBtn.disabled = false;
                loader.classList.remove('active');
            }
        }

        function showMessage(text, type) {
            const messageDiv = document.getElementById('message');
            messageDiv.textContent = text;
            messageDiv.className = 'message ' + type;
        }

        function displayResult(result) {
            const container = document.getElementById('resultContainer');
            const statsHTML = \`
                <div class="stats">
                    <div class="stat-item">
                        <div class="stat-value">\${result.success ? '✅' : '❌'}</div>
                        <div class="stat-label">Status</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value">\${result.status}</div>
                        <div class="stat-label">HTTP Code</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value">\${result.mobile || 'N/A'}</div>
                        <div class="stat-label">Mobile</div>
                    </div>
                </div>
                <div class="result-card">
                    <div class="result-header" onclick="toggleResponse(this.parentElement)">
                        <span style="font-weight: 600;">📋 Response Details</span>
                        <span>
                            <span class="status-badge \${result.success ? 'status-success' : 'status-failed'}">
                                \${result.success ? 'Success' : 'Failed'}
                            </span>
                            <span class="arrow">▼</span>
                        </span>
                    </div>
                    <div class="response-body">
                        <pre>\${JSON.stringify(result.data, null, 2)}</pre>
                    </div>
                </div>
            \`;
            container.innerHTML = statsHTML;
        }

        function toggleResponse(element) {
            const body = element.querySelector('.response-body');
            const arrow = element.querySelector('.arrow');
            if (body) {
                body.classList.toggle('active');
                if (arrow) arrow.classList.toggle('rotated');
            }
        }

        document.getElementById('mobileInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') sendOTP();
        });
    </script>
</body>
</html>`;
}

// ========================
// Cloudflare Worker Entry Point
// ========================
export default {
    async fetch(request) {
        const url = new URL(request.url);
        
        // Handle CORS
        if (request.method === 'OPTIONS') {
            return new Response(null, {
                status: 204,
                headers: {
                    'Access-Control-Allow-Origin': '*',
                    'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
                    'Access-Control-Allow-Headers': 'Content-Type',
                }
            });
        }

        // API endpoint - Send OTP
        if (url.pathname === '/api/send-otp') {
            const mobile = url.searchParams.get('mobile');
            
            if (!mobile) {
                return new Response(JSON.stringify({
                    success: false,
                    error: 'Missing mobile parameter'
                }), {
                    status: 400,
                    headers: {
                        'Content-Type': 'application/json',
                        'Access-Control-Allow-Origin': '*'
                    }
                });
            }

            try {
                const result = await sendOTP(mobile);
                return new Response(JSON.stringify(result), {
                    status: 200,
                    headers: {
                        'Content-Type': 'application/json',
                        'Access-Control-Allow-Origin': '*'
                    }
                });
            } catch (error) {
                return new Response(JSON.stringify({
                    success: false,
                    error: error.message
                }), {
                    status: 500,
                    headers: {
                        'Content-Type': 'application/json',
                        'Access-Control-Allow-Origin': '*'
                    }
                });
            }
        }

        // Serve HTML page for root
        if (url.pathname === '/' || url.pathname === '') {
            return new Response(serveHTML(), {
                status: 200,
                headers: {
                    'Content-Type': 'text/html; charset=utf-8',
                }
            });
        }

        // 404 for other routes
        return new Response('Not Found', { status: 404 });
    }
};
