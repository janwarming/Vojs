<?php
// Enhanced Command Line Interface v3.0
session_start();

// Security: Restrict to public_html directory only
$public_html = realpath($_SERVER['DOCUMENT_ROOT']);
if (!$public_html) {
    die('Error: Cannot determine public_html directory');
}

// Initialize session variables
if (!isset($_SESSION['current_dir'])) {
    $_SESSION['current_dir'] = $public_html;
}
if (!isset($_SESSION['prev_dir'])) {
    $_SESSION['prev_dir'] = $public_html;
}

// Ensure current directory is within public_html bounds
$current_real = realpath($_SESSION['current_dir']);
if (!$current_real || strpos($current_real, $public_html) !== 0) {
    $_SESSION['current_dir'] = $public_html;
}

// Initialize command history (session-only, no file persistence)
if (!isset($_SESSION['command_history'])) {
    $_SESSION['command_history'] = [];
}

// Built-in aliases (session-only)
$aliases = [
    'll' => 'dir -l',
    'cls' => 'clear', 
    'la' => 'dir -a',
    'home' => 'cd /',
    'back' => 'undo'
];

// Helper function to get files/directories for autocomplete
function getPathCompletions($base_dir, $partial_path = '') {
    $completions = [];
    $search_dir = $base_dir;
    
    if ($partial_path) {
        $path_parts = explode('/', $partial_path);
        $filename_part = array_pop($path_parts);
        
        if (!empty($path_parts)) {
            $subdir = implode('/', $path_parts);
            $test_dir = realpath($base_dir . DIRECTORY_SEPARATOR . $subdir);
            if ($test_dir && is_dir($test_dir)) {
                $search_dir = $test_dir;
            }
        }
    } else {
        $filename_part = '';
    }
    
    if (is_dir($search_dir)) {
        $files = scandir($search_dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            if (empty($filename_part) || stripos($file, $filename_part) === 0) {
                $relative_base = !empty($path_parts) ? implode('/', $path_parts) . '/' : '';
                $completions[] = $relative_base . $file;
            }
        }
    }
    
    return $completions;
}

// Handle AJAX requests for autocomplete
if (isset($_GET['autocomplete'])) {
    header('Content-Type: application/json');
    $command = $_GET['command'] ?? '';
    $current_dir = realpath($_SESSION['current_dir']);
    
    $parts = explode(' ', trim($command));
    $cmd = strtolower($parts[0] ?? '');
    $path_arg = $parts[1] ?? '';
    
    $completions = [];
    
    if (in_array($cmd, ['cat', 'info', 'edit'])) {
        // File completion for these commands
        $completions = getPathCompletions($current_dir, $path_arg);
    } elseif ($cmd === 'cd') {
        // Directory completion for cd
        $all_completions = getPathCompletions($current_dir, $path_arg);
        foreach ($all_completions as $completion) {
            $full_path = $current_dir . DIRECTORY_SEPARATOR . $completion;
            if (is_dir($full_path)) {
                $completions[] = $completion;
            }
        }
    } elseif (empty($cmd) || count($parts) === 1) {
        // Command or file execution completion
        $built_in_commands = ['cd', 'dir', 'ls', 'pwd', 'cat', 'info', 'clear', 'cls', 'help', 'undo', 'alias'];
        $file_completions = getPathCompletions($current_dir, $command);
        
        // Filter for executable files
        $executable_files = [];
        foreach ($file_completions as $file) {
            $full_path = $current_dir . DIRECTORY_SEPARATOR . $file;
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, ['php', 'html'])) {
                $executable_files[] = $file;
            }
        }
        
        $completions = array_merge(
            array_filter($built_in_commands, fn($cmd) => stripos($cmd, $command) === 0),
            $executable_files
        );
    }
    
    echo json_encode(array_values(array_unique($completions)));
    exit;
}

