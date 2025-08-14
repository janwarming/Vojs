<?php

// Function to check for changelog keywords in the first N lines
function hasChangelog($content) {
    $keywords = ['changelog', 'release notes', 'version'];
    $lines = explode("\n", $content);
    $linesToCheck = array_slice($lines, 0, 30); // Check first 30 lines

    foreach ($linesToCheck as $line) {
        foreach ($keywords as $keyword) {
            if (stripos($line, $keyword) !== false) {
                return true;
            }
        }
    }
    return false;
}

// Get all files in the current directory
$files = scandir('.');

foreach ($files as $file) {
    // Regex to match filenames like 'name-123.ext'
    if (preg_match('/^(.*)-(\d+)\.(php|html|htm)$/i', $file, $matches)) {
        $baseName = $matches[1];
        $version = $matches[2];
        $extension = $matches[3];
        $newName = "{$baseName}.{$extension}";

        echo "Processing: {$file}\n";
        echo "  -> New name: {$newName}\n";

        // Read the original file content
        $content = file_get_contents($file);
        if ($content === false) {
            echo "  -> Error: Could not read file.\n";
            continue;
        }

        // Check for existing changelog
        if (hasChangelog($content)) {
            echo "  -> Changelog found. No changes to content needed.\n";
        } else {
            echo "  -> No changelog found. Adding one.\n";
            $date = date('Y-m-d');

            if (strtolower($extension) === 'php') {
                $changelogComment = "/*\n * Changelog:\n * - {$date} Initial version from filename version {$version}\n */\n";
                // Check if the file starts with <?php
                if (substr(ltrim($content), 0, 5) === '<?php') {
                    // Insert comment after the opening tag
                    $content = preg_replace('/<\?php\s*/', "<?php\n{$changelogComment}", $content, 1);
                } else {
                    // Prepend with tags
                    $content = "<?php\n{$changelogComment}?>\n" . $content;
                }
            } else { // html, htm
                $changelog = "<!--\nChangelog:\n- {$date} Initial version from filename version {$version}\n-->\n";
                $content = $changelog . $content;
            }
        }

        // Write the new file
        if (file_put_contents($newName, $content) === false) {
            echo "  -> Error: Could not write to new file {$newName}.\n";
            continue;
        } else {
            echo "  -> Successfully created {$newName}.\n";
        }

        // Delete the old file
        if (unlink($file)) {
            echo "  -> Successfully deleted old file {$file}.\n";
        } else {
            echo "  -> Error: Could not delete old file {$file}.\n";
        }

        echo "\n";
    }
}

echo "Version removal script finished.\n";

?>
