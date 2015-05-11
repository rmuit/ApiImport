<?php
// RM 201412 - the below input files were created through a test-export.php
// script I wrote myself, which operates on an ancient Magento site (megatool.nl)
// because sets/groups/attrs/etc cannot be exported by default.
//
// This script imports those files, and other CSV files, with the help of
// Danslo/ApiImport from github.
// Should be placed and run in new site (kluscenter.nl) root.

$sets_file = '/Volumes/SSD2/www/megatool.nl/var-export.sets.php';
$groups_file = '/Volumes/SSD2/www/megatool.nl/var-export.groups.php';
$attrs_file = '/Volumes/SSD2/www/megatool.nl/var-export.attributes.php';
$options_file = '/Volumes/SSD2/www/megatool.nl/var-export.options.php';
$assoc_file = '/Volumes/SSD2/www/megatool.nl/var-export.assoc.php';

// These are 38 rows, no quotes around fieldnames, I don't remember where they came from anymore
//$products_file = '/Volumes/SSD2/www/megatool.nl/catalog_product_20141217_160405.csv';

// This is a profile export from megatool ("Profile" being the old pre-1.? way of doing an export)
$products_file = '/Volumes/SSD2/www/kluscenter.nl/export-megatool.nl-20141218-profiel.csv';
$stock_file = '/Volumes/SSD2/www/kluscenter.nl/voorraad/VRD791.csv';

// Trying with one row:
//$products_file = '/Users/roderik/tmp/tmp.csv';
$products_file_is_old_format = TRUE;

$import_sets = FALSE;
$import_attributes = FALSE;
$import_associations = FALSE;
$import_products = FALSE;
$import_stock = TRUE;

require_once 'app/Mage.php';

Mage::init();

//? $api = Mage::getModel('api_import/import_api');
$api = new Danslo_ApiImport_Model_Import_Api();

// Import sets and groups
if ($import_sets) {
  eval('$sets = ' . file_get_contents($sets_file) . ';');
  eval('$groups = ' . file_get_contents($groups_file) . ';');

  // for no good reason(?), the import decided to name it sort order 'sortOrder'
  foreach ($sets as &$set) {
    $set['sortOrder'] = $set['sort_order'];
    unset($set['sort_order']);
  }
  // @todo there still is an error

  // Insert groups into sets array, in the way the importer expects them.
  // (Yes, in principle group names could overwrite other properties...)
  foreach ($groups as $group) {
    $sets[$group['attribute_set_id']][$group['attribute_group_name']] =
      $group['sort_order'];
  }

//  // If you want to prune groups, take care to never prune Default.
//  // The importer does not have a good way for this. You have to
//  $sets_dummy = $sets;
//  $sets_dummy[] = array(
//    'attribute_set_name' => 'Default',
//    @todo insert all groups for Default in THIS website here.
//  );
//  $success = $api->importAttributeSets($sets_dummy, Danslo_ApiImport_Model_Import::BEHAVIOR_DELETE_IF_NOT_EXIST);

  $success = $api->importAttributeSets($sets);
  // @todo there still is an error with sortOrder being imported as group name!? test.

  unset($sets);
  unset($groups);
}

