<?php
/*
 * Changelog:
 * - 2025-08-14 Initial version from filename version 103
 */
// Dynamic Directory Browser - Automatically scans directory
$currentDir = $_GET['dir'] ?? '.';
$currentDir = str_replace(['../', '..\\'], '', $currentDir); // Security: prevent directory traversal

function getFileInfo($path, $name) {
    $fullPath = $path . DIRECTORY_SEPARATOR . $name;
    $info = [
        'name' => $name,
        'type' => is_dir($fullPath) ? 'folder' : 'file',
        'size' => is_file($fullPath) ? filesize($fullPath) : 0,
        'date' => date('Y-m-d', filemtime($fullPath)),
        'extension' => is_file($fullPath) ? strtolower(pathinfo($name, PATHINFO_EXTENSION)) : 'folder'
    ];
    return $info;
}

function loadExcludedFiles($dir) {
    $excluded = [];
    $configFile = $dir . DIRECTORY_SEPARATOR . 'File-Explorer.php.cfg';

    if (file_exists($configFile) && is_readable($configFile)) {
        $content = file_get_contents($configFile);
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);
            // Skip empty lines and comments (lines starting with #)
            if (!empty($line) && substr($line, 0, 1) !== '#') {
                $excluded[] = $line;
            }
        }
    }

    return $excluded;
}

function scanDirectory($dir) {
    $files = [];
    if (!is_dir($dir) || !is_readable($dir)) {
        return $files;
    }

    // Load excluded files from config
    $excludedFiles = loadExcludedFiles($dir);

    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        if (substr($item, 0, 1) === '.') continue; // Skip hidden files

        // Skip files listed in File-Explorer.php.cfg
        if (in_array($item, $excludedFiles)) continue;

        $files[] = getFileInfo($dir, $item);
    }
    return $files;
}

