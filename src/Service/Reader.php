<?php
/*
 * Reader.php
 * © BELDJOUHRI Abdelghani 2016 <b4n92uid@gmail.com>
 */

namespace App\Service;

use PhpOffice\PhpSpreadsheet\IOFactory;


class Reader
{
  static function readCSV($filename)
  {
    $reader = IOFactory::createReader('Csv')
      ->setDelimiter(';')
      ->setEnclosure('"')
      ->setSheetIndex(0)
      ->setContiguous(true);

    $sheet = $reader->load($filename);
    $sheetData = $sheet->getActiveSheet()->toArray(null, true, true, false);

    $headers = $sheetData[0];

    // Skip header
    array_shift($sheetData);

    $data = [];

    foreach ($sheetData as $row) {

      $rowData = [];

      foreach ($row as $index => $value) {
        $rowData[$headers[$index]] = $value;
      }

      $data[] = $rowData;
    }

    return $data;
  }

  static function readExcel($filename)
  {
    $sheet = IOFactory::load($filename);
    $sheetData = $sheet->getActiveSheet()->toArray(null, false, false, true);

    $headers = $sheetData[1];

    // Skip header
    array_shift($sheetData);

    $data = array();
    foreach ($sheetData as $l) {

      if (empty($l))
        continue;

      $line = array();
      foreach ($headers as $hkey => $hname) {
        $line[$hname] = $l[$hkey];
      }

      $data[] = $line;
    }

    return $data;
  }

  static function readJson($filename)
  {
    return json_decode(file_get_contents($filename));
  }

  static function readDatabase($filename, $format = null)
  {
    if ($format === null)
      $format = pathinfo($filename, PATHINFO_EXTENSION);

    $call = array(
      'json' => 'readJson',
      'xlsx' => 'readExcel',
      'xls' => 'readExcel',
      'csv' => 'readCSV',
    );

    if (array_key_exists($format, $call))
      return call_user_func('App\Service\Reader::' . $call[$format], $filename);

    throw new Exception("readDatabase : File type not handled");
  }
}
