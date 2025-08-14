<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// API Handler - Check if this is an API request
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET');
    header('Access-Control-Allow-Headers: Content-Type');
    
    $apiType = $_GET['api'];
    
    try {
        switch ($apiType) {
            case 'yahoo':
                handleYahooAPI();
                break;
            case 'finnhub':
                handleFinnhubAPI();
                break;
            case 'coingecko':
                handleCoinGeckoAPI();
                break;
            default:
                throw new Exception('Invalid API type: ' . $apiType);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => $e->getMessage(),
            'api' => $apiType,
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
            'debug' => true
        ]);
    }
    exit;
}

// Yahoo Finance API Handler
function handleYahooAPI() {
    $symbol = $_GET['symbol'] ?? '';
    if (empty($symbol)) {
        throw new Exception('Symbol parameter is required');
    }
    
    $yahooUrl = 'https://query1.finance.yahoo.com/v8/finance/chart/' . urlencode($symbol) . '?interval=1d&range=1y&includePrePost=false';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $yahooUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    
    if (!empty($curlError)) {
        curl_close($ch);
        throw new Exception('cURL error for Yahoo Finance: ' . $curlError);
    }
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception('Yahoo Finance HTTP error: ' . $httpCode . ' for symbol: ' . $symbol);
    }
    
    $data = json_decode($response, true);
    if (!$data || !isset($data['chart']['result'][0])) {
        throw new Exception('Invalid response format from Yahoo Finance for symbol: ' . $symbol);
    }
    
    $result = $data['chart']['result'][0];
    $meta = $result['meta'];
    
    $currentPrice = $meta['regularMarketPrice'] ?? $meta['previousClose'] ?? null;
    $previousClose = $meta['previousClose'] ?? null;
    $change = 0;
    $changePercent = 0;
    
    // Get more accurate current price from quotes if available
    if (isset($result['indicators']['quote'][0]['close'])) {
        $closes = array_filter($result['indicators']['quote'][0]['close'], function($c) {
            return $c !== null && is_numeric($c);
        });
        
        if (count($closes) >= 2) {
            $closesArray = array_values($closes);
            $currentPrice = end($closesArray);
            $previousClose = $closesArray[count($closesArray) - 2];
        }
    }
    
    if ($currentPrice && $previousClose && is_numeric($currentPrice) && is_numeric($previousClose)) {
        $change = $currentPrice - $previousClose;
        $changePercent = ($change / $previousClose) * 100;
    }
    
    // Calculate 52-week high/low from historical data
    $fiftyTwoWeekHigh = null;
    $fiftyTwoWeekLow = null;
    
    if (isset($result['indicators']['quote'][0])) {
        $quotes = $result['indicators']['quote'][0];
        
        if (isset($quotes['high'])) {
            $highs = array_filter($quotes['high'], function($h) { return is_numeric($h); });
            if (!empty($highs)) $fiftyTwoWeekHigh = max($highs);
        }
        
        if (isset($quotes['low'])) {
            $lows = array_filter($quotes['low'], function($l) { return is_numeric($l); });
            if (!empty($lows)) $fiftyTwoWeekLow = min($lows);
        }
    }
    
    // Fallback to meta data if available
    $fiftyTwoWeekHigh = $fiftyTwoWeekHigh ?? ($meta['fiftyTwoWeekHigh'] ?? null);
    $fiftyTwoWeekLow = $fiftyTwoWeekLow ?? ($meta['fiftyTwoWeekLow'] ?? null);
    
    echo json_encode([
        'price' => $currentPrice,
        'change' => $change,
        'changePercent' => $changePercent,
        'volume' => $meta['regularMarketVolume'] ?? null,
        'fiftyTwoWeekHigh' => $fiftyTwoWeekHigh,
        'fiftyTwoWeekLow' => $fiftyTwoWeekLow,
        'symbol' => $meta['symbol'] ?? $symbol,
        'timestamp' => time(),
        'source' => 'yahoo'
    ]);
}

function handleFinnhubAPI() {
    $symbol = $_GET['symbol'] ?? '';
    if (empty($symbol)) {
        throw new Exception('Symbol parameter is required');
    }
    
    // Working Finnhub API key
    $apiKey = 'd1i4mfhr01qhsrhdaub0d1i4mfhr01qhsrhdaubg';
    $finnhubUrl = 'https://finnhub.io/api/v1/stock/profile2?symbol=' . urlencode($symbol) . '&token=' . $apiKey;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $finnhubUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    
    if (!empty($curlError)) {
        curl_close($ch);
        throw new Exception('cURL error for Finnhub: ' . $curlError);
    }
    curl_close($ch);
    
    if ($httpCode === 429) {
        throw new Exception('Finnhub API rate limit exceeded');
    }
    
    if ($httpCode !== 200) {
        throw new Exception('Finnhub HTTP error: ' . $httpCode);
    }
    
    $data = json_decode($response, true);
    if (!$data) {
        throw new Exception('Invalid response format from Finnhub');
    }
    
    $marketCap = null;
    $sharesOutstanding = null;
    
    if (isset($data['marketCapitalization']) && is_numeric($data['marketCapitalization'])) {
        $marketCap = $data['marketCapitalization'] * 1000000; // Finnhub returns in millions
    }
    
    if (isset($data['shareOutstanding']) && is_numeric($data['shareOutstanding'])) {
        $sharesOutstanding = $data['shareOutstanding'] * 1000000; // Finnhub returns in millions
    }
    
    echo json_encode([
        'marketCap' => $marketCap,
        'sharesOutstanding' => $sharesOutstanding,
        'symbol' => $symbol,
        'timestamp' => time(),
        'source' => 'finnhub'
    ]);
}

