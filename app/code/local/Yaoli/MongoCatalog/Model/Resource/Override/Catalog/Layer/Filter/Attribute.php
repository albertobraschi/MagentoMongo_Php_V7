<?php
/**
 *
 * @category  Yaoli
 * @package   Yaoli_MongoCatalog
 */
class Yaoli_MongoCatalog_Model_Resource_Override_Catalog_Layer_Filter_Attribute
    extends Mage_Catalog_Model_Resource_Layer_Filter_Attribute
{
    /**
     * Retrieve array with products counts per attribute option
     *
     * @param Mage_Catalog_Model_Layer_Filter_Attribute $filter The current catalog filter
     *
     * @return array
     */
    public function getCount($filter)
    {
        $catalogResource = Mage::getResourceModel("catalog/product");
        $attribute       = $filter->getAttributeModel();

        /**
         * For legacy SQL based attributes, Magento based the query on "catalog/product_index_eav", let him do
         */
        if (in_array($attribute->getAttributeCode(), $catalogResource->getSqlAttributesCodes())) {
            return parent::getCount($filter);
        }

        /**
         * Since we have MongoDB, nothing is stored on eav index table for other attributes
         * Let's build the query with an aggregation
         *
         * @see http://docs.mongodb.org/manual/reference/operator/aggregation/
         */
        $collection = clone $filter->getLayer()->getProductCollection();

        /** @var Yaoli_MongoCore_Model_Resource_Connection_Adapter $adapter */
        $adapter        = Mage::getSingleton('mongocore/resource_connection_adapter');
        $queryBuilder   = $adapter->getQueryBuilder();
        $collectionName = Mage::getResourceModel('catalog/product')->getEntityTable();
        $docCollection  = $adapter->getCollection($collectionName);

        /** Build a condition to have all products which have the specified attribute as "notnull" AND not an empty string */
        $scopedAttributeName = 'attr_' . $filter->getStoreId() . '.' . $attribute->getAttributeCode();
        $globalAttributeName = 'attr_' . Mage_Core_Model_App::ADMIN_STORE_ID . '.' . $attribute->getAttributeCode();

        $filterCascade = array(
            '$or' => array(
                array('$and'=> array(
                    array($scopedAttributeName => array('$exists' => 1)),
                )),
                array('$and'=> array(
                    array($scopedAttributeName => array('$exists' => 0)),
                    array($globalAttributeName => array('$exists' => 1)),
                )),
            )
        );

        $documentFilter = $filterCascade;

        $documentFilter['$or'][0]['$and'][] = array($scopedAttributeName => array('$' . "ne" => 'null'));
        $documentFilter['$or'][0]['$and'][] = array($scopedAttributeName => array('$' . "ne" => ''));
        $documentFilter['$or'][1]['$and'][] = array($globalAttributeName => array('$' . "ne" => 'null'));
        $documentFilter['$or'][1]['$and'][] = array($globalAttributeName => array('$' . "ne" => ''));

        /** First, the matching, current product ids, and our calculated document filter **/
        $match = array(
            '$and' => array(
                $queryBuilder->getIdsFilter($collection->getAllIds()), // This avoid to parse the whole collection
                $documentFilter                                        // This match only products having data for this attribute
            )
        );

        /** And then, the grouping, by attribute values, and calculating a sum */
        $group =  array(
            "_id" => array(
                "{$scopedAttributeName}" => '$' . $scopedAttributeName,
                "{$globalAttributeName}" => '$' . $globalAttributeName,
            ),
            "total" => array('$sum' =>  1)
        );

        /** Building aggregation pipeline based on match and group previously built */
        $pipeline = array(
            array('$match' => $match),
            array('$group' => $group)
        );

        $aggregation = $docCollection->aggregate($pipeline)->toArray();

        /**
         * Now parse the aggregation result
         * Goal is to obtain an array like this :
         *
         * <attribute option id> => <total number of occurences>
         */
        $aggregationResult = array();

        //if (($aggregation['ok'] == 1) && (isset($aggregation["result"]))) {
        if (count($aggregation) > 0) {
            foreach ($aggregation as $aggregate) {
                if(is_object($aggregate))
                {
                    $aggregate = Mage::helper('mongocore')->objectToArray($aggregate);
                }
                if (isset($aggregate["_id"]) && (isset($aggregate['total']))) {
                    $option = null;
                    foreach ($aggregate["_id"] as $value) {
                        $option = $value;
                    }
                    if (!is_null($option)) {
                        $aggregationResult[$option] = $aggregate['total'];
                    }
                }
            }
        }

        if (is_array($aggregationResult) && count($aggregationResult) > 0)
            return ksort($aggregationResult);

        return parent::getCount($filter);
    }
}