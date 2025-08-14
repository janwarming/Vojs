<?php
// PHP SSL/TLS Certificate Security Analyzer - Fixed Version
// Separate API and HTML to avoid JSON parsing errors

// Configuration
ini_set('display_errors', 0); // Hide PHP errors from JSON output
error_reporting(E_ALL);

class SSLAnalyzer {
    private $domain;
    private $port;
    private $timeout;
    
    public function __construct($domain, $port = 443, $timeout = 10) {
        $this->domain = $domain;
        $this->port = $port;
        $this->timeout = $timeout;
    }
    
    /**
     * Get comprehensive SSL certificate information
     */
    public function getCertificateInfo() {
        $context = stream_context_create([
            "ssl" => [
                "capture_peer_cert" => true,
                "capture_peer_cert_chain" => true,
                "verify_peer" => false,
                "verify_peer_name" => false,
            ]
        ]);
        
        $socket = @stream_socket_client(
            "ssl://{$this->domain}:{$this->port}",
            $errno, $errstr, $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        
        if (!$socket) {
            throw new Exception("Kan ikke oprette forbindelse til {$this->domain}:{$this->port} - $errstr ($errno)");
        }
        
        $params = stream_context_get_params($socket);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        $certChain = $params['options']['ssl']['peer_certificate_chain'] ?? [];
        
        fclose($socket);
        
        if (!$cert) {
            throw new Exception("Kunne ikke hente SSL certificat fra {$this->domain}");
        }
        
        $certData = openssl_x509_parse($cert);
        if (!$certData) {
            throw new Exception("Kunne ikke parse SSL certificat");
        }
        
        return [
            'certificate' => $this->parseCertificateData($certData, $cert),
            'chain' => $this->parseCertificateChain($certChain),
            'domain' => $this->domain,
            'port' => $this->port
        ];
    }
    
    /**
     * Parse certificate data
     */
    private function parseCertificateData($certData, $cert) {
        $validFrom = date('Y-m-d H:i:s', $certData['validFrom_time_t']);
        $validTo = date('Y-m-d H:i:s', $certData['validTo_time_t']);
        $daysLeft = floor(($certData['validTo_time_t'] - time()) / 86400);
        
        // Get public key info safely
        $publicKey = @openssl_pkey_get_public($cert);
        $keySize = 'Unknown';
        $keyType = 'Unknown';
        
        if ($publicKey) {
            $keyDetails = openssl_pkey_get_details($publicKey);
            if ($keyDetails) {
                $keySize = $keyDetails['bits'] ?? 'Unknown';
                $keyType = $keyDetails['type'] === OPENSSL_KEYTYPE_RSA ? 'RSA' : 
                          ($keyDetails['type'] === OPENSSL_KEYTYPE_EC ? 'EC' : 'Unknown');
            }
        }
        
        return [
            'subject' => $this->formatDN($certData['subject'] ?? []),
            'issuer' => $this->formatDN($certData['issuer'] ?? []),
            'serial_number' => $certData['serialNumberHex'] ?? $certData['serialNumber'] ?? 'Unknown',
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'days_until_expiry' => $daysLeft,
            'is_expired' => $daysLeft < 0,
            'is_expiring_soon' => $daysLeft < 30,
            'signature_algorithm' => $certData['signatureTypeSN'] ?? 'Unknown',
            'public_key' => [
                'algorithm' => $keyType,
                'size' => $keySize
            ],
            'san' => $this->getSANs($certData['extensions'] ?? []),
            'fingerprints' => $this->getCertificateFingerprints($cert)
        ];
    }
    
    /**
     * Parse certificate chain
     */
    private function parseCertificateChain($chain) {
        $chainInfo = [];
        foreach ($chain as $cert) {
            $certData = openssl_x509_parse($cert);
            if ($certData) {
                $chainInfo[] = [
                    'subject' => $this->formatDN($certData['subject'] ?? []),
                    'issuer' => $this->formatDN($certData['issuer'] ?? []),
                    'valid_from' => date('Y-m-d H:i:s', $certData['validFrom_time_t']),
                    'valid_to' => date('Y-m-d H:i:s', $certData['validTo_time_t']),
                    'is_ca' => isset($certData['extensions']['basicConstraints']) && 
                              strpos($certData['extensions']['basicConstraints'], 'CA:TRUE') !== false
                ];
            }
        }
        return $chainInfo;
    }
    
    /**
     * Analyze security headers
     */
    public function getSecurityHeaders() {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://{$this->domain}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'SSL-Analyzer/1.0'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error || !$response) {
            return [
                'error' => $error ?: 'Kunne ikke hente security headers',
                'http_code' => $httpCode
            ];
        }
        
        $headers = $this->parseHeaders($response);
        
        return [
            'http_code' => $httpCode,
            'strict_transport_security' => $headers['strict-transport-security'] ?? null,
            'content_security_policy' => $headers['content-security-policy'] ?? null,
            'x_frame_options' => $headers['x-frame-options'] ?? null,
            'x_content_type_options' => $headers['x-content-type-options'] ?? null,
            'x_xss_protection' => $headers['x-xss-protection'] ?? null,
            'referrer_policy' => $headers['referrer-policy'] ?? null
        ];
    }
    
