<?php
require __DIR__ . '/vendor/autoload.php';

if (class_exists(\Smalot\PdfParser\Parser::class)) {
    echo "PDFPARSER OK\n";
} else {
    echo "PDFPARSER ERROR\n";
}