// Import attributes with option values
if ($import_attributes) {
  eval('$attrs = ' . file_get_contents($attrs_file) . ';');
  eval('$options = ' . file_get_contents($options_file) . ';');

  // Insert options/values into attributes array, in the way the importer =>
  // Mage_Eav_Model_Entity_Setup::addAttribute() expects them.
  //
  // We have the field names in the table. We need to convert those to array
  // keys which addAttribute() expects (so that it will convert them back to
  // the same field names)...
  $map = array(
    'backend_model' => 'backend',
    'backend_type' => 'type',
    'backend_table' => 'table',
    'frontend_model' => 'frontend',
    'frontend_input' => 'input',
    'frontend_label' => 'label',
    //'frontend_class' => 'frontend_class', // don't do equal values
    'source_model' => 'source',
    'is_required' => 'required',
    //'is_user_defined' => 'user_defined', // does not exist in our array
    'default_value' => 'default',
    'is_unique' => 'unique',
    //'note' => 'note', // don't do equal values

    'is_global' => 'global', // is only a field in the catalog table, though it's
                             // referenced in Mage_Eav_Model_Entity_Setup too...

    'frontend_input_renderer'       =>'input_renderer',
    'is_visible'                    =>'visible',
    'is_searchable'                 =>'searchable',
    'is_filterable'                 =>'filterable',
    'is_comparable'                 =>'comparable',
    'is_visible_on_front'           =>'visible_on_front',
    'is_wysiwyg_enabled'            =>'wysiwyg_enabled',
    //'is_html_allowed_on_front'      =>'is_html_allowed_on_front', // don't do equal values
    'is_visible_in_advanced_search' =>'visible_in_advanced_search',
    'is_filterable_in_search'       =>'filterable_in_search',
    //'used_in_product_listing'       =>'used_in_product_listing', // don't do equal values
    //'used_for_sort_by'              =>'used_for_sort_by', // don't do equal values
    //'apply_to'                      =>'apply_to', // don't do equal values
    //'position'                      =>'position', // don't do equal values
    //'is_configurable'               =>'is_configurable', // don't do equal values
    'is_used_for_promo_rules'       =>'used_for_promo_rules',
    // catalog_eav_attribute fields which are apparently not possible to import
    // because not referenced in Mage_Catalog_Model_Resource_Setup:
    // is_used_for_price_rules, search_weight.
  );
  foreach ($attrs as &$attr) {
    foreach ($map as $field => $key) {
      // This should always be true...
      if (isset($attr[$field])) {
        $attr[$key] = $attr[$field];
        // We don't need to delete $field; it will be ignored. But just to be sure
        // for future versions:
        unset($attr[$field]);
      }
    }
    // These are all user defined attributes
    $attr['user_defined'] = 1;
    // In our import data, attribute codes are sometimes too long, which will make import bork.
    if (strlen($attr['attribute_code']) > Mage_Eav_Model_Entity_Attribute::ATTRIBUTE_CODE_MAX_LENGTH) {
      $attr['attribute_code'] = substr($attr['attribute_code'], 0, Mage_Eav_Model_Entity_Attribute::ATTRIBUTE_CODE_MAX_LENGTH);
    }
    // For some reason, the import wants the 'attribute_code' not only as
    // 'attribute_code' (which is necessary for db-inserting), but also as
    // 'attribute_id' (which it uses for comparison)
    $attr['attribute_id'] = $attr['attribute_code'];
  }
  // - addAttribute() can also deal with 'group' to add a group to ALL sets, and
  //   then add the attribute to all those groups - but that's not for us.
  // - addAttribute() can add one option with multiple values (per store id) as
  //   'value', or multiple options with only one value (for store id 0). We do
  //   the latter; see our tuned export where we only exported store id 0.
  //   (It cannot add multiple options with multiple values.)
  foreach ($options as $option) {
    while (isset($attrs[$option['attribute_id']]['option']['values'][$option['sort_order']])) {
      if (is_numeric($option['sort_order'])) {
        // There can be several values with the same order, but the import api
        // does not let us import those. So increase values. (This only goes well
        // if we process the options in ascending order of $option['sort_order'],
        // otherwise they can get mixed up.)
        $option['sort_order']++;
      }
      else {
        // Non-numeric sort_order should never be, anyway.
        echo "Skipping option value {$option['value']} for attribute {$option['attribute_id']}\n";
        break;
      }
    }
    $attrs[$option['attribute_id']]['option']['values'][$option['sort_order']] =
      $option['value'];
  }

  // First delete the user defined attributes that exist in this db but shouldn't.
  $attrs_dummy = $attrs;
  $attrs_dummy[] = array('attribute_id' => 'ebizmarts_mark_visited');
  $attrs_dummy[] = array('attribute_id' => 'eta');
  $attrs_dummy[] = array('attribute_id' => 'delivery_info');
  $success = $api->importAttributes($attrs_dummy, Danslo_ApiImport_Model_Import::BEHAVIOR_DELETE_IF_NOT_EXIST);
  // Import.
  $success = $api->importAttributes($attrs);

  unset($attrs);
  unset($options);
}


