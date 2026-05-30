<?php
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('.'));
foreach ($files as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $content = file_get_contents($file->getRealPath());
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            echo "BOM found in " . $file->getRealPath() . "\n";
            // Remove BOM
            $content = substr($content, 3);
            file_put_contents($file->getRealPath(), $content);
            echo "-> BOM removed\n";
        }
        
        // Also check if there is whitespace before <?php at the very beginning of the file
        if (preg_match('/^\s+<\?php/', $content)) {
            echo "Leading whitespace found in " . $file->getRealPath() . "\n";
            $content = preg_replace('/^\s+<\?php/', '<?php', $content);
            file_put_contents($file->getRealPath(), $content);
            echo "-> Whitespace removed\n";
        }
    }
}
echo "Done.\n";