function handleCoinGeckoAPI() {
    $coinId = $_GET['id'] ?? '';
    if (empty($coinId)) {
        throw new Exception('ID parameter is required');
    }
    
    $coingeckoUrl = 'https://api.coingecko.com/api/v3/coins/' . urlencode($coinId) . '?localization=false&tickers=false&market_data=true&community_data=false&developer_data=false&sparkline=false';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $coingeckoUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'User-Agent: Market-Tracker-PHP/2.08'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    
    if (!empty($curlError)) {
        curl_close($ch);
        throw new Exception('cURL error for CoinGecko: ' . $curlError);
    }
    curl_close($ch);
    
    if ($httpCode === 429) {
        throw new Exception('CoinGecko API rate limit exceeded');
    }
    
    if ($httpCode !== 200) {
        throw new Exception('CoinGecko HTTP error: ' . $httpCode);
    }
    
    $data = json_decode($response, true);
    if (!$data) {
        throw new Exception('Invalid response format from CoinGecko');
    }
    
    $marketCap = $data['market_data']['market_cap']['usd'] ?? null;
    $currentPrice = $data['market_data']['current_price']['usd'] ?? null;
    $circulatingSupply = $data['market_data']['circulating_supply'] ?? null;
    
    // Calculate market cap if not directly available
    if (!$marketCap && $currentPrice && $circulatingSupply) {
        $marketCap = $currentPrice * $circulatingSupply;
    }
    
    echo json_encode([
        'marketCap' => $marketCap,
        'currentPrice' => $currentPrice,
        'circulatingSupply' => $circulatingSupply,
        'coinId' => $coinId,
        'timestamp' => time(),
        'source' => 'coingecko'
    ]);
}

