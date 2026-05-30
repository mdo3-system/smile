<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require 'vendor/autoload.php';
require 'db_connect.php';
require 'estimate_pdf_generator.php';
echo "Starting...\n";
try {
    $pdf = generate_estimate_pdf(1, $pdo);
    echo "PDF generated at: $pdf\n";
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