$files = scanDirectory($currentDir);
$fileData = json_encode($files);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Directory Explorer - <?php echo htmlspecialchars(basename(realpath($currentDir))); ?></title>
    <style>
        :root {
            --primary-color: #2563eb;
            --primary-hover: #1d4ed8;
            --secondary-color: #64748b;
            --background: #f8fafc;
            --surface: #ffffff;
            --surface-hover: #f1f5f9;
            --border: #e2e8f0;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --text-muted: #94a3b8;
            --success: #10b981;
            --warning: #f59e0b;
            --error: #ef4444;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --radius: 0.5rem;
            --radius-lg: 0.75rem;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--background);
            color: var(--text-primary);
            line-height: 1.6;
            font-size: 14px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .header {
            background: var(--surface);
            border-radius: var(--radius-lg);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border);
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .title-icon {
            font-size: 2.5rem;
            background: linear-gradient(135deg, var(--primary-color), #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .breadcrumb {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 1rem;
        }

        .breadcrumb a {
            color: var(--primary-color);
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .controls {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .search-container {
            position: relative;
            flex: 1;
            min-width: 300px;
        }

        .search-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--surface);
            font-size: 0.875rem;
            transition: all 0.2s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgb(37 99 235 / 0.1);
        }

        .search-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 1rem;
        }

        .view-toggle {
            display: flex;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
        }

        .view-btn {
            padding: 0.5rem 0.75rem;
            border: none;
            background: transparent;
            cursor: pointer;
            transition: all 0.2s ease;
            color: var(--text-secondary);
        }

        .view-btn.active {
            background: var(--primary-color);
            color: white;
        }

        .view-btn:hover:not(.active) {
            background: var(--surface-hover);
        }

        .refresh-btn {
            padding: 0.5rem 1rem;
            border: 1px solid var(--border);
            background: var(--surface);
            border-radius: var(--radius);
            cursor: pointer;
            transition: all 0.2s ease;
            color: var(--text-secondary);
            font-size: 0.875rem;
        }

        .refresh-btn:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .stats {
            display: flex;
            gap: 2rem;
            color: var(--text-secondary);
            font-size: 0.875rem;
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .main-content {
            background: var(--surface);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border);
            overflow: hidden;
        }

        .toolbar {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border);
            background: var(--surface);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .sort-controls {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .sort-btn {
            padding: 0.5rem;
            border: 1px solid var(--border);
            background: var(--surface);
            border-radius: var(--radius);
            cursor: pointer;
            transition: all 0.2s ease;
            color: var(--text-secondary);
        }

        .sort-btn:hover {
            background: var(--surface-hover);
            border-color: var(--primary-color);
        }

        .sort-btn.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .file-list {
            min-height: 400px;
        }

        .file-item {
            display: grid;
            grid-template-columns: auto 1fr auto auto auto;
            gap: 1rem;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border);
            transition: all 0.2s ease;
            align-items: center;
        }

        .file-item:hover {
            background: var(--surface-hover);
        }

        .file-item:last-child {
            border-bottom: none;
        }

        .file-icon {
            font-size: 1.5rem;
            width: 2rem;
            text-align: center;
        }

        .file-info {
            min-width: 0;
        }

        .file-name {
            font-weight: 500;
            color: var(--text-primary);
            text-decoration: none;
            word-break: break-all;
            transition: color 0.2s ease;
        }

        .file-name:hover {
            color: var(--primary-color);
        }

        .file-path {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }

        .file-size {
            color: var(--text-secondary);
            font-size: 0.875rem;
            font-variant-numeric: tabular-nums;
        }

        .file-date {
            color: var(--text-secondary);
            font-size: 0.875rem;
            font-variant-numeric: tabular-nums;
        }

        .file-type {
            padding: 0.25rem 0.5rem;
            background: var(--surface-hover);
            border-radius: var(--radius);
            font-size: 0.75rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            font-weight: 500;
        }

        .file-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
            padding: 1.5rem;
        }

        .file-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1rem;
            transition: all 0.2s ease;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
        }

        .file-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary-color);
        }

        .file-card-icon {
            font-size: 2.5rem;
            text-align: center;
            margin-bottom: 0.75rem;
        }

        .file-card-name {
            font-weight: 500;
            color: var(--text-primary);
            word-break: break-all;
            margin-bottom: 0.5rem;
            display: block;
        }

        .file-card-meta {
            font-size: 0.75rem;
            color: var(--text-muted);
            display: flex;
            justify-content: space-between;
        }

        .folder-item .file-icon {
            color: #d97706;
        }

        .hidden {
            display: none !important;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--text-muted);
        }

        .empty-state-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .header-top {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch;
            }

            .controls {
                justify-content: stretch;
            }

            .search-container {
                min-width: auto;
            }

            .file-item {
                grid-template-columns: auto 1fr;
                gap: 0.75rem;
            }

            .file-size,
            .file-date,
            .file-type {
                display: none;
            }

            .file-grid {
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
                gap: 0.75rem;
                padding: 1rem;
            }

            .stats {
                flex-direction: column;
                gap: 0.5rem;
            }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .file-item,
        .file-card {
            animation: fadeIn 0.3s ease forwards;
        }

        ::-webkit-scrollbar {
            width: 6px;
        }

        ::-webkit-scrollbar-track {
            background: var(--background);
        }

        ::-webkit-scrollbar-thumb {
            background: var(--border);
            border-radius: 3px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--text-muted);
        }
    </style>