// If not an API request, serve the HTML interface
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Market Tracker v2.08</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #0f0f23 0%, #1a1a2e 50%, #16213e 100%);
            min-height: 100vh;
            color: white;
            overflow-x: auto;
        }

        .container {
            max-width: 1500px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            text-align: center;
            margin-bottom: 40px;
            padding: 40px 0;
        }

        .header h1 {
            font-size: 3.5rem;
            font-weight: 700;
            background: linear-gradient(45deg, #fff, #f0f0f0);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 10px;
            text-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }

        .header p {
            font-size: 1.2rem;
            opacity: 0.9;
            font-weight: 300;
        }

        .status-indicator {
            position: fixed;
            top: 20px;
            left: 20px;
            padding: 8px 16px;
            border-radius: 25px;
            font-size: 0.9rem;
            font-weight: 600;
            z-index: 100;
            transition: all 0.3s ease;
            max-width: 300px;
        }

        .status-connected {
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .status-loading {
            background: rgba(245, 158, 11, 0.2);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        .status-error {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .refresh-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 50px;
            padding: 12px 24px;
            color: white;
            cursor: pointer;
            backdrop-filter: blur(15px);
            transition: all 0.3s ease;
            font-weight: 600;
            z-index: 100;
        }

        .refresh-btn:hover {
            background: rgba(255,255,255,0.2);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.4);
        }

        .refresh-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .debug-info {
            background: rgba(99, 102, 241, 0.1);
            border: 1px solid rgba(99, 102, 241, 0.3);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }

        .debug-console {
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            font-family: 'SF Mono', 'Monaco', 'Inconsolata', monospace;
            font-size: 0.8rem;
            max-height: 200px;
            overflow-y: auto;
            display: none;
        }

        .error-info {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            display: none;
        }

        .table-container {
            background: rgba(255,255,255,0.05);
            border-radius: 20px;
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.1);
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0,0,0,0.4);
        }

        .market-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 1rem;
        }

        .market-table thead {
            background: rgba(255,255,255,0.1);
        }

        .market-table th {
            padding: 20px 12px;
            text-align: left;
            font-weight: 700;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid rgba(255,255,255,0.15);
            white-space: nowrap;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }

        .market-table th:hover {
            background: rgba(255,255,255,0.05);
        }

        .market-table th.sortable::after {
            content: ' ↕';
            opacity: 0.5;
        }

        .market-table th.sort-asc::after {
            content: ' ↑';
            opacity: 1;
        }

        .market-table th.sort-desc::after {
            content: ' ↓';
            opacity: 1;
        }

        .market-table th:nth-child(4),
        .market-table th:nth-child(7) {
            text-align: right;
        }

        .market-table th:nth-child(5),
        .market-table th:nth-child(6),
        .market-table th:nth-child(8) {
            text-align: center;
        }

        .market-table tbody tr {
            border-bottom: 1px solid rgba(255,255,255,0.08);
            transition: all 0.3s ease;
        }

        .market-table tbody tr:hover {
            background: rgba(255,255,255,0.08);
            transform: translateX(5px);
        }

        .market-table tbody tr:last-child {
            border-bottom: none;
        }

        .market-table td {
            padding: 18px 12px;
            vertical-align: middle;
        }

        .flag-cell {
            text-align: center;
            width: 50px;
        }

        .flag {
            font-size: 1.6rem;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.3));
        }

        .name-cell {
            width: 200px;
        }

        .index-name {
            font-weight: 700;
            font-size: 1rem;
            margin-bottom: 2px;
        }

        .index-desc {
            font-size: 0.8rem;
            opacity: 0.8;
        }

        .country-cell {
            width: 130px;
            font-size: 0.85rem;
        }

        .price-cell {
            width: 110px;
            text-align: right;
            font-family: 'SF Mono', 'Monaco', 'Inconsolata', 'Roboto Mono', monospace;
            font-weight: 700;
            font-size: 1rem;
        }

        .change-cell {
            width: 100px;
            text-align: center;
        }

        .change-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 10px;
            font-size: 0.8rem;
            font-weight: 600;
            min-width: 60px;
            text-align: center;
        }

        .positive {
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .negative {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .neutral {
            background: rgba(156, 163, 175, 0.2);
            color: #9ca3af;
            border: 1px solid rgba(156, 163, 175, 0.3);
        }

        .marketcap-cell {
            width: 100px;
            text-align: right;
            font-family: 'SF Mono', 'Monaco', 'Inconsolata', 'Roboto Mono', monospace;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .range52w-cell {
            width: 100px;
            text-align: center;
            font-size: 0.8rem;
            line-height: 1.2;
        }

        .range52w-low {
            color: #ef4444;
            font-weight: 600;
        }

        .range52w-high {
            color: #10b981;
            font-weight: 600;
        }

        .ath-cell {
            width: 90px;
            text-align: center;
        }

        .ath-badge {
            display: inline-block;
            padding: 5px 8px;
            border-radius: 10px;
            font-size: 0.8rem;
            font-weight: 600;
            min-width: 55px;
            text-align: center;
        }

        .ath-green {
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .ath-yellow {
            background: rgba(245, 158, 11, 0.2);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        .ath-red {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .loading-cell {
            color: #f59e0b;
            font-style: italic;
            opacity: 0.7;
        }

        .error-cell {
            color: #ef4444;
            font-style: italic;
            opacity: 0.7;
        }

        .spinner {
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top: 2px solid #f59e0b;
            width: 16px;
            height: 16px;
            animation: spin 1s linear infinite;
            display: inline-block;
            margin-right: 8px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .last-updated {
            text-align: center;
            margin-top: 20px;
            opacity: 0.7;
            font-size: 0.9rem;
        }

        .source-badge {
            display: inline-block;
            background: rgba(138, 43, 226, 0.2);
            color: #8a2be2;
            font-size: 0.65rem;
            padding: 2px 5px;
            border-radius: 4px;
            margin-left: 6px;
            font-weight: 600;
        }

        .finnhub-badge {
            background: rgba(255, 193, 7, 0.2);
            color: #ffc107;
        }

        .coingecko-badge {
            background: rgba(34, 197, 94, 0.2);
            color: #22c55e;
        }

        .supply-badge {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .fade-in {
            animation: fadeInUp 0.6s ease forwards;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .debug-toggle {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 50px;
            padding: 8px 16px;
            color: white;
            cursor: pointer;
            backdrop-filter: blur(15px);
            transition: all 0.3s ease;
            font-size: 0.8rem;
            z-index: 100;
        }

        .debug-toggle:hover {
            background: rgba(255,255,255,0.2);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Market Tracker v2.08</h1>
            <p>Yahoo Finance API (prices, 52W ranges) • Finnhub API (stock market caps) • CoinGecko API (crypto market caps) • Research-based calculations for indices and precious metals</p>
        </div>

        <div class="debug-info">
            <strong>📊 Data Sources and Calculations:</strong><br>
            <strong>Yahoo Finance API:</strong> All prices, day changes, 52-week ranges for stocks, indices, futures, and crypto<br>
            <strong>Finnhub API(FH badge):</strong> Stock market capitalizations (real-time company valuations)<br>
            <strong>CoinGecko API(CG badge):</strong> Cryptocurrency market capitalizations (circulating supply times price)<br>
            <strong>Calculated Values (-- badge):</strong><br>
            • <strong>Precious Metals:</strong> Spot price times estimated global above-ground supply (Gold: 6.71B oz, Silver: 55.94B oz)<br>
            • <strong>Stock Indices:</strong> Index value times constituent multiplier based on research:<br>
            - Dow Jones: times 463M <br>
            - S and P 500: times 8.4B <br>
            - NASDAQ-100: times 1.28B <br>
        </div>

        <div class="error-info" id="errorInfo">
            <strong>❌ Error Details:</strong>
            <div id="errorDetails"></div>
        </div>

        <div class="debug-console" id="debugConsole">
            <strong>🐛 Debug Console:</strong>
            <div id="debugLog"></div>
        </div>

        <div class="status-indicator" id="statusIndicator">
            🔄 Initializing...
        </div>

        <button class="refresh-btn" onclick="refreshAllData()" id="refreshBtn">
            Refresh Data
        </button>

        <button class="debug-toggle" onclick="toggleDebug()" id="debugToggle">
            Show Debug
        </button>

        <div class="table-container">
            <table class="market-table">
                <thead>
                    <tr>
                        <th></th>
                        <th>Name</th>
                        <th>Country</th>
                        <th>Price</th>
                        <th>Day Change</th>
                        <th>Day Change %</th>
                        <th>Market Cap / Supply Value</th>
                        <th>52W Range</th>
                        <th>% 52W High</th>
                    </tr>
                </thead>
                <tbody id="table-body">
                    <!-- Rows will be populated immediately, then filled progressively -->
                </tbody>
            </table>
            <div class="last-updated" id="lastUpdated">Loading...</div>
        </div>
    </div>

    <script>
        // Market definitions
        const marketDefinitions = [
            // Indices
            { key: 'dji', symbol: '^DJI', flag: '🇺🇸', name: 'Dow Jones', desc: 'Dow Jones Industrial Average', country: 'United States', type: 'index', indexType: 'dow' },
            { key: 'gspc', symbol: '^GSPC', flag: '🇺🇸', name: 'S&P 500', desc: 'S&P 500 Index', country: 'United States', type: 'index', indexType: 'sp500' },
            
            // Futures
            { key: 'nasdaq', symbol: 'MNQ=F', flag: '🇺🇸', name: 'NASDAQ-100', desc: 'Micro E-mini NASDAQ-100 Futures', country: 'United States', type: 'futures', indexType: 'nasdaq100' },
            { key: 'gold', symbol: 'GC=F', flag: '🥇', name: 'Gold', desc: 'Gold Futures (CME)', country: 'Global', type: 'futures', metalId: 'gold' },
            { key: 'silver', symbol: 'SI=F', flag: '🥈', name: 'Silver', desc: 'Silver Futures (CME)', country: 'Global', type: 'futures', metalId: 'silver' },
            
            // Stocks
            { key: 'nvda', symbol: 'NVDA', flag: '🇺🇸', name: 'NVIDIA', desc: 'NVIDIA Corporation', country: 'United States', type: 'stock' },
            { key: 'msft', symbol: 'MSFT', flag: '🇺🇸', name: 'Microsoft', desc: 'Microsoft Corporation', country: 'United States', type: 'stock' },
            { key: 'aapl', symbol: 'AAPL', flag: '🇺🇸', name: 'Apple', desc: 'Apple Inc.', country: 'United States', type: 'stock' },
            { key: 'googl', symbol: 'GOOGL', flag: '🇺🇸', name: 'Alphabet', desc: 'Alphabet Inc. (Google)', country: 'United States', type: 'stock' },
            { key: 'amd', symbol: 'AMD', flag: '🇺🇸', name: 'AMD', desc: 'Advanced Micro Devices', country: 'United States', type: 'stock' },
            { key: 'meta', symbol: 'META', flag: '🇺🇸', name: 'Meta', desc: 'Meta Platforms (Facebook)', country: 'United States', type: 'stock' },
            { key: 'amzn', symbol: 'AMZN', flag: '🇺🇸', name: 'Amazon', desc: 'Amazon.com Inc.', country: 'United States', type: 'stock' },
            
            // Crypto
            { key: 'bitcoin', symbol: 'BTC-USD', flag: '🟠', name: 'Bitcoin', desc: 'Bitcoin USD', country: 'Global', type: 'crypto', coinGeckoId: 'bitcoin' },
            { key: 'ethereum', symbol: 'ETH-USD', flag: '🔷', name: 'Ethereum', desc: 'Ethereum USD', country: 'Global', type: 'crypto', coinGeckoId: 'ethereum' }
        ];

        let marketData = {};
        let loadingCount = 0;
        let totalCount = marketDefinitions.length;
        let debugMode = false;
        let errorCount = 0;
        let sortColumn = null;
        let sortDirection = 'asc';

        // Global supply estimates (troy ounces) - updated estimates from geological surveys
        const globalSupplyEstimates = {
            'gold': 6710000000,    // ~6.71 billion troy ounces above-ground supply
            'silver': 55940000000  // ~55.94 billion troy ounces above-ground supply
        };

        // Index market cap calculation multipliers (based on current total constituent market caps)
        const indexCalculationMultipliers = {
            'dow': 463000000,      // Dow Jones: $20.589T ÷ 44,371 = ~463M multiplier
            'sp500': 8400000000,   // S&P 500: $52.5T ÷ 6,259 = ~8.4B multiplier
            'nasdaq100': 1280000000 // NASDAQ-100: $29.32T ÷ 22,971 = ~1.28B multiplier
        };

        // Debug logging
        function debugLog(message, type = 'info') {
            const timestamp = new Date().toLocaleTimeString();
            const logElement = document.getElementById('debugLog');
            const colorMap = {
                'info': '#3b82f6',
                'success': '#10b981', 
                'error': '#ef4444',
                'warning': '#f59e0b'
            };
            
            if (logElement) {
                logElement.innerHTML += `<div style="color: ${colorMap[type] || '#ffffff'};">[${timestamp}] ${message}</div>`;
                logElement.scrollTop = logElement.scrollHeight;
            }
            
            console.log(`[${timestamp}] ${message}`);
        }

        function showError(error, context = '') {
            errorCount++;
            const errorInfo = document.getElementById('errorInfo');
            const errorDetails = document.getElementById('errorDetails');
            
            if (errorInfo && errorDetails) {
                errorInfo.style.display = 'block';
                errorDetails.innerHTML += `<div><strong>${context}:</strong> ${error}</div>`;
            }
            
            debugLog(`ERROR ${context}: ${error}`, 'error');
            
            if (errorCount >= 3) {
                updateStatus('error', '❌ Multiple errors detected - check debug console');
            }
        }

        function toggleDebug() {
            debugMode = !debugMode;
            const debugConsole = document.getElementById('debugConsole');
            const debugToggle = document.getElementById('debugToggle');
            
            if (debugMode) {
                debugConsole.style.display = 'block';
                debugToggle.textContent = 'Hide Debug';
                debugLog('Debug mode enabled', 'info');
            } else {
                debugConsole.style.display = 'none';
                debugToggle.textContent = 'Show Debug';
            }
        }

        // Utility functions
        function formatNumber(num) {
            if (num === null || num === undefined || isNaN(num)) return 'N/A';
            return parseFloat(num).toLocaleString('en-US', { 
                minimumFractionDigits: 2, 
                maximumFractionDigits: 2 
            });
        }

        function formatPrice(num, type) {
            if (num === null || num === undefined || isNaN(num)) return 'N/A';
            const formatted = parseFloat(num).toLocaleString('en-US', { 
                minimumFractionDigits: 2, 
                maximumFractionDigits: 2 
            });
            
            if (type === 'crypto' || type === 'futures') {
                return '$' + formatted;
            } else if (type === 'index') {
                return formatted;
            }
            return '$' + formatted;
        }

        function formatMarketCap(num) {
            if (num === null || num === undefined || num === 0 || isNaN(num)) {
                return 'N/A';
            }
            
            // Always display in trillions with 2 decimal places
            return (num / 1e12).toFixed(2) + 'T';
        }

        function formatChange(change) {
            if (change === null || change === undefined || isNaN(change)) return 'N/A';
            const sign = change >= 0 ? '+' : '';
            return sign + parseFloat(change).toFixed(2);
        }

        function formatChangePercent(change) {
            if (change === null || change === undefined || isNaN(change)) return 'N/A';
            const sign = change >= 0 ? '+' : '';
            return sign + parseFloat(change).toFixed(2) + '%';
        }

        function calculate52WeekStatus(currentPrice, high52w) {
            if (!currentPrice || !high52w || isNaN(currentPrice) || isNaN(high52w)) return null;
            const distance = ((currentPrice - high52w) / high52w) * 100;
            return distance;
        }

        function get52WeekClass(distance) {
            if (distance === null || distance === undefined || isNaN(distance)) return 'neutral';
            if (distance >= -5) return 'ath-green';
            if (distance >= -25) return 'ath-yellow';
            return 'ath-red';
        }

        function getChangeClass(change) {
            if (change === null || change === undefined || isNaN(change) || change === 0) return 'neutral';
            return change >= 0 ? 'positive' : 'negative';
        }

        function updateStatus(status, message) {
            const indicator = document.getElementById('statusIndicator');
            indicator.className = 'status-indicator status-' + status;
            indicator.textContent = message;
            debugLog(`Status: ${message}`, status === 'error' ? 'error' : 'info');
        }

        function createInitialTableRow(market) {
            const row = document.createElement('tr');
            row.className = 'fade-in';
            row.setAttribute('data-market', market.key);
            row.id = 'row-' + market.key;
            
            row.innerHTML = `
                <td class="flag-cell"><div class="flag">${market.flag}</div></td>
                <td class="name-cell">
                    <div class="index-name">${market.name}</div>
                    <div class="index-desc">${market.desc}</div>
                </td>
                <td class="country-cell">${market.country}</td>
                <td class="price-cell loading-cell"><div class="spinner"></div>Loading...</td>
                <td class="change-cell loading-cell"><div class="spinner"></div>Loading...</td>
                <td class="change-cell loading-cell"><div class="spinner"></div>Loading...</td>
                <td class="marketcap-cell loading-cell"><div class="spinner"></div>Loading...</td>
                <td class="range52w-cell loading-cell"><div class="spinner"></div>Loading...</td>
                <td class="ath-cell loading-cell"><div class="spinner"></div>Loading...</td>
            `;
            
            return row;
        }

        function updateTableRow(market, data) {
            const row = document.getElementById('row-' + market.key);
            if (!row) return;

            const price = formatPrice(data.price, market.type);
            const change = formatChange(data.change);
            const changePercent = formatChangePercent(data.changePercent);
            
            let marketCapDisplay = formatMarketCap(data.marketCap);
            let marketCapBadge = '';
            if (data.marketCap) {
                if (market.type === 'stock') {
                    marketCapBadge = '<span class="source-badge finnhub-badge">FH</span>';
                } else if (market.type === 'crypto') {
                    marketCapBadge = '<span class="source-badge coingecko-badge">CG</span>';
                } else if ((market.type === 'futures' && market.metalId) || (market.type === 'index' && market.indexType) || (market.type === 'futures' && market.indexType)) {
                    marketCapBadge = '<span class="source-badge supply-badge">--</span>';
                    marketCapDisplay = '~' + marketCapDisplay; // Add ~ to indicate calculated value
                }
            }
            
            const high52wDistance = calculate52WeekStatus(data.price, data.fiftyTwoWeekHigh);
            const high52wText = high52wDistance !== null ? 
                (high52wDistance >= 0 ? '+' : '') + high52wDistance.toFixed(1) + '%' : 'N/A';
            const high52wClass = get52WeekClass(high52wDistance);
            
            let range52w = 'N/A';
            if (data.fiftyTwoWeekLow && data.fiftyTwoWeekHigh) {
                const lowFormatted = data.fiftyTwoWeekLow.toFixed(2);
                const highFormatted = data.fiftyTwoWeekHigh.toFixed(2);
                range52w = `<div class="range52w-low">${lowFormatted}</div><div class="range52w-high">${highFormatted}</div>`;
            }
            
            row.children[3].innerHTML = price;
            row.children[3].className = 'price-cell';
            row.children[4].innerHTML = `<span class="change-badge ${getChangeClass(data.change)}">${change}</span>`;
            row.children[4].className = 'change-cell';
            row.children[5].innerHTML = `<span class="change-badge ${getChangeClass(data.changePercent)}">${changePercent}</span>`;
            row.children[5].className = 'change-cell';
            row.children[6].innerHTML = marketCapDisplay + marketCapBadge;
            row.children[6].className = 'marketcap-cell';
            row.children[7].innerHTML = range52w;
            row.children[7].className = 'range52w-cell';
            row.children[8].innerHTML = `<span class="ath-badge ${high52wClass}">${high52wText}</span>`;
            row.children[8].className = 'ath-cell';
            
            debugLog(`✅ Updated row for ${market.symbol}`, 'success');
        }

        function setRowError(market, error) {
            const row = document.getElementById('row-' + market.key);
            if (!row) return;

            for (let i = 3; i <= 8; i++) {
                row.children[i].innerHTML = 'Error';
                row.children[i].className = row.children[i].className.replace('loading-cell', 'error-cell');
            }
            
            debugLog(`❌ Error for ${market.symbol}: ${error}`, 'error');
        }

        async function fetchMarketData(market) {
            try {
                debugLog(`🔄 Fetching ${market.symbol}...`, 'info');
                
                const priceUrl = `?api=yahoo&symbol=${encodeURIComponent(market.symbol)}`;
                debugLog(`Requesting: ${priceUrl}`, 'info');
                
                const priceResponse = await fetch(priceUrl);
                debugLog(`Response status: ${priceResponse.status}`, 'info');
                
                if (!priceResponse.ok) {
                    const errorText = await priceResponse.text();
                    throw new Error(`Price fetch failed: ${priceResponse.status} - ${errorText.substring(0, 100)}`);
                }
                
                const priceData = await priceResponse.json();
                
                if (priceData.error) {
                    throw new Error(`API Error: ${priceData.error}`);
                }
                
                debugLog(`📊 Price data for ${market.symbol}: $${priceData.price}`, 'success');
                
                let data = {
                    price: priceData.price,
                    change: priceData.change,
                    changePercent: priceData.changePercent,
                    fiftyTwoWeekHigh: priceData.fiftyTwoWeekHigh,
                    fiftyTwoWeekLow: priceData.fiftyTwoWeekLow,
                    marketCap: null
                };

                // Calculate market cap / supply value based on type
                if (market.type === 'stock') {
                    try {
                        const capUrl = `?api=finnhub&symbol=${encodeURIComponent(market.symbol)}`;
                        const capResponse = await fetch(capUrl);
                        if (capResponse.ok) {
                            const capData = await capResponse.json();
                            if (!capData.error) {
                                data.marketCap = capData.marketCap;
                                debugLog(`💰 Finnhub market cap for ${market.symbol}: ${formatMarketCap(data.marketCap)}`, 'success');
                            }
                        }
                    } catch (capError) {
                        debugLog(`⚠️ Could not fetch Finnhub market cap for ${market.symbol}: ${capError.message}`, 'warning');
                    }
                } else if (market.type === 'crypto' && market.coinGeckoId) {
                    try {
                        const capUrl = `?api=coingecko&id=${encodeURIComponent(market.coinGeckoId)}`;
                        const capResponse = await fetch(capUrl);
                        if (capResponse.ok) {
                            const capData = await capResponse.json();
                            if (!capData.error) {
                                data.marketCap = capData.marketCap;
                                debugLog(`💰 CoinGecko market cap for ${market.symbol}: ${formatMarketCap(data.marketCap)}`, 'success');
                            }
                        }
                    } catch (capError) {
                        debugLog(`⚠️ Could not fetch CoinGecko market cap for ${market.symbol}: ${capError.message}`, 'warning');
                    }
                } else if (market.type === 'futures' && market.metalId && data.price) {
                    // Calculate supply value using Yahoo Finance price × global supply estimates
                    if (globalSupplyEstimates[market.metalId]) {
                        data.marketCap = data.price * globalSupplyEstimates[market.metalId];
                        debugLog(`🥇 Calculated supply value for ${market.symbol}: ${formatMarketCap(data.marketCap)} (${data.price} × ${globalSupplyEstimates[market.metalId].toLocaleString()} oz)`, 'success');
                    }
                } else if ((market.type === 'index' && market.indexType) || (market.type === 'futures' && market.indexType)) {
                    // Calculate approximate market cap for indices using index value × multiplier
                    if (indexCalculationMultipliers[market.indexType] && data.price) {
                        data.marketCap = data.price * indexCalculationMultipliers[market.indexType];
                        debugLog(`📊 Calculated market cap for ${market.symbol}: ${formatMarketCap(data.marketCap)} (${data.price} × ${indexCalculationMultipliers[market.indexType].toLocaleString()})`, 'success');
                    }
                }

                marketData[market.key] = data;
                updateTableRow(market, data);

            } catch (error) {
                showError(error.message, market.symbol);
                setRowError(market, error);
            } finally {
                // Always increment loading count and check completion
                loadingCount++;
                updateStatus('loading', `🔄 Loaded ${loadingCount}/${totalCount} markets...`);
                
                if (loadingCount === totalCount) {
                    const successCount = totalCount - errorCount;
                    if (errorCount === 0) {
                        updateStatus('connected', '✅ All Data Loaded Successfully');
                    } else {
                        updateStatus('connected', `✅ Loaded ${successCount}/${totalCount} markets (${errorCount} errors)`);
                    }
                    document.getElementById('lastUpdated').textContent = 'Last updated: ' + new Date().toLocaleString();
                    document.getElementById('refreshBtn').disabled = false;
                    document.getElementById('refreshBtn').textContent = 'Refresh Data';
                }
            }
        }

        function initializeTable() {
            const tableBody = document.getElementById('table-body');
            tableBody.innerHTML = '';
            
            marketDefinitions.forEach(market => {
                const row = createInitialTableRow(market);
                tableBody.appendChild(row);
            });
            
            // Add sorting functionality to table headers
            const headers = document.querySelectorAll('.market-table th');
            headers.forEach((header, index) => {
                if (index > 0) { // Skip the flag column
                    header.classList.add('sortable');
                    header.addEventListener('click', () => sortTable(index));
                }
            });
            
            debugLog('📋 Table initialized with loading placeholders', 'info');
        }

        function sortTable(columnIndex) {
            const table = document.querySelector('.market-table tbody');
            const rows = Array.from(table.rows);
            
            // Determine sort direction
            if (sortColumn === columnIndex) {
                sortDirection = sortDirection === 'desc' ? 'asc' : 'desc';
            } else {
                sortDirection = 'desc';  // First click should be descending
                sortColumn = columnIndex;
            }
            
            // Update header indicators
            const headers = document.querySelectorAll('.market-table th');
            headers.forEach((header, index) => {
                header.classList.remove('sort-asc', 'sort-desc');
                if (index === columnIndex) {
                    header.classList.add(sortDirection === 'asc' ? 'sort-asc' : 'sort-desc');
                }
            });
            
            // Sort rows
            rows.sort((a, b) => {
                let aValue = a.cells[columnIndex].textContent.trim();
                let bValue = b.cells[columnIndex].textContent.trim();
                
                // Handle different data types
                if (columnIndex === 3 || columnIndex === 6) { // Price or Market Cap columns
                    aValue = parseFloat(aValue.replace(/[^0-9.-]/g, '')) || 0;
                    bValue = parseFloat(bValue.replace(/[^0-9.-]/g, '')) || 0;
                } else if (columnIndex === 4 || columnIndex === 5 || columnIndex === 8) { // Change columns
                    aValue = parseFloat(aValue.replace(/[^0-9.-]/g, '')) || 0;
                    bValue = parseFloat(bValue.replace(/[^0-9.-]/g, '')) || 0;
                } else if (columnIndex === 7) { // 52W Range - use high value
                    const aHigh = aValue.match(/(\d+\.?\d*)/g);
                    const bHigh = bValue.match(/(\d+\.?\d*)/g);
                    aValue = aHigh ? parseFloat(aHigh[aHigh.length - 1]) : 0;
                    bValue = bHigh ? parseFloat(bHigh[bHigh.length - 1]) : 0;
                }
                
                if (typeof aValue === 'string') {
                    return sortDirection === 'asc' 
                        ? aValue.localeCompare(bValue)
                        : bValue.localeCompare(aValue);
                } else {
                    return sortDirection === 'asc' 
                        ? aValue - bValue
                        : bValue - aValue;
                }
            });
            
            // Re-append sorted rows
            rows.forEach(row => table.appendChild(row));
            
            debugLog(`📊 Table sorted by column ${columnIndex} (${sortDirection})`, 'info');
        }

        async function loadAllData() {
            debugLog('🚀 Starting progressive data load...', 'info');
            
            // Reset counters
            marketData = {};
            loadingCount = 0;
            errorCount = 0;
            
            // Clear previous errors
            const errorInfo = document.getElementById('errorInfo');
            const errorDetails = document.getElementById('errorDetails');
            if (errorInfo && errorDetails) {
                errorInfo.style.display = 'none';
                errorDetails.innerHTML = '';
            }
            
            document.getElementById('refreshBtn').disabled = true;
            document.getElementById('refreshBtn').textContent = 'Loading...';
            updateStatus('loading', '🔄 Loading markets...');
            
            initializeTable();
            
            // Fetch data with staggered timing to avoid rate limits
            for (let i = 0; i < marketDefinitions.length; i++) {
                const market = marketDefinitions[i];
                
                // Add delay between requests to avoid rate limiting
                if (i > 0) {
                    await new Promise(resolve => setTimeout(resolve, 300));
                }
                
                // Don't await this - let them run in parallel with delays
                fetchMarketData(market);
            }
        }

        function refreshAllData() {
            debugLog('🔄 Manual refresh triggered', 'info');
            loadAllData();
        }

        // Global error handler
        window.addEventListener('error', function(e) {
            showError(e.message, 'Global');
            return false;
        });

        window.addEventListener('unhandledrejection', function(e) {
            showError(e.reason, 'Promise');
            return false;
        });

        document.addEventListener('DOMContentLoaded', function() {
            debugLog('🎯 Market Tracker v2.08 loaded - Fixed sorting behavior!', 'info');
            updateStatus('loading', '🔄 Initializing...');
            
            setTimeout(() => {
                loadAllData();
            }, 500);
            
            // Auto-refresh every 5 minutes
            setInterval(() => {
                debugLog('⏰ Auto-refresh triggered', 'info');
                loadAllData();
            }, 300000);
        });

        // Debug functions for console
        window.debugMarketData = function() {
            console.log('Current market data:', marketData);
            console.log('Market definitions:', marketDefinitions);
            console.log('Global supply estimates:', globalSupplyEstimates);
            console.log('Index calculation multipliers:', indexCalculationMultipliers);
            console.log('Error count:', errorCount);
            console.log('Loading count:', loadingCount);
            console.log('Sort column:', sortColumn, 'Sort direction:', sortDirection);
        };

        window.testAPI = async function(api, param) {
            try {
                if (!['yahoo', 'finnhub', 'coingecko'].includes(api)) {
                    console.error('Available APIs: yahoo, finnhub, coingecko');
                    return;
                }
                const url = `?api=${api}&${api === 'yahoo' ? 'symbol' : 'id'}=${param}`;
                console.log('Testing:', url);
                const response = await fetch(url);
                const data = await response.json();
                console.log('Result:', data);
                return data;
            } catch (error) {
                console.error('Test failed:', error);
                return error;
            }
        };
    </script>
</body>
</html>