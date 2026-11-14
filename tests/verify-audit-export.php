<?php

require __DIR__.'/../vendor/autoload.php';
[$script, $file, $format, $plate, $total] = $argv;
if ($format === 'pdf') {
    $text = (new Smalot\PdfParser\Parser)->parseFile($file)->getText();
    if (! str_contains($text, $plate) || ! str_contains($text, $total)) throw new RuntimeException('Downloaded PDF differs from the confirmed payment.');
} else {
    $book = PhpOffice\PhpSpreadsheet\IOFactory::load($file);
    $cell = $book->getSheet(0)->getCell('B7');
    if ($cell->getDataType() !== 'n' || (float) $cell->getValue() !== (float) $total) throw new RuntimeException('Downloaded Excel total is not numeric or differs.');
    $found = false;
    foreach ($book->getAllSheets() as $sheet) foreach ($sheet->toArray() as $row) if (in_array($plate, $row, true)) $found = true;
    if (! $found) throw new RuntimeException('Downloaded Excel is missing the vehicle.');
    $book->disconnectWorksheets();
}
echo 'Downloaded '.$format.' content verified.'.PHP_EOL;
