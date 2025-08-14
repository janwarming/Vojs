<?php
// IP Security Analyzer v1.06

// Configuration
ini_set('display_errors', 0); // Hide errors in production
error_reporting(E_ALL);

class IPSecurityAnalyzer {
    private $ip;
    private $timeout;
    
    // Common ports for scanning
    private const COMMON_PORTS = [
        21 => 'FTP',
        22 => 'SSH',
        23 => 'Telnet',
        25 => 'SMTP',
        53 => 'DNS',
        80 => 'HTTP',
        110 => 'POP3',
        143 => 'IMAP',
        443 => 'HTTPS',
        993 => 'IMAPS',
        995 => 'POP3S',
        3389 => 'RDP',
        5432 => 'PostgreSQL',
        3306 => 'MySQL',
        1433 => 'MSSQL',
        6379 => 'Redis',
        27017 => 'MongoDB'
    ];
    
    public function __construct($ip, $timeout = 10) {
        $this->ip = $ip;
        $this->timeout = $timeout;
    }
    
    /**
     * Validate IP address
     */
    public function validateIP() {
        if (!filter_var($this->ip, FILTER_VALIDATE_IP)) {
            throw new Exception("Invalid IP address format: {$this->ip}");
        }
        
        if (filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return ['is_private' => true, 'note' => 'Private/Reserved IP address'];
        }
        
        return ['is_private' => false];
    }
    
    /**
     * Get comprehensive geolocation information using real APIs
     */
    public function getGeolocationInfo() {
        try {
            $geoData = [];
            
            // Primary API: ipapi.co
            $primaryData = $this->getIPAPIData();
            if ($primaryData) {
                $geoData = array_merge($geoData, $primaryData);
            }
            
            // Fallback API: ip-api.com
            if (empty($geoData)) {
                $fallbackData = $this->getIPAPIComData();
                if ($fallbackData) {
                    $geoData = array_merge($geoData, $fallbackData);
                }
            }
            
            // Add reverse DNS
            $reverseDns = @gethostbyaddr($this->ip);
            $geoData['reverse_dns'] = ($reverseDns && $reverseDns !== $this->ip) ? $reverseDns : 'Not available';
            
            return $geoData;
            
        } catch (Exception $e) {
            return ['error' => 'Unable to retrieve geolocation: ' . $e->getMessage()];
        }
    }
    
