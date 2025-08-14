<?php
// Client Security Analyzer v1.06

// Configuration
ini_set('display_errors', 0); // Hide errors in production
error_reporting(E_ALL);

class ClientSecurityAnalyzer {
    private $userAgent;
    private $ipAddress;
    private $timeout;
    
    // Browser detection patterns - Order matters! Check specific browsers before generic ones
    private const BROWSER_PATTERNS = [
        'Edge' => '/Edg\/([0-9\.]+)/',
        'Opera' => '/OPR\/([0-9\.]+)/',
        'Vivaldi' => '/Vivaldi\/([0-9\.]+)/',
        'Brave' => '/Brave\/([0-9\.]+)/',
        'Chrome' => '/Chrome\/([0-9\.]+)/',
        'Firefox' => '/Firefox\/([0-9\.]+)/',
        'Safari' => '/Version\/([0-9\.]+).*Safari/',
        'Internet Explorer' => '/MSIE ([0-9\.]+)/',
        'IE11' => '/Trident.*rv:([0-9\.]+)/'
    ];
    
    // OS detection patterns - Order matters! Check specific OS before generic ones
    private const OS_PATTERNS = [
        'Windows 11' => '/Windows NT 10\.0.*; Win64.*rv:.*\) Gecko|Windows NT 10\.0.*WOW64|Windows NT 10\.0.*Win64/',
        'Windows 10' => '/Windows NT 10\.0/',
        'Windows 8.1' => '/Windows NT 6\.3/',
        'Windows 8' => '/Windows NT 6\.2/',
        'Windows 7' => '/Windows NT 6\.1/',
        'Windows Vista' => '/Windows NT 6\.0/',
        'Windows XP' => '/Windows NT 5\.1|Windows XP/',
        'Android' => '/Android ([0-9\.]+)/',
        'iOS' => '/OS ([0-9_\.]+) like Mac OS X/',
        'macOS Sonoma' => '/Mac OS X 10_15.*Version\/17/',
        'macOS Ventura' => '/Mac OS X 10_15.*Version\/16/',
        'macOS Monterey' => '/Mac OS X 10_15.*Version\/15/',
        'macOS Big Sur' => '/Mac OS X 10_15.*Version\/14/',
        'macOS Catalina' => '/Mac OS X 10_15/',
        'macOS Mojave' => '/Mac OS X 10_14/',
        'macOS High Sierra' => '/Mac OS X 10_13/',
        'macOS Sierra' => '/Mac OS X 10_12/',
        'Mac OS X' => '/Mac OS X ([0-9_\.]+)/',
        'ChromeOS' => '/CrOS/',
        'Ubuntu' => '/Ubuntu/',
        'Linux' => '/Linux/'
    ];
    
    // Security vulnerability databases
    private const BROWSER_VULNERABILITIES = [
        'Edge' => [
            '120' => ['status' => 'Current', 'security' => 'Good', 'notes' => 'Latest stable version'],
            '119' => ['status' => 'Supported', 'security' => 'Good', 'notes' => 'Recent version'],
            '118' => ['status' => 'Outdated', 'security' => 'Medium', 'notes' => 'Missing security updates'],
            '117' => ['status' => 'Vulnerable', 'security' => 'Low', 'notes' => 'Known security vulnerabilities']
        ],
        'Chrome' => [
            '120' => ['status' => 'Current', 'security' => 'Good', 'notes' => 'Latest stable version'],
            '119' => ['status' => 'Supported', 'security' => 'Good', 'notes' => 'Recent version'],
            '118' => ['status' => 'Outdated', 'security' => 'Medium', 'notes' => 'Missing security updates'],
            '117' => ['status' => 'Vulnerable', 'security' => 'Low', 'notes' => 'Known security vulnerabilities']
        ],
        'Firefox' => [
            '121' => ['status' => 'Current', 'security' => 'Good', 'notes' => 'Latest stable version'],
            '120' => ['status' => 'Supported', 'security' => 'Good', 'notes' => 'Recent version'],
            '119' => ['status' => 'Outdated', 'security' => 'Medium', 'notes' => 'Missing security updates']
        ],
        'Safari' => [
            '17' => ['status' => 'Current', 'security' => 'Good', 'notes' => 'Latest version'],
            '16' => ['status' => 'Supported', 'security' => 'Good', 'notes' => 'Recent version'],
            '15' => ['status' => 'Outdated', 'security' => 'Medium', 'notes' => 'Missing security updates']
        ],
        'Opera' => [
            '105' => ['status' => 'Current', 'security' => 'Good', 'notes' => 'Latest stable version'],
            '104' => ['status' => 'Supported', 'security' => 'Good', 'notes' => 'Recent version'],
            '103' => ['status' => 'Outdated', 'security' => 'Medium', 'notes' => 'Missing security updates']
        ]
    ];
    
