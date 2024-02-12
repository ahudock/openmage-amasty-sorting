<?php
/**
 * @author Amasty Team
 * @copyright Copyright (c) Amasty Ltd. ( http://www.amasty.com/ )
 * @package Amasty_Sorting
 */
/**
 * @author     Andy Hudock <ahudock@pm.me>
 * @package    Threeprong_Sorting
 *
 * Overwrite Toolbar to add PHP 8.2 compatibility to Amasty's Sorting module
 */
class Threeprong_Sorting_Block_Catalog_Product_List_Toolbar extends Amasty_Sorting_Block_Catalog_Product_List_Toolbar_Pure
{
    const SEARCH_SORTING = Amasty_Sorting_Block_Catalog_Product_List_Toolbar::SEARCH_SORTING;
    protected $methods = null;

    public function getMethods()
    {
        if (is_null($this->methods)){
            $this->methods = Mage::helper('amsorting')->getMethodModels();
        }

        return $this->methods;
    }

    protected function _construct()
    {
        parent::_construct();

        if ($this->reverse($this->_orderField))
            $this->_direction = 'desc';
    }

    public function getOrderUrl($order, $direction)
    {
        if ($order && $this->reverse($order)) {
            $direction =  'desc';
        }
        return parent::getOrderUrl($order, $direction);
    }

    public function getCurrentDirection()
    {
        $dir = parent::getCurrentDirection();
        $url = strtolower($this->getRequest()->getParam($this->getDirectionVarName()) ?? '');
        if (!$url && $this->reverse($this->getCurrentOrder())){
            $dir = 'desc';
        }
        return $dir;
    }

    /**
     * @return string
     */
    public function getCurrentOrder()
    {
        return $this->getHideCurrentOrder() ? '' : parent::getCurrentOrder();
    }

    public function setCollection($collection)
    {
        $collection->clear();
        // do not apply current order using default logic
        $this->setHideCurrentOrder(true);
        parent::setCollection($collection);
        $this->setHideCurrentOrder(false);

        $request = Mage::app()->getRequest();
        if ($collection->getFlag('amsorting') || 'TM_Helpmate' == $request->getControllerModule()) {
            return $this;
        }

        $methods = $this->getMethods();
        $isSearch = in_array($request->getModuleName(), array('sqli_singlesearchresult', 'catalogsearch'));
        if ($isSearch && isset($methods[$this->getCurrentOrder()])) {
            $collection->getSelect()->reset(Zend_Db_Select::ORDER);
        }

        $this->addHighPriorityOrders($collection)
            ->addOrdersFromConfig($collection)
            ->addLowPriorityOrders($collection);

        if (Mage::getStoreConfig('amsorting/debug/print_query')
            && $this->getRequest()->getParam('debug')
        ) {
            Mage::helper('ambase/utils')->_echo($collection->getSelect());
        }

        $collection->setFlag('amsorting', true);
        return $this;
    }

    /**
     * @param $collection
     * @return $this
     */
    protected function addHighPriorityOrders($collection)
    {
        // no image sorting will be the first or the second (after stock). LIFO queue
        $hasImage = Mage::getSingleton('amsorting/method_image');
        $hasImage->apply($collection, '');
        // in stock sorting will be first, as the method always moves it's paremater first. LIFO queue
        $inStock = Mage::getSingleton('amsorting/method_instock');
        $inStock->apply($collection, '');
        return $this;
    }

    /**
     * @param $collection
     * @return $this
     */
    protected function addOrdersFromConfig($collection)
    {
        $appliedSortMethods = array();

        $this->applySortByMethod($collection, $this->getCurrentOrder());
        $appliedSortMethods[] = $this->getCurrentDirection();

        $paths = array('category_1', 'category_2');
        if (Mage::registry(self::SEARCH_SORTING)) {
            $paths = array('search_1', 'search_2');
        }

        foreach ($paths as $path) {
            $sortMethod = Mage::getStoreConfig('amsorting/default_sorting/' . $path);
            if (!empty($sortMethod) && !isset($appliedSortMethods[$sortMethod])) {
                $this->applySortByMethod($collection, $sortMethod);
                $appliedSortMethods[] = $this->getCurrentDirection();
            }
        }

        return $this;
    }

    /**
     * @param $collection
     * @param string $sortMethod
     * @return $this
     */
    protected function applySortByMethod($collection, $sortMethod)
    {
        $methods = $this->getMethods();
        if (isset($methods[$sortMethod])) {
            $methods[$sortMethod]->apply($collection, $this->getCurrentDirection());
            $appliedSortMethods[] = $this->getCurrentDirection();
        } elseif ($sortMethod == 'relevance') {
            $this->addRelevanceSorting($collection, $this->getCurrentDirection());
        } else {
            $collection->addAttributeToSort($sortMethod, $this->getCurrentDirection());
        }

        return $this;
    }

    /**
     * @param $collection
     * @return $this
     */
    protected function addLowPriorityOrders($collection)
    {
        if(isset($from['cat_index'])) {
            $collection->getSelect()->order('cat_index.position ' . $this->getCurrentDirection());
        }

        if (Mage::getStoreConfig('amsorting/general/sort_by_id')) {
            $collection->getSelect()->order('e.entity_id ' . $this->getCurrentDirection());
        }
        return $this;
    }

    protected function reverse($order)
    {
        $methods = $this->getMethods();
        if (isset($methods[$order])){
            return true;
        }

        $attr = Mage::getStoreConfig('amsorting/general/desc_attributes');
        if ($attr){
            return in_array($order, explode(',', $attr));

        }

        return false;
    }

    /**
     * @param $collection
     * @param $dir
     */
    protected function addRelevanceSorting($collection, $dir)
    {
        // for trigger _resortFoundDataByRelevance
        $collection->addOrder('relevance');
        if ($collection instanceof Mage_CatalogSearch_Model_Resource_Fulltext_Collection) {
            if (method_exists($collection, 'getFoundIds')) {
                $ids = $collection->getFoundIds();
                if (!empty($ids)) {
                    /** @var Mage_CatalogSearch_Model_Resource_Helper_Mysql4 $resourceHelper */
                    $resourceHelper = Mage::getResourceHelper('catalogsearch');
                    $collection->getSelect()->order(
                        new Zend_Db_Expr(
                            $resourceHelper->getFieldOrderExpression(
                                'e.' . $collection->getResource()->getIdFieldName(),
                                $ids
                            )
                            . ' ' . Zend_Db_Select::SQL_ASC
                        )
                    );
                }
            } else {
                $collection->getSelect()->order("relevance {$dir}");
            }
        }
    }
}