// Handle command execution
$output = '';
$clear_screen = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['command'])) {
    $command = trim($_POST['command']);
    $current_dir = realpath($_SESSION['current_dir']);
    
    // Add to history (session only)
    if ($command !== '' && (empty($_SESSION['command_history']) || end($_SESSION['command_history']) !== $command)) {
        $_SESSION['command_history'][] = $command;
        if (count($_SESSION['command_history']) > 100) {
            array_shift($_SESSION['command_history']);
        }
    }
    
    // Parse command
    $parts = array_filter(explode(' ', $command), 'strlen');
    if (empty($parts)) {
        // Empty command, just show prompt
    } else {
        $cmd = array_shift($parts);
        $args = $parts;
        
        // Resolve aliases
        $original_cmd = $cmd;
        if (isset($aliases[strtolower($cmd)])) {
            $alias_expansion = $aliases[strtolower($cmd)];
            $alias_parts = explode(' ', $alias_expansion);
            $cmd = array_shift($alias_parts);
            $args = array_merge($alias_parts, $args);
        }
        
        $cmd_lower = strtolower($cmd);
        
        // Execute commands
        switch ($cmd_lower) {
            case 'cd':
                $target = $args[0] ?? '';
                if ($target === '' || $target === '~' || $target === '/') {
                    $_SESSION['prev_dir'] = $current_dir;
                    $_SESSION['current_dir'] = $public_html;
                } elseif ($target === '..') {
                    $_SESSION['prev_dir'] = $current_dir;
                    $parent = dirname($current_dir);
                    if (strpos(realpath($parent), $public_html) === 0) {
                        $_SESSION['current_dir'] = $parent;
                    } else {
                        $output = "<span class='error'>Cannot navigate above public_html directory</span>";
                    }
                } elseif ($target === '-') {
                    $temp = $_SESSION['current_dir'];
                    $_SESSION['current_dir'] = $_SESSION['prev_dir'];
                    $_SESSION['prev_dir'] = $temp;
                } else {
                    $new_path = $current_dir . DIRECTORY_SEPARATOR . $target;
                    $real_path = realpath($new_path);
                    if ($real_path && is_dir($real_path) && strpos($real_path, $public_html) === 0) {
                        $_SESSION['prev_dir'] = $current_dir;
                        $_SESSION['current_dir'] = $real_path;
                    } else {
                        $output = "<span class='error'>Directory '$target' not found or inaccessible</span>";
                    }
                }
                break;
                
            case 'dir':
            case 'ls':
                $show_all = in_array('-a', $args);
                $long_format = in_array('-l', $args);
                
                $files = scandir($current_dir);
                $output = "<div class='file-list'>";
                
                foreach ($files as $file) {
                    if ($file === '.' || $file === '..') continue;
                    if (!$show_all && $file[0] === '.') continue;
                    
                    $file_path = $current_dir . DIRECTORY_SEPARATOR . $file;
                    $is_dir = is_dir($file_path);
                    $size = $is_dir ? '<DIR>' : number_format(filesize($file_path));
                    $modified = date('Y-m-d H:i', filemtime($file_path));
                    $permissions = substr(sprintf('%o', fileperms($file_path)), -3);
                    
                    $class = $is_dir ? 'dir' : 'file';
                    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    if (in_array($ext, ['php', 'html'])) {
                        $class .= ' executable';
                    }
                    
                    if ($long_format) {
                        $output .= sprintf(
                            "<span class='%s'>%s %8s %s %s</span>\n",
                            $class,
                            $permissions,
                            $size,
                            $modified,
                            $file
                        );
                    } else {
                        $output .= "<span class='$class'>$file</span>\n";
                    }
                }
                $output .= "</div>";
                break;
                
            case 'pwd':
                $relative = str_replace($public_html, '', $current_dir);
                $output = $relative ?: '/';
                break;
                
            case 'cat':
                if (empty($args)) {
                    $output = "<span class='error'>Usage: cat &lt;filename&gt;</span>";
                } else {
                    $file_path = $current_dir . DIRECTORY_SEPARATOR . $args[0];
                    $real_path = realpath($file_path);
                    if ($real_path && file_exists($real_path) && is_readable($real_path)) {
                        $ext = strtolower(pathinfo($real_path, PATHINFO_EXTENSION));
                        if (in_array($ext, ['txt', 'php', 'html', 'css', 'js', 'json', 'xml', 'md'])) {
                            $content = file_get_contents($real_path);
                            $output = "<pre class='file-content'>" . htmlspecialchars($content) . "</pre>";
                        } else {
                            $output = "<span class='error'>Cannot display binary file</span>";
                        }
                    } else {
                        $output = "<span class='error'>File '{$args[0]}' not found or not readable</span>";
                    }
                }
                break;
                
            case 'info':
                if (empty($args)) {
                    $output = "<span class='error'>Usage: info &lt;filename&gt;</span>";
                } else {
                    $file_path = $current_dir . DIRECTORY_SEPARATOR . $args[0];
                    $real_path = realpath($file_path);
                    if ($real_path && file_exists($real_path)) {
                        $stat = stat($real_path);
                        $type = is_dir($real_path) ? 'Directory' : 'File';
                        $size = is_dir($real_path) ? '-' : number_format($stat['size']) . ' bytes';
                        $perms = substr(sprintf('%o', $stat['mode']), -3);
                        $modified = date('Y-m-d H:i:s', $stat['mtime']);
                        $accessed = date('Y-m-d H:i:s', $stat['atime']);
                        
                        $output = "<div class='info'>";
                        $output .= "Name: {$args[0]}\n";
                        $output .= "Type: $type\n";
                        $output .= "Size: $size\n";
                        $output .= "Permissions: $perms\n";
                        $output .= "Modified: $modified\n";
                        $output .= "Accessed: $accessed\n";
                        $output .= "</div>";
                    } else {
                        $output = "<span class='error'>File '{$args[0]}' not found</span>";
                    }
                }
                break;
                
            case 'clear':
            case 'cls':
                $clear_screen = true;
                break;
                
            case 'undo':
            case 'back':
                $temp = $_SESSION['current_dir'];
                $_SESSION['current_dir'] = $_SESSION['prev_dir'];
                $_SESSION['prev_dir'] = $temp;
                $output = "Switched to: " . str_replace($public_html, '', $_SESSION['current_dir']) ?: '/';
                break;
                
            case 'alias':
                if (empty($args)) {
                    $output = "<div class='info'>Current aliases:\n";
                    foreach ($aliases as $alias => $command) {
                        $output .= "$alias => $command\n";
                    }
                    $output .= "</div>";
                } else {
                    $output = "<span class='error'>Aliases are session-only in this version</span>";
                }
                break;
                
            case 'help':
                $output = "<div class='help'>";
                $output .= "Enhanced Command Line Interface v3.0\n\n";
                $output .= "Available commands:\n";
                $output .= "  cd &lt;dir&gt;     - Change directory (.. for parent, / for root, - for previous)\n";
                $output .= "  dir/ls       - List files (-a for all, -l for detailed)\n";
                $output .= "  pwd          - Show current directory\n";
                $output .= "  cat &lt;file&gt;   - Display file contents\n";
                $output .= "  info &lt;file&gt;  - Show file information\n";
                $output .= "  clear/cls    - Clear screen\n";
                $output .= "  undo/back    - Return to previous directory\n";
                $output .= "  alias        - Show built-in aliases\n";
                $output .= "  &lt;file.php&gt;   - Execute PHP/HTML file\n";
                $output .= "  help         - Show this help\n\n";
                $output .= "Features:\n";
                $output .= "  • Tab completion for files and commands\n";
                $output .= "  • Arrow keys for command history\n";
                $output .= "  • Path autocomplete with /\n";
                $output .= "  • Restricted to public_html directory\n";
                $output .= "</div>";
                break;
                
            default:
                // Try to execute as file
                $file_path = $current_dir . DIRECTORY_SEPARATOR . $cmd;
                $real_path = realpath($file_path);
                
                if ($real_path && file_exists($real_path) && is_readable($real_path)) {
                    $ext = strtolower(pathinfo($real_path, PATHINFO_EXTENSION));
                    if (in_array($ext, ['php', 'html'])) {
                        $relative_path = str_replace($_SERVER['DOCUMENT_ROOT'], '', $real_path);
                        $url = '/' . ltrim($relative_path, '/');
                        header("Location: $url");
                        exit;
                    } else {
                        $output = "<span class='error'>Cannot execute file type: $ext</span>";
                    }
                } else {
                    $output = "<span class='error'>Command '$original_cmd' not found</span>";
                }
        }
    }
}