</head>
<body>
    <div class="container">
        <header class="header">
            <div class="breadcrumb">
                <a href="?dir=.">🏠 Home</a>
                <?php
                if ($currentDir !== '.') {
                    $pathParts = explode(DIRECTORY_SEPARATOR, trim($currentDir, DIRECTORY_SEPARATOR));
                    $breadcrumbPath = '.';
                    echo ' / ';
                    foreach ($pathParts as $i => $part) {
                        if ($i > 0) $breadcrumbPath .= DIRECTORY_SEPARATOR;
                        $breadcrumbPath .= $part;
                        if ($i === count($pathParts) - 1) {
                            echo htmlspecialchars($part);
                        } else {
                            echo '<a href="?dir=' . urlencode($breadcrumbPath) . '">' . htmlspecialchars($part) . '</a> / ';
                        }
                    }
                }
                ?>
            </div>

            <div class="header-top">
                <h1 class="title">
                    <span class="title-icon">📁</span>
                    Directory Explorer
                </h1>
                <div style="display: flex; gap: 1rem; align-items: center;">
                    <button class="refresh-btn" onclick="location.reload()">
                        🔄 Refresh
                    </button>
                    <div class="view-toggle">
                        <button class="view-btn active" data-view="list">
                            <span>📋</span>
                        </button>
                        <button class="view-btn" data-view="grid">
                            <span>⊞</span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="controls">
                <div class="search-container">
                    <span class="search-icon">🔍</span>
                    <input type="text" class="search-input" placeholder="Search files and folders..." id="searchInput">
                </div>
            </div>

            <div class="stats">
                <div class="stat-item">
                    <span>📂</span>
                    <span id="folderCount">0 folders</span>
                </div>
                <div class="stat-item">
                    <span>📄</span>
                    <span id="fileCount">0 files</span>
                </div>
                <div class="stat-item">
                    <span>💾</span>
                    <span id="totalSize">0 B</span>
                </div>
                <div class="stat-item">
                    <span>🕒</span>
                    <span>Last updated: <?php echo date('M j, Y H:i'); ?></span>
                </div>
            </div>
        </header>

        <main class="main-content">
            <div class="toolbar">
                <div class="sort-controls">
                    <span style="color: var(--text-secondary); margin-right: 0.5rem;">Sort by:</span>
                    <button class="sort-btn active" data-sort="name">Name</button>
                    <button class="sort-btn" data-sort="size">Size</button>
                    <button class="sort-btn" data-sort="date">Date</button>
                    <button class="sort-btn" data-sort="type">Type</button>
                </div>
                <div style="color: var(--text-secondary); font-size: 0.875rem;" id="resultCount">
                    <?php echo count($files); ?> items
                </div>
            </div>

            <div id="fileContainer">
                <!-- Files will be populated by JavaScript -->
            </div>
        </main>
    </div>

    <script>
        // File data from PHP
        const files = <?php echo $fileData; ?>;
        const currentDirectory = <?php echo json_encode($currentDir); ?>;

        // Application state
        let currentView = 'list';
        let currentSort = 'name';
        let currentFiles = [...files];

        // DOM elements
        const searchInput = document.getElementById('searchInput');
        const fileContainer = document.getElementById('fileContainer');
        const folderCount = document.getElementById('folderCount');
        const fileCount = document.getElementById('fileCount');
        const totalSize = document.getElementById('totalSize');
        const resultCount = document.getElementById('resultCount');

        // Utility functions
        function formatBytes(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
        }

        function formatDate(dateStr) {
            const date = new Date(dateStr);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        }

        function getFileIcon(file) {
            if (file.type === 'folder') return '📁';

            const iconMap = {
                'pdf': '📄',
                'doc': '📝', 'docx': '📝',
                'xls': '📊', 'xlsx': '📊',
                'ppt': '📊', 'pptx': '📊',
                'txt': '📄',
                'jpg': '🖼️', 'jpeg': '🖼️', 'png': '🖼️', 'gif': '🖼️', 'svg': '🖼️',
                'mp4': '🎬', 'avi': '🎬', 'mov': '🎬', 'mkv': '🎬',
                'mp3': '🎵', 'wav': '🎵', 'flac': '🎵',
                'zip': '📦', 'rar': '📦', '7z': '📦',
                'html': '🌐', 'htm': '🌐',
                'js': '⚙️', 'css': '🎨', 'json': '⚙️',
                'py': '🐍', 'java': '☕', 'cpp': '⚙️', 'c': '⚙️'
            };

            return iconMap[file.extension] || '📄';
        }

        function getFileTypeLabel(file) {
            if (file.type === 'folder') return 'Folder';
            return file.extension.toUpperCase();
        }

        function getFileUrl(file) {
            if (file.type === 'folder') {
                const newDir = currentDirectory === '.' ? file.name : currentDirectory + '/' + file.name;
                return `?dir=${encodeURIComponent(newDir)}`;
            }
            return currentDirectory === '.' ? file.name : currentDirectory + '/' + file.name;
        }

        // Sorting functions
        function sortFiles(files, sortBy) {
            return [...files].sort((a, b) => {
                // Always put folders first
                if (a.type !== b.type) {
                    return a.type === 'folder' ? -1 : 1;
                }

                switch (sortBy) {
                    case 'name':
                        return a.name.localeCompare(b.name);
                    case 'size':
                        return b.size - a.size;
                    case 'date':
                        return new Date(b.date) - new Date(a.date);
                    case 'type':
                        return a.extension.localeCompare(b.extension);
                    default:
                        return 0;
                }
            });
        }

        // Search function
        function filterFiles(files, searchTerm) {
            if (!searchTerm) return files;
            return files.filter(file =>
                file.name.toLowerCase().includes(searchTerm.toLowerCase())
            );
        }

        // Render functions
        function renderListView(files) {
            if (files.length === 0) {
                return `
                    <div class="empty-state">
                        <div class="empty-state-icon">🔍</div>
                        <div>No files found matching your search</div>
                    </div>
                `;
            }

            return `
                <div class="file-list">
                    ${files.map(file => `
                        <div class="file-item ${file.type === 'folder' ? 'folder-item' : ''}">
                            <div class="file-icon">${getFileIcon(file)}</div>
                            <div class="file-info">
                                <a href="${getFileUrl(file)}" class="file-name">
                                    ${file.name}
                                </a>
                                ${file.type === 'folder' ? '<div class="file-path">Folder</div>' : ''}
                            </div>
                            <div class="file-type">${getFileTypeLabel(file)}</div>
                            <div class="file-size">${file.size > 0 ? formatBytes(file.size) : '-'}</div>
                            <div class="file-date">${formatDate(file.date)}</div>
                        </div>
                    `).join('')}
                </div>
            `;
        }

        function renderGridView(files) {
            if (files.length === 0) {
                return `
                    <div class="empty-state">
                        <div class="empty-state-icon">🔍</div>
                        <div>No files found matching your search</div>
                    </div>
                `;
            }

            return `
                <div class="file-grid">
                    ${files.map(file => `
                        <a href="${getFileUrl(file)}" class="file-card ${file.type === 'folder' ? 'folder-item' : ''}">
                            <div class="file-card-icon">${getFileIcon(file)}</div>
                            <div class="file-card-name">${file.name}</div>
                            <div class="file-card-meta">
                                <span>${file.size > 0 ? formatBytes(file.size) : 'Folder'}</span>
                                <span>${formatDate(file.date)}</span>
                            </div>
                        </a>
                    `).join('')}
                </div>
            `;
        }

        function updateStats(files) {
            const folders = files.filter(f => f.type === 'folder').length;
            const filesList = files.filter(f => f.type === 'file');
            const totalBytes = filesList.reduce((sum, f) => sum + f.size, 0);

            folderCount.textContent = `${folders} folder${folders !== 1 ? 's' : ''}`;
            fileCount.textContent = `${filesList.length} file${filesList.length !== 1 ? 's' : ''}`;
            totalSize.textContent = formatBytes(totalBytes);
            resultCount.textContent = `Showing ${files.length} item${files.length !== 1 ? 's' : ''}`;
        }

        function render() {
            const searchTerm = searchInput.value;
            const filteredFiles = filterFiles(files, searchTerm);
            const sortedFiles = sortFiles(filteredFiles, currentSort);

            currentFiles = sortedFiles;

            if (currentView === 'list') {
                fileContainer.innerHTML = renderListView(sortedFiles);
            } else {
                fileContainer.innerHTML = renderGridView(sortedFiles);
            }

            updateStats(sortedFiles);
        }

        // Event listeners
        searchInput.addEventListener('input', render);

        // View toggle
        document.querySelectorAll('.view-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.view-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                currentView = btn.dataset.view;
                render();
            });
        });

        // Sort buttons
        document.querySelectorAll('.sort-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.sort-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                currentSort = btn.dataset.sort;
                render();
            });
        });

        // Initial render
        render();
    </script>
</body>
</html>