<?php
/**
 * @category  Yaoli
 * @package   Yaoli_MongoCatalog
 */
class Yaoli_MongoCatalog_Model_Resource_Override_Catalog_Product_Attribute_Backend_Media
    extends Mage_Catalog_Model_Resource_Product_Attribute_Backend_Media
{

    /**
     * This collection is used to access to the product collection into MongoDB
     *
     * @var MongoCollection
     */
    protected $_docCollection;


    /**
     * Use the mongo adapter to get access to a collection used as storage for products.
     * The collection used has the same name as main entity table (catalog_product_entity).
     *
     * @return MongoCollection The Mongo document collection
     */
    protected function _getDocumentCollection()
    {
        if (is_null($this->_docCollection)) {
            $adapter = Mage::getSingleton('mongocore/resource_connection_adapter');
            $collectionName = Mage::getResourceModel('catalog/product')->getEntityTable();
            $this->_docCollection = $adapter->getCollection($collectionName);
        }

        return $this->_docCollection;
    }


    /**
     * Load the raw gallery data from MongoDB for a product and an attribute code
     *
     * @param Mage_Catalog_Model_Product $product       The product we want to load the gallery for
     * @param string                     $attributeCode The attribute code of the loadedgallery
     *
     * @return array The raw content of the gallery
     */
    public function loadData($product, $attributeCode)
    {
        $result = array();

        //$loadFilter = array('_id' => new MongoInt32($product->getId()));
        $loadFilter = array('_id' => intval($product->getId()));
        $fieldName = 'galleries.' . $attributeCode;
        $loadField = array($fieldName);

        $loadData = $this->_getDocumentCollection()->findOne($loadFilter, $loadField);

        if (isset($loadData['galleries']) && isset($loadData['galleries'][$attributeCode])) {
            // Loop on images and calculate a md5 hash base on the image file
            foreach ($loadData['galleries'][$attributeCode] as $image) {
                $imageHash          = $image['value_id'];
                $result[$imageHash] = $image;
            }
        }

        return $result;

    }


    /**
     * Put the gallery value into the document collection
     *
     * @param Mage_Catalog_Model_Product $product       The product we want to save the gallery for
     * @param string                     $attributeCode The attribute code of the saved gallery
     * @param array                      $savedGallery  The content of the gallery to be saved
     *
     * @return Mage_Catalog_Model_Resource_Product_Attribute_Backend_Media Self reference
     */
    public function saveGallery($product, $attributeCode, $savedGallery)
    {
        //$updateFilter = array('_id' => new MongoInt32($product->getId()));
        $updateFilter = array('_id' => intval($product->getId()));
        $updateValue = array('galleries.' . $attributeCode => array_values($savedGallery));

        //$this->_getDocumentCollection()->updateOne($updateFilter, array('$set' => $updateValue));
        $this->_getDocumentCollection()->updateMany($updateFilter, array('$set' => $updateValue));

        return $this;
    }

}