    /**
     * Get data from ipapi.co (real API)
     */
    private function getIPAPIData() {
        try {
            $url = "https://ipapi.co/{$this->ip}/json/";
            
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 5,
                    'user_agent' => 'Vojsio-IP-Analyzer/1.06'
                ]
            ]);
            
            $response = @file_get_contents($url, false, $context);
            
            if ($response) {
                $data = json_decode($response, true);
                
                if ($data && !isset($data['error'])) {
                    return [
                        'country' => $data['country_name'] ?? 'Unknown',
                        'country_code' => $data['country_code'] ?? 'Unknown',
                        'region' => $data['region'] ?? 'Unknown',
                        'city' => $data['city'] ?? 'Unknown',
                        'postal_code' => $data['postal'] ?? 'Unknown',
                        'latitude' => $data['latitude'] ?? 'Unknown',
                        'longitude' => $data['longitude'] ?? 'Unknown',
                        'timezone' => $data['timezone'] ?? 'Unknown',
                        'organization' => $data['org'] ?? 'Unknown',
                        'asn' => $data['asn'] ?? 'Unknown',
                        'currency' => $data['currency'] ?? 'Unknown',
                        'languages' => $data['languages'] ?? 'Unknown'
                    ];
                }
            }
        } catch (Exception $e) {
            // API call failed
        }
        
        return null;
    }
    
    /**
     * Check IP reputation using AbuseIPDB (real threat intelligence)
     */
    public function getAbuseIPDBData() {
        try {
            $apiKey = 'b72873b554aba41f786a8d621e3fddbe66e26af140d3831d036889dba215e86145628bb7c8c020f7';
            $url = 'https://api.abuseipdb.com/api/v2/check';
            
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => [
                        'Key: ' . $apiKey,
                        'Accept: application/json',
                        'User-Agent: Vojsio-IP-Analyzer/1.06'
                    ],
                    'timeout' => 10,
                    'content' => http_build_query([
                        'ipAddress' => $this->ip,
                        'maxAgeInDays' => 90,
                        'verbose' => ''
                    ])
                ]
            ]);
            
            $url .= '?' . http_build_query([
                'ipAddress' => $this->ip,
                'maxAgeInDays' => 90,
                'verbose' => ''
            ]);
            
            $response = @file_get_contents($url, false, $context);
            
            if ($response) {
                $data = json_decode($response, true);
                
                if ($data && !isset($data['errors'])) {
                    return [
                        'ip_address' => $data['ipAddress'] ?? $this->ip,
                        'is_public' => $data['isPublic'] ?? false,
                        'ip_version' => $data['ipVersion'] ?? 'Unknown',
                        'is_whitelisted' => $data['isWhitelisted'] ?? false,
                        'abuse_confidence' => $data['abuseConfidencePercentage'] ?? 0,
                        'country_code' => $data['countryCode'] ?? 'Unknown',
                        'country_name' => $data['countryName'] ?? 'Unknown',
                        'usage_type' => $data['usageType'] ?? 'Unknown',
                        'isp' => $data['isp'] ?? 'Unknown',
                        'domain' => $data['domain'] ?? 'Unknown',
                        'total_reports' => $data['totalReports'] ?? 0,
                        'distinct_users' => $data['numDistinctUsers'] ?? 0,
                        'last_reported' => $data['lastReportedAt'] ?? 'Never'
                    ];
                } else {
                    return ['error' => $data['errors'][0]['detail'] ?? 'Unknown AbuseIPDB error'];
                }
            } else {
                return ['error' => 'Failed to connect to AbuseIPDB API'];
            }
        } catch (Exception $e) {
            return ['error' => 'AbuseIPDB lookup failed: ' . $e->getMessage()];
        }
    }
    
    /**
     * Get data from ip-api.com as fallback (real API)
     */
    private function getIPAPIComData() {
        try {
            $url = "http://ip-api.com/json/{$this->ip}?fields=status,message,country,countryCode,region,regionName,city,zip,lat,lon,timezone,isp,org,as,query";
            
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 5,
                    'user_agent' => 'Vojsio-IP-Analyzer/1.06'
                ]
            ]);
            
            $response = @file_get_contents($url, false, $context);
            
            if ($response) {
                $data = json_decode($response, true);
                
                if ($data && $data['status'] === 'success') {
                    return [
                        'country' => $data['country'] ?? 'Unknown',
                        'country_code' => $data['countryCode'] ?? 'Unknown',
                        'region' => $data['regionName'] ?? 'Unknown',
                        'city' => $data['city'] ?? 'Unknown',
                        'postal_code' => $data['zip'] ?? 'Unknown',
                        'latitude' => $data['lat'] ?? 'Unknown',
                        'longitude' => $data['lon'] ?? 'Unknown',
                        'timezone' => $data['timezone'] ?? 'Unknown',
                        'organization' => $data['org'] ?? 'Unknown',
                        'isp' => $data['isp'] ?? 'Unknown',
                        'asn' => $data['as'] ?? 'Unknown'
                    ];
                }
            }
        } catch (Exception $e) {
            // Fallback API failed
        }
        
        return null;
    }
    
    /**
     * Perform real port scanning
     */
    public function scanCommonPorts() {
        $openPorts = [];
        $scanResults = [];
        
        foreach (self::COMMON_PORTS as $port => $service) {
            $isOpen = $this->checkPort($port);
            $scanResults[$port] = [
                'service' => $service,
                'status' => $isOpen ? 'open' : 'closed',
                'is_open' => $isOpen
            ];
            
            if ($isOpen) {
                $openPorts[] = $port;
            }
        }
        
        return [
            'open_ports' => $openPorts,
            'scan_results' => $scanResults,
            'total_scanned' => count(self::COMMON_PORTS),
            'open_count' => count($openPorts)
        ];
    }
    
    /**
     * Check if a specific port is open (real test)
     */
    private function checkPort($port) {
        $connection = @fsockopen($this->ip, $port, $errno, $errstr, 2);
        if ($connection) {
            fclose($connection);
            return true;
        }
        return false;
    }
    
    /**
     * Get real WHOIS information
     */
    public function getWhoisInfo() {
        try {
            $whoisServers = [
                'whois.arin.net',
                'whois.ripe.net',
                'whois.apnic.net',
                'whois.lacnic.net',
                'whois.afrinic.net'
            ];
            
            $whoisData = [];
            
            foreach ($whoisServers as $server) {
                $result = $this->queryWhoisServer($server, $this->ip);
                if ($result && strlen($result) > 100) {
                    $whoisData[$server] = $this->parseWhoisData($result);
                    break; // Use first successful result
                }
            }
            
            return $whoisData;
            
        } catch (Exception $e) {
            return ['error' => 'WHOIS lookup failed: ' . $e->getMessage()];
        }
    }
    
    /**
     * Query real WHOIS server
     */
    private function queryWhoisServer($server, $ip) {
        try {
            $socket = @fsockopen($server, 43, $errno, $errstr, 5);
            if (!$socket) {
                return false;
            }
            
            fwrite($socket, $ip . "\r\n");
            $result = '';
            while (!feof($socket)) {
                $result .= fgets($socket, 128);
            }
            fclose($socket);
            
            return $result;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Parse real WHOIS data
     */
    private function parseWhoisData($whoisText) {
        $parsed = [];
        $lines = explode("\n", $whoisText);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || $line[0] === '#' || $line[0] === '%') {
                continue;
            }
            
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $key = trim(strtolower($key));
                $value = trim($value);
                
                if (in_array($key, ['netname', 'orgname', 'org-name', 'organization', 'netrange', 'cidr', 'country', 'admin-c', 'tech-c'])) {
                    $parsed[$key] = $value;
                }
            }
        }
        
        return $parsed;
    }
    
    /**
     * Get real network information
     */
    public function getNetworkInfo() {
        try {
            $networkInfo = [];
            
            // Real IP classification
            $networkInfo['ip_version'] = filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 'IPv4' : 'IPv6';
            $networkInfo['is_private'] = !filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE);
            $networkInfo['is_reserved'] = !filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE);
            
            // IP type classification
            if ($networkInfo['is_private']) {
                $networkInfo['ip_type'] = 'Private';
            } elseif ($networkInfo['is_reserved']) {
                $networkInfo['ip_type'] = 'Reserved';
            } else {
                $networkInfo['ip_type'] = 'Public';
            }
            
            // Real reachability test
            $networkInfo['reachable'] = $this->pingIP();
            
            return $networkInfo;
            
        } catch (Exception $e) {
            return ['error' => 'Network analysis failed: ' . $e->getMessage()];
        }
    }
    
    /**
     * Real ping test
     */
    private function pingIP() {
        $socket = @fsockopen($this->ip, 80, $errno, $errstr, 3);
        if ($socket) {
            fclose($socket);
            return true;
        }
        
        // Try HTTPS
        $socket = @fsockopen($this->ip, 443, $errno, $errstr, 3);
        if ($socket) {
            fclose($socket);
            return true;
        }
        
        return false;
    }
    
    /**
     * Main analysis function - real data only
     */
    public function getSecurityAnalysis() {
        try {
            // Validate IP first
            $validation = $this->validateIP();
            
            $analysis = [
                'ip' => $this->ip,
                'timestamp' => date('Y-m-d H:i:s'),
                'validation' => $validation,
                'geolocation' => $this->getGeolocationInfo(),
                'network_info' => $this->getNetworkInfo(),
                'port_scan' => $this->scanCommonPorts(),
                'abuse_check' => $this->getAbuseIPDBData(),
                'whois' => $this->getWhoisInfo()
            ];
            
            return $analysis;
            
        } catch (Exception $e) {
            return [
                'error' => $e->getMessage(),
                'ip' => $this->ip,
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }
    }
}