// Associations
if ($import_associations) {
  eval('$assocs = ' . file_get_contents($assoc_file) . ';');

  $all_sets = array();

  foreach ($assocs as &$assoc) {
    // Remember sets with highest order.
    $set = $assoc['attribute_set_name'];
    if (!isset($all_sets[$set]) || $all_sets[$set] < $assoc['sort_order']) {
      $all_sets[$set] = $assoc['sort_order'];
    }

    // We have the names, but the import wants them as 'id' keys.
    // (I can see how they came to that reasoning, though IMHO it's flawed)

    // In our import data, attribute codes are sometimes too long, which will make import bork.
    if (strlen($assoc['attribute_code']) > Mage_Eav_Model_Entity_Attribute::ATTRIBUTE_CODE_MAX_LENGTH) {
      $assoc['attribute_code'] = substr($assoc['attribute_code'], 0, Mage_Eav_Model_Entity_Attribute::ATTRIBUTE_CODE_MAX_LENGTH);
    }
    $assoc['attribute_id'] = $assoc['attribute_code'];
    unset($assoc['attribute_code']);

    $assoc['attribute_set_id'] = $assoc['attribute_set_name'];
    unset($assoc['attribute_set_name']);

    $assoc['attribute_group_id'] = $assoc['attribute_group_name'];
    unset($assoc['attribute_group_name']);
  }

  // First delete the user defined associations that exist in this db but shouldn't.
  $success = $api->importAttributeAssociations($assocs, Danslo_ApiImport_Model_Import::BEHAVIOR_DELETE_IF_NOT_EXIST);

  // Then make sure that we also create associations for the following
  // attributes which are all _not_ in the Megatool site (=> not in $assocs):
  foreach ($all_sets as $set => $order) {
    foreach (array(
               'hide_default_stock_status',
               'custom_stock_status',
               'custom_stock_status_qty_based',
               'custom_stock_status_qty_rule',
               'eta',
               'custom_design',
               'custom_design_from',
               'custom_design_to',
               'custom_layout_update',
             ) as $attr) {

      $assocs[] = array(
        'attribute_id' => $attr,
        'attribute_set_id' => $set,
        'attribute_group_id' => 'General', // 'Default' seems to work too.
        'sort_order' => ++$order,
      );
    }
  }
  // Import.
  $success = $api->importAttributeAssociations($assocs);
}


// Products