    /**
     * Get comprehensive security analysis
     */
    public function getSecurityAnalysis() {
        try {
            $certInfo = $this->getCertificateInfo();
            $securityHeaders = $this->getSecurityHeaders();
            
            $analysis = [
                'domain' => $this->domain,
                'port' => $this->port,
                'timestamp' => date('Y-m-d H:i:s'),
                'certificate' => $certInfo['certificate'],
                'certificate_chain' => $certInfo['chain'],
                'security_headers' => $securityHeaders,
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
                'domain' => $this->domain,
                'port' => $this->port,
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }
    }
    
    /**
     * Calculate security score
     */
    private function calculateSecurityScore($analysis) {
        $score = 0;
        $cert = $analysis['certificate'];
        $headers = $analysis['security_headers'];
        
        // Certificate validity (30 points)
        if (!$cert['is_expired']) {
            $score += 20;
            if ($cert['days_until_expiry'] > 30) $score += 5;
            if ($cert['days_until_expiry'] > 90) $score += 5;
        }
        
        // Key strength (20 points)
        if (is_numeric($cert['public_key']['size'])) {
            if ($cert['public_key']['size'] >= 2048) $score += 15;
            if ($cert['public_key']['size'] >= 4096) $score += 5;
        }
        
        // Signature algorithm (10 points)
        $sigAlg = strtolower($cert['signature_algorithm']);
        if (strpos($sigAlg, 'sha256') !== false || strpos($sigAlg, 'sha384') !== false || strpos($sigAlg, 'sha512') !== false) {
            $score += 10;
        }
        
        // Security headers (40 points)
        if ($headers['strict_transport_security']) $score += 15;
        if ($headers['content_security_policy']) $score += 10;
        if ($headers['x_frame_options']) $score += 5;
        if ($headers['x_content_type_options']) $score += 5;
        if ($headers['x_xss_protection']) $score += 5;
        
        return min(100, max(0, $score));
    }
    
    /**
     * Identify vulnerabilities
     */
    private function identifyVulnerabilities($analysis) {
        $vulnerabilities = [];
        $cert = $analysis['certificate'];
        $headers = $analysis['security_headers'];
        
        // Certificate issues
        if ($cert['is_expired']) {
            $vulnerabilities[] = [
                'severity' => 'critical',
                'type' => 'Certificat Udløbet',
                'description' => 'SSL certificatet er udløbet og skal fornyes øjeblikkeligt'
            ];
        } elseif ($cert['is_expiring_soon']) {
            $vulnerabilities[] = [
                'severity' => 'warning',
                'type' => 'Certificat Udløber Snart',
                'description' => "Certificatet udløber om {$cert['days_until_expiry']} dage"
            ];
        }
        
        // Weak key size
        if (is_numeric($cert['public_key']['size']) && $cert['public_key']['size'] < 2048) {
            $vulnerabilities[] = [
                'severity' => 'high',
                'type' => 'Svag Nøglestørrelse',
                'description' => "Nøglestørrelse ({$cert['public_key']['size']} bits) er under anbefalede minimum på 2048 bits"
            ];
        }
        
        // Missing security headers
        if (!$headers['strict_transport_security']) {
            $vulnerabilities[] = [
                'severity' => 'medium',
                'type' => 'Manglende HSTS Header',
                'description' => 'Strict-Transport-Security header ikke fundet'
            ];
        }
        
        if (!$headers['content_security_policy']) {
            $vulnerabilities[] = [
                'severity' => 'low',
                'type' => 'Manglende CSP Header',
                'description' => 'Content-Security-Policy header ikke fundet'
            ];
        }
        
        return $vulnerabilities;
    }
    
    /**
     * Get recommendations
     */
    private function getRecommendations($analysis) {
        $recommendations = [];
        $cert = $analysis['certificate'];
        $headers = $analysis['security_headers'];
        
        if ($cert['days_until_expiry'] < 60) {
            $recommendations[] = 'Planlæg certificat fornyelse';
        }
        
        if (is_numeric($cert['public_key']['size']) && $cert['public_key']['size'] < 4096) {
            $recommendations[] = 'Overvej opgradering til 4096-bit RSA nøgle eller ECC';
        }
        
        if (!$headers['strict_transport_security']) {
            $recommendations[] = 'Implementér HSTS header med includeSubDomains';
        }
        
        if (!$headers['content_security_policy']) {
            $recommendations[] = 'Implementér Content Security Policy';
        }
        
        return $recommendations;
    }
    
    // Helper methods
    private function formatDN($dn) {
        if (is_array($dn)) {
            $parts = [];
            foreach ($dn as $key => $value) {
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
                $parts[] = "$key=$value";
            }
            return implode(', ', $parts);
        }
        return (string)$dn;
    }
    
    private function getSANs($extensions) {
        if (isset($extensions['subjectAltName'])) {
            return array_map('trim', explode(',', $extensions['subjectAltName']));
        }
        return [];
    }
    
    private function getCertificateFingerprints($cert) {
        return [
            'sha1' => openssl_x509_fingerprint($cert, 'sha1'),
            'sha256' => openssl_x509_fingerprint($cert, 'sha256')
        ];
    }
    
    private function parseHeaders($response) {
        $headers = [];
        $lines = explode("\r\n", $response);
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                [$key, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }
        return $headers;
    }
}

// API Endpoint - Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action']) && $_POST['action'] === 'analyze') {
    // Set proper JSON headers
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    try {
        $domain = trim($_POST['domain'] ?? '');
        $port = intval($_POST['port'] ?? 443);
        
        if (empty($domain)) {
            throw new Exception('Domæne er påkrævet');
        }
        
        // Clean domain
        $domain = preg_replace('/^https?:\/\//', '', $domain);
        $domain = preg_replace('/\/.*$/', '', $domain);
        $domain = strtolower($domain);
        
        if (!filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new Exception('Ugyldigt domæne format');
        }
        
        $analyzer = new SSLAnalyzer($domain, $port);
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

// HTML Interface - Only show if not an API request
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP SSL/TLS Certificate Analyzer</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #2c3e50, #34495e);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
            font-weight: 300;
        }

