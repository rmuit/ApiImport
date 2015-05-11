<?php
// Try to export data through Magento, which was needed in a newer shop.
// We could probably have just used SQL queries without starting Magento, but
// this seems nice for possible future extension.

$sets_file = '/Volumes/SSD2/www/megatool.nl/var-export.sets.php';
$groups_file = '/Volumes/SSD2/www/megatool.nl/var-export.groups.php';
$attrs_file = '/Volumes/SSD2/www/megatool.nl/var-export.attributes.php';
$options_file = '/Volumes/SSD2/www/megatool.nl/var-export.options.php';
$assoc_file = '/Volumes/SSD2/www/megatool.nl/var-export.assoc.php';

require_once 'app/Mage.php';

// This doesn't exist on all environments (at least not on the old Mageto version we exported from):
//Mage::init();
// For older versions, we don't use $app but this initializes things:
$app = Mage::app();

$setup = new Mage_Catalog_Model_Resource_Eav_Mysql4_Setup('catalog_product_attribute_set');
$conn = $setup->getConnection();

$entityTypeId = $setup->getEntityTypeId(Mage_Catalog_Model_Product::ENTITY);

// We cannot export all sets plus groups in a way that is immediately suitable
// for importing through Danslo_ApiImport_Model_Import_Api, without data loss.
// (We also want to store the group IDs because we need them later.) This means
// we need to do preprocessing of arrays before import anyway. So let's just
// fetch the needed data in different arrays keyed by their original IDs.

// Sets

$select = $conn->select()
  ->from(
    array('s' => $setup->getTable('eav/attribute_set')),
    array('attribute_set_id', 'attribute_set_name', 'sort_order'))
  ->where("s.entity_type_id = :entity_type_id")
  ->where("s.attribute_set_name <> 'Default'");
$rows = $conn->fetchAssoc($select, array('entity_type_id' => $entityTypeId));

// We really don't need the ID as a value, though (it's the key now)
foreach ($rows as &$row) {
  unset($row['attribute_set_id']);
}

$fp = fopen($sets_file, 'w');
fwrite($fp, var_export($rows, TRUE));
fclose($fp);
echo "Wrote $sets_file\n";


// Groups

$select = $conn->select()
  ->from(
    array('g' => $setup->getTable('eav/attribute_group')),
    array('attribute_group_id', 'attribute_set_id', 'attribute_group_name', 'sort_order'))
  ->join(
    array('s' => $setup->getTable('eav/attribute_set')),
    's.attribute_set_id = g.attribute_set_id',
    array())
  ->where("s.entity_type_id = :entity_type_id")
  ->where("s.attribute_set_name <> 'Default'")
  ->order(array('g.attribute_set_id', 'g.sort_order'));
$rows = $conn->fetchAssoc($select, array('entity_type_id' => $entityTypeId));

// We really don't need the ID as a value, though (it's the key now)
foreach ($rows as &$row) {
  unset($row['attribute_group_id']);
}

$fp = fopen($groups_file, 'w');
fwrite($fp, var_export($rows, TRUE));
fclose($fp);
echo "Wrote $groups_file\n";


// Attributes

$select = $conn->select()
  ->from(
    array('a' => $setup->getTable('eav/attribute')),
    array('attribute_id', 'attribute_code', 'backend_model', 'backend_type', 'backend_table', 'frontend_model', 'frontend_input', 'frontend_label', 'frontend_class', 'source_model', 'is_required', 'default_value', 'is_unique', 'note'))
    // Mage_Eav_Model_Entity_Setup::addAttribute() => _prepareValues() allows a
    // specific set of fields: see above. Notes:
    // These are NOT THE ALLOWED KEY NAMES for addAttribute(), but the table
    //   fieldnames which those are going to be converted INTO. So our import
    //   must first convert those to the expected input array-key names.
    //
    // Since we don't want to output attribute_id in the joined table, we need
    // to mention all individual fields here. We also export
    // is_used_for_price_rules & search_weight even though these apparently
    // cannot be imported.
    ->join(
      array('c' => $setup->getTable('catalog/eav_attribute')),
      'a.attribute_id = c.attribute_id',
      array('is_global', 'frontend_input_renderer', 'is_visible', 'is_searchable', 'is_filterable', 'is_comparable', 'is_visible_on_front', 'is_wysiwyg_enabled', 'is_html_allowed_on_front', 'is_visible_in_advanced_search', 'is_filterable_in_search',  'used_in_product_listing',  'used_for_sort_by',  'apply_to',  'position',  'is_configurable', 'is_used_for_promo_rules', 'is_used_for_price_rules'))
      //, 'search_weight')) // OK, we're not putting this in the definition because it is not present in older stores...
  ->where("a.entity_type_id = :entity_type_id")
    //  As far as I know, we must only export is_user_defined=1 (so that field is
    // left out from the field list above) and if we're missing another field...
    // we should probably install another extension?
  ->where('a.is_user_defined = 1');