if ($import_products) {

  $data = array();
  $fp = fopen($products_file, 'r');
  $fieldnames = fgetcsv($fp);

  // Change some standard properties (from old 'Profile' export) to add an
  // underscore (needed by class Mage_ImportExport_Model_Import_Entity_Product,
  // defined as constants there)
  // Note: in my import there is no 'category' or 'root_category', this is just here for completeness
  if (!empty($products_file_is_old_format)) {
    foreach (array('store', 'attribute_set', 'type', 'category', 'root_category')
             as $column) {
      if (($key = array_search($column, $fieldnames, TRUE)) !== FALSE) {
        $fieldnames[$key] = "_$column";
      }
    }
    foreach (array(
               'websites' => '_product_websites'
             ) as $column => $new_column) {
      if (($key = array_search($column, $fieldnames, TRUE)) !== FALSE) {
        $fieldnames[$key] = $new_column;
      }
    }
  }

  // Add extra columns
  $add_extra = FALSE;
  if (array_search('use_config_gift_message_available', $fieldnames, TRUE) !== FALSE) {
    $fieldnames[] = 'use_config_gift_message_available';
  }


  while ($product = fgetcsv($fp)) {

    $product = array_combine($fieldnames, $product);

    // hide_default_stock_status/custom_stock_status_qty_based have valid values
    // Ja/Nee; others have valid values Yes/No.
    foreach (array('is_recurring', 'v_groef', 'parkeersteun', 'spaanafvoer', 'waterpasfunctie', 'batterijen_meegeleverd', 'statiefschroefdraad') as $column) {
      if ($product[$column] === 'Ja') {
        $product[$column] = 'Yes';
      }
      elseif ($product[$column] === 'Nee') {
        $product[$column] = 'No';
      }
    }

    // First:
    if ($product['gift_message_available'] === 'Gebruik configuratie') {
      $product['use_config_gift_message_available'] = 1;
    }
    elseif ($add_extra) {
      // We need to add this column
      $product['use_config_gift_message_available'] = 0;
    }

    // More:
    // page_layout - moet zijn:
    //  category_listing
    //  empty
    //  1 column
    //  2 columns with left bar
    //  2 columns with right bar
    //  3 columns
    //           gezien: "Geen veranderingen in de vormgeving"
    //
    // options_container - moet zijn column1, column2 (geziene waarde: "Blok na info kolom"
    //
    // tax_class_id - moet zijn: 0, 2, 4, 6 - gezien: "Belastbare goederen"
    // status: 1, 2   - gez: Uitgeschakeld
    // visibility: 1, 2, 3, 4 - gez: "Catalogus, Zoeken"
    // gift_message_available: 1, 0 - gezi: Gebruik configuratie
    $transform = array(
      'page_layout' => array(
        'Geen veranderingen in de vormgeving' => NULL,
      ),
      'options_container' => array(
        'Blok na Info Kolom' => 'block after info column', // official options definition: [ 'product info column' => 'container1', 'block after info column' => 'container2' ]
      ),
      'tax_class_id' => array(
        'Belastbare goederen' => 2,
        // 0=Geen, 2=Taxable Goods, 4=Shipping, 6=Tax exempt, 11=BTW Hoog, 12=BTW Laag, 13=BTW Vrij
      ),
      'status' => array(
        'Ingeschakeld' => 1,
        'Uitgeschakeld' => 2,
        // 1=Aan, 2=Uitgeschakeld
      ),
      'visibility' => array(
        'Niet Individueel Zichtbaar' => 1, // this value found in old export
        'Niet afzonderlijk zichtbaar' => 1,
        'Catalogus' => 2,
        'Zoeken' => 3,
        'Catalogus, Zoeken' => 4,
        'Catalogus , Zoeken' => 4, // this value found in old export
        // 1=Niet afzonderlijk zichtbaar, 2=Catalogus, 3=Zoeken, 4=Catalogus, Zoeken
      ),
      'gift_message_available' => array(
        'Ja' => 1,
        'Nee' => 0,
        'Gebruik configuratie' => 0,
      )
    );
    foreach ($transform as $field => $trans_values) {
      if (!isset($product[$field])) {
        $error = 1;
        exit;
      }
      elseif ($product[$field] !== '' && !array_key_exists($product[$field], $trans_values)) {
        $error = 1;
        exit;
      }
      else {
        $product[$field] = $trans_values[$product[$field]];
      }
    }

    // Images
    if (!isset($product['_media_image']) && isset($product['image'])) {
      $product['_media_image'] = $product['image'];
      $product['_media_attribute_id'] = 88;
      $product['_media_lable'] = '';
      $product['_media_is_disabled'] = 0;
      $product['_media_position'] = 1;
    }


    // Mage_ImportExport_Model_Import_Entity_Product::_saveProducts() will see
    // the row as a continuation of the previous one, remember the SKU from a previous row,
    // and process specific multivalue things (categories, media, ...) if the SKU column is empty.
    $data[] = $product;

    // media_gallery attribute - 77 in mt, 88 in kc

    // media files should be in /Volumes/SSD2/www/kluscenter.nl/media/import
  }
  fclose($fp);

  $api->importEntities($data);

// All the below are failed experiments at using other CSV libs, before finding
// out that standard fgetCSV actually DID work for the products file.

  // This lib uses fgetcsv, doesn't work.
//  // Yeah yeah yeah we should autoload instead. I know.
//  require '/usr/local/lib/php/github/easy-csv/lib/EasyCSV/AbstractBase.php';
//  require '/usr/local/lib/php/github/easy-csv/lib/EasyCSV/Reader.php';
//  $reader = new \EasyCSV\Reader($products_file);
//  $data = $reader->getAll();

  // This lib uses fgetcsv, doesn't work.
//  require '/Users/roderik/.composer/vendor/autoload.php';
//  $csvFile = new Keboola\Csv\CsvFile($products_file);
//  foreach($csvFile as $row) {
//      $data[] = $row;
//  }

//  $GLOBALS['mydata'] = array();

  // Yeah yeah yeah we should autoload instead. I know.
//  require '/usr/local/lib/php/github/csv/src/Goodby/CSV/Import/Protocol/LexerInterface.php';
//  require '/usr/local/lib/php/github/csv/src/Goodby/CSV/Import/Protocol/InterpreterInterface.php';
//  require '/usr/local/lib/php/github/csv/src/Goodby/CSV/Import/Standard/Lexer.php';
//  require '/usr/local/lib/php/github/csv/src/Goodby/CSV/Import/Standard/Interpreter.php';
//  require '/usr/local/lib/php/github/csv/src/Goodby/CSV/Import/Standard/LexerConfig.php';


//  $lexer = new Lexer(new LexerConfig());
//  $interpreter = new Interpreter();
//  $interpreter->addObserver(function(array $row) {
//    $GLOBALS['mydata'][] = $row;
//  });
//  $lexer->parse($products_file, $interpreter);
//  $data = $GLOBALS['mydata'];
}


// Stock

if ($import_stock) {

  // We don't write now, but we'll need the write connection later anyway
  $conn = Mage::getSingleton('core/resource')->getConnection('write');
  $attribute_id_ean = '292';
  $skus = $conn->fetchPairs("SELECT e.value, p.sku FROM catalog_product_entity_varchar e INNER JOIN catalog_product_entity p ON e.entity_id=p.entity_id WHERE e.attribute_id=$attribute_id_ean AND e.store_id=0 AND e.value IS NOT NULL");

  // Read file with EAN -> stock data.
  $stock_data = array();
  $fp = fopen($stock_file, 'r');
  // No field names present
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
}
