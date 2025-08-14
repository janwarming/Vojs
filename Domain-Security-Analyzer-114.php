<?php
// Domain Security Analyzer v1.14

// Configuration
ini_set('display_errors', 0); // Hide errors in production
error_reporting(E_ALL);

class SSLAnalyzer {
    private $domain;
    private $port;
    private $timeout;
    
    // Security standards for different key types
    private const KEY_SECURITY_STANDARDS = [
        'RSA' => [
            'minimum_secure' => 2048,
            'recommended' => 4096,
            'weak_threshold' => 2048
        ],
        'EC' => [
            'minimum_secure' => 256,
            'recommended' => 384,
            'weak_threshold' => 256
        ],
        'DSA' => [
            'minimum_secure' => 2048,
            'recommended' => 3072,
            'weak_threshold' => 2048
        ]
    ];
    
    // ECC to RSA equivalent security levels
    private const ECC_RSA_EQUIVALENTS = [
        160 => 1024,   // Weak
        224 => 2048,   // Legacy
        256 => 3072,   // Standard
        384 => 7680,   // Strong
        521 => 15360   // Very Strong
    ];
    
    // Enhanced CDN patterns for better detection
    private const CDN_PATTERNS = [
        'cloudflare' => ['cloudflare', 'cf-ray', 'cf-cache-status'],
        'fastly' => ['fastly', 'fastly-cache', 'x-served-by'],
        'akamai' => ['akamai', 'edgekey', 'edgesuite', 'akamaitechnologies'],
        'maxcdn' => ['maxcdn', 'netdna'],
        'amazon' => ['cloudfront', 'amazonaws'],
        'google' => ['googleapis', 'googleusercontent', 'gstatic'],
        'microsoft' => ['azureedge', 'azure'],
        'keycdn' => ['keycdn'],
        'bunnycdn' => ['bunnycdn'],
        'stackpath' => ['stackpath', 'netdna-ssl'],
        'jsdelivr' => ['jsdelivr'],
        'unpkg' => ['unpkg'],
        'cdnjs' => ['cdnjs']
    ];
    
    public function __construct($domain, $port = 443, $timeout = 10) {
        $this->domain = $domain;
        $this->port = $port;
        $this->timeout = $timeout;
    }
    
