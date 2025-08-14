<?php
/*
 * Windows-like Desktop Interface
 * Version: 1.23
 * 
 * Release Notes:
 * v1.23 - Fixed JavaScript errors:
 *         - Fixed "Cannot read properties of undefined" error in click handlers
 *         - Fixed AudioContext initialization by requiring user gesture first
 *         - Added safe element checking before calling .contains() methods
 *         - Audio now initializes on first user interaction (click, touch, or keypress)
 * v1.22 - Fixed context menu bug:
 *         - Fixed Control Panel not opening from right-click context menu
 *         - Added event.stopPropagation() to all context menu handlers
 *         - Prevents context menu clicks from triggering document click handlers
 * v1.21 - Added comprehensive sound system:
 *         - Sound effects for all major interactions using Web Audio API
 *         - Start menu, settings, window operations, app launching sounds
 *         - Right-click, menu navigation, button hover audio feedback
 *         - Sound settings in control panel with volume control and mute option
 *         - Authentic Windows-like audio experience
 * v1.20 - Updated default window size:
 *         - Changed default window size from 800x600 to 1600x900
 *         - Added 1600x900 as a standard preset size option
 *         - Better support for modern widescreen displays
 * v1.19 - Cleaned up settings panel:
 *         - Removed non-functional "Snap to Edges" and "Animate Windows" options
 *         - Streamlined Windows tab to focus on essential window sizing options
 *         - Improved settings organization and clarity
 */

function loadExcludedFiles($dir) {
    $excluded = [];
    $configFile = $dir . DIRECTORY_SEPARATOR . 'Desktop.php.cfg';
    
    if (file_exists($configFile) && is_readable($configFile)) {
        $content = file_get_contents($configFile);
        $lines = explode("\n", $content);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line) && substr($line, 0, 1) !== '#') {
                $excluded[] = $line;
            }
        }
    }
    
    return $excluded;
}

function scanForApps($dir, $basePath = '') {
    $apps = [];
    if (!is_dir($dir) || !is_readable($dir)) {
        return $apps;
    }
    
    $excludedFiles = loadExcludedFiles($dir);
    $items = scandir($dir);
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        if (substr($item, 0, 1) === '.') continue;
        if (in_array($item, $excludedFiles)) continue;
        
        $fullPath = $dir . DIRECTORY_SEPARATOR . $item;
        $relativePath = $basePath ? $basePath . '/' . $item : $item;
        
        if (is_dir($fullPath)) {
            // Recursively scan subdirectories
            $subApps = scanForApps($fullPath, $relativePath);
            $apps = array_merge($apps, $subApps);
        } elseif (is_file($fullPath)) {
            $extension = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            if (in_array($extension, ['php', 'html', 'htm'])) {
                $apps[] = [
                    'name' => pathinfo($item, PATHINFO_FILENAME),
                    'path' => $relativePath,
                    'type' => $extension,
                    'folder' => $basePath ?: 'Root',
                    'size' => filesize($fullPath),
                    'date' => date('Y-m-d H:i', filemtime($fullPath))
                ];
            }
        }
    }
    
    return $apps;
}