    private const OS_VULNERABILITIES = [
        'Windows 11' => ['status' => 'Current', 'security' => 'Good', 'support' => 'Active'],
        'Windows 10' => ['status' => 'Supported', 'security' => 'Good', 'support' => 'Extended until 2025'],
        'Windows 8.1' => ['status' => 'End of Life', 'security' => 'Critical', 'support' => 'No security updates'],
        'Windows 7' => ['status' => 'End of Life', 'security' => 'Critical', 'support' => 'No security updates'],
        'macOS Sonoma' => ['status' => 'Current', 'security' => 'Good', 'support' => 'Active'],
        'macOS Ventura' => ['status' => 'Supported', 'security' => 'Good', 'support' => 'Security updates only'],
        'macOS Monterey' => ['status' => 'Legacy', 'security' => 'Medium', 'support' => 'Limited updates'],
        'Android' => ['status' => 'Variable', 'security' => 'Medium', 'support' => 'Depends on device manufacturer'],
        'iOS' => ['status' => 'Current', 'security' => 'Good', 'support' => 'Active updates'],
        'ChromeOS' => ['status' => 'Current', 'security' => 'Good', 'support' => 'Automatic updates'],
        'Ubuntu' => ['status' => 'Current', 'security' => 'Good', 'support' => 'LTS versions supported'],
        'Linux' => ['status' => 'Variable', 'security' => 'Good', 'support' => 'Depends on distribution']
    ];
    
    public function __construct($timeout = 10) {
        $this->userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $this->ipAddress = $this->getClientIP();
        $this->timeout = $timeout;
    }
    