    /**
     * Get comprehensive SSL certificate information
     */
    public function getCertificateInfo() {
        try {
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
                throw new Exception("Unable to establish connection to {$this->domain}:{$this->port} - $errstr ($errno)");
            }
            
            $params = stream_context_get_params($socket);
            $cert = $params['options']['ssl']['peer_certificate'] ?? null;
            $certChain = $params['options']['ssl']['peer_certificate_chain'] ?? [];
            
            fclose($socket);
            
            if (!$cert) {
                throw new Exception("Failed to retrieve SSL certificate from {$this->domain}");
            }
            
            $certData = openssl_x509_parse($cert);
            if (!$certData) {
                throw new Exception("Failed to parse SSL certificate");
            }
            
            return [
                'certificate' => $this->parseCertificateData($certData, $cert),
                'chain' => $this->parseCertificateChain($certChain),
                'domain' => $this->domain,
                'port' => $this->port
            ];
        } catch (Exception $e) {
            throw new Exception("Certificate analysis failed: " . $e->getMessage());
        }
    }
    
    /**
     * Get detailed IP and location information using external API
     */
    public function getIPLocationInfo() {
        try {
            $ipInfo = [];
            
            // Get IPv4 address
            $ipv4 = @gethostbyname($this->domain);
            if ($ipv4 && $ipv4 !== $this->domain && filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ipInfo['ipv4'] = $ipv4;
                
                // Get detailed location info from API
                $locationData = $this->getLocationFromAPI($ipv4);
                if ($locationData) {
                    $ipInfo = array_merge($ipInfo, $locationData);
                } else {
                    // Fallback to basic detection if API fails
                    $basicData = $this->getBasicLocationInfo($ipv4);
                    $ipInfo = array_merge($ipInfo, $basicData);
                }
            }
            
            // Get IPv6 address
            try {
                $dnsRecords = @dns_get_record($this->domain, DNS_AAAA);
                if ($dnsRecords && !empty($dnsRecords)) {
                    $ipInfo['ipv6'] = $dnsRecords[0]['ipv6'] ?? null;
                }
            } catch (Exception $e) {
                // IPv6 lookup failed, continue without it
            }
            
            // Enhanced CDN detection
            $cdnInfo = $this->detectCDN($ipInfo);
            if ($cdnInfo) {
                $ipInfo['is_cdn'] = $cdnInfo;
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
            // Using ipapi.co (same as your WhoIS tool)
            $url = "https://ipapi.co/{$ip}/json/";
            
            // Set up context for the request
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 5,
                    'user_agent' => 'Vojsio-Domain-Analyzer/1.14'
                ]
            ]);
            
            $response = @file_get_contents($url, false, $context);
            
            if ($response) {
                $data = json_decode($response, true);
                
                if ($data && !isset($data['error'])) {
                    return [
                        'country' => $data['country_name'] ?? 'Unknown',
                        'region' => $data['region'] ?? 'Unknown',
                        'city' => $data['city'] ?? 'Unknown',
                        'organization' => $data['org'] ?? 'Unknown',
                        'timezone' => $data['timezone'] ?? 'Unknown',
                        'reverse_dns' => @gethostbyaddr($ip) ?: 'Not available'
                    ];
                }
            }
        } catch (Exception $e) {
            // API call failed, will use fallback
        }
        
        return null;
    }
    
    /**
     * Basic location detection as fallback
     */
    private function getBasicLocationInfo($ip) {
        $locationInfo = [];
        
        // Get reverse DNS
        $reverseDns = @gethostbyaddr($ip);
        if (!$reverseDns || $reverseDns === $ip) {
            $reverseDns = '';
        } else {
            $reverseDns = strtolower($reverseDns);
            $locationInfo['reverse_dns'] = $reverseDns;
        }
        
        // Basic organization detection
        $org = $this->detectOrganization($reverseDns, $ip);
        if ($org && $org !== 'Unknown') {
            $locationInfo['organization'] = $org;
            
            // For known organizations, provide approximate location
            switch ($org) {
                case 'GOOGLE':
                    $locationInfo['country'] = 'United States';
                    $locationInfo['region'] = 'Global';
                    $locationInfo['city'] = 'Multiple Locations';
                    $locationInfo['timezone'] = 'UTC';
                    break;
                case 'CLOUDFLARE':
                    $locationInfo['country'] = 'Global CDN';
                    $locationInfo['region'] = 'Worldwide';
                    $locationInfo['city'] = 'Multiple Locations';
                    $locationInfo['timezone'] = 'UTC';
                    break;
                case 'AMAZON':
                    $locationInfo['country'] = 'United States';
                    $locationInfo['region'] = 'AWS Global';
                    $locationInfo['city'] = 'Multiple Locations';
                    $locationInfo['timezone'] = 'UTC';
                    break;
                default:
                    // Try country detection from reverse DNS
                    $countryInfo = $this->detectCountryFromDNS($reverseDns);
                    if ($countryInfo) {
                        $locationInfo = array_merge($locationInfo, $countryInfo);
                    }
                    break;
            }
        } else {
            // Try country detection from reverse DNS
            $countryInfo = $this->detectCountryFromDNS($reverseDns);
            if ($countryInfo) {
                $locationInfo = array_merge($locationInfo, $countryInfo);
            }
        }
        
        return $locationInfo;
    }
    
    /**
     * Detect organization with enhanced patterns
     */
    private function detectOrganization($reverseDns, $ip) {
        if (!$reverseDns) {
            return 'Unknown';
        }
        
        $orgPatterns = [
            'google' => 'GOOGLE',
            'amazon' => 'AMAZON',
            'microsoft' => 'MICROSOFT',
            'cloudflare' => 'CLOUDFLARE',
            'fastly' => 'FASTLY',
            'akamai' => 'AKAMAI',
            'digitalocean' => 'DIGITALOCEAN',
            'linode' => 'LINODE',
            'siteground' => 'SITEGROUND',
            'ovh' => 'OVH',
            'hetzner' => 'HETZNER'
        ];
        
        foreach ($orgPatterns as $pattern => $org) {
            if (strpos($reverseDns, $pattern) !== false) {
                return $org;
            }
        }
        
        return 'Unknown';
    }
    
    /**
     * Detect country from DNS with basic mapping
     */
    private function detectCountryFromDNS($reverseDns) {
        $countryMappings = [
            '.de' => ['country' => 'Germany', 'region' => 'Various', 'city' => 'Multiple Cities', 'timezone' => 'Europe/Berlin'],
            '.uk' => ['country' => 'United Kingdom', 'region' => 'England', 'city' => 'London', 'timezone' => 'Europe/London'],
            '.fr' => ['country' => 'France', 'region' => 'Île-de-France', 'city' => 'Paris', 'timezone' => 'Europe/Paris'],
            '.nl' => ['country' => 'The Netherlands', 'region' => 'North Holland', 'city' => 'Amsterdam', 'timezone' => 'Europe/Amsterdam'],
            '.se' => ['country' => 'Sweden', 'region' => 'Stockholm', 'city' => 'Stockholm', 'timezone' => 'Europe/Stockholm'],
            '.dk' => ['country' => 'Denmark', 'region' => 'Copenhagen', 'city' => 'Copenhagen', 'timezone' => 'Europe/Copenhagen'],
            '.us' => ['country' => 'United States', 'region' => 'Various', 'city' => 'Multiple Cities', 'timezone' => 'America/New_York'],
            '.ca' => ['country' => 'Canada', 'region' => 'Ontario', 'city' => 'Toronto', 'timezone' => 'America/Toronto'],
        ];
        
        foreach ($countryMappings as $pattern => $data) {
            if (strpos($reverseDns, $pattern) !== false) {
                return $data;
            }
        }
        
        return null;
    }
    
    /**
     * Get DNS records with enhanced error handling
     */
    public function getDNSRecords() {
        try {
            $dnsInfo = [];
            
            // A Records (IPv4)
            $aRecords = @dns_get_record($this->domain, DNS_A);
            if ($aRecords && is_array($aRecords)) {
                $dnsInfo['A'] = array_map(function($record) {
                    return $record['ip'];
                }, $aRecords);
            }
            
            // AAAA Records (IPv6)
            try {
                $aaaaRecords = @dns_get_record($this->domain, DNS_AAAA);
                if ($aaaaRecords && is_array($aaaaRecords)) {
                    $dnsInfo['AAAA'] = array_map(function($record) {
                        return $record['ipv6'];
                    }, $aaaaRecords);
                }
            } catch (Exception $e) {
                // IPv6 records not available
            }
            
            // MX Records
            $mxRecords = @dns_get_record($this->domain, DNS_MX);
            if ($mxRecords && is_array($mxRecords)) {
                $dnsInfo['MX'] = array_map(function($record) {
                    return $record['pri'] . ' ' . $record['target'];
                }, $mxRecords);
            }
            
            // TXT Records
            $txtRecords = @dns_get_record($this->domain, DNS_TXT);
            if ($txtRecords && is_array($txtRecords)) {
                $dnsInfo['TXT'] = array_map(function($record) {
                    $txt = $record['txt'];
                    // Identify special TXT records
                    if (strpos($txt, 'v=spf1') === 0) return '[SPF] ' . $txt;
                    if (strpos($txt, 'v=DKIM1') === 0) return '[DKIM] ' . $txt;
                    if (strpos($txt, 'v=DMARC1') === 0) return '[DMARC] ' . $txt;
                    if (strpos($txt, 'google-site-verification') === 0) return '[Google Verification] ' . $txt;
                    return $txt;
                }, $txtRecords);
            }
            
            // NS Records
            $nsRecords = @dns_get_record($this->domain, DNS_NS);
            if ($nsRecords && is_array($nsRecords)) {
                $dnsInfo['NS'] = array_map(function($record) {
                    return $record['target'];
                }, $nsRecords);
            }
            
            // CNAME Records
            $cnameRecords = @dns_get_record($this->domain, DNS_CNAME);
            if ($cnameRecords && is_array($cnameRecords)) {
                $dnsInfo['CNAME'] = array_map(function($record) {
                    return $record['target'];
                }, $cnameRecords);
            }
            
            // Try to get CAA records (not all PHP versions support this)
            try {
                if (defined('DNS_CAA')) {
                    $caaRecords = @dns_get_record($this->domain, DNS_CAA);
                    if ($caaRecords && is_array($caaRecords)) {
                        $dnsInfo['CAA'] = array_map(function($record) {
                            return $record['flags'] . ' ' . $record['tag'] . ' "' . $record['value'] . '"';
                        }, $caaRecords);
                    }
                }
            } catch (Exception $e) {
                // CAA records not supported
            }
            
            return $dnsInfo;
            
        } catch (Exception $e) {
            return ['error' => 'Unable to retrieve DNS information: ' . $e->getMessage()];
        }
    }
    
    /**
     * Parse certificate data with enhanced key analysis
     */
    private function parseCertificateData($certData, $cert) {
        $validFrom = date('Y-m-d H:i:s', $certData['validFrom_time_t']);
        $validTo = date('Y-m-d H:i:s', $certData['validTo_time_t']);
        $daysLeft = floor(($certData['validTo_time_t'] - time()) / 86400);
        
        // Get enhanced public key info
        $keyInfo = $this->analyzePublicKey($cert);
        
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
            'public_key' => $keyInfo,
            'san' => $this->getSANs($certData['extensions'] ?? []),
            'fingerprints' => $this->getCertificateFingerprints($cert)
        ];
    }
    
    /**
     * Enhanced public key analysis
     */
    private function analyzePublicKey($cert) {
        $keyInfo = [
            'algorithm' => 'Unknown',
            'size' => 'Unknown',
            'type_detail' => 'Unknown',
            'security_level' => 'Unknown',
            'rsa_equivalent' => null
        ];
        
        try {
            $publicKey = @openssl_pkey_get_public($cert);
            if (!$publicKey) {
                return $keyInfo;
            }
            
            $keyDetails = openssl_pkey_get_details($publicKey);
            if (!$keyDetails) {
                return $keyInfo;
            }
            
            $keyInfo['size'] = $keyDetails['bits'] ?? 'Unknown';
            
            // Determine key type
            if (isset($keyDetails['type'])) {
                switch ($keyDetails['type']) {
                    case OPENSSL_KEYTYPE_RSA:
                        $keyInfo['algorithm'] = 'RSA';
                        $keyInfo['type_detail'] = 'RSA';
                        $keyInfo['security_level'] = $this->evaluateRSASecurityLevel($keyInfo['size']);
                        break;
                        
                    case OPENSSL_KEYTYPE_EC:
                        $keyInfo['algorithm'] = 'EC';
                        $keyInfo['type_detail'] = $this->getECCurveDetails($keyDetails);
                        $keyInfo['security_level'] = $this->evaluateECCSecurityLevel($keyInfo['size']);
                        $keyInfo['rsa_equivalent'] = $this->getECCRSAEquivalent($keyInfo['size']);
                        break;
                        
                    case OPENSSL_KEYTYPE_DSA:
                        $keyInfo['algorithm'] = 'DSA';
                        $keyInfo['type_detail'] = 'DSA';
                        $keyInfo['security_level'] = $this->evaluateDSASecurityLevel($keyInfo['size']);
                        break;
                }
            }
        } catch (Exception $e) {
            // Key analysis failed, return defaults
        }
        
        return $keyInfo;
    }
    
    /**
     * Get ECC curve details
     */
    private function getECCurveDetails($keyDetails) {
        if (isset($keyDetails['ec']['curve_name'])) {
            return "ECC ({$keyDetails['ec']['curve_name']})";
        }
        return 'ECC';
    }
    
    /**
     * Evaluate RSA key security level
     */
    private function evaluateRSASecurityLevel($keySize) {
        if (!is_numeric($keySize)) return 'Unknown';
        
        if ($keySize < 1024) return 'Critically Weak';
        if ($keySize < 2048) return 'Weak (Legacy)';
        if ($keySize < 3072) return 'Adequate';
        if ($keySize < 4096) return 'Good';
        return 'Excellent';
    }
    
    /**
     * Evaluate ECC key security level
     */
    private function evaluateECCSecurityLevel($keySize) {
        if (!is_numeric($keySize)) return 'Unknown';
        
        if ($keySize < 224) return 'Weak';
        if ($keySize < 256) return 'Legacy';
        if ($keySize < 384) return 'Good';
        if ($keySize >= 521) return 'Excellent';
        return 'Very Good';
    }
    
    /**
     * Evaluate DSA key security level
     */
    private function evaluateDSASecurityLevel($keySize) {
        if (!is_numeric($keySize)) return 'Unknown';
        
        if ($keySize < 1024) return 'Critically Weak';
        if ($keySize < 2048) return 'Weak (Legacy)';
        if ($keySize < 3072) return 'Adequate';
        return 'Good';
    }
    
    /**
     * Get RSA equivalent for ECC keys
     */
    private function getECCRSAEquivalent($eccKeySize) {
        if (!is_numeric($eccKeySize)) return null;
        
        $closest = null;
        $minDiff = PHP_INT_MAX;
        
        foreach (self::ECC_RSA_EQUIVALENTS as $eccBits => $rsaBits) {
            $diff = abs($eccKeySize - $eccBits);
            if ($diff < $minDiff) {
                $minDiff = $diff;
                $closest = $rsaBits;
            }
        }
        
        return $closest;
    }
    
    /**
     * Parse certificate chain
     */
    private function parseCertificateChain($chain) {
        $chainInfo = [];
        
        try {
            foreach ($chain as $cert) {
                $certData = openssl_x509_parse($cert);
                if ($certData) {
                    $keyInfo = $this->analyzePublicKey($cert);
                    
                    $chainInfo[] = [
                        'subject' => $this->formatDN($certData['subject'] ?? []),
                        'issuer' => $this->formatDN($certData['issuer'] ?? []),
                        'valid_from' => date('Y-m-d H:i:s', $certData['validFrom_time_t']),
                        'valid_to' => date('Y-m-d H:i:s', $certData['validTo_time_t']),
                        'is_ca' => isset($certData['extensions']['basicConstraints']) && 
                                  strpos($certData['extensions']['basicConstraints'], 'CA:TRUE') !== false,
                        'public_key' => $keyInfo
                    ];
                }
            }
        } catch (Exception $e) {
            // Chain parsing failed
        }
        
        return $chainInfo;
    }
    
    /**
     * Enhanced CDN detection with multiple methods
     */
    private function detectCDN($ipInfo = []) {
        try {
            $cdnIndicators = [];
            
            // Method 1: Check CNAME records
            $cnameRecords = @dns_get_record($this->domain, DNS_CNAME);
            if ($cnameRecords && is_array($cnameRecords)) {
                foreach ($cnameRecords as $record) {
                    $target = strtolower($record['target']);
                    $detectedCDN = $this->analyzeCDNPattern($target);
                    if ($detectedCDN) {
                        $cdnIndicators[] = $detectedCDN;
                    }
                }
            }
            
            // Method 2: Check reverse DNS
            if (isset($ipInfo['reverse_dns']) && $ipInfo['reverse_dns']) {
                $reverseDns = strtolower($ipInfo['reverse_dns']);
                $detectedCDN = $this->analyzeCDNPattern($reverseDns);
                if ($detectedCDN) {
                    $cdnIndicators[] = $detectedCDN;
                }
            }
            
            // Method 3: Check organization information
            if (isset($ipInfo['organization']) && $ipInfo['organization']) {
                $org = strtolower($ipInfo['organization']);
                $detectedCDN = $this->analyzeCDNPattern($org);
                if ($detectedCDN) {
                    $cdnIndicators[] = $detectedCDN;
                }
            }
            
            // Method 4: Check HTTP headers for CDN indicators
            $headerCDN = $this->detectCDNFromHeaders();
            if ($headerCDN) {
                $cdnIndicators[] = $headerCDN;
            }
            
            // Method 5: Check NS records for CDN providers
            $nsRecords = @dns_get_record($this->domain, DNS_NS);
            if ($nsRecords && is_array($nsRecords)) {
                foreach ($nsRecords as $record) {
                    $target = strtolower($record['target']);
                    $detectedCDN = $this->analyzeCDNPattern($target);
                    if ($detectedCDN) {
                        $cdnIndicators[] = $detectedCDN;
                    }
                }
            }
            
            // Remove duplicates and return
            $cdnIndicators = array_unique($cdnIndicators);
            return empty($cdnIndicators) ? false : implode(', ', $cdnIndicators);
            
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Analyze string patterns for CDN detection
     */
    private function analyzeCDNPattern($text) {
        $text = strtolower($text);
        
        foreach (self::CDN_PATTERNS as $cdnName => $patterns) {
            foreach ($patterns as $pattern) {
                if (strpos($text, $pattern) !== false) {
                    return ucfirst($cdnName);
                }
            }
        }
        
        // Generic CDN detection
        if (strpos($text, 'cdn') !== false || 
            strpos($text, 'cache') !== false || 
            strpos($text, 'edge') !== false) {
            return 'CDN Detected';
        }
        
        return false;
    }
    
    /**
     * Detect CDN from HTTP headers
     */
    private function detectCDNFromHeaders() {
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => "https://{$this->domain}",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_NOBODY => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_USERAGENT => 'Vojsio-Domain-Analyzer/1.14'
            ]);
            
            $response = curl_exec($ch);
            curl_close($ch);
            
            if (!$response) {
                return false;
            }
            
            $headers = $this->parseHeaders($response);
            
            // Check for CDN-specific headers
            $cdnHeaders = [
                'cf-ray' => 'Cloudflare',
                'cf-cache-status' => 'Cloudflare',
                'x-served-by' => 'Fastly',
                'x-cache' => 'Various CDN',
                'x-edge-location' => 'CloudFront',
                'x-amz-cf-id' => 'CloudFront',
                'server' => 'CDN Detection'
            ];
            
            foreach ($cdnHeaders as $headerName => $cdnName) {
                if (isset($headers[$headerName])) {
                    if ($headerName === 'server') {
                        $serverValue = strtolower($headers[$headerName]);
                        $detectedCDN = $this->analyzeCDNPattern($serverValue);
                        if ($detectedCDN) {
                            return $detectedCDN;
                        }
                    } else {
                        return $cdnName;
                    }
                }
            }
            
            return false;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get security headers
     */
    public function getSecurityHeaders() {
        try {
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
                CURLOPT_USERAGENT => 'Vojsio-Domain-Analyzer/1.14'
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error || !$response) {
                return [
                    'error' => $error ?: 'Failed to retrieve security headers',
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
        } catch (Exception $e) {
            return ['error' => 'Failed to retrieve security headers: ' . $e->getMessage()];
        }
    }
    
    /**
     * Main analysis function
     */
    public function getSecurityAnalysis() {
        try {
            $certInfo = $this->getCertificateInfo();
            $securityHeaders = $this->getSecurityHeaders();
            $ipLocation = $this->getIPLocationInfo();
            $dnsRecords = $this->getDNSRecords();
            
            $analysis = [
                'domain' => $this->domain,
                'port' => $this->port,
                'timestamp' => date('Y-m-d H:i:s'),
                'certificate' => $certInfo['certificate'],
                'certificate_chain' => $certInfo['chain'],
                'security_headers' => $securityHeaders,
                'ip_location' => $ipLocation,
                'dns_records' => $dnsRecords,
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
        $cert = $analysis['certificate'] ?? [];
        $headers = $analysis['security_headers'] ?? [];
        $keyInfo = $cert['public_key'] ?? [];
        
        // Certificate validity (30 points)
        if (isset($cert['is_expired']) && !$cert['is_expired']) {
            $score += 20;
            if (isset($cert['days_until_expiry'])) {
                if ($cert['days_until_expiry'] > 30) $score += 5;
                if ($cert['days_until_expiry'] > 90) $score += 5;
            }
        }
        
        // Key strength (25 points)
        $score += $this->calculateKeySecurityScore($keyInfo);
        
        // Signature algorithm (10 points)
        if (isset($cert['signature_algorithm'])) {
            $sigAlg = strtolower($cert['signature_algorithm']);
            if (strpos($sigAlg, 'sha256') !== false || strpos($sigAlg, 'sha384') !== false || strpos($sigAlg, 'sha512') !== false) {
                $score += 10;
            } elseif (strpos($sigAlg, 'sha1') === false && strpos($sigAlg, 'md5') === false) {
                $score += 5;
            }
        }
        
        // Security headers (35 points)
        if (isset($headers['strict_transport_security']) && $headers['strict_transport_security']) $score += 15;
        if (isset($headers['content_security_policy']) && $headers['content_security_policy']) $score += 10;
        if (isset($headers['x_frame_options']) && $headers['x_frame_options']) $score += 5;
        if (isset($headers['x_content_type_options']) && $headers['x_content_type_options']) $score += 3;
        if (isset($headers['x_xss_protection']) && $headers['x_xss_protection']) $score += 2;
        
        return min(100, max(0, $score));
    }
    
    /**
     * Calculate key security score
     */
    private function calculateKeySecurityScore($keyInfo) {
        if (!isset($keyInfo['size']) || !is_numeric($keyInfo['size']) || !isset($keyInfo['algorithm'])) {
            return 0;
        }
        
        $keySize = (int)$keyInfo['size'];
        $algorithm = $keyInfo['algorithm'];
        
        if (!isset(self::KEY_SECURITY_STANDARDS[$algorithm])) {
            return 5;
        }
        
        $standards = self::KEY_SECURITY_STANDARDS[$algorithm];
        
        if ($keySize < $standards['weak_threshold']) {
            return 0;
        } elseif ($keySize < $standards['minimum_secure']) {
            return 8;
        } elseif ($keySize < $standards['recommended']) {
            return 15;
        } else {
            return 25;
        }
    }
    
    /**
     * Identify vulnerabilities
     */
    private function identifyVulnerabilities($analysis) {
        $vulnerabilities = [];
        $cert = $analysis['certificate'] ?? [];
        $headers = $analysis['security_headers'] ?? [];
        
        // Certificate issues
        if (isset($cert['is_expired']) && $cert['is_expired']) {
            $vulnerabilities[] = [
                'severity' => 'critical',
                'type' => 'Certificate Expired',
                'description' => 'SSL certificate has expired and requires immediate renewal'
            ];
        } elseif (isset($cert['is_expiring_soon']) && $cert['is_expiring_soon']) {
            $vulnerabilities[] = [
                'severity' => 'warning',
                'type' => 'Certificate Expiring Soon',
                'description' => "Certificate expires in {$cert['days_until_expiry']} days"
            ];
        }
        
        // Missing security headers
        if (!isset($headers['strict_transport_security']) || !$headers['strict_transport_security']) {
            $vulnerabilities[] = [
                'severity' => 'medium',
                'type' => 'Missing HSTS Header',
                'description' => 'Strict-Transport-Security header not found'
            ];
        }
        
        return $vulnerabilities;
    }
    
    /**
     * Get recommendations
     */
    private function getRecommendations($analysis) {
        $recommendations = [];
        $cert = $analysis['certificate'] ?? [];
        $headers = $analysis['security_headers'] ?? [];
        
        if (isset($cert['days_until_expiry']) && $cert['days_until_expiry'] < 60) {
            $recommendations[] = 'Schedule certificate renewal within the next 30 days';
        }
        
        if (!isset($headers['strict_transport_security']) || !$headers['strict_transport_security']) {
            $recommendations[] = 'Implement HSTS header with includeSubDomains';
        }
        
        if (!isset($headers['content_security_policy']) || !$headers['content_security_policy']) {
            $recommendations[] = 'Implement Content Security Policy';
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
                list($key, $value) = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }
        return $headers;
    }
}

// API Endpoint
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action']) && $_POST['action'] === 'analyze') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    try {
        $domain = trim($_POST['domain'] ?? '');
        $port = intval($_POST['port'] ?? 443);
        
        if (empty($domain)) {
            throw new Exception('Domain is required');
        }
        
        // Clean domain
        $domain = preg_replace('/^https?:\/\//', '', $domain);
        $domain = preg_replace('/\/.*$/', '', $domain);
        $domain = strtolower($domain);
        
        if (!filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new Exception('Invalid domain format');
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Domain v1.14</title>
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
            color: #00ff00;
            min-height: 100vh;
            overflow-x: hidden;
            position: relative;
        }

        /* Matrix rain effect */
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
                linear-gradient(rgba(0, 255, 0, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0, 255, 0, 0.03) 1px, transparent 1px);
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
            background: linear-gradient(90deg, transparent, #00ff00, transparent);
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
            color: #00ff00;
            text-shadow: 
                0 0 5px #00ff00,
                0 0 10px #00ff00,
                0 0 15px #00ff00,
                0 0 20px #00ff00;
            animation: glow-pulse 3s ease-in-out infinite;
        }

        @keyframes glow-pulse {
            0%, 100% { text-shadow: 0 0 5px #00ff00, 0 0 10px #00ff00, 0 0 15px #00ff00; }
            50% { text-shadow: 0 0 10px #00ff00, 0 0 20px #00ff00, 0 0 30px #00ff00, 0 0 40px #00ff00; }
        }

        .header .subtitle {
            font-size: 1.1em;
            color: #00aa00;
            font-weight: 400;
            letter-spacing: 2px;
            opacity: 0.8;
        }

        .version-badge {
            display: inline-block;
            background: linear-gradient(45deg, #003300, #006600);
            border: 1px solid #00ff00;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.8em;
            margin-top: 10px;
            animation: badge-glow 3s ease-in-out infinite;
        }

        @keyframes badge-glow {
            0%, 100% { box-shadow: 0 0 5px rgba(0, 255, 0, 0.3); }
            50% { box-shadow: 0 0 15px rgba(0, 255, 0, 0.6); }
        }

        .terminal-window {
            background: rgba(0, 20, 0, 0.95);
            border: 2px solid #00ff00;
            border-radius: 10px;
            box-shadow: 
                0 0 20px rgba(0, 255, 0, 0.3),
                inset 0 0 20px rgba(0, 255, 0, 0.1);
            margin-bottom: 30px;
            overflow: hidden;
            position: relative;
        }

        .terminal-header {
            background: linear-gradient(90deg, #003300, #004400);
            padding: 10px 20px;
            border-bottom: 1px solid #00ff00;
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
        .terminal-button:nth-child(3) { background: #00ff00; }

        .terminal-title {
            font-size: 0.9em;
            color: #00ff00;
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
            color: #00ff00;
            font-size: 0.9em;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .form-input {
            width: 100%;
            padding: 15px 20px;
            background: rgba(0, 0, 0, 0.8);
            border: 2px solid #003300;
            border-radius: 8px;
            color: #00ff00;
            font-family: 'JetBrains Mono', monospace;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: #00ff00;
            background: rgba(0, 20, 0, 0.9);
            box-shadow: 
                0 0 10px rgba(0, 255, 0, 0.3),
                inset 0 0 10px rgba(0, 255, 0, 0.1);
        }

        .form-input::placeholder {
            color: #006600;
        }

        .analyze-btn {
            width: 100%;
            padding: 18px;
            background: linear-gradient(45deg, #003300, #006600);
            border: 2px solid #00ff00;
            border-radius: 8px;
            color: #00ff00;
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
            background: linear-gradient(90deg, transparent, rgba(0, 255, 0, 0.2), transparent);
            transition: left 0.5s ease;
        }

        .analyze-btn:hover {
            background: linear-gradient(45deg, #004400, #008800);
            box-shadow: 
                0 0 20px rgba(0, 255, 0, 0.5),
                inset 0 0 20px rgba(0, 255, 0, 0.1);
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
            border-top: 3px solid #00ff00;
            border-radius: 50%;
            animation: matrix-spin 1s linear infinite;
        }

        .spinner-ring:nth-child(2) {
            width: 45px;
            height: 45px;
            top: 7.5px;
            left: 7.5px;
            border-top-color: #00aa00;
            animation-delay: -0.3s;
        }

        .spinner-ring:nth-child(3) {
            width: 30px;
            height: 30px;
            top: 15px;
            left: 15px;
            border-top-color: #006600;
            animation-delay: -0.6s;
        }

        @keyframes matrix-spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .loading-text {
            color: #00ff00;
            font-size: 1.1em;
            font-weight: 600;
            margin-bottom: 10px;
            animation: text-glow 2s ease-in-out infinite;
        }

        @keyframes text-glow {
            0%, 100% { text-shadow: 0 0 5px #00ff00; }
            50% { text-shadow: 0 0 15px #00ff00, 0 0 25px #00ff00; }
        }

        .loading-dots {
            color: #00aa00;
            font-size: 0.9em;
        }

        .results {
            margin-top: 30px;
            display: none;
        }

        .security-score {
            text-align: center;
            background: rgba(0, 20, 0, 0.8);
            border: 2px solid #00ff00;
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
            background: linear-gradient(90deg, #00ff00, #00aa00, #00ff00);
            animation: progress-glow 3s ease-in-out infinite;
        }

        @keyframes progress-glow {
            0%, 100% { opacity: 0.7; }
            50% { opacity: 1; box-shadow: 0 0 20px #00ff00; }
        }

        .score-value {
            font-size: 4em;
            font-weight: 700;
            color: #00ff00;
            margin-bottom: 15px;
            text-shadow: 
                0 0 10px #00ff00,
                0 0 20px #00ff00;
            animation: score-pulse 2s ease-in-out infinite;
        }

        @keyframes score-pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        .score-label {
            font-size: 1.2em;
            color: #00aa00;
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
            background: rgba(0, 20, 0, 0.9);
            border: 2px solid #003300;
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
            background: linear-gradient(90deg, #00ff00, #00aa00);
        }

        .result-card:hover {
            border-color: #00ff00;
            box-shadow: 
                0 10px 30px rgba(0, 255, 0, 0.2),
                inset 0 0 20px rgba(0, 255, 0, 0.05);
            transform: translateY(-5px);
        }

        .card-title {
            font-size: 1.3em;
            color: #00ff00;
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
            text-shadow: 0 0 10px #00ff00;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: start;
            padding: 12px 0;
            border-bottom: 1px solid #003300;
            transition: all 0.3s ease;
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-item:hover {
            background: rgba(0, 255, 0, 0.05);
            padding-left: 10px;
        }

        .info-label {
            font-weight: 600;
            color: #00aa00;
            flex-shrink: 0;
            margin-right: 20px;
            font-size: 0.9em;
        }

        .info-value {
            color: #00ff00;
            text-align: right;
            word-break: break-word;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.85em;
            text-shadow: 0 0 5px rgba(0, 255, 0, 0.3);
        }

        .security-level {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8em;
            font-weight: 600;
            text-transform: uppercase;
        }

        .security-excellent { background: rgba(0, 255, 0, 0.2); color: #00ff00; }
        .security-very-good { background: rgba(0, 200, 0, 0.2); color: #00cc00; }
        .security-good { background: rgba(100, 255, 0, 0.2); color: #66ff00; }
        .security-adequate { background: rgba(255, 255, 0, 0.2); color: #ffff00; }
        .security-legacy { background: rgba(255, 165, 0, 0.2); color: #ffa500; }
        .security-weak { background: rgba(255, 0, 0, 0.2); color: #ff6666; }

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
        .vuln-warning { 
            border-left-color: #ffaa00; 
            background: rgba(255, 170, 0, 0.1);
        }
        .vuln-low { 
            border-left-color: #00ff00; 
            background: rgba(0, 255, 0, 0.1);
        }

        .vuln-title {
            font-weight: 700;
            margin-bottom: 8px;
            color: #00ff00;
            font-size: 1.1em;
        }

        .vuln-description {
            color: #00aa00;
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

        .chain-item {
            margin-bottom: 20px;
            padding: 15px;
            background: rgba(0, 0, 0, 0.5);
            border: 1px solid #003300;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .chain-item:hover {
            border-color: #00ff00;
            background: rgba(0, 20, 0, 0.8);
        }

        .chain-level {
            font-weight: 700;
            color: #00ff00;
            margin-bottom: 10px;
            font-size: 1.1em;
        }

        .chain-details {
            font-size: 0.85em;
            color: #00aa00;
            line-height: 1.4;
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
            border: 1px solid #003300;
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, #00ff00, #006600);
            border-radius: 6px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, #00ff00, #00aa00);
        }
    </style>
</head>
<body>
    <div class="matrix-bg">
        <canvas class="matrix-canvas" id="matrixCanvas"></canvas>
    </div>

    <div class="container">
        <div class="header">
            <h1>🔒 Vojs.IO SECURITY ANALYZER</h1>
            <div class="subtitle">ENHANCED DOMAIN SECURITY ANALYSIS</div>
            <div class="version-badge">v1.14</div>
        </div>

        <div class="terminal-window">
            <div class="terminal-header">
                <div class="terminal-buttons">
                    <div class="terminal-button"></div>
                    <div class="terminal-button"></div>
                    <div class="terminal-button"></div>
                </div>
                <div class="terminal-title">Enhanced Domain Security Analysis Engine</div>
            </div>
            
            <div class="terminal-content">
                <form id="analysisForm">
                    <div class="form-group">
                        <label class="form-label">[TARGET_DOMAIN]</label>
                        <input type="text" class="form-input" id="domain" name="domain" 
                               placeholder="example.com or https://example.com" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">[PORT_NUMBER]</label>
                        <input type="number" class="form-input" id="port" name="port" 
                               value="443" min="1" max="65535">
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
                    <div class="loading-text">ANALYZING COMPLETE SECURITY PROFILE</div>
                    <div class="loading-dots">Establishing secure connection...</div>
                </div>

                <div class="results" id="results">
                    <!-- Results will be populated here -->
                </div>
            </div>
        </div>
    </div>

    <script>
        // Matrix rain effect
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
                
                ctx.fillStyle = '#00ff00';
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
            
            const domain = document.getElementById('domain').value.trim();
            const port = document.getElementById('port').value || 443;
            
            if (!domain) {
                alert('Please enter a domain');
                return;
            }
            
            document.getElementById('loading').style.display = 'block';
            document.getElementById('results').style.display = 'none';
            document.getElementById('analyzeBtn').disabled = true;
            
            // Enhanced loading text
            const loadingSteps = [
                'Establishing secure connection...',
                'Retrieving SSL certificate...',
                'Analyzing cryptographic strength...',
                'Resolving IP addresses...',
                'Detecting detailed location via API...',
                'Identifying organization...',
                'Performing enhanced CDN detection...',
                'Analyzing HTTP response headers...',
                'Querying DNS records...',
                'Checking security headers...',
                'Calculating security score...',
                'Generating comprehensive report...'
            ];
            
            let stepIndex = 0;
            const loadingInterval = setInterval(() => {
                if (stepIndex < loadingSteps.length) {
                    document.querySelector('.loading-dots').textContent = loadingSteps[stepIndex];
                    stepIndex++;
                }
            }, 900);
            
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
                    `<div class="error">[ERROR] ${error.message}</div>`;
                document.getElementById('results').style.display = 'block';
            } finally {
                clearInterval(loadingInterval);
                document.getElementById('loading').style.display = 'none';
                document.getElementById('analyzeBtn').disabled = false;
            }
        });
        
        function formatSecurityLevel(level) {
            const className = level.toLowerCase().replace(/\s+/g, '-').replace(/[()]/g, '');
            return `<span class="security-level security-${className}">${level}</span>`;
        }
        
        function formatDNSRecords(records) {
            if (!records || typeof records !== 'object') return '';
            
            let html = '';
            
            // Order records by importance
            const recordOrder = ['A', 'AAAA', 'MX', 'TXT', 'NS', 'CNAME', 'CAA', 'SOA'];
            
            recordOrder.forEach(type => {
                if (records[type] && type !== 'error') {
                    const values = records[type];
                    
                    if (Array.isArray(values) && values.length > 0) {
                        html += `<div class="info-item">
                            <span class="info-label">${type}_RECORDS:</span>
                            <span class="info-value">`;
                        
                        if (values.length === 1) {
                            html += values[0];
                        } else {
                            html += values.join('<br>');
                        }
                        
                        html += `</span></div>`;
                    }
                }
            });
            
            return html;
        }
        
        function displayResults(data) {
            const resultsDiv = document.getElementById('results');
            
            if (data.error) {
                resultsDiv.innerHTML = `<div class="error">[SYSTEM_ERROR] ${data.error}</div>`;
                resultsDiv.style.display = 'block';
                return;
            }
            
            const cert = data.certificate || {};
            const headers = data.security_headers || {};
            const keyInfo = cert.public_key || {};
            const ipInfo = data.ip_location || {};
            const dnsRecords = data.dns_records || {};
            
            const html = `
                <div class="security-score">
                    <div class="score-value">${data.security_score || 0}</div>
                    <div class="score-label">SECURITY SCORE / 100</div>
                </div>
                
                <div class="results-grid">
                    <div class="result-card">
                        <div class="card-title">
                            <span class="card-icon">📜</span>
                            CERTIFICATE DATA
                        </div>
                        <div class="info-item">
                            <span class="info-label">SUBJECT:</span>
                            <span class="info-value">${cert.subject || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">ISSUER:</span>
                            <span class="info-value">${cert.issuer || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">VALID_FROM:</span>
                            <span class="info-value">${cert.valid_from || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">VALID_UNTIL:</span>
                            <span class="info-value">${cert.valid_to || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">DAYS_REMAINING:</span>
                            <span class="info-value">${cert.days_until_expiry || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">SIGNATURE_ALG:</span>
                            <span class="info-value">${cert.signature_algorithm || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">SERIAL_NUMBER:</span>
                            <span class="info-value">${cert.serial_number || 'Unknown'}</span>
                        </div>
                    </div>
                    
                    <div class="result-card">
                        <div class="card-title">
                            <span class="card-icon">🔐</span>
                            CRYPTOGRAPHIC ANALYSIS
                        </div>
                        <div class="info-item">
                            <span class="info-label">KEY_ALGORITHM:</span>
                            <span class="info-value">${keyInfo.type_detail || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">KEY_SIZE:</span>
                            <span class="info-value">${keyInfo.size || 'Unknown'} bits</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">SECURITY_LEVEL:</span>
                            <span class="info-value">${keyInfo.security_level ? formatSecurityLevel(keyInfo.security_level) : 'Unknown'}</span>
                        </div>
                        ${keyInfo.rsa_equivalent ? `
                        <div class="info-item">
                            <span class="info-label">RSA_EQUIVALENT:</span>
                            <span class="info-value">${keyInfo.rsa_equivalent} bits</span>
                        </div>
                        ` : ''}
                    </div>
                    
                    <div class="result-card">
                        <div class="card-title">
                            <span class="card-icon">🛡️</span>
                            SECURITY HEADERS
                        </div>
                        <div class="info-item">
                            <span class="info-label">HTTP_STATUS:</span>
                            <span class="info-value">${headers.http_code || 'UNKNOWN'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">HSTS:</span>
                            <span class="info-value">${headers.strict_transport_security ? 'ENABLED' : 'NOT_FOUND'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">CSP:</span>
                            <span class="info-value">${headers.content_security_policy ? 'ENABLED' : 'NOT_FOUND'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">X_FRAME_OPTIONS:</span>
                            <span class="info-value">${headers.x_frame_options || 'NOT_FOUND'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">X_CONTENT_TYPE:</span>
                            <span class="info-value">${headers.x_content_type_options || 'NOT_FOUND'}</span>
                        </div>
                    </div>
                    
                    <div class="result-card">
                        <div class="card-title">
                            <span class="card-icon">🔏</span>
                            FINGERPRINTS
                        </div>
                        <div class="info-item">
                            <span class="info-label">SHA1:</span>
                            <span class="info-value">${cert.fingerprints?.sha1 || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">SHA256:</span>
                            <span class="info-value">${cert.fingerprints?.sha256 || 'Unknown'}</span>
                        </div>
                        ${cert.san && cert.san.length > 0 ? `
                        <div class="info-item">
                            <span class="info-label">ALT_NAMES:</span>
                            <span class="info-value">${cert.san.join(', ')}</span>
                        </div>
                        ` : ''}
                    </div>
                    
                    <div class="result-card">
                        <div class="card-title">
                            <span class="card-icon">🌐</span>
                            DNS RECORDS
                        </div>
                        ${Object.keys(dnsRecords).length > 0 && !dnsRecords.error ? 
                            formatDNSRecords(dnsRecords) : 
                            '<div class="info-value">Unable to retrieve DNS records</div>'
                        }
                    </div>
                    
                    <div class="result-card">
                        <div class="card-title">
                            <span class="card-icon">🌍</span>
                            IP & LOCATION INFORMATION
                        </div>
                        <div class="info-item">
                            <span class="info-label">IP_ADDRESS:</span>
                            <span class="info-value">${ipInfo.ipv4 || 'Unavailable'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">COUNTRY:</span>
                            <span class="info-value">${ipInfo.country || 'Unknown'}</span>
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
                            <span class="info-label">ORGANIZATION:</span>
                            <span class="info-value">${ipInfo.organization || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">TIMEZONE:</span>
                            <span class="info-value">${ipInfo.timezone || 'Unknown'}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">CDN_SERVICE:</span>
                            <span class="info-value">${ipInfo.is_cdn || 'None Detected'}</span>
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
                    <ul style="padding-left: 20px; color: #00aa00;">
                        ${data.recommendations.map(rec => `<li style="margin-bottom: 12px; line-height: 1.5;">> ${rec}</li>`).join('')}
                    </ul>
                </div>
                ` : ''}
                
                ${data.certificate_chain && data.certificate_chain.length > 0 ? `
                <div class="result-card" style="margin-top: 30px;">
                    <div class="card-title">
                        <span class="card-icon">🔗</span>
                        CERTIFICATE CHAIN
                    </div>
                    ${data.certificate_chain.map((chainCert, index) => `
                        <div class="chain-item">
                            <div class="chain-level">[LEVEL_${index + 1}]</div>
                            <div class="chain-details">
                                SUBJECT: ${chainCert.subject}<br>
                                ISSUER: ${chainCert.issuer}<br>
                                VALIDITY: ${chainCert.valid_from} → ${chainCert.valid_to}<br>
                                KEY: ${chainCert.public_key?.type_detail || 'Unknown'} ${chainCert.public_key?.size || 'Unknown'} bits (${chainCert.public_key?.security_level || 'Unknown'})
                            </div>
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