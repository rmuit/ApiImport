<?php
// Import stock from a file with EAN - stock data.

$stock_file = '/home/users/klusxftp/voorraad/VRD791.csv';

require_once 'app/Mage.php';

Mage::init();
//? $api = Mage::getModel('api_import/import_api');
$api = new Danslo_ApiImport_Model_Import_Api();

// Get EAN - SKU map.

// We don't write now, but we'll need the write connection later anyway:
$conn = Mage::getSingleton('core/resource')->getConnection('write');
$attribute_id_ean = '292';
$skus = $conn->fetchPairs("SELECT e.value, p.sku FROM catalog_product_entity_varchar e INNER JOIN catalog_product_entity p ON e.entity_id=p.entity_id WHERE e.attribute_id=$attribute_id_ean AND e.store_id=0 AND e.value IS NOT NULL");

// Read file with EAN - stock data. Construct array with SKU - stock data.
$stock_data = array();
$fp = fopen($stock_file, 'r');
// No field names present.
while ($row = fgetcsv($fp, 0, ';')) {
  // Some lines do not have EAN. Do not give error.
  if (count($row) == 2 && !empty($row[0]) && is_numeric($row[1])
  // Some EANs are not in the shop. Actually, most of them (~62000 lines in
  // the file, ~7000 products in the shop.) Do not give an error.
      && isset($skus[$row[0]])) {
    // I don't know what negative stock means.
    $stock_data[] = array('sku' => $skus[$row[0]], 'qty' => $row[1] > 0 ? $row[1] : 0);
  }
}
fclose($fp);

$api->importEntities($stock_data, null, Danslo_ApiImport_Model_Import::BEHAVIOR_STOCK);