    /**
     * Get real client IP address
     */
    private function getClientIP() {
        $ipKeys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = explode(',', $ip)[0];
                }
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    }
    
    /**
     * Analyze browser information
     */
    public function analyzeBrowser() {
        $browserInfo = [
            'user_agent' => $this->userAgent,
            'browser_name' => 'Unknown',
            'browser_version' => 'Unknown',
            'browser_engine' => 'Unknown',
            'security_status' => 'Unknown',
            'security_notes' => 'Unable to determine security status'
        ];
        
        // Detect browser and version (order matters - specific browsers first!)
        foreach (self::BROWSER_PATTERNS as $browser => $pattern) {
            if (preg_match($pattern, $this->userAgent, $matches)) {
                $browserInfo['browser_name'] = $browser;
                $browserInfo['browser_version'] = $matches[1] ?? 'Unknown';
                
                // Special handling for IE11 detection
                if ($browser === 'IE11') {
                    $browserInfo['browser_name'] = 'Internet Explorer';
                }
                
                break;
            }
        }
        
        // Detect browser engine
        if (strpos($this->userAgent, 'Gecko') !== false) {
            $browserInfo['browser_engine'] = 'Gecko';
        } elseif (strpos($this->userAgent, 'WebKit') !== false) {
            $browserInfo['browser_engine'] = 'WebKit';
        } elseif (strpos($this->userAgent, 'Blink') !== false) {
            $browserInfo['browser_engine'] = 'Blink';
        } elseif (strpos($this->userAgent, 'Trident') !== false) {
            $browserInfo['browser_engine'] = 'Trident';
        }
        
        // Check security status
        $this->checkBrowserSecurity($browserInfo);
        
        return $browserInfo;
    }
    
    /**
     * Analyze operating system information
     */
    public function analyzeOperatingSystem() {
        $osInfo = [
            'os_name' => 'Unknown',
            'os_version' => 'Unknown',
            'architecture' => 'Unknown',
            'security_status' => 'Unknown',
            'support_status' => 'Unknown'
        ];
        
        // Detect OS (order matters - specific OS first!)
        foreach (self::OS_PATTERNS as $os => $pattern) {
            if (preg_match($pattern, $this->userAgent, $matches)) {
                $osInfo['os_name'] = $os;
                
                // Extract version for specific OS
                if ($os === 'Android' && isset($matches[1])) {
                    $osInfo['os_version'] = $matches[1];
                } elseif ($os === 'iOS' && isset($matches[1])) {
                    $osInfo['os_version'] = str_replace('_', '.', $matches[1]);
                } elseif ($os === 'Mac OS X' && isset($matches[1])) {
                    $osInfo['os_version'] = str_replace('_', '.', $matches[1]);
                }
                
                break;
            }
        }
        
        // Detect architecture
        if (strpos($this->userAgent, 'WOW64') !== false || strpos($this->userAgent, 'Win64') !== false || strpos($this->userAgent, 'x86_64') !== false) {
            $osInfo['architecture'] = '64-bit';
        } elseif (strpos($this->userAgent, 'Win32') !== false || strpos($this->userAgent, 'i686') !== false) {
            $osInfo['architecture'] = '32-bit';
        } elseif (strpos($this->userAgent, 'arm') !== false || strpos($this->userAgent, 'ARM') !== false) {
            $osInfo['architecture'] = 'ARM';
        } elseif (strpos($this->userAgent, 'Mobile') !== false && $osInfo['os_name'] === 'Android') {
            $osInfo['architecture'] = 'ARM/Mobile';
        }
        
        // Check OS security status
        $this->checkOSSecurity($osInfo);
        
        return $osInfo;
    }
    
    /**
     * Get client IP and location information
     */
    public function getIPLocationInfo() {
        try {
            $ipInfo = [
                'ip_address' => $this->ipAddress,
                'country' => 'Unknown',
                'region' => 'Unknown',
                'city' => 'Unknown',
                'organization' => 'Unknown',
                'isp' => 'Unknown',
                'timezone' => 'Unknown'
            ];
            
            // Get location data from API
            $locationData = $this->getLocationFromAPI($this->ipAddress);
            if ($locationData) {
                $ipInfo = array_merge($ipInfo, $locationData);
            }
            
            return $ipInfo;
            
        } catch (Exception $e) {
            return ['error' => 'Unable to retrieve IP information: ' . $e->getMessage()];
        }
    }
    
    /**
     * Get location information from IP geolocation API
     */
    private function getLocationFromAPI($ip) {
        try {
            // Using ipapi.co (same as other tools)
            $url = "https://ipapi.co/{$ip}/json/";
            
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 5,
                    'user_agent' => 'Vojsio-Client-Analyzer/1.06'
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
                        'organization' => $data['org'] ?? 'Unknown',
                        'isp' => $data['org'] ?? 'Unknown',
                        'timezone' => $data['timezone'] ?? 'Unknown',
                        'latitude' => $data['latitude'] ?? 'Unknown',
                        'longitude' => $data['longitude'] ?? 'Unknown'
                    ];
                }
            }
        } catch (Exception $e) {
            // API call failed
        }
        
        return null;
    }
    
    /**
     * Get comprehensive client information
     */
    public function getClientHeaders() {
        return [
            'accept_language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'Unknown',
            'accept_encoding' => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? 'Unknown',
            'accept' => $_SERVER['HTTP_ACCEPT'] ?? 'Unknown',
            'dnt' => $_SERVER['HTTP_DNT'] ?? 'Unknown',
            'connection' => $_SERVER['HTTP_CONNECTION'] ?? 'Unknown',
            'upgrade_insecure_requests' => $_SERVER['HTTP_UPGRADE_INSECURE_REQUESTS'] ?? 'Unknown',
            'sec_fetch_site' => $_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'Unknown',
            'sec_fetch_mode' => $_SERVER['HTTP_SEC_FETCH_MODE'] ?? 'Unknown'
        ];
    }
    
    /**
     * Check browser security status
     */
    private function checkBrowserSecurity(&$browserInfo) {
        $browser = $browserInfo['browser_name'];
        $version = $browserInfo['browser_version'];
        
        if (isset(self::BROWSER_VULNERABILITIES[$browser])) {
            $majorVersion = explode('.', $version)[0];
            
            if (isset(self::BROWSER_VULNERABILITIES[$browser][$majorVersion])) {
                $securityData = self::BROWSER_VULNERABILITIES[$browser][$majorVersion];
                $browserInfo['security_status'] = $securityData['security'];
                $browserInfo['security_notes'] = $securityData['notes'];
                $browserInfo['version_status'] = $securityData['status'];
            } else {
                // Version not in database - check if it's newer or older
                if (is_numeric($majorVersion)) {
                    $knownVersions = array_keys(self::BROWSER_VULNERABILITIES[$browser]);
                    $maxKnownVersion = max(array_map('intval', $knownVersions));
                    
                    if (intval($majorVersion) > $maxKnownVersion) {
                        // Newer than our database - assume it's current
                        $browserInfo['security_status'] = 'Good';
                        $browserInfo['security_notes'] = 'Version newer than database - likely secure';
                        $browserInfo['version_status'] = 'Current';
                    } elseif (intval($majorVersion) < 100) {
                        // Very old version
                        $browserInfo['security_status'] = 'Critical';
                        $browserInfo['security_notes'] = 'Very outdated version with known vulnerabilities';
                        $browserInfo['version_status'] = 'Vulnerable';
                    } else {
                        // Unknown but reasonably recent
                        $browserInfo['security_status'] = 'Medium';
                        $browserInfo['security_notes'] = 'Version unknown - update recommended';
                        $browserInfo['version_status'] = 'Unknown';
                    }
                }
            }
        }
    }
    
    /**
     * Check OS security status
     */
    private function checkOSSecurity(&$osInfo) {
        $os = $osInfo['os_name'];
        
        if (isset(self::OS_VULNERABILITIES[$os])) {
            $securityData = self::OS_VULNERABILITIES[$os];
            $osInfo['security_status'] = $securityData['security'];
            $osInfo['support_status'] = $securityData['support'];
            $osInfo['version_status'] = $securityData['status'];
        }
    }
    
    /**
     * Calculate security score
     */
    private function calculateSecurityScore($analysis) {
        $score = 0;
        $browser = $analysis['browser'] ?? [];
        $os = $analysis['operating_system'] ?? [];
        
        // Browser security (40 points)
        switch ($browser['security_status'] ?? 'Unknown') {
            case 'Good': $score += 40; break;
            case 'Medium': $score += 25; break;
            case 'Low': $score += 10; break;
            case 'Critical': $score += 0; break;
            default: $score += 15; break;
        }
        
        // OS security (40 points)
        switch ($os['security_status'] ?? 'Unknown') {
            case 'Good': $score += 40; break;
            case 'Medium': $score += 25; break;
            case 'Low': $score += 10; break;
            case 'Critical': $score += 0; break;
            default: $score += 15; break;
        }
        
        // Additional factors (20 points)
        $headers = $analysis['client_headers'] ?? [];
        if (isset($headers['dnt']) && $headers['dnt'] === '1') $score += 5; // Do Not Track
        if (isset($headers['upgrade_insecure_requests']) && $headers['upgrade_insecure_requests'] === '1') $score += 5;
        if (isset($headers['sec_fetch_site'])) $score += 5; // Modern security headers
        $score += 5; // Base connectivity score
        
        return min(100, max(0, $score));
    }
    
    /**
     * Identify security vulnerabilities
     */
    private function identifyVulnerabilities($analysis) {
        $vulnerabilities = [];
        $browser = $analysis['browser'] ?? [];
        $os = $analysis['operating_system'] ?? [];
        
        // Browser vulnerabilities
        if (isset($browser['security_status'])) {
            switch ($browser['security_status']) {
                case 'Critical':
                    $vulnerabilities[] = [
                        'severity' => 'critical',
                        'type' => 'Outdated Browser',
                        'description' => "Browser version {$browser['browser_version']} has critical security vulnerabilities"
                    ];
                    break;
                case 'Low':
                    $vulnerabilities[] = [
                        'severity' => 'high',
                        'type' => 'Browser Security Risk',
                        'description' => "Browser version {$browser['browser_version']} has known security issues"
                    ];
                    break;
                case 'Medium':
                    $vulnerabilities[] = [
                        'severity' => 'medium',
                        'type' => 'Browser Update Needed',
                        'description' => "Browser version {$browser['browser_version']} is missing recent security updates"
                    ];
                    break;
            }
        }
        
        // OS vulnerabilities
        if (isset($os['security_status'])) {
            switch ($os['security_status']) {
                case 'Critical':
                    $vulnerabilities[] = [
                        'severity' => 'critical',
                        'type' => 'Unsupported Operating System',
                        'description' => "Operating system {$os['os_name']} no longer receives security updates"
                    ];
                    break;
                case 'Medium':
                    $vulnerabilities[] = [
                        'severity' => 'medium',
                        'type' => 'OS Security Risk',
                        'description' => "Operating system {$os['os_name']} has limited security support"
                    ];
                    break;
            }
        }
        
        return $vulnerabilities;
    }
    
    /**
     * Get security recommendations
     */
    private function getRecommendations($analysis) {
        $recommendations = [];
        $browser = $analysis['browser'] ?? [];
        $os = $analysis['operating_system'] ?? [];
        
        if (isset($browser['security_status']) && in_array($browser['security_status'], ['Critical', 'Low', 'Medium'])) {
            $recommendations[] = "Update {$browser['browser_name']} to the latest version immediately";
        }
        
        if (isset($os['security_status']) && $os['security_status'] === 'Critical') {
            $recommendations[] = "Upgrade to a supported operating system version";
        }
        
        if (isset($browser['browser_name']) && $browser['browser_name'] === 'Internet Explorer') {
            $recommendations[] = "Switch to a modern browser like Chrome, Firefox, or Edge";
        }
        
        $headers = $analysis['client_headers'] ?? [];
        if (!isset($headers['dnt']) || $headers['dnt'] !== '1') {
            $recommendations[] = "Enable 'Do Not Track' in browser privacy settings";
        }
        
        return $recommendations;
    }
    
    /**
     * Main analysis function
     */
    public function getSecurityAnalysis() {
        try {
            $analysis = [
                'ip_address' => $this->ipAddress,
                'timestamp' => date('Y-m-d H:i:s'),
                'browser' => $this->analyzeBrowser(),
                'operating_system' => $this->analyzeOperatingSystem(),
                'ip_location' => $this->getIPLocationInfo(),
                'client_headers' => $this->getClientHeaders(),
                'vulnerabilities' => [],
                'recommendations' => []
            ];
            
            // Calculate security score
            $analysis['security_score'] = $this->calculateSecurityScore($analysis);
            
            // Identify vulnerabilities
            $analysis['vulnerabilities'] = $this->identifyVulnerabilities($analysis);
            
            // Get recommendations
            $analysis['recommendations'] = $this->getRecommendations($analysis);
            
            return $analysis;
            
        } catch (Exception $e) {
            return [
                'error' => $e->getMessage(),
                'ip_address' => $this->ipAddress,
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
        $analyzer = new ClientSecurityAnalyzer();
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
    <title>Client v1.06</title>
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
            color: #0066ff;
            min-height: 100vh;
            overflow-x: hidden;
            position: relative;
        }

        /* Matrix rain effect with blue */
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
                linear-gradient(rgba(0, 102, 255, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0, 102, 255, 0.03) 1px, transparent 1px);
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
            background: linear-gradient(90deg, transparent, #0066ff, transparent);
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
            color: #0066ff;
            text-shadow: 
                0 0 5px #0066ff,
                0 0 10px #0066ff,
                0 0 15px #0066ff,
                0 0 20px #0066ff;
            animation: glow-pulse 3s ease-in-out infinite;
        }

        @keyframes glow-pulse {
            0%, 100% { text-shadow: 0 0 5px #0066ff, 0 0 10px #0066ff, 0 0 15px #0066ff; }
            50% { text-shadow: 0 0 10px #0066ff, 0 0 20px #0066ff, 0 0 30px #0066ff, 0 0 40px #0066ff; }
        }

        .header .subtitle {
            font-size: 1.1em;
            color: #0044aa;
            font-weight: 400;
            letter-spacing: 2px;
            opacity: 0.8;
        }

        .version-badge {
            display: inline-block;
            background: linear-gradient(45deg, #000033, #000066);
            border: 1px solid #0066ff;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.8em;
            margin-top: 10px;
            animation: badge-glow 3s ease-in-out infinite;
        }

        @keyframes badge-glow {
            0%, 100% { box-shadow: 0 0 5px rgba(0, 102, 255, 0.3); }
            50% { box-shadow: 0 0 15px rgba(0, 102, 255, 0.6); }
        }

        .terminal-window {
            background: rgba(0, 0, 20, 0.95);
            border: 2px solid #0066ff;
            border-radius: 10px;
            box-shadow: 
                0 0 20px rgba(0, 102, 255, 0.3),
                inset 0 0 20px rgba(0, 102, 255, 0.1);
            margin-bottom: 30px;
            overflow: hidden;
            position: relative;
        }

        .terminal-header {
            background: linear-gradient(90deg, #000033, #000044);
            padding: 10px 20px;
            border-bottom: 1px solid #0066ff;
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
        .terminal-button:nth-child(3) { background: #0066ff; }

        .terminal-title {
            font-size: 0.9em;
            color: #0066ff;
            font-weight: 600;
        }

        .terminal-content {
            padding: 30px;
        }

        .analyze-btn {
            width: 100%;
            padding: 18px;
            background: linear-gradient(45deg, #000033, #000066);
            border: 2px solid #0066ff;
            border-radius: 8px;
            color: #0066ff;
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
            background: linear-gradient(90deg, transparent, rgba(0, 102, 255, 0.2), transparent);
            transition: left 0.5s ease;
        }

        .analyze-btn:hover {
            background: linear-gradient(45deg, #000044, #000088);
            box-shadow: 
                0 0 20px rgba(0, 102, 255, 0.5),
                inset 0 0 20px rgba(0, 102, 255, 0.1);
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
            border-top: 3px solid #0066ff;
            border-radius: 50%;
            animation: matrix-spin 1s linear infinite;
        }

        .spinner-ring:nth-child(2) {
            width: 45px;
            height: 45px;
            top: 7.5px;
            left: 7.5px;
            border-top-color: #0044aa;
            animation-delay: -0.3s;
        }

        .spinner-ring:nth-child(3) {
            width: 30px;
            height: 30px;
            top: 15px;
            left: 15px;
            border-top-color: #002266;
            animation-delay: -0.6s;
        }

        @keyframes matrix-spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .loading-text {
            color: #0066ff;
            font-size: 1.1em;
            font-weight: 600;
            margin-bottom: 10px;
            animation: text-glow 2s ease-in-out infinite;
        }

        @keyframes text-glow {
            0%, 100% { text-shadow: 0 0 5px #0066ff; }
            50% { text-shadow: 0 0 15px #0066ff, 0 0 25px #0066ff; }
        }

        .loading-dots {
            color: #0044aa;
            font-size: 0.9em;
        }

        .results {
            margin-top: 30px;
            display: none;
        }

        .security-score {
            text-align: center;
            background: rgba(0, 0, 20, 0.8);
            border: 2px solid #0066ff;
            border-radius: 15px;
            padding: 40px 30px;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
        }

        .security-score::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #0066ff, #0044aa, #0066ff);
            animation: progress-glow 3s ease-in-out infinite;
        }

        @keyframes progress-glow {
            0%, 100% { opacity: 0.7; }
            50% { opacity: 1; box-shadow: 0 0 20px #0066ff; }
        }

        .score-value {
            font-size: 4em;
            font-weight: 700;
            color: #0066ff;
            margin-bottom: 15px;
            text-shadow: 
                0 0 10px #0066ff,
                0 0 20px #0066ff;
            animation: score-pulse 2s ease-in-out infinite;
        }

        @keyframes score-pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        .score-label {
            font-size: 1.2em;
            color: #0044aa;
            text-transform: uppercase;
            letter-spacing: 2px;
            font-weight: 600;
        }

        .results-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 30px;
        }

        .result-card {
            background: rgba(0, 0, 20, 0.9);
            border: 2px solid #000033;
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
            background: linear-gradient(90deg, #0066ff, #0044aa);
        }

        .result-card:hover {
            border-color: #0066ff;
            box-shadow: 
                0 10px 30px rgba(0, 102, 255, 0.2),
                inset 0 0 20px rgba(0, 102, 255, 0.05);
            transform: translateY(-5px);
        }

        .card-title {
            font-size: 1.3em;
            color: #0066ff;
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
            text-shadow: 0 0 10px #0066ff;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: start;
            padding: 12px 0;
            border-bottom: 1px solid #000033;
            transition: all 0.3s ease;
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-item:hover {
            background: rgba(0, 102, 255, 0.05);
            padding-left: 10px;
        }

        .info-label {
            font-weight: 600;
            color: #0044aa;
            flex-shrink: 0;
            margin-right: 20px;
            font-size: 0.9em;
        }

        .info-value {
            color: #0066ff;
            text-align: right;
            word-break: break-word;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.85em;
            text-shadow: 0 0 5px rgba(0, 102, 255, 0.3);
        }

        .security-level {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8em;
            font-weight: 600;
            text-transform: uppercase;
        }

        .security-good { background: rgba(0, 255, 0, 0.2); color: #00ff00; }
        .security-medium { background: rgba(255, 255, 0, 0.2); color: #ffff00; }
        .security-low { background: rgba(255, 165, 0, 0.2); color: #ffa500; }
        .security-critical { background: rgba(255, 0, 0, 0.2); color: #ff6666; }

        .vulnerability {
            margin-bottom: 20px;
            padding: 20px;
            border-left: 4px solid;
            border-radius: 0 8px 8px 0;
            background: rgba(0, 0, 0, 0.6);
            transition: all 0.3s ease;
        }

        .vulnerability:hover {
            background: rgba(0, 0, 0, 0.8);
            transform: translateX(5px);
        }

        .vuln-critical { 
            border-left-color: #ff0000; 
            background: rgba(255, 0, 0, 0.1);
        }
        .vuln-high { 
            border-left-color: #ff6600; 
            background: rgba(255, 102, 0, 0.1);
        }
        .vuln-medium { 
            border-left-color: #ffaa00; 
            background: rgba(255, 170, 0, 0.1);
        }
        .vuln-low { 
            border-left-color: #0066ff; 
            background: rgba(0, 102, 255, 0.1);
        }

        .vuln-title {
            font-weight: 700;
            margin-bottom: 8px;
            color: #0066ff;
            font-size: 1.1em;
        }

        .vuln-description {
            color: #0044aa;
            line-height: 1.5;
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
            border: 1px solid #000033;
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, #0066ff, #002266);
            border-radius: 6px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, #0066ff, #0044aa);
        }
    </style>
</head>
<body>
    <div class="matrix-bg">
        <canvas class="matrix-canvas" id="matrixCanvas"></canvas>
    </div>

    <div class="container">
        <div class="header">
            <h1>🖥️ Vojs.IO SECURITY ANALYZER</h1>
            <div class="subtitle">ENHANCED CLIENT SECURITY ANALYSIS</div>
            <div class="version-badge">v1.06</div>
        </div>

        <div class="terminal-window">
            <div class="terminal-header">
                <div class="terminal-buttons">
                    <div class="terminal-button"></div>
                    <div class="terminal-button"></div>
                    <div class="terminal-button"></div>
                </div>
                <div class="terminal-title">Enhanced Client Security Analysis Engine</div>
            </div>
            
            <div class="terminal-content">
                <div style="text-align: center; margin-bottom: 30px;">
                    <p style="color: #0044aa; font-size: 1.1em; margin-bottom: 20px;">
                        ANALYZING YOUR CLIENT CONFIGURATION
                    </p>
                    <p style="color: #002266; font-size: 0.9em; line-height: 1.5;">
                        This tool analyzes your browser, operating system, IP location, and security configuration.<br>
                        All analysis is performed to assess your client-side security posture.
                    </p>
                </div>
                
                <button type="button" class="analyze-btn" id="analyzeBtn" onclick="performAnalysis()">
                    > INITIATE_ANALYSIS
                </button>

                <div class="loading" id="loading">
                    <div class="loading-spinner">
                        <div class="spinner-ring"></div>
                        <div class="spinner-ring"></div>
                        <div class="spinner-ring"></div>
                    </div>
                    <div class="loading-text">ANALYZING CLIENT SECURITY PROFILE</div>
                    <div class="loading-dots">Detecting browser information...</div>
                </div>

                <div class="results" id="results">
                    <!-- Results will be populated here -->
                </div>
            </div>
        </div>
    </div>

    <script>
        // Matrix rain effect with blue
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
                
                ctx.fillStyle = '#0066ff';
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

        // Collect additional client-side information
        function getClientSideInfo() {
            const info = {
                screen_resolution: screen.width + 'x' + screen.height,
                color_depth: screen.colorDepth,
                pixel_ratio: window.devicePixelRatio || 1,
                timezone_offset: new Date().getTimezoneOffset(),
                language: navigator.language,
                languages: navigator.languages ? navigator.languages.join(', ') : navigator.language,
                platform: navigator.platform,
                hardware_concurrency: navigator.hardwareConcurrency || 'Unknown',
                memory: getMemoryInfo(),
                online: navigator.onLine,
                cookie_enabled: navigator.cookieEnabled,
                java_enabled: navigator.javaEnabled ? navigator.javaEnabled() : false,
                webgl_support: getWebGLSupport(),
                local_storage: checkLocalStorage(),
                session_storage: checkSessionStorage(),
                do_not_track: navigator.doNotTrack || 'Unknown',
                max_touch_points: navigator.maxTouchPoints || 0
            };
            
            return info;
        }
        
        function getMemoryInfo() {
            if (navigator.deviceMemory) {
                const reportedMemory = navigator.deviceMemory;
                if (reportedMemory >= 8) {
                    return reportedMemory + ' GB (or more)';
                } else {
                    return reportedMemory + ' GB (approximate)';
                }
            } else {
                return 'Not Available (API not supported)';
            }
        }
        
        function getWebGLSupport() {
            try {
                const canvas = document.createElement('canvas');
                const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
                return gl ? 'Supported' : 'Not Supported';
            } catch (e) {
                return 'Not Supported';
            }
        }
        
        function checkLocalStorage() {
            try {
                localStorage.setItem('test', 'test');
                localStorage.removeItem('test');
                return 'Supported';
            } catch (e) {
                return 'Not Supported';
            }
        }
        
        function checkSessionStorage() {
            try {
                sessionStorage.setItem('test', 'test');
                sessionStorage.removeItem('test');
                return 'Supported';
            } catch (e) {
                return 'Not Supported';
            }
        }

        async function performAnalysis() {
            document.getElementById('loading').style.display = 'block';
            document.getElementById('results').style.display = 'none';
            document.getElementById('analyzeBtn').disabled = true;
            
            // Client-side loading steps
            const loadingSteps = [
                'Detecting browser information...',
                'Analyzing operating system...',
                'Collecting screen capabilities...',
                'Testing web technologies...',
                'Querying IP geolocation...',
                'Analyzing security headers...',
                'Checking browser security...',
                'Evaluating OS vulnerabilities...',
                'Calculating security score...',
                'Generating comprehensive report...'
            ];
            
            let stepIndex = 0;
            const loadingInterval = setInterval(() => {
                if (stepIndex < loadingSteps.length) {
                    document.querySelector('.loading-dots').textContent = loadingSteps[stepIndex];
                    stepIndex++;
                }
            }, 800);
            
            try {
                // Get client-side info
                const clientInfo = getClientSideInfo();
                
                // Send to server for analysis
                const formData = new FormData();
                formData.append('action', 'analyze');
                formData.append('client_info', JSON.stringify(clientInfo));
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();
                
                // Merge client-side and server-side data
                data.client_capabilities = clientInfo;
                
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
        }
        
        function formatSecurityLevel(level) {
            const className = level.toLowerCase().replace(/\s+/g, '-').replace(/[()]/g, '');
            return `<span class="security-level security-${className}">${level}</span>`;
        }
        
        function displayResults(data) {
            const resultsDiv = document.getElementById('results');
            
            if (data.error) {
                resultsDiv.innerHTML = `<div class="error">[SYSTEM_ERROR] ${data.error}</div>`;
                resultsDiv.style.display = 'block';
                return;
            }
            
            const browser = data.browser || {};
            const os = data.operating_system || {};
            const ipInfo = data.ip_location || {};
            const headers = data.client_headers || {};
            const capabilities = data.client_capabilities || {};
            
            const html = `
                <div class="security-score">
                    <div class="score-value">${data.security_score || 0}</div>
                    <div class="score-label">CLIENT SECURITY SCORE / 100</div>
                </div>
                
                <div class="results-grid">
                    <div class="result-card">
                        <div class="card-title">
                            <span class="card-icon">🌐</span>
                            BROWSER INFORMATION
                        </div>
                        <div class="info-item">
                            <span class="info-label">BROWSER:</span>
                            <span class="info-value">${browser.browser_name || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">VERSION:</span>
                            <span class="info-value">${browser.browser_version || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">ENGINE:</span>
                            <span class="info-value">${browser.browser_engine || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">SECURITY_STATUS:</span>
                            <span class="info-value">${browser.security_status ? formatSecurityLevel(browser.security_status) : 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">VERSION_STATUS:</span>
                            <span class="info-value">${browser.version_status || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">SECURITY_NOTES:</span>
                            <span class="info-value">${browser.security_notes || 'No notes available'}</span>
                        </div>
                    </div>
                    
                    <div class="result-card">
                        <div class="card-title">
                            <span class="card-icon">💻</span>
                            OPERATING SYSTEM
                        </div>
                        <div class="info-item">
                            <span class="info-label">OS_NAME:</span>
                            <span class="info-value">${os.os_name || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">OS_VERSION:</span>
                            <span class="info-value">${os.os_version || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">ARCHITECTURE:</span>
                            <span class="info-value">${os.architecture || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">PLATFORM:</span>
                            <span class="info-value">${capabilities.platform || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">SECURITY_STATUS:</span>
                            <span class="info-value">${os.security_status ? formatSecurityLevel(os.security_status) : 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">SUPPORT_STATUS:</span>
                            <span class="info-value">${os.support_status || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">VERSION_STATUS:</span>
                            <span class="info-value">${os.version_status || 'Unknown'}</span>
                        </div>
                        ${os.os_name === 'Android' ? `
                        <div class="info-item" style="font-size: 0.8em; opacity: 0.8;">
                            <span class="info-label">ANDROID_NOTE:</span>
                            <span class="info-value">Security updates depend on device manufacturer and carrier</span>
                        </div>
                        ` : ''}
                    </div>
                    
                    <div class="result-card">
                        <div class="card-title">
                            <span class="card-icon">🌍</span>
                            IP & LOCATION
                        </div>
                        <div class="info-item">
                            <span class="info-label">IP_ADDRESS:</span>
                            <span class="info-value">${data.ip_address || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">COUNTRY:</span>
                            <span class="info-value">${ipInfo.country || 'Unknown'} ${ipInfo.country_code ? '(' + ipInfo.country_code + ')' : ''}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">REGION:</span>
                            <span class="info-value">${ipInfo.region || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">CITY:</span>
                            <span class="info-value">${ipInfo.city || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">ISP:</span>
                            <span class="info-value">${ipInfo.isp || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">ORGANIZATION:</span>
                            <span class="info-value">${ipInfo.organization || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">TIMEZONE:</span>
                            <span class="info-value">${ipInfo.timezone || 'Unknown'}</span>
                        </div>
                    </div>
                    
                    <div class="result-card">
                        <div class="card-title">
                            <span class="card-icon">🖥️</span>
                            CLIENT CAPABILITIES
                        </div>
                        <div class="info-item">
                            <span class="info-label">SCREEN_RESOLUTION:</span>
                            <span class="info-value">${capabilities.screen_resolution || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">COLOR_DEPTH:</span>
                            <span class="info-value">${capabilities.color_depth || 'Unknown'} bits</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">PIXEL_RATIO:</span>
                            <span class="info-value">${capabilities.pixel_ratio || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">CPU_CORES:</span>
                            <span class="info-value">${capabilities.hardware_concurrency || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">DEVICE_MEMORY:</span>
                            <span class="info-value">${capabilities.memory || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">WEBGL_SUPPORT:</span>
                            <span class="info-value">${capabilities.webgl_support || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">TOUCH_POINTS:</span>
                            <span class="info-value">${capabilities.max_touch_points || 0}</span>
                        </div>
                    </div>
                    
                    <div class="result-card">
                        <div class="card-title">
                            <span class="card-icon">🌐</span>
                            LOCALIZATION
                        </div>
                        <div class="info-item">
                            <span class="info-label">LANGUAGE:</span>
                            <span class="info-value">${capabilities.language || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">LANGUAGES:</span>
                            <span class="info-value">${capabilities.languages || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">TIMEZONE_OFFSET:</span>
                            <span class="info-value">${capabilities.timezone_offset !== undefined ? capabilities.timezone_offset + ' minutes' : 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">ACCEPT_LANGUAGE:</span>
                            <span class="info-value">${headers.accept_language || 'Unknown'}</span>
                        </div>
                    </div>
                    
                    <div class="result-card">
                        <div class="card-title">
                            <span class="card-icon">🔒</span>
                            SECURITY FEATURES
                        </div>
                        <div class="info-item">
                            <span class="info-label">COOKIES_ENABLED:</span>
                            <span class="info-value">${capabilities.cookie_enabled ? 'YES' : 'NO'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">JAVA_ENABLED:</span>
                            <span class="info-value">${capabilities.java_enabled ? 'YES' : 'NO'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">LOCAL_STORAGE:</span>
                            <span class="info-value">${capabilities.local_storage || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">SESSION_STORAGE:</span>
                            <span class="info-value">${capabilities.session_storage || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">DO_NOT_TRACK:</span>
                            <span class="info-value">${capabilities.do_not_track || headers.dnt || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">ONLINE_STATUS:</span>
                            <span class="info-value">${capabilities.online ? 'ONLINE' : 'OFFLINE'}</span>
                        </div>
                    </div>
                </div>
                
                ${data.vulnerabilities && data.vulnerabilities.length > 0 ? `
                <div class="result-card" style="margin-top: 30px;">
                    <div class="card-title">
                        <span class="card-icon">⚠️</span>
                        SECURITY VULNERABILITIES
                    </div>
                    ${data.vulnerabilities.map(vuln => `
                        <div class="vulnerability vuln-${vuln.severity}">
                            <div class="vuln-title">[${vuln.severity.toUpperCase()}] ${vuln.type}</div>
                            <div class="vuln-description">${vuln.description}</div>
                        </div>
                    `).join('')}
                </div>
                ` : ''}
                
                ${data.recommendations && data.recommendations.length > 0 ? `
                <div class="result-card" style="margin-top: 30px;">
                    <div class="card-title">
                        <span class="card-icon">💡</span>
                        SECURITY RECOMMENDATIONS
                    </div>
                    <ul style="padding-left: 20px; color: #0044aa;">
                        ${data.recommendations.map(rec => `<li style="margin-bottom: 12px; line-height: 1.5;">> ${rec}</li>`).join('')}
                    </ul>
                </div>
                ` : ''}
            `;
            
            resultsDiv.innerHTML = html;
            resultsDiv.style.display = 'block';
        }
    </script>
</body>
</html>