// API Endpoint
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action']) && $_POST['action'] === 'analyze') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    try {
        $ip = trim($_POST['ip'] ?? '');
        
        if (empty($ip)) {
            throw new Exception('IP address is required');
        }
        
        // Clean IP
        $ip = preg_replace('/^https?:\/\//', '', $ip);
        $ip = preg_replace('/\/.*$/', '', $ip);
        $ip = preg_replace('/:.+$/', '', $ip); // Remove port if present
        
        $analyzer = new IPSecurityAnalyzer($ip);
        $analysis = $analyzer->getSecurityAnalysis();
        
        echo json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'error' => $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IP v1.06</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@300;400;500;600;700&display=swap');
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'JetBrains Mono', 'Courier New', monospace;
            background: #000000;
            color: #ff0000;
            min-height: 100vh;
            overflow-x: hidden;
            position: relative;
        }

        /* Matrix rain effect with red */
        .matrix-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: -1;
            opacity: 0.1;
        }

        .matrix-canvas {
            display: block;
        }

        /* Animated grid background */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: 
                linear-gradient(rgba(255, 0, 0, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 0, 0, 0.03) 1px, transparent 1px);
            background-size: 50px 50px;
            animation: grid-move 20s linear infinite;
            z-index: -1;
        }

        @keyframes grid-move {
            0% { transform: translate(0, 0); }
            100% { transform: translate(50px, 50px); }
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
            position: relative;
            z-index: 1;
        }

        .header {
            text-align: center;
            margin-bottom: 50px;
            position: relative;
        }

        .header::before {
            content: '';
            position: absolute;
            top: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 200px;
            height: 2px;
            background: linear-gradient(90deg, transparent, #ff0000, transparent);
            animation: pulse-line 2s ease-in-out infinite;
        }

        @keyframes pulse-line {
            0%, 100% { opacity: 0.5; transform: translateX(-50%) scaleX(1); }
            50% { opacity: 1; transform: translateX(-50%) scaleX(1.2); }
        }

        .header h1 {
            font-size: 3em;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 4px;
            margin-bottom: 15px;
            color: #ff0000;
            text-shadow: 
                0 0 5px #ff0000,
                0 0 10px #ff0000,
                0 0 15px #ff0000,
                0 0 20px #ff0000;
            animation: glow-pulse 3s ease-in-out infinite;
        }

        @keyframes glow-pulse {
            0%, 100% { text-shadow: 0 0 5px #ff0000, 0 0 10px #ff0000, 0 0 15px #ff0000; }
            50% { text-shadow: 0 0 10px #ff0000, 0 0 20px #ff0000, 0 0 30px #ff0000, 0 0 40px #ff0000; }
        }

        .header .subtitle {
            font-size: 1.1em;
            color: #aa0000;
            font-weight: 400;
            letter-spacing: 2px;
            opacity: 0.8;
        }

        .version-badge {
            display: inline-block;
            background: linear-gradient(45deg, #330000, #660000);
            border: 1px solid #ff0000;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.8em;
            margin-top: 10px;
            animation: badge-glow 3s ease-in-out infinite;
        }

        @keyframes badge-glow {
            0%, 100% { box-shadow: 0 0 5px rgba(255, 0, 0, 0.3); }
            50% { box-shadow: 0 0 15px rgba(255, 0, 0, 0.6); }
        }

        .terminal-window {
            background: rgba(20, 0, 0, 0.95);
            border: 2px solid #ff0000;
            border-radius: 10px;
            box-shadow: 
                0 0 20px rgba(255, 0, 0, 0.3),
                inset 0 0 20px rgba(255, 0, 0, 0.1);
            margin-bottom: 30px;
            overflow: hidden;
            position: relative;
        }

        .terminal-header {
            background: linear-gradient(90deg, #330000, #440000);
            padding: 10px 20px;
            border-bottom: 1px solid #ff0000;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .terminal-buttons {
            display: flex;
            gap: 8px;
        }

        .terminal-button {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #ff5555;
        }

        .terminal-button:nth-child(2) { background: #ffaa00; }
        .terminal-button:nth-child(3) { background: #ff0000; }

        .terminal-title {
            font-size: 0.9em;
            color: #ff0000;
            font-weight: 600;
        }

        .terminal-content {
            padding: 30px;
        }

        .form-group {
            margin-bottom: 25px;
            position: relative;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #ff0000;
            font-size: 0.9em;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .form-input {
            width: 100%;
            padding: 15px 20px;
            background: rgba(0, 0, 0, 0.8);
            border: 2px solid #330000;
            border-radius: 8px;
            color: #ff0000;
            font-family: 'JetBrains Mono', monospace;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: #ff0000;
            background: rgba(20, 0, 0, 0.9);
            box-shadow: 
                0 0 10px rgba(255, 0, 0, 0.3),
                inset 0 0 10px rgba(255, 0, 0, 0.1);
        }

        .form-input::placeholder {
            color: #660000;
        }

        .analyze-btn {
            width: 100%;
            padding: 18px;
            background: linear-gradient(45deg, #330000, #660000);
            border: 2px solid #ff0000;
            border-radius: 8px;
            color: #ff0000;
            font-family: 'JetBrains Mono', monospace;
            font-size: 16px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .analyze-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 0, 0, 0.2), transparent);
            transition: left 0.5s ease;
        }

        .analyze-btn:hover {
            background: linear-gradient(45deg, #440000, #880000);
            box-shadow: 
                0 0 20px rgba(255, 0, 0, 0.5),
                inset 0 0 20px rgba(255, 0, 0, 0.1);
            transform: translateY(-2px);
        }

        .analyze-btn:hover::before {
            left: 100%;
        }

        .analyze-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .loading {
            text-align: center;
            padding: 50px 20px;
            display: none;
        }

        .loading-spinner {
            width: 60px;
            height: 60px;
            margin: 0 auto 30px;
            position: relative;
        }

        .spinner-ring {
            position: absolute;
            width: 100%;
            height: 100%;
            border: 3px solid transparent;
            border-top: 3px solid #ff0000;
            border-radius: 50%;
            animation: matrix-spin 1s linear infinite;
        }

        .spinner-ring:nth-child(2) {
            width: 45px;
            height: 45px;
            top: 7.5px;
            left: 7.5px;
            border-top-color: #aa0000;
            animation-delay: -0.3s;
        }

        .spinner-ring:nth-child(3) {
            width: 30px;
            height: 30px;
            top: 15px;
            left: 15px;
            border-top-color: #660000;
            animation-delay: -0.6s;
        }

        @keyframes matrix-spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .loading-text {
            color: #ff0000;
            font-size: 1.1em;
            font-weight: 600;
            margin-bottom: 10px;
            animation: text-glow 2s ease-in-out infinite;
        }

        @keyframes text-glow {
            0%, 100% { text-shadow: 0 0 5px #ff0000; }
            50% { text-shadow: 0 0 15px #ff0000, 0 0 25px #ff0000; }
        }

        .loading-dots {
            color: #aa0000;
            font-size: 0.9em;
        }

        .results {
            margin-top: 30px;
            display: none;
        }

        .results-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 30px;
        }

        .result-card {
            background: rgba(20, 0, 0, 0.9);
            border: 2px solid #330000;
            border-radius: 12px;
            padding: 30px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .result-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #ff0000, #aa0000);
        }

        .result-card:hover {
            border-color: #ff0000;
            box-shadow: 
                0 10px 30px rgba(255, 0, 0, 0.2),
                inset 0 0 20px rgba(255, 0, 0, 0.05);
            transform: translateY(-5px);
        }

        .card-title {
            font-size: 1.3em;
            color: #ff0000;
            margin-bottom: 25px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .card-icon {
            font-size: 1.5em;
            text-shadow: 0 0 10px #ff0000;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: start;
            padding: 12px 0;
            border-bottom: 1px solid #330000;
            transition: all 0.3s ease;
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-item:hover {
            background: rgba(255, 0, 0, 0.05);
            padding-left: 10px;
        }

        .info-label {
            font-weight: 600;
            color: #aa0000;
            flex-shrink: 0;
            margin-right: 20px;
            font-size: 0.9em;
        }

        .info-value {
            color: #ff0000;
            text-align: right;
            word-break: break-word;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.85em;
            text-shadow: 0 0 5px rgba(255, 0, 0, 0.3);
        }

        .error {
            background: rgba(255, 0, 0, 0.1);
            border: 2px solid #ff0000;
            color: #ff6666;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            font-family: 'JetBrains Mono', monospace;
        }

        .port-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }

        .port-item {
            padding: 6px 12px;
            background: rgba(0, 0, 0, 0.5);
            border-radius: 6px;
            font-size: 0.8em;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .port-open {
            border: 1px solid #ff0000;
            color: #ff6666;
        }

        .port-closed {
            border: 1px solid #666666;
            color: #aaaaaa;
            opacity: 0.6;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }
            
            .header h1 {
                font-size: 2.2em;
                letter-spacing: 2px;
            }
            
            .results-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .info-item {
                flex-direction: column;
                align-items: start;
            }
            
            .info-value {
                text-align: left;
                margin-top: 5px;
            }
            
            .terminal-content {
                padding: 20px;
            }
        }

        /* Scrollbar styling */
        ::-webkit-scrollbar {
            width: 12px;
        }

        ::-webkit-scrollbar-track {
            background: #000000;
            border: 1px solid #330000;
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, #ff0000, #660000);
            border-radius: 6px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, #ff0000, #aa0000);
        }
    </style>
</head>
<body>
    <div class="matrix-bg">
        <canvas class="matrix-canvas" id="matrixCanvas"></canvas>
    </div>

    <div class="container">
        <div class="header">
            <h1>🔍 Vojs.IO SECURITY ANALYZER</h1>
            <div class="subtitle">ENHANCED IP SECURITY ANALYSIS</div>
            <div class="version-badge">v1.06</div>
        </div>

        <div class="terminal-window">
            <div class="terminal-header">
                <div class="terminal-buttons">
                    <div class="terminal-button"></div>
                    <div class="terminal-button"></div>
                    <div class="terminal-button"></div>
                </div>
                <div class="terminal-title">Enhanced IP Security Analysis Engine</div>
            </div>
            
            <div class="terminal-content">
                <form id="analysisForm">
                    <div class="form-group">
                        <label class="form-label">[TARGET_IP_ADDRESS]</label>
                        <input type="text" class="form-input" id="ip" name="ip" 
                               placeholder="192.168.1.1 or 2001:db8::1" required>
                    </div>
                    
                    <button type="submit" class="analyze-btn" id="analyzeBtn">
                        > INITIATE_ANALYSIS
                    </button>
                </form>

                <div class="loading" id="loading">
                    <div class="loading-spinner">
                        <div class="spinner-ring"></div>
                        <div class="spinner-ring"></div>
                        <div class="spinner-ring"></div>
                    </div>
                    <div class="loading-text">ANALYZING IP WITH REAL DATA</div>
                    <div class="loading-dots">Validating IP address...</div>
                </div>

                <div class="results" id="results">
                    <!-- Results will be populated here -->
                </div>
            </div>
        </div>
    </div>

    <script>
        // Matrix rain effect with red
        function initMatrixRain() {
            const canvas = document.getElementById('matrixCanvas');
            const ctx = canvas.getContext('2d');
            
            canvas.width = window.innerWidth;
            canvas.height = window.innerHeight;
            
            const matrix = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789@#$%^&*()_+-=[]{}|;:,.<>?";
            const drops = [];
            const fontSize = 14;
            const columns = canvas.width / fontSize;
            
            for (let x = 0; x < columns; x++) {
                drops[x] = 1;
            }
            
            function draw() {
                ctx.fillStyle = 'rgba(0, 0, 0, 0.04)';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                
                ctx.fillStyle = '#ff0000';
                ctx.font = fontSize + 'px JetBrains Mono';
                
                for (let i = 0; i < drops.length; i++) {
                    const text = matrix[Math.floor(Math.random() * matrix.length)];
                    ctx.fillText(text, i * fontSize, drops[i] * fontSize);
                    
                    if (drops[i] * fontSize > canvas.height && Math.random() > 0.975) {
                        drops[i] = 0;
                    }
                    drops[i]++;
                }
            }
            
            setInterval(draw, 35);
        }
        
        // Initialize matrix effect
        initMatrixRain();
        
        // Resize handler
        window.addEventListener('resize', () => {
            const canvas = document.getElementById('matrixCanvas');
            canvas.width = window.innerWidth;
            canvas.height = window.innerHeight;
        });

        document.getElementById('analysisForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const ip = document.getElementById('ip').value.trim();
            
            if (!ip) {
                alert('Please enter an IP address');
                return;
            }
            
            document.getElementById('loading').style.display = 'block';
            document.getElementById('results').style.display = 'none';
            document.getElementById('analyzeBtn').disabled = true;
            
            // Real loading steps
            const loadingSteps = [
                'Validating IP address...',
                'Querying ipapi.co geolocation...',
                'Fallback to ip-api.com if needed...',
                'Checking AbuseIPDB threat intelligence...',
                'Performing real port scanning...',
                'Testing network connectivity...',
                'Querying WHOIS databases...',
                'Reverse DNS lookup...',
                'Compiling real data report...'
            ];
            
            let stepIndex = 0;
            const loadingInterval = setInterval(() => {
                if (stepIndex < loadingSteps.length) {
                    document.querySelector('.loading-dots').textContent = loadingSteps[stepIndex];
                    stepIndex++;
                }
            }, 1000);
            
            try {
                const formData = new FormData();
                formData.append('action', 'analyze');
                formData.append('ip', ip);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();
                displayResults(data);
                
            } catch (error) {
                console.error('Error:', error);
                document.getElementById('results').innerHTML = 
                    `<div class="error">[ERROR] ${error.message}</div>`;
                document.getElementById('results').style.display = 'block';
            } finally {
                clearInterval(loadingInterval);
                document.getElementById('loading').style.display = 'none';
                document.getElementById('analyzeBtn').disabled = false;
            }
        });
        
        function formatPortScan(portData) {
            if (!portData || !portData.scan_results) return '';
            
            let html = '<div class="port-list">';
            const results = portData.scan_results;
            
            Object.keys(results).forEach(port => {
                const result = results[port];
                const statusClass = result.is_open ? 'port-open' : 'port-closed';
                
                html += `
                    <div class="port-item ${statusClass}">
                        ${port}/${result.service}
                        <span>${result.status.toUpperCase()}</span>
                    </div>
                `;
            });
            
            html += '</div>';
            return html;
        }
        
        function displayResults(data) {
            const resultsDiv = document.getElementById('results');
            
            if (data.error) {
                resultsDiv.innerHTML = `<div class="error">[SYSTEM_ERROR] ${data.error}</div>`;
                resultsDiv.style.display = 'block';
                return;
            }
            
            const geo = data.geolocation || {};
            const network = data.network_info || {};
            const ports = data.port_scan || {};
            const abuse = data.abuse_check || {};
            const whois = data.whois || {};
            
            const html = `
                <div class="results-grid">
                    <div class="result-card">
                        <div class="card-title">
                            <span class="card-icon">🌐</span>
                            IP INFORMATION
                        </div>
                        <div class="info-item">
                            <span class="info-label">IP_ADDRESS:</span>
                            <span class="info-value">${data.ip || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">IP_VERSION:</span>
                            <span class="info-value">${network.ip_version || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">IP_TYPE:</span>
                            <span class="info-value">${network.ip_type || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">REACHABLE:</span>
                            <span class="info-value">${network.reachable ? 'YES' : 'NO'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">REVERSE_DNS:</span>
                            <span class="info-value">${geo.reverse_dns || 'Not available'}</span>
                        </div>
                    </div>
                    
                    <div class="result-card">
                        <div class="card-title">
                            <span class="card-icon">🌍</span>
                            GEOLOCATION DATA
                        </div>
                        <div class="info-item">
                            <span class="info-label">COUNTRY:</span>
                            <span class="info-value">${geo.country || 'Unknown'} ${geo.country_code ? '(' + geo.country_code + ')' : ''}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">REGION:</span>
                            <span class="info-value">${geo.region || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">CITY:</span>
                            <span class="info-value">${geo.city || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">POSTAL_CODE:</span>
                            <span class="info-value">${geo.postal_code || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">COORDINATES:</span>
                            <span class="info-value">${geo.latitude && geo.longitude ? geo.latitude + ', ' + geo.longitude : 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">TIMEZONE:</span>
                            <span class="info-value">${geo.timezone || 'Unknown'}</span>
                        </div>
                    </div>
                    
                    <div class="result-card">
                        <div class="card-title">
                            <span class="card-icon">🏢</span>
                            ORGANIZATION DATA
                        </div>
                        <div class="info-item">
                            <span class="info-label">ORGANIZATION:</span>
                            <span class="info-value">${geo.organization || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">ISP:</span>
                            <span class="info-value">${geo.isp || geo.organization || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">ASN:</span>
                            <span class="info-value">${geo.asn || 'Unknown'}</span>
                        </div>
                        ${geo.currency ? `
                        <div class="info-item">
                            <span class="info-label">CURRENCY:</span>
                            <span class="info-value">${geo.currency}</span>
                        </div>
                        ` : ''}
                    </div>
                    
                    <div class="result-card">
                        <div class="card-title">
                            <span class="card-icon">🔍</span>
                            THREAT INTELLIGENCE
                        </div>
                        ${abuse.error ? `
                        <div class="info-item">
                            <span class="info-label">ERROR:</span>
                            <span class="info-value">${abuse.error}</span>
                        </div>
                        ` : `
                        <div class="info-item">
                            <span class="info-label">ABUSE_CONFIDENCE:</span>
                            <span class="info-value">${abuse.abuse_confidence || 0}%</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">IS_PUBLIC:</span>
                            <span class="info-value">${abuse.is_public ? 'YES' : 'NO'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">IS_WHITELISTED:</span>
                            <span class="info-value">${abuse.is_whitelisted ? 'YES' : 'NO'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">USAGE_TYPE:</span>
                            <span class="info-value">${abuse.usage_type || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">TOTAL_REPORTS:</span>
                            <span class="info-value">${abuse.total_reports || 0}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">LAST_REPORTED:</span>
                            <span class="info-value">${abuse.last_reported || 'Never'}</span>
                        </div>
                        `}
                    </div>
                    
                    <div class="result-card">
                        <div class="card-title">
                            <span class="card-icon">🔓</span>
                            PORT SCAN RESULTS
                        </div>
                        <div class="info-item">
                            <span class="info-label">OPEN_PORTS:</span>
                            <span class="info-value">${ports.open_count || 0}/${ports.total_scanned || 0}</span>
                        </div>
                        ${ports.open_ports && ports.open_ports.length > 0 ? `
                        <div class="info-item">
                            <span class="info-label">OPEN_LIST:</span>
                            <span class="info-value">${ports.open_ports.join(', ')}</span>
                        </div>
                        ` : ''}
                        ${formatPortScan(ports)}
                    </div>
                    
                    ${Object.keys(whois).length > 0 && !whois.error ? `
                    <div class="result-card">
                        <div class="card-title">
                            <span class="card-icon">📋</span>
                            WHOIS INFORMATION
                        </div>
                        ${Object.keys(whois).map(server => {
                            if (whois[server] && typeof whois[server] === 'object') {
                                return Object.keys(whois[server]).map(key => `
                                    <div class="info-item">
                                        <span class="info-label">${key.toUpperCase()}:</span>
                                        <span class="info-value">${whois[server][key]}</span>
                                    </div>
                                `).join('');
                            }
                            return '';
                        }).join('')}
                    </div>
                    ` : ''}
                </div>
            `;
            
            resultsDiv.innerHTML = html;
            resultsDiv.style.display = 'block';
        }
    </script>
</body>
</html>