        .header p {
            opacity: 0.9;
            font-size: 1.1em;
        }

        .form-section {
            padding: 40px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #555;
        }

        .form-input {
            width: 100%;
            padding: 15px 20px;
            border: 2px solid #e1e8ed;
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .analyze-btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .analyze-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        }

        .analyze-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .loading {
            text-align: center;
            padding: 40px;
            display: none;
        }

        .spinner {
            width: 50px;
            height: 50px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .results {
            margin-top: 30px;
            display: none;
        }

        .score-display {
            text-align: center;
            background: linear-gradient(135deg, #f8f9ff, #ffffff);
            border: 2px solid #e1e8ed;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
        }

        .score-value {
            font-size: 3em;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 10px;
        }

        .results-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 25px;
        }

        .result-card {
            background: linear-gradient(135deg, #f8f9ff, #ffffff);
            border: 1px solid #e1e8ed;
            border-radius: 15px;
            padding: 25px;
        }

        .card-title {
            font-size: 1.4em;
            color: #2c3e50;
            margin-bottom: 20px;
            font-weight: 600;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: start;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 600;
            color: #555;
            flex-shrink: 0;
            margin-right: 15px;
        }

        .info-value {
            color: #333;
            text-align: right;
            word-break: break-word;
            font-family: monospace;
            font-size: 0.9em;
        }

        .vulnerability {
            margin-bottom: 15px;
            padding: 15px;
            border-left: 4px solid;
            border-radius: 0 8px 8px 0;
        }

        .vuln-critical { border-left-color: #e74c3c; background: #fdf2f2; }
        .vuln-high { border-left-color: #f39c12; background: #fef9e7; }
        .vuln-medium { border-left-color: #f1c40f; background: #fffef7; }
        .vuln-warning { border-left-color: #f1c40f; background: #fffef7; }
        .vuln-low { border-left-color: #27ae60; background: #f0f9f0; }

        .vuln-title {
            font-weight: bold;
            margin-bottom: 5px;
        }

        .error {
            background: #fee;
            border: 1px solid #fcc;
            color: #c66;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
        }

        @media (max-width: 768px) {
            .results-grid {
                grid-template-columns: 1fr;
            }
            
            .info-item {
                flex-direction: column;
                align-items: start;
            }
            
            .info-value {
                text-align: left;
                margin-top: 5px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔒 PHP SSL/TLS Certificate Analyzer</h1>
            <p>Rigtig certificat-analyse med PHP's indbyggede SSL funktioner</p>
        </div>

        <div class="form-section">
            <form id="analysisForm">
                <div class="form-group">
                    <label class="form-label">Domæne eller URL</label>
                    <input type="text" class="form-input" id="domain" name="domain" 
                           placeholder="example.com eller https://example.com" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Port (valgfri)</label>
                    <input type="number" class="form-input" id="port" name="port" 
                           value="443" min="1" max="65535">
                </div>
                
                <button type="submit" class="analyze-btn" id="analyzeBtn">
                    🔍 Analysér SSL/TLS Certificate
                </button>
            </form>

            <div class="loading" id="loading">
                <div class="spinner"></div>
                <p>Analyserer SSL/TLS konfiguration...</p>
            </div>

            <div class="results" id="results">
                <!-- Results will be populated here -->
            </div>
        </div>
    </div>

    <script>
        document.getElementById('analysisForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const domain = document.getElementById('domain').value.trim();
            const port = document.getElementById('port').value || 443;
            
            if (!domain) {
                alert('Indtast venligst et domæne');
                return;
            }
            
            document.getElementById('loading').style.display = 'block';
            document.getElementById('results').style.display = 'none';
            document.getElementById('analyzeBtn').disabled = true;
            
            try {
                const formData = new FormData();
                formData.append('action', 'analyze');
                formData.append('domain', domain);
                formData.append('port', port);
                
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
                    `<div class="error">Fejl: ${error.message}</div>`;
                document.getElementById('results').style.display = 'block';
            } finally {
                document.getElementById('loading').style.display = 'none';
                document.getElementById('analyzeBtn').disabled = false;
            }
        });
        
        function displayResults(data) {
            const resultsDiv = document.getElementById('results');
            
            if (data.error) {
                resultsDiv.innerHTML = `<div class="error">Fejl: ${data.error}</div>`;
                resultsDiv.style.display = 'block';
                return;
            }
            
            const cert = data.certificate;
            const headers = data.security_headers;
            
            const html = `
                <div class="score-display">
                    <div class="score-value">${data.security_score}/100</div>
                    <div>Samlet Sikkerhedsscore</div>
                </div>
                
                <div class="results-grid">
                    <div class="result-card">
                        <div class="card-title">📜 Certificat Information</div>
                        <div class="info-item">
                            <span class="info-label">Domæne:</span>
                            <span class="info-value">${cert.subject}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Udsteder:</span>
                            <span class="info-value">${cert.issuer}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Gyldig fra:</span>
                            <span class="info-value">${cert.valid_from}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Gyldig til:</span>
                            <span class="info-value">${cert.valid_to}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Dage tilbage:</span>
                            <span class="info-value">${cert.days_until_expiry}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Nøglestørrelse:</span>
                            <span class="info-value">${cert.public_key.size} bits (${cert.public_key.algorithm})</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Signatur algoritme:</span>
                            <span class="info-value">${cert.signature_algorithm}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Serienummer:</span>
                            <span class="info-value">${cert.serial_number}</span>
                        </div>
                    </div>
                    
                    <div class="result-card">
                        <div class="card-title">🔐 Sikkerhedsheaders</div>
                        <div class="info-item">
                            <span class="info-label">HTTP Status:</span>
                            <span class="info-value">${headers.http_code || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">HSTS:</span>
                            <span class="info-value">${headers.strict_transport_security ? 'Aktiveret' : 'Ikke fundet'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">CSP:</span>
                            <span class="info-value">${headers.content_security_policy ? 'Aktiveret' : 'Ikke fundet'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">X-Frame-Options:</span>
                            <span class="info-value">${headers.x_frame_options || 'Ikke fundet'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">X-Content-Type-Options:</span>
                            <span class="info-value">${headers.x_content_type_options || 'Ikke fundet'}</span>
                        </div>
                    </div>
                    
                    <div class="result-card">
                        <div class="card-title">📊 Fingerprints</div>
                        <div class="info-item">
                            <span class="info-label">SHA1:</span>
                            <span class="info-value">${cert.fingerprints.sha1}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">SHA256:</span>
                            <span class="info-value">${cert.fingerprints.sha256}</span>
                        </div>
                        ${cert.san && cert.san.length > 0 ? `
                        <div class="info-item">
                            <span class="info-label">Alternative navne:</span>
                            <span class="info-value">${cert.san.join(', ')}</span>
                        </div>
                        ` : ''}
                    </div>
                </div>
                
                ${data.vulnerabilities && data.vulnerabilities.length > 0 ? `
                <div class="result-card" style="margin-top: 25px;">
                    <div class="card-title">⚠️ Sikkerhedsproblemer</div>
                    ${data.vulnerabilities.map(vuln => `
                        <div class="vulnerability vuln-${vuln.severity}">
                            <div class="vuln-title">${vuln.type}</div>
                            <div>${vuln.description}</div>
                        </div>
                    `).join('')}
                </div>
                ` : ''}
                
                ${data.recommendations && data.recommendations.length > 0 ? `
                <div class="result-card" style="margin-top: 25px;">
                    <div class="card-title">💡 Anbefalinger</div>
                    <ul style="padding-left: 20px;">
                        ${data.recommendations.map(rec => `<li style="margin-bottom: 8px;">${rec}</li>`).join('')}
                    </ul>
                </div>
                ` : ''}
                
                ${data.certificate_chain && data.certificate_chain.length > 0 ? `
                <div class="result-card" style="margin-top: 25px;">
                    <div class="card-title">🔗 Certificate Chain</div>
                    ${data.certificate_chain.map((chainCert, index) => `
                        <div style="margin-bottom: 15px; padding: 10px; background: #f8f9fa; border-radius: 8px;">
                            <strong>Level ${index + 1}:</strong><br>
                            <small>Subject: ${chainCert.subject}<br>
                            Issuer: ${chainCert.issuer}<br>
                            Valid: ${chainCert.valid_from} til ${chainCert.valid_to}</small>
                        </div>
                    `).join('')}
                </div>
                ` : ''}
            `;
            
            resultsDiv.innerHTML = html;
            resultsDiv.style.display = 'block';
        }
    </script>
</body>
</html>