// Get current directory for display
$current_display = str_replace($public_html, '', realpath($_SESSION['current_dir'])) ?: '/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Command-Line Interface</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(135deg, #0c0c0c 0%, #1a1a1a 100%);
            color: #00ff00;
            font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
            height: 100vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        
        #header {
            background: rgba(0, 255, 0, 0.1);
            border-bottom: 1px solid #00ff00;
            padding: 10px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        #title {
            font-size: 18px;
            font-weight: bold;
        }
        
        #path-display {
            color: #00ccff;
            font-size: 14px;
        }
        
        #console-container {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            position: relative;
        }
        
        #console {
            white-space: pre-wrap;
            word-wrap: break-word;
            line-height: 1.4;
            min-height: calc(100vh - 140px);
        }
        
        #input-container {
            background: rgba(0, 0, 0, 0.8);
            border-top: 1px solid #00ff00;
            padding: 15px 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        #prompt {
            color: #00ff00;
            font-weight: bold;
            white-space: nowrap;
        }
        
        #command {
            flex: 1;
            background: transparent;
            color: #ffffff;
            border: none;
            font-family: inherit;
            font-size: 14px;
            outline: none;
            caret-color: #00ff00;
        }
        
        #autocomplete {
            position: absolute;
            bottom: 70px;
            left: 20px;
            right: 20px;
            background: rgba(0, 0, 0, 0.95);
            border: 1px solid #00ff00;
            max-height: 150px;
            overflow-y: auto;
            display: none;
            z-index: 1000;
        }
        
        .autocomplete-item {
            padding: 8px 15px;
            cursor: pointer;
            color: #cccccc;
        }
        
        .autocomplete-item:hover,
        .autocomplete-item.selected {
            background: rgba(0, 255, 0, 0.2);
            color: #ffffff;
        }
        
        .error { color: #ff4444; }
        .info { color: #00ccff; }
        .help { color: #ffff00; }
        .file-content { 
            color: #ffffff; 
            background: rgba(255, 255, 255, 0.05);
            padding: 10px;
            border-left: 3px solid #00ff00;
            margin: 10px 0;
        }
        
        .file-list .dir { 
            color: #00ccff; 
            font-weight: bold;
        }
        .file-list .file { 
            color: #ffffff; 
        }
        .file-list .executable { 
            color: #ffff00; 
        }
        
        /* Scrollbar styling */
        ::-webkit-scrollbar {
            width: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #1a1a1a;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #00ff00;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #00cc00;
        }
    </style>
</head>
<body>
    <div id="header">
        <div id="title">Command-Line Interface v3.0</div>
        <div id="path-display"><?php echo htmlspecialchars($current_display); ?></div>
    </div>
    
    <div id="console-container">
        <div id="console">
            <?php if ($clear_screen): ?>
                <script>document.getElementById('console').innerHTML = '';</script>
            <?php else: ?>
                <?php if (!isset($_POST['command'])): ?>
                    <span class='help'>Enhanced Command Line Interface v3.0
Type 'help' for available commands. Use Tab for autocomplete, arrows for history.</span>

                <?php endif; ?>
                <?php if (isset($output) && $output !== ''): ?>
<?php echo $output; ?>

                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <div id="autocomplete"></div>
    
    <form id="input-form" method="post" style="margin: 0;">
        <div id="input-container">
            <span id="prompt"><?php echo htmlspecialchars(basename($_SESSION['current_dir'])); ?>&gt;</span>
            <input type="text" id="command" name="command" autocomplete="off" spellcheck="false">
        </div>
    </form>

    <script>
        // Command history and autocomplete system
        let history = <?php echo json_encode($_SESSION['command_history']); ?>;
        let historyIndex = history.length;
        let autocompleteItems = [];
        let selectedIndex = -1;
        let currentCompletions = [];
        
        const commandInput = document.getElementById('command');
        const autocompleteDiv = document.getElementById('autocomplete');
        const consoleDiv = document.getElementById('console');
        
        // Focus input on load
        window.addEventListener('load', () => {
            commandInput.focus();
        });
        
        // Scroll console to bottom
        function scrollToBottom() {
            consoleDiv.scrollTop = consoleDiv.scrollHeight;
        }
        
        // Fetch autocomplete suggestions
        async function fetchCompletions(command) {
            try {
                const response = await fetch(`?autocomplete=1&command=${encodeURIComponent(command)}`);
                return await response.json();
            } catch (error) {
                console.error('Autocomplete error:', error);
                return [];
            }
        }
        
        // Show autocomplete dropdown
        function showAutocomplete(completions) {
            currentCompletions = completions;
            selectedIndex = -1;
            
            if (completions.length === 0) {
                hideAutocomplete();
                return;
            }
            
            autocompleteDiv.innerHTML = '';
            completions.forEach((item, index) => {
                const div = document.createElement('div');
                div.className = 'autocomplete-item';
                div.textContent = item;
                div.addEventListener('click', () => {
                    applyCompletion(item);
                });
                autocompleteDiv.appendChild(div);
            });
            
            autocompleteDiv.style.display = 'block';
        }
        
        // Hide autocomplete dropdown
        function hideAutocomplete() {
            autocompleteDiv.style.display = 'none';
            selectedIndex = -1;
        }
        
        // Apply selected completion
        function applyCompletion(completion) {
            const currentValue = commandInput.value;
            const parts = currentValue.split(' ');
            
            if (parts.length === 1) {
                // Command completion
                commandInput.value = completion + ' ';
            } else {
                // Argument completion
                parts[parts.length - 1] = completion;
                commandInput.value = parts.join(' ') + (completion.includes('.') ? '' : '/');
            }
            
            hideAutocomplete();
            commandInput.focus();
        }
        
        // Handle keyboard navigation in autocomplete
        function navigateAutocomplete(direction) {
            if (currentCompletions.length === 0) return;
            
            const items = autocompleteDiv.querySelectorAll('.autocomplete-item');
            
            // Remove previous selection
            if (selectedIndex >= 0 && items[selectedIndex]) {
                items[selectedIndex].classList.remove('selected');
            }
            
            // Update selected index
            if (direction === 'up') {
                selectedIndex = selectedIndex <= 0 ? currentCompletions.length - 1 : selectedIndex - 1;
            } else {
                selectedIndex = selectedIndex >= currentCompletions.length - 1 ? 0 : selectedIndex + 1;
            }
            
            // Apply new selection
            if (items[selectedIndex]) {
                items[selectedIndex].classList.add('selected');
                items[selectedIndex].scrollIntoView({ block: 'nearest' });
            }
        }
        
        // Debounce function for autocomplete
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }
        
        // Debounced autocomplete trigger
        const triggerAutocomplete = debounce(async (value) => {
            if (value.trim()) {
                const completions = await fetchCompletions(value);
                showAutocomplete(completions);
            } else {
                hideAutocomplete();
            }
        }, 150);
        
        // Input event handler
        commandInput.addEventListener('input', (e) => {
            triggerAutocomplete(e.target.value);
        });
        
        // Keyboard event handler
        commandInput.addEventListener('keydown', (e) => {
            switch (e.key) {
                case 'ArrowUp':
                    e.preventDefault();
                    if (autocompleteDiv.style.display === 'block') {
                        navigateAutocomplete('up');
                    } else if (historyIndex > 0) {
                        historyIndex--;
                        commandInput.value = history[historyIndex] || '';
                        hideAutocomplete();
                    }
                    break;
                    
                case 'ArrowDown':
                    e.preventDefault();
                    if (autocompleteDiv.style.display === 'block') {
                        navigateAutocomplete('down');
                    } else if (historyIndex < history.length) {
                        historyIndex++;
                        commandInput.value = historyIndex < history.length ? history[historyIndex] : '';
                        hideAutocomplete();
                    }
                    break;
                    
                case 'Tab':
                    e.preventDefault();
                    if (selectedIndex >= 0 && currentCompletions[selectedIndex]) {
                        applyCompletion(currentCompletions[selectedIndex]);
                    } else if (currentCompletions.length === 1) {
                        applyCompletion(currentCompletions[0]);
                    } else if (currentCompletions.length > 0) {
                        selectedIndex = 0;
                        autocompleteDiv.querySelector('.autocomplete-item').classList.add('selected');
                    }
                    break;
                    
                case 'Enter':
                    if (selectedIndex >= 0 && currentCompletions[selectedIndex]) {
                        e.preventDefault();
                        applyCompletion(currentCompletions[selectedIndex]);
                    } else {
                        hideAutocomplete();
                        // Allow form submission
                    }
                    break;
                    
                case 'Escape':
                    hideAutocomplete();
                    break;
            }
        });
        
        // Hide autocomplete when clicking outside
        document.addEventListener('click', (e) => {
            if (!autocompleteDiv.contains(e.target) && e.target !== commandInput) {
                hideAutocomplete();
            }
        });
        
        // Form submission handler
        document.getElementById('input-form').addEventListener('submit', (e) => {
            historyIndex = history.length;
            hideAutocomplete();
            
            // Add command to history immediately for smoother UX
            const command = commandInput.value.trim();
            if (command && (history.length === 0 || history[history.length - 1] !== command)) {
                history.push(command);
                historyIndex = history.length;
            }
            
            setTimeout(() => {
                commandInput.focus();
                scrollToBottom();
            }, 100);
        });
        
        // Initial scroll to bottom
        scrollToBottom();
    </script>
</body>
</html>