$apps = scanForApps('.');
$appsJson = json_encode($apps);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Windows Desktop v1.23</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1e3c72, #2a5298);
            height: 100vh;
            overflow: hidden;
            user-select: none;
        }

        .desktop {
            height: calc(100vh - 48px);
            position: relative;
            background-image: 
                radial-gradient(circle at 20% 80%, rgba(120, 119, 198, 0.3) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(255, 119, 198, 0.15) 0%, transparent 50%);
        }

        .taskbar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: 48px;
            background: linear-gradient(180deg, #0078d4 0%, #106ebe 100%);
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            padding: 0 8px;
            z-index: 1000;
            color: white;
        }

        .start-button {
            width: 72px;
            height: 36px;
            background: linear-gradient(145deg, #0086f0, #0067c0);
            border: 1px solid #005a9e;
            border-radius: 4px;
            color: white;
            font-size: 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            margin-right: 8px;
            box-shadow: 
                inset 1px 1px 2px rgba(255, 255, 255, 0.3),
                inset -1px -1px 2px rgba(0, 0, 0, 0.2),
                0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .start-button:hover {
            background: linear-gradient(145deg, #0077d4, #005a9e);
            box-shadow: 
                inset 1px 1px 3px rgba(255, 255, 255, 0.4),
                inset -1px -1px 3px rgba(0, 0, 0, 0.3),
                0 3px 6px rgba(0, 0, 0, 0.3);
        }

        .start-button:active {
            transform: translateY(1px);
            box-shadow: 
                inset 2px 2px 4px rgba(0, 0, 0, 0.3),
                inset -1px -1px 2px rgba(255, 255, 255, 0.2),
                0 1px 2px rgba(0, 0, 0, 0.2);
        }

        .settings-button {
            width: 36px;
            height: 36px;
            background: linear-gradient(145deg, #0086f0, #0067c0);
            border: 1px solid #005a9e;
            border-radius: 4px;
            color: white;
            font-size: 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            margin-right: 8px;
            box-shadow: 
                inset 1px 1px 2px rgba(255, 255, 255, 0.3),
                inset -1px -1px 2px rgba(0, 0, 0, 0.2),
                0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .settings-button:hover {
            background: linear-gradient(145deg, #0077d4, #005a9e);
            box-shadow: 
                inset 1px 1px 3px rgba(255, 255, 255, 0.4),
                inset -1px -1px 3px rgba(0, 0, 0, 0.3),
                0 3px 6px rgba(0, 0, 0, 0.3);
        }

        .settings-button:active {
            transform: translateY(1px);
            box-shadow: 
                inset 2px 2px 4px rgba(0, 0, 0, 0.3),
                inset -1px -1px 2px rgba(255, 255, 255, 0.2),
                0 1px 2px rgba(0, 0, 0, 0.2);
        }

        .taskbar-apps {
            display: flex;
            gap: 4px;
            flex: 1;
        }

        .taskbar-app {
            height: 36px;
            min-width: 160px;
            max-width: 200px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 4px;
            color: white;
            font-size: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            padding: 0 8px;
            transition: all 0.2s ease;
        }

        .taskbar-app:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .taskbar-app.active {
            background: rgba(255, 255, 255, 0.3);
            border-color: rgba(255, 255, 255, 0.4);
        }

        .taskbar-time {
            color: white;
            font-size: 12px;
            text-align: center;
            min-width: 80px;
            padding: 0 12px;
        }

        .start-menu {
            position: fixed;
            bottom: 48px;
            left: 0;
            width: 320px;
            max-height: 600px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 8px 8px 0 0;
            border: 1px solid rgba(0, 0, 0, 0.1);
            display: none;
            z-index: 1001;
            overflow: hidden;
        }

        .start-menu.show {
            display: block;
            animation: slideUp 0.2s ease;
        }

        .settings-panel {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 600px;
            max-height: 80vh;
            background: white;
            border-radius: 8px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(0, 0, 0, 0.1);
            display: none;
            z-index: 1003;
            overflow: hidden;
        }

        .settings-panel.show {
            display: block;
            animation: fadeIn 0.2s ease;
        }

        .settings-header {
            padding: 16px;
            background: linear-gradient(135deg, #0078d4, #106ebe);
            color: white;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .settings-tabs {
            display: flex;
            background: #f5f5f5;
            border-bottom: 1px solid #ddd;
        }

        .settings-tab {
            flex: 1;
            padding: 12px 16px;
            text-align: center;
            cursor: pointer;
            border: none;
            background: transparent;
            font-size: 14px;
            transition: background 0.2s ease;
        }

        .settings-tab:hover {
            background: #e0e0e0;
        }

        .settings-tab.active {
            background: white;
            border-bottom: 2px solid #0078d4;
        }

        .settings-content {
            max-height: 60vh;
            overflow-y: auto;
            padding: 20px;
        }

        .settings-tab-content {
            display: none;
        }

        .settings-tab-content.active {
            display: block;
        }

        .settings-group {
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid #eee;
        }

        .settings-group:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .settings-group-title {
            font-weight: 600;
            margin-bottom: 12px;
            color: #333;
            font-size: 16px;
        }

        .settings-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
        }

        .settings-input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        .settings-row {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-bottom: 12px;
        }

        .settings-row .settings-input {
            flex: 1;
        }

        .color-input {
            width: 60px;
            height: 40px;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
        }

        .background-preview {
            width: 120px;
            height: 80px;
            border: 2px solid #ddd;
            border-radius: 4px;
            background-size: cover;
            background-position: center;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666;
            font-size: 12px;
            text-align: center;
        }

        .background-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 12px;
            margin-top: 12px;
        }

        .background-option {
            position: relative;
            cursor: pointer;
        }

        .background-option.selected .background-preview {
            border-color: #0078d4;
            border-width: 3px;
        }

        .background-option.selected::after {
            content: '✓';
            position: absolute;
            top: 4px;
            right: 4px;
            background: #0078d4;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
        }

        .settings-buttons {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid #eee;
        }

        .settings-button-action {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }

        .settings-button-primary {
            background: #0078d4;
            color: white;
        }

        .settings-button-secondary {
            background: #f0f0f0;
            color: #333;
        }

        .file-input {
            display: none;
        }

        .file-input-button {
            padding: 8px 16px;
            background: #f0f0f0;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-align: center;
        }

        .file-input-button:hover {
            background: #e0e0e0;
        }

        .volume-slider {
            width: 100px;
        }

        @keyframes slideUp {
            from {
                transform: translateY(20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translate(-50%, -50%) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translate(-50%, -50%) scale(1);
            }
        }

        .start-menu-header {
            padding: 16px;
            background: linear-gradient(135deg, #0078d4, #106ebe);
            color: white;
            font-weight: 600;
        }

        .start-menu-content {
            max-height: 500px;
            overflow-y: auto;
        }

        .start-menu-section {
            padding: 8px 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
        }

        .start-menu-folder {
            padding: 8px 16px;
            font-weight: 600;
            color: #333;
            background: rgba(0, 0, 0, 0.05);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .start-menu-folder:hover {
            background: rgba(0, 0, 0, 0.1);
        }

        .start-menu-folder .arrow {
            transition: transform 0.2s ease;
        }

        .start-menu-folder.expanded .arrow {
            transform: rotate(90deg);
        }

        .start-menu-apps {
            display: none;
            background: rgba(0, 0, 0, 0.02);
        }

        .start-menu-apps.show {
            display: block;
        }

        .start-menu-app {
            padding: 10px 24px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: background 0.2s ease;
            color: #333;
        }

        .start-menu-app:hover {
            background: rgba(0, 120, 212, 0.1);
        }

        .start-menu-app-icon {
            width: 24px;
            height: 24px;
            background: linear-gradient(135deg, #0078d4, #106ebe);
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 12px;
            font-weight: bold;
        }

        .start-menu-app-info {
            flex: 1;
        }

        .start-menu-app-name {
            font-weight: 500;
            font-size: 14px;
        }

        .start-menu-app-details {
            font-size: 11px;
            color: #666;
            margin-top: 2px;
        }

        .window {
            position: absolute;
            background: white;
            border-radius: 8px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(0, 0, 0, 0.1);
            min-width: 300px;
            min-height: 200px;
            display: none;
            z-index: 100;
        }

        .window.show {
            display: block;
        }

        .window.active {
            z-index: 200;
        }

        .window-titlebar {
            height: 32px;
            border-bottom: 1px solid #ccc;
            border-radius: 8px 8px 0 0;
            display: flex;
            align-items: center;
            padding: 0 8px;
            cursor: move;
            background: linear-gradient(180deg, #f0f0f0, #e0e0e0);
            color: #333;
        }

        .window-title {
            flex: 1;
            font-size: 12px;
            font-weight: 500;
            padding-left: 8px;
        }

        .window-controls {
            display: flex;
            gap: 4px;
        }

        .window-control {
            width: 24px;
            height: 20px;
            border: none;
            border-radius: 2px;
            font-size: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s ease;
        }

        .window-minimize {
            background: #e1e1e1;
            color: #333;
        }

        .window-minimize:hover {
            background: #d1d1d1;
        }

        .window-maximize {
            background: #e1e1e1;
            color: #333;
        }

        .window-maximize:hover {
            background: #d1d1d1;
        }

        .window-close {
            background: #e81123;
            color: white;
        }

        .window-close:hover {
            background: #c50e1f;
        }

        .window-content {
            height: calc(100% - 33px);
            border-radius: 0 0 8px 8px;
            overflow: hidden;
            position: relative;
        }

        .window-content iframe {
            width: 100%;
            height: 100%;
            border: none;
            border-radius: 0 0 8px 8px;
        }

        /* Resize handles */
        .resize-handle {
            position: absolute;
            background: transparent;
            z-index: 10;
            border: 1px solid transparent;
        }

        .resize-handle:hover {
            background: rgba(0, 120, 212, 0.2);
            border: 1px solid rgba(0, 120, 212, 0.5);
        }

        .resize-handle-se {
            bottom: 0;
            right: 0;
            width: 12px;
            height: 12px;
            cursor: se-resize;
        }

        .resize-handle-s {
            bottom: 0;
            left: 12px;
            right: 12px;
            height: 5px;
            cursor: s-resize;
        }

        .resize-handle-e {
            top: 12px;
            right: 0;
            width: 5px;
            bottom: 12px;
            cursor: e-resize;
        }

        .resize-handle-sw {
            bottom: 0;
            left: 0;
            width: 12px;
            height: 12px;
            cursor: sw-resize;
        }

        .resize-handle-w {
            top: 12px;
            left: 0;
            width: 5px;
            bottom: 12px;
            cursor: w-resize;
        }

        .resize-handle-nw {
            top: 0;
            left: 0;
            width: 12px;
            height: 12px;
            cursor: nw-resize;
        }

        .resize-handle-n {
            top: 0;
            left: 12px;
            right: 12px;
            height: 5px;
            cursor: n-resize;
        }

        .resize-handle-ne {
            top: 0;
            right: 0;
            width: 12px;
            height: 12px;
            cursor: ne-resize;
        }

        .desktop-shortcut {
            position: absolute;
            width: 80px;
            height: 90px;
            display: flex;
            flex-direction: column;
            align-items: center;
            cursor: pointer;
            padding: 8px;
            border-radius: 4px;
            transition: background 0.2s ease;
        }

        .desktop-shortcut:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .desktop-shortcut-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #0078d4, #106ebe);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            margin-bottom: 4px;
        }

        .desktop-shortcut-name {
            font-size: 11px;
            color: white;
            text-align: center;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.8);
            word-wrap: break-word;
            line-height: 1.2;
        }

        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.05);
        }

        ::-webkit-scrollbar-thumb {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: rgba(0, 0, 0, 0.3);
        }

        .context-menu {
            position: fixed;
            background: white;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            display: none;
            z-index: 1002;
            min-width: 150px;
        }

        .context-menu-item {
            padding: 8px 16px;
            cursor: pointer;
            font-size: 12px;
            border-bottom: 1px solid #eee;
        }

        .context-menu-item:hover {
            background: #f0f0f0;
        }

        .context-menu-item:last-child {
            border-bottom: none;
        }

        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.3);
            display: none;
            z-index: 999;
        }

        .overlay.show {
            display: block;
        }

        /* Custom Dialog System */
        .custom-dialog {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            min-width: 300px;
            max-width: 500px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(0, 0, 0, 0.1);
            display: none;
            z-index: 1004;
            overflow: hidden;
        }

        .custom-dialog.show {
            display: block;
            animation: dialogFadeIn 0.2s ease;
        }

        .custom-dialog-header {
            padding: 12px 16px;
            background: linear-gradient(135deg, #0078d4, #106ebe);
            color: white;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 14px;
        }

        .custom-dialog-content {
            padding: 16px;
            font-size: 14px;
            line-height: 1.4;
            color: #333;
        }

        .custom-dialog-buttons {
            padding: 12px 16px;
            background: #f8f8f8;
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            border-top: 1px solid #eee;
        }

        .custom-dialog-button {
            padding: 6px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            transition: background 0.2s ease;
        }

        .custom-dialog-button-primary {
            background: #0078d4;
            color: white;
        }

        .custom-dialog-button-primary:hover {
            background: #106ebe;
        }

        .custom-dialog-button-secondary {
            background: #e0e0e0;
            color: #333;
        }

        .custom-dialog-button-secondary:hover {
            background: #d0d0d0;
        }

        .custom-dialog-input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            margin-top: 8px;
        }

        @keyframes dialogFadeIn {
            from {
                opacity: 0;
                transform: translate(-50%, -50%) scale(0.9);
            }
            to {
                opacity: 1;
                transform: translate(-50%, -50%) scale(1);
            }
        }

        /* Desktop Icon Context Menu */
        .desktop-context-menu {
            position: fixed;
            background: white;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            display: none;
            z-index: 1002;
            min-width: 150px;
        }

        /* Start Menu Item Context Menu */
        .start-menu-context-menu {
            position: fixed;
            background: white;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            display: none;
            z-index: 1002;
            min-width: 150px;
        }

        /* Start Button Context Menu */
        .start-button-context-menu {
            position: fixed;
            background: white;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            display: none;
            z-index: 1002;
            min-width: 180px;
        }
    </style>
</head>
<body>
    <div class="overlay" id="overlay"></div>
    
    <div class="desktop" id="desktop">
        <!-- Desktop shortcuts will be generated here -->
    </div>

    <div class="taskbar">
        <button class="start-button" id="startButton">⊞</button>
        <button class="settings-button" id="settingsButton" title="Control Panel">⚙</button>
        <div class="taskbar-apps" id="taskbarApps"></div>
        <div class="taskbar-time" id="taskbarTime"></div>
    </div>

    <div class="start-menu" id="startMenu">
        <div class="start-menu-header" id="startMenuHeader">
            All Programs
        </div>
        <div class="start-menu-content" id="startMenuContent">
            <!-- Start menu content will be generated here -->
        </div>
    </div>

    <div class="settings-panel" id="settingsPanel">
        <div class="settings-header" id="settingsHeader">
            <span>Control Panel</span>
            <button class="window-control window-close" onclick="closeSettings()">×</button>
        </div>
        <div class="settings-tabs">
            <button class="settings-tab active" onclick="switchTab('display')">Display</button>
            <button class="settings-tab" onclick="switchTab('datetime')">Date & Time</button>
            <button class="settings-tab" onclick="switchTab('windows')">Windows</button>
            <button class="settings-tab" onclick="switchTab('sound')">Sound</button>
        </div>
        <div class="settings-content">
            <!-- Display Tab -->
            <div class="settings-tab-content active" id="display-tab">
                <div class="settings-group">
                    <div class="settings-group-title">Colors</div>
                    <div class="settings-row">
                        <label class="settings-label">Taskbar Color:</label>
                        <input type="color" id="taskbarColor" class="color-input" value="#0078d4">
                    </div>
                    <div class="settings-row">
                        <label class="settings-label">Window Heading Color:</label>
                        <input type="color" id="windowHeadingColor" class="color-input" value="#0078d4">
                    </div>
                </div>
                <div class="settings-group">
                    <div class="settings-group-title">Background</div>
                    <div class="background-options">
                        <div class="background-option selected" data-bg="gradient">
                            <div class="background-preview" style="background: linear-gradient(135deg, #1e3c72, #2a5298);">Default</div>
                        </div>
                        <div class="background-option" data-bg="solid-blue">
                            <div class="background-preview" style="background: #2a5298;">Solid Blue</div>
                        </div>
                        <div class="background-option" data-bg="solid-dark">
                            <div class="background-preview" style="background: #2c2c2c;">Dark</div>
                        </div>
                        <div class="background-option" data-bg="custom">
                            <div class="background-preview" id="customBgPreview">Custom</div>
                        </div>
                    </div>
                    <div style="margin-top: 12px;">
                        <label for="backgroundUpload" class="file-input-button">Upload Background Image</label>
                        <input type="file" id="backgroundUpload" class="file-input" accept="image/*">
                    </div>
                </div>
            </div>

            <!-- Date & Time Tab -->
            <div class="settings-tab-content" id="datetime-tab">
                <div class="settings-group">
                    <div class="settings-group-title">Time Format</div>
                    <div class="settings-row">
                        <label class="settings-label">Time Display:</label>
                        <select id="timeFormat" class="settings-input">
                            <option value="12">12 Hour (AM/PM)</option>
                            <option value="24">24 Hour</option>
                        </select>
                    </div>
                    <div class="settings-row">
                        <label class="settings-label">Date Format:</label>
                        <select id="dateFormat" class="settings-input">
                            <option value="short">Short (Jan 1)</option>
                            <option value="long">Long (January 1, 2024)</option>
                            <option value="numeric">Numeric (1/1/2024)</option>
                            <option value="iso">ISO (2024-01-01)</option>
                        </select>
                    </div>
                    <div class="settings-row">
                        <label class="settings-label">Show Seconds:</label>
                        <input type="checkbox" id="showSeconds">
                    </div>
                </div>
            </div>

            <!-- Windows Tab -->
            <div class="settings-tab-content" id="windows-tab">
                <div class="settings-group">
                    <div class="settings-group-title">Default Window Size</div>
                    <div class="settings-row">
                        <input type="number" id="defaultWidth" class="settings-input" placeholder="Width (px)" min="300" max="1920" value="1600">
                        <input type="number" id="defaultHeight" class="settings-input" placeholder="Height (px)" min="200" max="1080" value="900">
                    </div>
                </div>
                <div class="settings-group">
                    <div class="settings-group-title">Preset Sizes</div>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <button class="settings-button-action settings-button-secondary" onclick="setPresetSize(640, 480)">640×480</button>
                        <button class="settings-button-action settings-button-secondary" onclick="setPresetSize(800, 600)">800×600</button>
                        <button class="settings-button-action settings-button-secondary" onclick="setPresetSize(1024, 768)">1024×768</button>
                        <button class="settings-button-action settings-button-secondary" onclick="setPresetSize(1200, 800)">1200×800</button>
                        <button class="settings-button-action settings-button-secondary" onclick="setPresetSize(1600, 900)">1600×900</button>
                    </div>
                </div>
            </div>

            <!-- Sound Tab -->
            <div class="settings-tab-content" id="sound-tab">
                <div class="settings-group">
                    <div class="settings-group-title">Sound Settings</div>
                    <div class="settings-row">
                        <label class="settings-label">Master Volume:</label>
                        <input type="range" id="masterVolume" class="volume-slider" min="0" max="100" value="50">
                        <span id="volumeDisplay">50%</span>
                    </div>
                    <div class="settings-row">
                        <label class="settings-label">Enable Sounds:</label>
                        <input type="checkbox" id="soundEnabled" checked>
                    </div>
                </div>
                <div class="settings-group">
                    <div class="settings-group-title">Sound Test</div>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <button class="settings-button-action settings-button-secondary" onclick="testSound('click')">Click</button>
                        <button class="settings-button-action settings-button-secondary" onclick="testSound('open')">Open</button>
                        <button class="settings-button-action settings-button-secondary" onclick="testSound('close')">Close</button>
                        <button class="settings-button-action settings-button-secondary" onclick="testSound('minimize')">Minimize</button>
                        <button class="settings-button-action settings-button-secondary" onclick="testSound('maximize')">Maximize</button>
                        <button class="settings-button-action settings-button-secondary" onclick="testSound('error')">Error</button>
                        <button class="settings-button-action settings-button-secondary" onclick="testSound('startup')">Startup</button>
                    </div>
                </div>
            </div>
        </div>
        <div class="settings-buttons">
            <button class="settings-button-action settings-button-secondary" onclick="resetToDefaults()">Reset to Defaults</button>
            <button class="settings-button-action settings-button-primary" onclick="closeSettings()">Close</button>
        </div>
    </div>

    <div class="context-menu" id="contextMenu">
        <div class="context-menu-item" id="contextRefresh">Refresh</div>
        <div class="context-menu-item" id="contextReset">Reset Windows</div>
        <div class="context-menu-item" id="contextSettings">Control Panel</div>
        <div class="context-menu-item" id="contextProperties">Copy Desktop URL</div>
    </div>

    <!-- Window titlebar context menu -->
    <div class="context-menu" id="windowContextMenu">
        <div class="context-menu-item" id="copyUrl">Copy URL</div>
        <div class="context-menu-item" id="windowRefresh">Refresh Window</div>
        <div class="context-menu-item" id="windowClose">Close Window</div>
    </div>

    <!-- Desktop icon context menu -->
    <div class="desktop-context-menu" id="desktopIconContextMenu">
        <div class="context-menu-item" id="openApp">Open</div>
        <div class="context-menu-item" id="copyAppUrl">Copy URL</div>
        <div class="context-menu-item" id="appProperties">Properties</div>
    </div>

    <!-- Start menu item context menu -->
    <div class="start-menu-context-menu" id="startMenuItemContextMenu">
        <div class="context-menu-item" id="openStartApp">Open</div>
        <div class="context-menu-item" id="copyStartAppUrl">Copy URL</div>
        <div class="context-menu-item" id="startAppProperties">Properties</div>
    </div>

    <!-- Start button context menu -->
    <div class="start-button-context-menu" id="startButtonContextMenu">
        <div class="context-menu-item" id="openAllPrograms">All Programs</div>
        <div class="context-menu-item" id="openControlPanel">Control Panel</div>
        <div class="context-menu-item" id="copyDesktopUrl">Copy Desktop URL</div>
        <div class="context-menu-item" id="refreshDesktop">Refresh Desktop</div>
    </div>

    <!-- Custom Dialog System -->
    <div class="custom-dialog" id="customDialog">
        <div class="custom-dialog-header">
            <span id="dialogTitle">Information</span>
            <button class="window-control window-close" onclick="closeDialog()">×</button>
        </div>
        <div class="custom-dialog-content" id="dialogContent">
            <!-- Dialog content will be inserted here -->
        </div>
        <div class="custom-dialog-buttons" id="dialogButtons">
            <!-- Dialog buttons will be inserted here -->
        </div>
    </div>

    <script>
        // Application data from PHP
        const apps = <?php echo $appsJson; ?>;
        
        // Desktop state
        let openWindows = [];
        let nextWindowId = 1;
        let activeWindow = null;
        let isDragging = false;
        let dragWindow = null;
        let dragOffset = { x: 0, y: 0 };
        let isResizing = false;
        let resizeWindow = null;
        let resizeHandle = null;
        let resizeStart = { x: 0, y: 0, width: 0, height: 0, left: 0, top: 0 };
        let currentWindowPath = null; // For right-click context menu
        let currentAppPath = null; // For desktop icon context menu
        let currentAppName = null; // For desktop icon context menu

        // Custom Dialog System
        function showDialog(title, content, buttons = null) {
            playSound('open');
            
            document.getElementById('dialogTitle').textContent = title;
            document.getElementById('dialogContent').innerHTML = content;
            
            const dialogButtons = document.getElementById('dialogButtons');
            if (buttons) {
                let buttonsHtml = '';
                buttons.forEach(button => {
                    const buttonClass = button.primary ? 'custom-dialog-button-primary' : 'custom-dialog-button-secondary';
                    buttonsHtml += `<button class="custom-dialog-button ${buttonClass}" onclick="${button.action}">${button.text}</button>`;
                });
                dialogButtons.innerHTML = buttonsHtml;
            } else {
                dialogButtons.innerHTML = '<button class="custom-dialog-button custom-dialog-button-primary" onclick="closeDialog()">OK</button>';
            }
            
            document.getElementById('customDialog').classList.add('show');
            document.getElementById('overlay').classList.add('show');
        }

        function closeDialog() {
            playSound('close');
            document.getElementById('customDialog').classList.remove('show');
            document.getElementById('overlay').classList.remove('show');
        }

        function showUrlDialog(url, title = 'URL Information') {
            const content = `
                <div>Application URL:</div>
                <input type="text" class="custom-dialog-input" value="${url}" readonly onclick="this.select()" id="urlInput">
                <div style="margin-top: 8px; font-size: 12px; color: #666;">
                    Click the URL to select all text, then copy with Ctrl+C
                </div>
            `;
            
            const buttons = [
                { text: 'Copy to Clipboard', action: 'copyUrlToClipboard()', primary: true },
                { text: 'Close', action: 'closeDialog()', primary: false }
            ];
            
            showDialog(title, content, buttons);
            
            // Auto-select the URL text
            setTimeout(() => {
                const input = document.getElementById('urlInput');
                if (input) {
                    input.focus();
                    input.select();
                }
            }, 100);
        }

        function copyUrlToClipboard() {
            const input = document.getElementById('urlInput');
            if (input) {
                input.select();
                try {
                    navigator.clipboard.writeText(input.value).then(() => {
                        playSound('click');
                        showDialog('Success', 'URL copied to clipboard successfully!');
                    }).catch(() => {
                        document.execCommand('copy');
                        playSound('click');
                        showDialog('Success', 'URL copied to clipboard!');
                    });
                } catch (e) {
                    showDialog('Copy URL', 'Please manually copy the selected URL with Ctrl+C');
                }
            }
        }

        // Close all context menus
        function closeAllContextMenus() {
            document.getElementById('contextMenu').style.display = 'none';
            document.getElementById('windowContextMenu').style.display = 'none';
            document.getElementById('desktopIconContextMenu').style.display = 'none';
            document.getElementById('startMenuItemContextMenu').style.display = 'none';
            document.getElementById('startButtonContextMenu').style.display = 'none';
        }

        // Sound system
        let audioContext = null;
        let masterGain = null;
        let audioInitialized = false;

        // Settings - updated with sound settings
        let settings = {
            // Display settings
            taskbarColor: '#0078d4',
            windowHeadingColor: '#0078d4',
            backgroundType: 'gradient',
            customBackground: null,
            
            // Date & Time settings
            timeFormat: '12',
            dateFormat: 'short',
            showSeconds: false,
            
            // Window settings
            defaultWidth: 1600,
            defaultHeight: 900,
            
            // Sound settings
            soundEnabled: true,
            masterVolume: 50
        };

        // Initialize audio context and sound system (only after user interaction)
        function initializeAudio() {
            if (audioInitialized) return;
            
            try {
                audioContext = new (window.AudioContext || window.webkitAudioContext)();
                masterGain = audioContext.createGain();
                masterGain.connect(audioContext.destination);
                updateMasterVolume();
                audioInitialized = true;
                
                // Resume context if it's suspended
                if (audioContext.state === 'suspended') {
                    audioContext.resume();
                }
            } catch (e) {
                console.warn('Web Audio API not supported:', e);
                settings.soundEnabled = false;
            }
        }

        // Update master volume
        function updateMasterVolume() {
            if (masterGain) {
                masterGain.gain.value = settings.masterVolume / 100;
            }
        }

        // Play sound effect
        function playSound(type) {
            if (!settings.soundEnabled) return;
            
            // Initialize audio on first sound play attempt
            if (!audioInitialized) {
                initializeAudio();
                if (!audioInitialized) return; // Failed to initialize
            }
            
            if (!audioContext || !masterGain) return;
            
            try {
                const oscillator = audioContext.createOscillator();
                const gainNode = audioContext.createGain();
                
                oscillator.connect(gainNode);
                gainNode.connect(masterGain);
                
                const now = audioContext.currentTime;
                let frequency, duration, gainValue;
                
                switch(type) {
                    case 'click':
                        frequency = 800;
                        duration = 0.1;
                        gainValue = 0.1;
                        break;
                    case 'hover':
                        frequency = 600;
                        duration = 0.05;
                        gainValue = 0.05;
                        break;
                    case 'open':
                        frequency = 440;
                        duration = 0.3;
                        gainValue = 0.15;
                        // Create ascending tone
                        oscillator.frequency.setValueAtTime(440, now);
                        oscillator.frequency.exponentialRampToValueAtTime(880, now + duration);
                        break;
                    case 'close':
                        frequency = 880;
                        duration = 0.3;
                        gainValue = 0.15;
                        // Create descending tone
                        oscillator.frequency.setValueAtTime(880, now);
                        oscillator.frequency.exponentialRampToValueAtTime(440, now + duration);
                        break;
                    case 'minimize':
                        frequency = 660;
                        duration = 0.2;
                        gainValue = 0.12;
                        oscillator.frequency.setValueAtTime(660, now);
                        oscillator.frequency.exponentialRampToValueAtTime(330, now + duration);
                        break;
                    case 'maximize':
                        frequency = 330;
                        duration = 0.2;
                        gainValue = 0.12;
                        oscillator.frequency.setValueAtTime(330, now);
                        oscillator.frequency.exponentialRampToValueAtTime(660, now + duration);
                        break;
                    case 'error':
                        frequency = 200;
                        duration = 0.5;
                        gainValue = 0.2;
                        oscillator.type = 'sawtooth';
                        break;
                    case 'startup':
                        frequency = 262; // C4
                        duration = 0.8;
                        gainValue = 0.15;
                        // Play a chord progression: C-E-G
                        oscillator.frequency.setValueAtTime(262, now); // C
                        oscillator.frequency.setValueAtTime(330, now + 0.25); // E
                        oscillator.frequency.setValueAtTime(392, now + 0.5); // G
                        break;
                    case 'rightclick':
                        frequency = 700;
                        duration = 0.08;
                        gainValue = 0.08;
                        break;
                    case 'drag':
                        frequency = 500;
                        duration = 0.06;
                        gainValue = 0.06;
                        break;
                    default:
                        frequency = 440;
                        duration = 0.1;
                        gainValue = 0.1;
                }
                
                oscillator.type = oscillator.type || 'sine';
                
                if (type !== 'open' && type !== 'close' && type !== 'minimize' && type !== 'maximize' && type !== 'startup') {
                    oscillator.frequency.setValueAtTime(frequency, now);
                }
                
                gainNode.gain.setValueAtTime(0, now);
                gainNode.gain.linearRampToValueAtTime(gainValue, now + 0.01);
                gainNode.gain.exponentialRampToValueAtTime(0.001, now + duration);
                
                oscillator.start(now);
                oscillator.stop(now + duration);
            } catch (e) {
                console.warn('Error playing sound:', e);
            }
        }

        // Test sound function
        function testSound(type) {
            playSound(type);
        }

        // Load settings from localStorage
        function loadSettings() {
            const saved = localStorage.getItem('desktopSettings');
            if (saved) {
                const savedSettings = JSON.parse(saved);
                settings = {...settings, ...savedSettings};
            }
            applyAllSettings();
            updateSettingsUI();
        }

        // Save settings to localStorage
        function saveSettingsToStorage() {
            localStorage.setItem('desktopSettings', JSON.stringify(settings));
        }

        // Apply all settings to the UI
        function applyAllSettings() {
            applyColors();
            applyBackground();
            updateTime();
            updateMasterVolume();
        }

        // Apply color settings
        function applyColors() {
            // Update taskbar colors
            const taskbar = document.querySelector('.taskbar');
            taskbar.style.background = `linear-gradient(180deg, ${settings.taskbarColor} 0%, ${settings.taskbarColor}cc 100%)`;
            
            // Update start button and settings button
            const startButton = document.querySelector('.start-button');
            const settingsButton = document.querySelector('.settings-button');
            const lighterColor = lightenColor(settings.taskbarColor, 20);
            const darkerColor = darkenColor(settings.taskbarColor, 20);
            
            const buttonGradient = `linear-gradient(145deg, ${lighterColor}, ${darkerColor})`;
            startButton.style.background = buttonGradient;
            settingsButton.style.background = buttonGradient;
            startButton.style.borderColor = darkerColor;
            settingsButton.style.borderColor = darkerColor;
            
            // Update start menu header
            const startMenuHeader = document.getElementById('startMenuHeader');
            const settingsHeader = document.getElementById('settingsHeader');
            const headerGradient = `linear-gradient(135deg, ${settings.taskbarColor}, ${darkerColor})`;
            startMenuHeader.style.background = headerGradient;
            settingsHeader.style.background = headerGradient;
            
            // Calculate window heading colors and optimal text color
            const windowHeadingDark = darkenColor(settings.windowHeadingColor, 15);
            const windowTextColor = getContrastTextColor(settings.windowHeadingColor);
            
            // Apply colors directly to ALL active windows using inline styles ONLY
            document.querySelectorAll('.window').forEach(window => {
                const titlebar = window.querySelector('.window-titlebar');
                if (titlebar && window.classList.contains('active')) {
                    const bgGradient = `linear-gradient(180deg, ${settings.windowHeadingColor}, ${windowHeadingDark})`;
                    titlebar.style.setProperty('background', bgGradient, 'important');
                    titlebar.style.setProperty('color', windowTextColor, 'important');
                }
            });
            
            // Store colors for future windows
            window.currentWindowColors = {
                background: `linear-gradient(180deg, ${settings.windowHeadingColor}, ${windowHeadingDark})`,
                color: windowTextColor
            };
        }

        // Helper functions for color manipulation
        function lightenColor(color, percent) {
            const num = parseInt(color.replace('#', ''), 16);
            const amt = Math.round(2.55 * percent);
            const R = (num >> 16) + amt;
            const G = (num >> 8 & 0x00FF) + amt;
            const B = (num & 0x0000FF) + amt;
            return '#' + (0x1000000 + (R < 255 ? R < 1 ? 0 : R : 255) * 0x10000 +
                (G < 255 ? G < 1 ? 0 : G : 255) * 0x100 +
                (B < 255 ? B < 1 ? 0 : B : 255)).toString(16).slice(1);
        }

        function darkenColor(color, percent) {
            const num = parseInt(color.replace('#', ''), 16);
            const amt = Math.round(2.55 * percent);
            const R = (num >> 16) - amt;
            const G = (num >> 8 & 0x00FF) - amt;
            const B = (num & 0x0000FF) - amt;
            return '#' + (0x1000000 + (R > 255 ? 255 : R < 0 ? 0 : R) * 0x10000 +
                (G > 255 ? 255 : G < 0 ? 0 : G) * 0x100 +
                (B > 255 ? 255 : B < 0 ? 0 : B)).toString(16).slice(1);
        }

        // Calculate if text should be white or black based on background brightness
        function getContrastTextColor(backgroundColor) {
            const color = backgroundColor.replace('#', '');
            const r = parseInt(color.substr(0, 2), 16);
            const g = parseInt(color.substr(2, 2), 16);
            const b = parseInt(color.substr(4, 2), 16);
            const brightness = ((r * 299) + (g * 587) + (b * 114)) / 1000;
            return brightness > 128 ? '#000000' : '#ffffff';
        }

        // Apply background settings
        function applyBackground() {
            const desktop = document.querySelector('.desktop');
            
            switch(settings.backgroundType) {
                case 'gradient':
                    desktop.style.background = 'linear-gradient(135deg, #1e3c72, #2a5298)';
                    desktop.style.backgroundImage = 'radial-gradient(circle at 20% 80%, rgba(120, 119, 198, 0.3) 0%, transparent 50%), radial-gradient(circle at 80% 20%, rgba(255, 119, 198, 0.15) 0%, transparent 50%)';
                    break;
                case 'solid-blue':
                    desktop.style.background = '#2a5298';
                    desktop.style.backgroundImage = 'none';
                    break;
                case 'solid-dark':
                    desktop.style.background = '#2c2c2c';
                    desktop.style.backgroundImage = 'none';
                    break;
                case 'custom':
                    if (settings.customBackground) {
                        desktop.style.backgroundImage = `url(${settings.customBackground})`;
                        desktop.style.backgroundSize = 'cover';
                        desktop.style.backgroundPosition = 'center';
                        desktop.style.backgroundRepeat = 'no-repeat';
                    }
                    break;
            }
        }

        // Update settings UI elements
        function updateSettingsUI() {
            document.getElementById('taskbarColor').value = settings.taskbarColor;
            document.getElementById('windowHeadingColor').value = settings.windowHeadingColor;
            document.getElementById('timeFormat').value = settings.timeFormat;
            document.getElementById('dateFormat').value = settings.dateFormat;
            document.getElementById('showSeconds').checked = settings.showSeconds;
            document.getElementById('defaultWidth').value = settings.defaultWidth;
            document.getElementById('defaultHeight').value = settings.defaultHeight;
            document.getElementById('soundEnabled').checked = settings.soundEnabled;
            document.getElementById('masterVolume').value = settings.masterVolume;
            document.getElementById('volumeDisplay').textContent = settings.masterVolume + '%';
            
            // Update background selection
            document.querySelectorAll('.background-option').forEach(option => {
                option.classList.remove('selected');
                if (option.dataset.bg === settings.backgroundType) {
                    option.classList.add('selected');
                }
            });
            
            // Update custom background preview
            if (settings.customBackground) {
                document.getElementById('customBgPreview').style.backgroundImage = `url(${settings.customBackground})`;
                document.getElementById('customBgPreview').style.backgroundSize = 'cover';
                document.getElementById('customBgPreview').style.backgroundPosition = 'center';
                document.getElementById('customBgPreview').textContent = '';
            }
        }

        // DOM elements
        const desktop = document.getElementById('desktop');
        const startButton = document.getElementById('startButton');
        const settingsButton = document.getElementById('settingsButton');
        const startMenu = document.getElementById('startMenu');
        const startMenuContent = document.getElementById('startMenuContent');
        const taskbarApps = document.getElementById('taskbarApps');
        const taskbarTime = document.getElementById('taskbarTime');
        const contextMenu = document.getElementById('contextMenu');
        const windowContextMenu = document.getElementById('windowContextMenu');
        const settingsPanel = document.getElementById('settingsPanel');
        const overlay = document.getElementById('overlay');

        // Utility functions
        function getAppIcon(type) {
            const icons = {
                'php': '🐘',
                'html': '🌐',
                'htm': '🌐'
            };
            return icons[type] || '📄';
        }

        function formatBytes(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return Math.round(bytes / Math.pow(k, i)) + ' ' + sizes[i];
        }

        function updateTime() {
            const now = new Date();
            
            let timeOptions = { 
                hour: '2-digit', 
                minute: '2-digit',
                hour12: settings.timeFormat === '12'
            };
            
            if (settings.showSeconds) {
                timeOptions.second = '2-digit';
            }
            
            const timeStr = now.toLocaleTimeString([], timeOptions);
            
            let dateStr;
            switch(settings.dateFormat) {
                case 'long':
                    dateStr = now.toLocaleDateString([], { year: 'numeric', month: 'long', day: 'numeric' });
                    break;
                case 'numeric':
                    dateStr = now.toLocaleDateString([], { year: 'numeric', month: 'numeric', day: 'numeric' });
                    break;
                case 'iso':
                    dateStr = now.toISOString().split('T')[0];
                    break;
                case 'short':
                default:
                    dateStr = now.toLocaleDateString([], { month: 'short', day: 'numeric' });
                    break;
            }
            
            taskbarTime.innerHTML = `${timeStr}<br>${dateStr}`;
        }

        // Tab switching
        function switchTab(tabName) {
            playSound('click');
            document.querySelectorAll('.settings-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelector(`[onclick="switchTab('${tabName}')"]`).classList.add('active');
            
            document.querySelectorAll('.settings-tab-content').forEach(content => {
                content.classList.remove('active');
            });
            document.getElementById(`${tabName}-tab`).classList.add('active');
        }

        // Color change handlers - apply immediately
        function setupColorHandlers() {
            document.getElementById('taskbarColor').addEventListener('change', (e) => {
                playSound('click');
                settings.taskbarColor = e.target.value;
                applyColors();
                saveSettingsToStorage();
            });
            
            document.getElementById('windowHeadingColor').addEventListener('change', (e) => {
                playSound('click');
                settings.windowHeadingColor = e.target.value;
                applyColors();
                // Immediately re-apply to active windows
                const activeWindow = document.querySelector('.window.active');
                if (activeWindow) {
                    setActiveWindow(activeWindow.id);
                }
                saveSettingsToStorage();
            });
        }

        // Sound settings handlers
        function setupSoundHandlers() {
            document.getElementById('masterVolume').addEventListener('input', (e) => {
                settings.masterVolume = parseInt(e.target.value);
                document.getElementById('volumeDisplay').textContent = settings.masterVolume + '%';
                updateMasterVolume();
                saveSettingsToStorage();
            });
            
            document.getElementById('soundEnabled').addEventListener('change', (e) => {
                settings.soundEnabled = e.target.checked;
                saveSettingsToStorage();
                if (settings.soundEnabled) {
                    playSound('click');
                }
            });
        }

        // Background selection - apply immediately
        function setupBackgroundHandlers() {
            document.querySelectorAll('.background-option').forEach(option => {
                option.addEventListener('click', () => {
                    playSound('click');
                    document.querySelectorAll('.background-option').forEach(opt => {
                        opt.classList.remove('selected');
                    });
                    option.classList.add('selected');
                    settings.backgroundType = option.dataset.bg;
                    applyBackground();
                    saveSettingsToStorage();
                });
            });
            
            // Custom background upload
            document.getElementById('backgroundUpload').addEventListener('change', (e) => {
                const file = e.target.files[0];
                if (file) {
                    playSound('open');
                    const reader = new FileReader();
                    reader.onload = (event) => {
                        settings.customBackground = event.target.result;
                        settings.backgroundType = 'custom';
                        
                        // Update UI
                        document.querySelectorAll('.background-option').forEach(opt => {
                            opt.classList.remove('selected');
                        });
                        document.querySelector('[data-bg="custom"]').classList.add('selected');
                        
                        // Update preview
                        const preview = document.getElementById('customBgPreview');
                        preview.style.backgroundImage = `url(${settings.customBackground})`;
                        preview.style.backgroundSize = 'cover';
                        preview.style.backgroundPosition = 'center';
                        preview.textContent = '';
                        
                        applyBackground();
                        saveSettingsToStorage();
                    };
                    reader.readAsDataURL(file);
                }
            });
        }

        // Settings functions
        function showSettings() {
            playSound('open');
            updateSettingsUI();
            settingsPanel.classList.add('show');
            overlay.classList.add('show');
        }

        function closeSettings() {
            playSound('close');
            // Save all current settings before closing
            settings.timeFormat = document.getElementById('timeFormat').value;
            settings.dateFormat = document.getElementById('dateFormat').value;
            settings.showSeconds = document.getElementById('showSeconds').checked;
            settings.defaultWidth = parseInt(document.getElementById('defaultWidth').value);
            settings.defaultHeight = parseInt(document.getElementById('defaultHeight').value);
            
            saveSettingsToStorage();
            applyAllSettings();
            
            settingsPanel.classList.remove('show');
            overlay.classList.remove('show');
        }

        function setPresetSize(width, height) {
            playSound('click');
            document.getElementById('defaultWidth').value = width;
            document.getElementById('defaultHeight').value = height;
        }

        function resetToDefaults() {
            playSound('error');
            settings = {
                taskbarColor: '#0078d4',
                windowHeadingColor: '#0078d4',
                backgroundType: 'gradient',
                customBackground: null,
                timeFormat: '12',
                dateFormat: 'short',
                showSeconds: false,
                defaultWidth: 1600,
                defaultHeight: 900,
                soundEnabled: true,
                masterVolume: 50
            };
            
            applyAllSettings();
            updateSettingsUI();
            saveSettingsToStorage();
        }

        // Generate start menu
        function generateStartMenu() {
            const folders = {};
            
            apps.forEach(app => {
                if (!folders[app.folder]) {
                    folders[app.folder] = [];
                }
                folders[app.folder].push(app);
            });

            let html = '';
            Object.keys(folders).sort().forEach(folderName => {
                if (folderName === 'Root') return;
                
                const folderId = folderName.replace(/[^a-zA-Z0-9]/g, '_');
                html += `
                    <div class="start-menu-section">
                        <div class="start-menu-folder" onclick="toggleFolder('${folderId}')">
                            📁 ${folderName}
                            <span class="arrow">▶</span>
                        </div>
                        <div class="start-menu-apps" id="folder_${folderId}">
                `;
                
                folders[folderName].forEach(app => {
                    html += `
                        <div class="start-menu-app" 
                             onclick="openApp('${app.path}', '${app.name}')" 
                             oncontextmenu="showStartMenuItemContextMenu(event, '${app.path}', '${app.name}')"
                             onmouseenter="playSound('hover')">
                            <div class="start-menu-app-icon">${getAppIcon(app.type)}</div>
                            <div class="start-menu-app-info">
                                <div class="start-menu-app-name">${app.name}</div>
                                <div class="start-menu-app-details">${app.type.toUpperCase()} • ${formatBytes(app.size)}</div>
                            </div>
                        </div>
                    `;
                });
                
                html += `
                        </div>
                    </div>
                `;
            });

            startMenuContent.innerHTML = html;
        }

        // Generate desktop shortcuts
        function generateDesktopShortcuts() {
            const rootApps = apps.filter(app => app.folder === 'Root');
            const shortcuts = rootApps.slice(0, 10);
            let html = '';
            
            shortcuts.forEach((app, index) => {
                const x = 20 + (index % 5) * 100;
                const y = 20 + Math.floor(index / 5) * 110;
                
                html += `
                    <div class="desktop-shortcut" style="left: ${x}px; top: ${y}px;" 
                         ondblclick="openApp('${app.path}', '${app.name}')"
                         oncontextmenu="showDesktopIconContextMenu(event, '${app.path}', '${app.name}')"
                         onmouseenter="playSound('hover')">
                        <div class="desktop-shortcut-icon">${getAppIcon(app.type)}</div>
                        <div class="desktop-shortcut-name">${app.name}</div>
                    </div>
                `;
            });
            
            desktop.innerHTML = html;
        }

        // Show desktop icon context menu
        function showDesktopIconContextMenu(event, appPath, appName) {
            event.preventDefault();
            event.stopPropagation();
            
            playSound('rightclick');
            currentAppPath = appPath;
            currentAppName = appName;
            
            closeAllContextMenus();
            
            const menu = document.getElementById('desktopIconContextMenu');
            menu.style.left = event.clientX + 'px';
            menu.style.top = event.clientY + 'px';
            menu.style.display = 'block';
        }

        // Show start menu item context menu
        function showStartMenuItemContextMenu(event, appPath, appName) {
            event.preventDefault();
            event.stopPropagation();
            
            playSound('rightclick');
            currentAppPath = appPath;
            currentAppName = appName;
            
            closeAllContextMenus();
            
            const menu = document.getElementById('startMenuItemContextMenu');
            menu.style.left = event.clientX + 'px';
            menu.style.top = event.clientY + 'px';
            menu.style.display = 'block';
        }

        // Show start button context menu
        function showStartButtonContextMenu(event) {
            event.preventDefault();
            event.stopPropagation();
            
            playSound('rightclick');
            closeAllContextMenus();
            
            const menu = document.getElementById('startButtonContextMenu');
            menu.style.left = event.clientX + 'px';
            menu.style.top = event.clientY + 'px';
            menu.style.display = 'block';
        }

        // Toggle folder in start menu
        function toggleFolder(folderId) {
            playSound('click');
            const folder = document.getElementById(`folder_${folderId}`);
            const folderElement = document.querySelector(`[onclick="toggleFolder('${folderId}')"]`);
            
            if (folder.classList.contains('show')) {
                folder.classList.remove('show');
                folderElement.classList.remove('expanded');
            } else {
                folder.classList.add('show');
                folderElement.classList.add('expanded');
            }
        }

        // Open application in new window
        function openApp(appPath, appName) {
            playSound('open');
            const windowId = `window_${nextWindowId++}`;
            
            // Create window element
            const windowElement = document.createElement('div');
            windowElement.className = 'window show active';
            windowElement.id = windowId;
            windowElement.style.left = (50 + openWindows.length * 30) + 'px';
            windowElement.style.top = (50 + openWindows.length * 30) + 'px';
            windowElement.style.width = settings.defaultWidth + 'px';
            windowElement.style.height = settings.defaultHeight + 'px';
            
            windowElement.innerHTML = `
                <div class="window-titlebar" onmousedown="startDrag(event, '${windowId}')" oncontextmenu="showWindowContextMenu(event, '${appPath}', '${windowId}')">
                    <div class="window-title">${appName}</div>
                    <div class="window-controls">
                        <button class="window-control window-minimize" onclick="minimizeWindow('${windowId}')" onmouseenter="playSound('hover')">−</button>
                        <button class="window-control window-maximize" onclick="maximizeWindow('${windowId}')" onmouseenter="playSound('hover')">⬜</button>
                        <button class="window-control window-close" onclick="closeWindow('${windowId}')" onmouseenter="playSound('hover')">×</button>
                    </div>
                </div>
                <div class="window-content">
                    <iframe src="${appPath}" frameborder="0"></iframe>
                    <div class="resize-handle resize-handle-nw" onmousedown="startResize(event, '${windowId}', 'nw')"></div>
                    <div class="resize-handle resize-handle-n" onmousedown="startResize(event, '${windowId}', 'n')"></div>
                    <div class="resize-handle resize-handle-ne" onmousedown="startResize(event, '${windowId}', 'ne')"></div>
                    <div class="resize-handle resize-handle-w" onmousedown="startResize(event, '${windowId}', 'w')"></div>
                    <div class="resize-handle resize-handle-e" onmousedown="startResize(event, '${windowId}', 'e')"></div>
                    <div class="resize-handle resize-handle-sw" onmousedown="startResize(event, '${windowId}', 'sw')"></div>
                    <div class="resize-handle resize-handle-s" onmousedown="startResize(event, '${windowId}', 's')"></div>
                    <div class="resize-handle resize-handle-se" onmousedown="startResize(event, '${windowId}', 'se')"></div>
                </div>
            `;
            
            desktop.appendChild(windowElement);
            
            openWindows.push({
                id: windowId,
                name: appName,
                path: appPath,
                element: windowElement,
                minimized: false
            });
            
            setActiveWindow(windowId);
            updateTaskbar();
            startMenu.classList.remove('show');
            
            windowElement.addEventListener('mousedown', () => {
                setActiveWindow(windowId);
            });
        }

        // Show window context menu for right-click on titlebar
        function showWindowContextMenu(event, appPath, windowId) {
            event.preventDefault();
            event.stopPropagation();
            
            playSound('rightclick');
            currentWindowPath = appPath;
            currentWindowId = windowId;
            
            closeAllContextMenus();
            
            const menu = document.getElementById('windowContextMenu');
            menu.style.left = event.clientX + 'px';
            menu.style.top = event.clientY + 'px';
            menu.style.display = 'block';
        }

        // Resize functionality
        function startResize(event, windowId, handle) {
            event.preventDefault();
            event.stopPropagation();
            
            if (event.button !== 0) return;
            
            if (isResizing || isDragging) {
                stopResize();
                isDragging = false;
                dragWindow = null;
            }
            
            playSound('drag');
            isResizing = true;
            resizeWindow = document.getElementById(windowId);
            resizeHandle = handle;
            
            resizeStart = {
                mouseX: event.clientX,
                mouseY: event.clientY,
                windowWidth: resizeWindow.offsetWidth,
                windowHeight: resizeWindow.offsetHeight,
                windowLeft: parseInt(resizeWindow.style.left) || 0,
                windowTop: parseInt(resizeWindow.style.top) || 0
            };
            
            resizeWindow.style.outline = '3px solid #0078d4';
            document.body.style.cursor = getResizeCursor(handle);
            startMouseCheck();
            setActiveWindow(windowId);
        }

        function stopResize() {
            if (resizeWindow) {
                resizeWindow.style.outline = '';
            }
            document.body.style.cursor = '';
            stopMouseCheck();
            isResizing = false;
            resizeWindow = null;
            resizeHandle = null;
        }

        function getResizeCursor(handle) {
            const cursors = {
                'nw': 'nw-resize', 'n': 'n-resize', 'ne': 'ne-resize',
                'e': 'e-resize', 'se': 'se-resize', 's': 's-resize',
                'sw': 'sw-resize', 'w': 'w-resize'
            };
            return cursors[handle] || 'default';
        }

        // Window management functions
        function setActiveWindow(windowId) {
            playSound('click');
            document.querySelectorAll('.window').forEach(w => {
                w.classList.remove('active');
                const titlebar = w.querySelector('.window-titlebar');
                if (titlebar) {
                    titlebar.classList.remove('active');
                    titlebar.style.setProperty('background', 'linear-gradient(180deg, #f0f0f0, #e0e0e0)', 'important');
                    titlebar.style.setProperty('color', '#333', 'important');
                }
            });
            
            const window = document.getElementById(windowId);
            if (window) {
                window.classList.add('active');
                const titlebar = window.querySelector('.window-titlebar');
                if (titlebar) {
                    titlebar.classList.add('active');
                    
                    const windowHeadingDark = darkenColor(settings.windowHeadingColor, 15);
                    const windowTextColor = getContrastTextColor(settings.windowHeadingColor);
                    const bgGradient = `linear-gradient(180deg, ${settings.windowHeadingColor}, ${windowHeadingDark})`;
                    
                    titlebar.style.setProperty('background', bgGradient, 'important');
                    titlebar.style.setProperty('color', windowTextColor, 'important');
                }
                activeWindow = windowId;
            }
            
            updateTaskbar();
        }

        function closeWindow(windowId) {
            playSound('close');
            const window = document.getElementById(windowId);
            if (window) {
                window.remove();
            }
            
            openWindows = openWindows.filter(w => w.id !== windowId);
            updateTaskbar();
            
            if (activeWindow === windowId) {
                activeWindow = openWindows.length > 0 ? openWindows[openWindows.length - 1].id : null;
                if (activeWindow) {
                    setActiveWindow(activeWindow);
                }
            }
        }

        function minimizeWindow(windowId) {
            playSound('minimize');
            const window = document.getElementById(windowId);
            const windowData = openWindows.find(w => w.id === windowId);
            
            if (window && windowData) {
                if (windowData.minimized) {
                    window.classList.add('show');
                    windowData.minimized = false;
                    setActiveWindow(windowId);
                } else {
                    window.classList.remove('show');
                    windowData.minimized = true;
                }
                updateTaskbar();
            }
        }

        function maximizeWindow(windowId) {
            playSound('maximize');
            const window = document.getElementById(windowId);
            if (window) {
                if (window.style.width === '100vw') {
                    window.style.left = '50px';
                    window.style.top = '50px';
                    window.style.width = settings.defaultWidth + 'px';
                    window.style.height = settings.defaultHeight + 'px';
                } else {
                    window.style.left = '0';
                    window.style.top = '0';
                    window.style.width = '100vw';
                    window.style.height = 'calc(100vh - 48px)';
                }
            }
        }

        function updateTaskbar() {
            let html = '';
            openWindows.forEach(window => {
                const activeClass = window.id === activeWindow ? 'active' : '';
                const minimizedText = window.minimized ? ' (minimized)' : '';
                html += `
                    <div class="taskbar-app ${activeClass}" onclick="focusWindow('${window.id}')" onmouseenter="playSound('hover')">
                        ${window.name}${minimizedText}
                    </div>
                `;
            });
            taskbarApps.innerHTML = html;
        }

        function focusWindow(windowId) {
            playSound('click');
            const windowData = openWindows.find(w => w.id === windowId);
            if (windowData) {
                if (windowData.minimized) {
                    minimizeWindow(windowId);
                } else {
                    setActiveWindow(windowId);
                }
            }
        }

        // Drag functionality
        function startDrag(event, windowId) {
            if (isResizing) return;
            if (event.button !== 0) return;
            
            event.preventDefault();
            playSound('drag');
            isDragging = true;
            dragWindow = document.getElementById(windowId);
            
            const rect = dragWindow.getBoundingClientRect();
            dragOffset.x = event.clientX - rect.left;
            dragOffset.y = event.clientY - rect.top;
            
            setActiveWindow(windowId);
        }

        // Mouse move handler
        function handleMouseMove(event) {
            if (isDragging && dragWindow && !isResizing) {
                const x = event.clientX - dragOffset.x;
                const y = event.clientY - dragOffset.y;
                
                dragWindow.style.left = Math.max(0, Math.min(x, window.innerWidth - dragWindow.offsetWidth)) + 'px';
                dragWindow.style.top = Math.max(0, Math.min(y, window.innerHeight - 48 - dragWindow.offsetHeight)) + 'px';
            } 
            
            if (isResizing && resizeWindow) {
                const deltaX = event.clientX - resizeStart.mouseX;
                const deltaY = event.clientY - resizeStart.mouseY;
                
                let newWidth = resizeStart.windowWidth;
                let newHeight = resizeStart.windowHeight;
                let newLeft = resizeStart.windowLeft;
                let newTop = resizeStart.windowTop;
                
                if (resizeHandle === 'se') {
                    newWidth = Math.max(300, resizeStart.windowWidth + deltaX);
                    newHeight = Math.max(200, resizeStart.windowHeight + deltaY);
                } else if (resizeHandle === 's') {
                    newHeight = Math.max(200, resizeStart.windowHeight + deltaY);
                } else if (resizeHandle === 'e') {
                    newWidth = Math.max(300, resizeStart.windowWidth + deltaX);
                } else if (resizeHandle === 'sw') {
                    newWidth = Math.max(300, resizeStart.windowWidth - deltaX);
                    newHeight = Math.max(200, resizeStart.windowHeight + deltaY);
                    newLeft = resizeStart.windowLeft + (resizeStart.windowWidth - newWidth);
                } else if (resizeHandle === 'w') {
                    newWidth = Math.max(300, resizeStart.windowWidth - deltaX);
                    newLeft = resizeStart.windowLeft + (resizeStart.windowWidth - newWidth);
                } else if (resizeHandle === 'nw') {
                    newWidth = Math.max(300, resizeStart.windowWidth - deltaX);
                    newHeight = Math.max(200, resizeStart.windowHeight - deltaY);
                    newLeft = resizeStart.windowLeft + (resizeStart.windowWidth - newWidth);
                    newTop = resizeStart.windowTop + (resizeStart.windowHeight - newHeight);
                } else if (resizeHandle === 'n') {
                    newHeight = Math.max(200, resizeStart.windowHeight - deltaY);
                    newTop = resizeStart.windowTop + (resizeStart.windowHeight - newHeight);
                } else if (resizeHandle === 'ne') {
                    newWidth = Math.max(300, resizeStart.windowWidth + deltaX);
                    newHeight = Math.max(200, resizeStart.windowHeight - deltaY);
                    newTop = resizeStart.windowTop + (resizeStart.windowHeight - newHeight);
                }
                
                resizeWindow.style.width = newWidth + 'px';
                resizeWindow.style.height = newHeight + 'px';
                resizeWindow.style.left = newLeft + 'px';
                resizeWindow.style.top = newTop + 'px';
            }
        }

        function handleMouseUp(event) {
            if (isDragging) {
                isDragging = false;
                dragWindow = null;
            }
            if (isResizing) {
                stopResize();
            }
        }

        // Event listeners
        document.addEventListener('mousemove', handleMouseMove, { passive: true });
        document.addEventListener('mouseup', handleMouseUp);
        document.body.addEventListener('mouseup', handleMouseUp);
        window.addEventListener('mouseup', handleMouseUp);
        window.addEventListener('blur', handleMouseUp);
        
        let mouseCheckInterval = null;
        
        function startMouseCheck() {
            if (mouseCheckInterval) clearInterval(mouseCheckInterval);
            mouseCheckInterval = setInterval(() => {}, 100);
        }
        
        function stopMouseCheck() {
            if (mouseCheckInterval) {
                clearInterval(mouseCheckInterval);
                mouseCheckInterval = null;
            }
        }

        document.addEventListener('click', handleMouseUp);

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                handleMouseUp();
            }
        });

        document.addEventListener('contextmenu', (event) => {
            // Always prevent browser context menu
            event.preventDefault();
            
            if (isResizing || isDragging) {
                handleMouseUp();
                return;
            }
            
            // Don't handle context menu if we're already in a specific handler
            if (event.target.closest('.start-button') || 
                event.target.closest('.desktop-shortcut') || 
                event.target.closest('.start-menu-app') ||
                event.target.closest('.window-titlebar')) {
                return; // Let the specific handlers deal with these
            }
            
            // Check if right-clicking on desktop (but not on shortcuts or windows)
            if (event.target === desktop || (desktop.contains(event.target) && 
                !event.target.closest('.window') && 
                !event.target.closest('.desktop-shortcut'))) {
                
                playSound('rightclick');
                closeAllContextMenus();
                
                const menu = document.getElementById('contextMenu');
                menu.style.left = event.clientX + 'px';
                menu.style.top = event.clientY + 'px';
                menu.style.display = 'block';
            }
        });

        // UI event handlers
        startButton.addEventListener('click', (event) => {
            event.stopPropagation();
            playSound('click');
            closeAllContextMenus();
            startMenu.classList.toggle('show');
        });

        // Add context menu to start button
        startButton.addEventListener('contextmenu', showStartButtonContextMenu);

        settingsButton.addEventListener('click', (event) => {
            event.stopPropagation();
            closeAllContextMenus();
            showSettings();
        });

        // Add hover sounds to buttons
        startButton.addEventListener('mouseenter', () => playSound('hover'));
        settingsButton.addEventListener('mouseenter', () => playSound('hover'));

        document.addEventListener('click', (event) => {
            // Safely check if elements exist before calling contains()
            const startMenuEl = document.getElementById('startMenu');
            const startButtonEl = document.getElementById('startButton');
            const settingsPanelEl = document.getElementById('settingsPanel');
            const settingsButtonEl = document.getElementById('settingsButton');
            
            if (startMenuEl && startButtonEl && !startMenuEl.contains(event.target) && !startButtonEl.contains(event.target)) {
                startMenuEl.classList.remove('show');
            }
            if (settingsPanelEl && settingsButtonEl && !settingsPanelEl.contains(event.target) && !settingsButtonEl.contains(event.target)) {
                closeSettings();
            }
            
            // Close all context menus if clicking outside them - safely check elements
            const contextMenuEl = document.getElementById('contextMenu');
            const windowContextMenuEl = document.getElementById('windowContextMenu');
            const desktopIconContextMenuEl = document.getElementById('desktopIconContextMenu');
            const startMenuItemContextMenuEl = document.getElementById('startMenuItemContextMenu');
            const startButtonContextMenuEl = document.getElementById('startButtonContextMenu');
            
            if (contextMenuEl && windowContextMenuEl && desktopIconContextMenuEl && 
                startMenuItemContextMenuEl && startButtonContextMenuEl &&
                !contextMenuEl.contains(event.target) && 
                !windowContextMenuEl.contains(event.target) &&
                !desktopIconContextMenuEl.contains(event.target) &&
                !startMenuItemContextMenuEl.contains(event.target) &&
                !startButtonContextMenuEl.contains(event.target)) {
                closeAllContextMenus();
            }
            
            // Close dialog if clicking outside it - safely check elements
            const customDialogEl = document.getElementById('customDialog');
            const overlayEl = document.getElementById('overlay');
            
            if (customDialogEl && overlayEl && !customDialogEl.contains(event.target) && 
                overlayEl.classList.contains('show') &&
                customDialogEl.classList.contains('show')) {
                closeDialog();
            }
        });

        // Context menu handlers
        document.getElementById('contextRefresh').addEventListener('click', (event) => {
            event.stopPropagation();
            playSound('click');
            closeAllContextMenus();
            location.reload();
        });

        document.getElementById('contextReset').addEventListener('click', (event) => {
            event.stopPropagation();
            playSound('error');
            closeAllContextMenus();
            stopMouseCheck();
            stopResize();
            isDragging = false;
            dragWindow = null;
            document.body.style.cursor = '';
            
            document.querySelectorAll('.window').forEach(window => {
                window.style.outline = '';
            });
            
            applyColors();
            showDialog('Reset Complete', 'All window operations have been reset.');
        });

        document.getElementById('contextSettings').addEventListener('click', (event) => {
            event.stopPropagation();
            playSound('click');
            closeAllContextMenus();
            showSettings();
        });

        // Add Copy Desktop URL to main context menu
        document.getElementById('contextProperties').addEventListener('click', (event) => {
            event.stopPropagation();
            playSound('click');
            closeAllContextMenus();
            const desktopUrl = window.location.href;
            showUrlDialog(desktopUrl, 'Desktop URL');
        });

        // Window context menu handlers
        document.getElementById('copyUrl').addEventListener('click', (event) => {
            event.stopPropagation();
            playSound('click');
            closeAllContextMenus();
            if (currentWindowPath) {
                const fullUrl = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '/') + currentWindowPath;
                showUrlDialog(fullUrl, 'Application URL');
            }
        });

        document.getElementById('windowRefresh').addEventListener('click', (event) => {
            event.stopPropagation();
            playSound('click');
            closeAllContextMenus();
            if (currentWindowId) {
                const window = document.getElementById(currentWindowId);
                const iframe = window.querySelector('iframe');
                if (iframe) {
                    iframe.src = iframe.src;
                    showDialog('Window Refreshed', 'The application window has been refreshed.');
                }
            }
        });

        document.getElementById('windowClose').addEventListener('click', (event) => {
            event.stopPropagation();
            closeAllContextMenus();
            if (currentWindowId) {
                closeWindow(currentWindowId);
            }
        });

        // Desktop icon context menu handlers
        document.getElementById('openApp').addEventListener('click', (event) => {
            event.stopPropagation();
            playSound('click');
            closeAllContextMenus();
            if (currentAppPath && currentAppName) {
                openApp(currentAppPath, currentAppName);
            }
        });

        document.getElementById('copyAppUrl').addEventListener('click', (event) => {
            event.stopPropagation();
            playSound('click');
            closeAllContextMenus();
            if (currentAppPath) {
                const fullUrl = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '/') + currentAppPath;
                showUrlDialog(fullUrl, currentAppName + ' - Application URL');
            }
        });

        document.getElementById('appProperties').addEventListener('click', (event) => {
            event.stopPropagation();
            playSound('click');
            closeAllContextMenus();
            if (currentAppPath && currentAppName) {
                const app = apps.find(a => a.path === currentAppPath && a.name === currentAppName);
                if (app) {
                    const content = `
                        <div><strong>Name:</strong> ${app.name}</div>
                        <div><strong>Type:</strong> ${app.type.toUpperCase()}</div>
                        <div><strong>Size:</strong> ${formatBytes(app.size)}</div>
                        <div><strong>Path:</strong> ${app.path}</div>
                        <div><strong>Folder:</strong> ${app.folder}</div>
                        <div><strong>Modified:</strong> ${app.date}</div>
                    `;
                    showDialog(app.name + ' - Properties', content);
                }
            }
        });

        // Start menu item context menu handlers
        document.getElementById('openStartApp').addEventListener('click', (event) => {
            event.stopPropagation();
            playSound('click');
            closeAllContextMenus();
            if (currentAppPath && currentAppName) {
                openApp(currentAppPath, currentAppName);
            }
        });

        document.getElementById('copyStartAppUrl').addEventListener('click', (event) => {
            event.stopPropagation();
            playSound('click');
            closeAllContextMenus();
            if (currentAppPath) {
                const fullUrl = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '/') + currentAppPath;
                showUrlDialog(fullUrl, currentAppName + ' - Application URL');
            }
        });

        document.getElementById('startAppProperties').addEventListener('click', (event) => {
            event.stopPropagation();
            playSound('click');
            closeAllContextMenus();
            if (currentAppPath && currentAppName) {
                const app = apps.find(a => a.path === currentAppPath && a.name === currentAppName);
                if (app) {
                    const content = `
                        <div><strong>Name:</strong> ${app.name}</div>
                        <div><strong>Type:</strong> ${app.type.toUpperCase()}</div>
                        <div><strong>Size:</strong> ${formatBytes(app.size)}</div>
                        <div><strong>Path:</strong> ${app.path}</div>
                        <div><strong>Folder:</strong> ${app.folder}</div>
                        <div><strong>Modified:</strong> ${app.date}</div>
                    `;
                    showDialog(app.name + ' - Properties', content);
                }
            }
        });

        // Start button context menu handlers
        document.getElementById('openAllPrograms').addEventListener('click', (event) => {
            event.stopPropagation();
            playSound('click');
            closeAllContextMenus();
            startMenu.classList.add('show');
        });

        document.getElementById('openControlPanel').addEventListener('click', (event) => {
            event.stopPropagation();
            playSound('click');
            closeAllContextMenus();
            showSettings();
        });

        document.getElementById('copyDesktopUrl').addEventListener('click', (event) => {
            event.stopPropagation();
            playSound('click');
            closeAllContextMenus();
            const desktopUrl = window.location.href;
            showUrlDialog(desktopUrl, 'Desktop URL');
        });

        document.getElementById('refreshDesktop').addEventListener('click', (event) => {
            event.stopPropagation();
            playSound('click');
            closeAllContextMenus();
            location.reload();
        });

        // Add hover sounds to menu items
        document.querySelectorAll('.context-menu-item').forEach(item => {
            item.addEventListener('mouseenter', () => playSound('hover'));
        });

        // Add hover sounds to dialog buttons (dynamic content)
        document.addEventListener('mouseenter', (event) => {
            if (event.target.classList.contains('custom-dialog-button')) {
                playSound('hover');
            }
        }, true);

        // Add hover sounds to all new context menu items
        document.querySelectorAll('.start-menu-context-menu .context-menu-item, .start-button-context-menu .context-menu-item, .desktop-context-menu .context-menu-item').forEach(item => {
            item.addEventListener('mouseenter', () => playSound('hover'));
        });

        // Initialize
        function init() {
            loadSettings();
            setupColorHandlers();
            setupBackgroundHandlers();
            setupSoundHandlers();
            generateStartMenu();
            generateDesktopShortcuts();
            updateTime();
            setInterval(updateTime, 1000);
            
            // Set up one-time listeners for first user interaction to initialize audio
            const firstInteractionEvents = ['click', 'touchstart', 'keydown'];
            const initAudioOnce = () => {
                if (!audioInitialized && settings.soundEnabled) {
                    initializeAudio();
                    // Play startup sound after audio is initialized
                    setTimeout(() => {
                        playSound('startup');
                    }, 100);
                }
                // Remove listeners after first interaction
                firstInteractionEvents.forEach(event => {
                    document.removeEventListener(event, initAudioOnce);
                });
            };
            
            firstInteractionEvents.forEach(event => {
                document.addEventListener(event, initAudioOnce, { once: true });
            });
        }

        init();
    </script>
</body>
</html>