<?php
/*
 * Changelog:
 * - 2025-08-14 Initial version from filename version 102
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crypto Tracker v1.02</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1e3c72, #2a5298);
            color: #333;
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
        }

        .header p {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .controls {
            padding: 20px 30px;
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
        }

        .input-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .input-group label {
            font-weight: 600;
            color: #495057;
            font-size: 0.9rem;
        }

        .input-group input, .input-group select {
            padding: 10px 15px;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }

        .input-group input:focus, .input-group select:focus {
            outline: none;
            border-color: #667eea;
        }

        .btn {
            padding: 12px 25px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            align-self: end;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .loading {
            text-align: center;
            padding: 40px;
            font-size: 1.2rem;
            color: #667eea;
        }

        .error {
            text-align: center;
            padding: 40px;
            color: #dc3545;
            background: #f8d7da;
            margin: 20px;
            border-radius: 8px;
            border: 1px solid #f5c6cb;
        }

        .table-container {
            overflow-x: auto;
            margin: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        th {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 15px 12px;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            padding: 15px 12px;
            border-bottom: 1px solid #e9ecef;
            font-size: 0.95rem;
        }

        tr:hover {
            background: #f8f9fa;
        }

        .coin-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .coin-logo {
            width: 32px;
            height: 32px;
            border-radius: 50%;
        }

        .coin-name {
            font-weight: 600;
            color: #333;
        }

        .coin-symbol {
            color: #6c757d;
            font-size: 0.85rem;
            text-transform: uppercase;
        }

        .price {
            font-weight: 700;
            font-size: 1.1rem;
            color: #333;
        }

        .change-positive {
            color: #28a745;
            font-weight: 600;
        }

        .change-negative {
            color: #dc3545;
            font-weight: 600;
        }

        .ath-green {
            background: #d4edda;
            color: #155724;
            padding: 4px 8px;
            border-radius: 20px;
            font-weight: 600;
            border: 1px solid #c3e6cb;
        }

        .ath-yellow {
            background: #fff3cd;
            color: #856404;
            padding: 4px 8px;
            border-radius: 20px;
            font-weight: 600;
            border: 1px solid #ffeaa7;
        }

        .ath-red {
            background: #f8d7da;
            color: #721c24;
            padding: 4px 8px;
            border-radius: 20px;
            font-weight: 600;
            border: 1px solid #f5c6cb;
        }

        .market-cap, .volume {
            font-family: 'Courier New', monospace;
            color: #495057;
        }

        .rank {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 4px 8px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        @media (max-width: 768px) {
            .header h1 {
                font-size: 2rem;
            }

            .controls {
                flex-direction: column;
                align-items: stretch;
            }

            .btn {
                align-self: stretch;
            }

            th, td {
                padding: 10px 8px;
                font-size: 0.85rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Cryptocurrency Tracker</h1>
            <p>Real-time crypto data powered by CoinGecko API v1.02</p>
        </div>

        <div class="controls">
            <div class="input-group">
                <label for="currency">Currency:</label>
                <select id="currency">
                    <option value="usd">USD</option>
                    <option value="eur">EUR</option>
                    <option value="btc">BTC</option>
                    <option value="eth">ETH</option>
                </select>
            </div>
            <div class="input-group">
                <label for="perPage">Results per page:</label>
                <select id="perPage">
                    <option value="50">50</option>
                    <option value="100" selected>100</option>
                    <option value="250">250</option>
                </select>
            </div>
            <button class="btn" onclick="fetchCryptoData()">Load Data</button>
        </div>

        <div id="content">
            <div class="loading" style="display: none;">
                Loading cryptocurrency data...
            </div>
        </div>
    </div>

    <script>
        let cryptoData = [];

        function formatNumber(num) {
            if (num === null || num === undefined) return 'N/A';

            if (num >= 1e12) {
                return '$' + (num / 1e12).toFixed(2) + 'T';
            } else if (num >= 1e9) {
                return '$' + (num / 1e9).toFixed(2) + 'B';
            } else if (num >= 1e6) {
                return '$' + (num / 1e6).toFixed(2) + 'M';
            } else if (num >= 1e3) {
                return '$' + (num / 1e3).toFixed(2) + 'K';
            } else {
                return '$' + num.toFixed(2);
            }
        }

        function formatPrice(price) {
            if (price === null || price === undefined) return 'N/A';

            if (price >= 1) {
                return '$' + price.toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            } else {
                return '$' + price.toFixed(6);
            }
        }

        function formatChange(change) {
            if (change === null || change === undefined) return 'N/A';

            const formatted = change.toFixed(2) + '%';
            return change >= 0 ? '+' + formatted : formatted;
        }

        function getChangeClass(change) {
            if (change === null || change === undefined) return '';
            return change >= 0 ? 'change-positive' : 'change-negative';
        }

        function calculateATHChange(currentPrice, ath) {
            if (!currentPrice || !ath || ath === 0) return null;

            const change = ((currentPrice - ath) / ath) * 100;
            return change;
        }

        function getATHDisplay(athChange) {
            if (athChange === null || athChange === undefined) {
                return { text: 'N/A', class: 'ath-red' };
            }

            if (athChange >= -0.01) { // At ATH (within 0.01%)
                return { text: '0%', class: 'ath-green' };
            } else if (athChange >= -20) { // Between 0% and -20%
                return { text: athChange.toFixed(2) + '%', class: 'ath-yellow' };
            } else { // More than -20%
                return { text: athChange.toFixed(2) + '%', class: 'ath-red' };
            }
        }

        async function fetchCryptoData() {
            const currency = document.getElementById('currency').value;
            const perPage = document.getElementById('perPage').value;

            document.getElementById('content').innerHTML = '<div class="loading">Loading cryptocurrency data...</div>';

            try {
                const response = await fetch(
                    `https://api.coingecko.com/api/v3/coins/markets?vs_currency=${currency}&order=market_cap_desc&per_page=${perPage}&page=1&sparkline=false&price_change_percentage=1h,24h,7d`
                );

                if (!response.ok) {
                    throw new Error(`API Error: ${response.status} - ${response.statusText}`);
                }

                cryptoData = await response.json();
                displayCryptoData();

            } catch (error) {
                console.error('Error fetching crypto data:', error);
                document.getElementById('content').innerHTML = `
                    <div class="error">
                        Error loading data: ${error.message}<br>
                        This might be due to API rate limits. Please try again in a moment.
                    </div>
                `;
            }
        }

        function displayCryptoData() {
            if (!cryptoData || cryptoData.length === 0) {
                document.getElementById('content').innerHTML = '<div class="error">No data available</div>';
                return;
            }

            const currency = document.getElementById('currency').value.toUpperCase();

            let tableHTML = `
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Coin</th>
                                <th>Price (${currency})</th>
                                <th>1h %</th>
                                <th>24h %</th>
                                <th>7d %</th>
                                <th>24h Volume</th>
                                <th>Market Cap</th>
                                <th>% from ATH</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            cryptoData.forEach(coin => {
                const athChange = calculateATHChange(coin.current_price, coin.ath);
                const athDisplay = getATHDisplay(athChange);

                tableHTML += `
                    <tr>
                        <td><span class="rank">#${coin.market_cap_rank || 'N/A'}</span></td>
                        <td>
                            <div class="coin-info">
                                <img src="${coin.image}" alt="${coin.name}" class="coin-logo">
                                <div>
                                    <div class="coin-name">${coin.name}</div>
                                    <div class="coin-symbol">${coin.symbol}</div>
                                </div>
                            </div>
                        </td>
                        <td class="price">${formatPrice(coin.current_price)}</td>
                        <td class="${getChangeClass(coin.price_change_percentage_1h_in_currency)}">
                            ${formatChange(coin.price_change_percentage_1h_in_currency)}
                        </td>
                        <td class="${getChangeClass(coin.price_change_percentage_24h)}">
                            ${formatChange(coin.price_change_percentage_24h)}
                        </td>
                        <td class="${getChangeClass(coin.price_change_percentage_7d_in_currency)}">
                            ${formatChange(coin.price_change_percentage_7d_in_currency)}
                        </td>
                        <td class="volume">${formatNumber(coin.total_volume)}</td>
                        <td class="market-cap">${formatNumber(coin.market_cap)}</td>
                        <td><span class="${athDisplay.class}">${athDisplay.text}</span></td>
                    </tr>
                `;
            });

            tableHTML += `
                        </tbody>
                    </table>
                </div>
            `;

            document.getElementById('content').innerHTML = tableHTML;
        }

        // Initialize with automatic data loading
        document.addEventListener('DOMContentLoaded', function() {
            fetchCryptoData();
        });
    </script>
</body>
</html>