$rows = $conn->fetchAssoc($select, array('entity_type_id' => $entityTypeId));

// We really don't need the ID as a value, though (it's the key now)
foreach ($rows as &$row) {
  unset($row['attribute_id']);
}

$fp = fopen($attrs_file, 'w');
fwrite($fp, var_export($rows, TRUE));
fclose($fp);
echo "Wrote $attrs_file\n";


// Options:
// The data model allows multiple options for one attribute, with multiple
// values (for each store ID?) per option. But
// Mage_Eav_Model_Entity_Setup::addAttribute() can only import either one option
// with multiple values, or multiple options with only a value for storeid=0!
// We will choose the latter AND will not export the value_id - this way
// our import gets simpler (only one row per option).
// NOTE: catalog_product_index_eav.value refers to an option_id, not value_id.

$select = $conn->select()
  ->from(
    array('o' => $setup->getTable('eav/attribute_option')),
    array('option_id', 'attribute_id', 'sort_order'))
  ->join(
    array('v' => $setup->getTable('eav/attribute_option_value')),
    'o.option_id = v.option_id',
    array('value'))
  // For filtering: same as attributes.
  ->join(
    array('a' => $setup->getTable('eav/attribute')),
    'o.attribute_id = a.attribute_id',
    array())
  ->where("a.entity_type_id = :entity_type_id")
  ->where('a.is_user_defined = 1')
  ->where("v.store_id = 0")
  ->order(array('o.attribute_id', 'o.sort_order', 'o.option_id'));
$rows = $conn->fetchAssoc($select, array('entity_type_id' => $entityTypeId));

// We really don't need the ID as a value, though (it's the key now)
foreach ($rows as &$row) {
  unset($row['option_id']);
}

$fp = fopen($options_file, 'w');
fwrite($fp, var_export($rows, TRUE));
fclose($fp);
echo "Wrote $options_file\n";


// Attribute associations:

$select = $conn->select()
  ->from(
    array('e' => $setup->getTable('eav/entity_attribute')),
    array('entity_attribute_id', 'sort_order'))
  ->join(
    array('a' => $setup->getTable('eav/attribute')),
    'e.attribute_id = a.attribute_id',
    array('attribute_code'))
  ->join(
    array('s' => $setup->getTable('eav/attribute_set')),
    'e.attribute_set_id = s.attribute_set_id',
    // We assume attribute_set_id fields from e and g are consistent.
    array('attribute_set_name'))
  ->join(
    array('g' => $setup->getTable('eav/attribute_group')),
    'e.attribute_group_id = g.attribute_group_id',
    array('attribute_group_name'))
  // We assume the entity_type_id fields from e, a & s are consistent.
  ->where("e.entity_type_id = :entity_type_id")
  // We need to take all associations for user-defined (i.e. part of this
  // import) attributes, including those to the Default set.
  // We also need to take all associations for a non-default (i.e. part of this
  // import) set, including those from non-user-defined attributes. It is not
  // guaranteed that these attributes will exist, so the import should not give
  // errors.
  // One thing, though: associations from non-user-defined attributes will NOT
  // first be deleted on a re-import! So make no mistakes in the export.
  ->where("(a.is_user_defined = 1 OR s.attribute_set_name <> 'Default')")
  ->order(array('e.attribute_id', 'e.attribute_set_id', 'e.attribute_group_id', 'e.sort_order'));
$rows = $conn->fetchAssoc($select, array('entity_type_id' => $entityTypeId));

// We really don't need the ID as a value, though (it's the key now).
// In fact we don't need it at all, but hey, if we need a key anyway, why not
// make this the one.
foreach ($rows as &$row) {
  unset($row['entity_attribute_id']);
}

$fp = fopen($assoc_file, 'w');
fwrite($fp, var_export($rows, TRUE));
fclose($fp);
echo "Wrote $assoc_file\n";


echo "Files contain a var_export so you could do\n";
echo "  eval('\$var = ' . file_get_contents(FILENAME) . ';');\nin your code\n";

// Actually, since we now only export one dimensional arrays: if we ever start
// using this more generally, we can fputcsv the values,
