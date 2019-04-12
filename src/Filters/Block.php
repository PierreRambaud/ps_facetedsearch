<?php
/**
 * 2007-2019 PrestaShop.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2019 PrestaShop SA
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * International Registered Trademark & Property of PrestaShop SA
 */
namespace PrestaShop\Module\FacetedSearch\Filters;

use Category;
use Combination;
use Configuration;
use Context;
use Db;
use Feature;
use FeatureValue;
use Group;
use Manufacturer;
use PrestaShop\Module\FacetedSearch\Adapter\AbstractAdapter;
use PrestaShop\Module\FacetedSearch\Adapter\InterfaceAdapter;
use PrestaShop\Module\FacetedSearch\Product\Search;
use PrestaShop\PrestaShop\Core\Localization\Locale;
use Shop;
use Tools;

/**
 * Display filters block on navigation
 */
class Block
{
    /** @var AbstractAdapter */
    private $facetedSearchAdapter;

    /**
     * @var boolean
     */
    private $psStockManagement;

    /**
     * @var boolean
     */
    private $psOrderOutOfStock;

    public function __construct(Search $productSearch)
    {
        $this->facetedSearchAdapter = $productSearch->getFacetedSearchAdapter();
    }

    /**
     * @param int $nbProducts
     * @param array $selectedFilters
     *
     * @return array
     */
    public function getFilterBlock(
        $nbProducts,
        $selectedFilters
    ) {
        $context = Context::getContext();
        $idLang = $context->language->id;
        $idShop = (int) $context->shop->id;
        $idParent = (int) Tools::getValue(
            'id_category',
            Tools::getValue('id_category_layered', Configuration::get('PS_HOME_CATEGORY'))
        );
        $parent = new Category((int) $idParent, $idLang);

        /* Get the filters for the current category */
        $filters = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
            'SELECT type, id_value, filter_show_limit, filter_type FROM ' . _DB_PREFIX_ . 'layered_category
            WHERE id_category = ' . (int) $idParent . '
            AND id_shop = ' . $idShop . '
            GROUP BY `type`, id_value ORDER BY position ASC'
        );

        $filterBlocks = [];
        // iterate through each filter, and the get corresponding filter block
        foreach ($filters as $filter) {
            switch ($filter['type']) {
                case 'price':
                    $filterBlocks[] = $this->getPriceRangeBlock($filter, $selectedFilters, $nbProducts, $context);
                    break;
                case 'weight':
                    $filterBlocks[] = $this->getWeightRangeBlock($filter, $selectedFilters, $nbProducts);
                    break;
                case 'condition':
                    $filterBlocks[] = $this->getConditionsBlock($filter, $selectedFilters);
                    break;
                case 'quantity':
                    $filterBlocks[] = $this->getQuantitiesBlock($filter, $selectedFilters);
                    break;
                case 'manufacturer':
                    $filterBlocks[] = $this->getManufacturersBlock($filter, $selectedFilters, $idLang);
                    break;
                case 'id_attribute_group':
                    $filterBlocks =
                        array_merge($filterBlocks, $this->getAttributesBlock($filter, $selectedFilters, $idLang));
                    break;
                case 'id_feature':
                    $filterBlocks =
                        array_merge($filterBlocks, $this->getFeaturesBlock($filter, $selectedFilters, $idLang));
                    break;
                case 'category':
                    $filterBlocks[] = $this->getCategoriesBlock($filter, $selectedFilters, $idLang, $parent);
            }
        }

        return [
            'filters' => $filterBlocks,
        ];
    }

    protected function showPriceFilter()
    {
        return Group::getCurrent()->show_prices;
    }

    /**
     * Get the filter block from the cache table
     *
     * @param string $filterHash
     *
     * @return array|null
     */
    public function getFromCache($filterHash)
    {
        $row = Db::getInstance()->getRow(
            'SELECT data FROM ' . _DB_PREFIX_ . 'layered_filter_block WHERE hash="' . pSQL($filterHash) . '"'
        );

        if (!empty($row)) {
            return unserialize(current($row));
        }

        return null;
    }

    /**
     * Insert the filter block into the cache table
     *
     * @param string $filterHash
     * @param array $data
     */
    public function insertIntoCache($filterHash, $data)
    {
        Db::getInstance()->execute(
            'INSERT INTO ' . _DB_PREFIX_ . 'layered_filter_block (hash, data)
            VALUES ("' . $filterHash . '", "' . pSQL(serialize($data)) . '")'
        );
    }

    /**
     * @param array $filter
     * @param array $selectedFilters
     * @param integer $nbProducts
     * @param Context $context
     *
     * @return array
     */
    private function getPriceRangeBlock($filter, $selectedFilters, $nbProducts, Context $context)
    {
        if (!$this->showPriceFilter()) {
            return [];
        }

        $priceSpecifications = $this->preparePriceSpecifications($context);

        $priceBlock = [
            'type_lite' => 'price',
            'type' => 'price',
            'id_key' => 0,
            'name' => Context::getContext()->getTranslator()->trans('Price', [], 'Modules.Facetedsearch.Shop'),
            'max' => '0',
            'min' => null,
            'unit' => $context->currency->symbol,
            'specifications' => $priceSpecifications,
            'filter_show_limit' => $filter['filter_show_limit'],
            'filter_type' => Converter::WIDGET_TYPE_SLIDER,
            'nbr' => $nbProducts,
        ];

        list($priceMinFilter, $priceMaxFilter, $weightFilter) = $this->ignorePriceAndWeightFilters(
            $this->facetedSearchAdapter->getInitialPopulation()
        );

        list($priceBlock['min'], $priceBlock['max']) = $this->facetedSearchAdapter->getInitialPopulation()->getMinMaxPriceValue();
        $priceBlock['value'] = !empty($selectedFilters['price']) ? $selectedFilters['price'] : null;


        $this->restorePriceAndWeightFilters(
            $this->facetedSearchAdapter->getInitialPopulation(),
            $priceMinFilter,
            $priceMaxFilter,
            $weightFilter
        );

        return $priceBlock;
    }

    /**
     * Price / weight filter block should not apply their own filters
     * otherwise they will always disappear if we filter on price / weight
     * because only one choice will remain
     *
     * @param InterfaceAdapter $filteredSearchAdapter
     *
     * @return array
     */
    private function ignorePriceAndWeightFilters(InterfaceAdapter $filteredSearchAdapter)
    {
        // disable the current price and weight filters to compute ranges
        $priceMinFilter = $filteredSearchAdapter->getFilter('price_min');
        $priceMaxFilter = $filteredSearchAdapter->getFilter('price_max');
        $weightFilter = $filteredSearchAdapter->getFilter('weight');
        $filteredSearchAdapter->resetFilter('price_min');
        $filteredSearchAdapter->resetFilter('price_max');
        $filteredSearchAdapter->resetFilter('weight');

        return [
            $priceMinFilter,
            $priceMaxFilter,
            $weightFilter,
        ];
    }

    /**
     * Restore price and weight filters
     *
     * @param InterfaceAdapter $filteredSearchAdapter
     * @param integer $priceMinFilter
     * @param integer $priceMaxFilter
     * @param integer $weightFilter
     *
     * @return array
     */
    private function restorePriceAndWeightFilters(
        $filteredSearchAdapter,
        $priceMinFilter,
        $priceMaxFilter,
        $weightFilter
    ) {
        // put back the price and weight filters
        $filteredSearchAdapter->setFilter('price_min', $priceMinFilter);
        $filteredSearchAdapter->setFilter('price_max', $priceMaxFilter);
        $filteredSearchAdapter->setFilter('weight', $weightFilter);
    }

    /**
     * Get the weight filter block
     *
     * @param array $filter
     * @param array $selectedFilters
     * @param int $nbProducts
     *
     * @return array
     */
    private function getWeightRangeBlock($filter, $selectedFilters, $nbProducts)
    {
        $weightBlock = [
            'type_lite' => 'weight',
            'type' => 'weight',
            'id_key' => 0,
            'name' => Context::getContext()->getTranslator()->trans('Weight', [], 'Modules.Facetedsearch.Shop'),
            'max' => '0',
            'min' => null,
            'unit' => Configuration::get('PS_WEIGHT_UNIT'),
            'specifications' => [],
            'filter_show_limit' => $filter['filter_show_limit'],
            'filter_type' => Converter::WIDGET_TYPE_SLIDER,
            'value' => null,
            'nbr' => $nbProducts,
        ];

        list($priceMinFilter, $priceMaxFilter, $weightFilter) = $this->ignorePriceAndWeightFilters(
            $this->facetedSearchAdapter->getInitialPopulation()
        );

        list($weightBlock['min'], $weightBlock['max']) = $this->facetedSearchAdapter->getInitialPopulation()->getMinMaxValue('p.weight');
        if (empty($weightBlock['min']) && empty($weightBlock['max'])) {
            // We don't need to continue, no filter available
            return [];
        }

        $weightBlock['value'] = !empty($selectedFilters['weight']) ? $selectedFilters['weight'] : null;

        $this->restorePriceAndWeightFilters(
            $this->facetedSearchAdapter->getInitialPopulation(),
            $priceMinFilter,
            $priceMaxFilter,
            $weightFilter
        );

        return $weightBlock;
    }

    /**
     * Get the condition filter block
     *
     * @param array $filter
     * @param array $selectedFilters
     *
     * @return array
     */
    private function getConditionsBlock($filter, $selectedFilters)
    {
        $conditionArray = [
            'new' => [
                'name' => Context::getContext()->getTranslator()->trans(
                    'New',
                    [],
                    'Modules.Facetedsearch.Shop'
                ),
                'nbr' => 0
            ],
            'used' => [
                'name' => Context::getContext()->getTranslator()->trans(
                    'Used',
                    [],
                    'Modules.Facetedsearch.Shop'
                ),
                'nbr' => 0
            ],
            'refurbished' => [
                'name' => Context::getContext()->getTranslator()->trans(
                    'Refurbished',
                    [],
                    'Modules.Facetedsearch.Shop'
                ),
                'nbr' => 0,
            ],
        ];
        $filteredSearchAdapter = $this->facetedSearchAdapter->getFilteredSearchAdapter('condition');
        $results = $filteredSearchAdapter->valueCount('condition');
        foreach ($results as $key => $values) {
            $condition = $values['condition'];
            $count = $values['c'];

            $conditionArray[$condition]['nbr'] = $count;
            if (isset($selectedFilters['condition'])
                && in_array($condition, $selectedFilters['condition'])
            ) {
                $conditionArray[$condition]['checked'] = true;
            }
        }

        $conditionBlock = [
            'type_lite' => 'condition',
            'type' => 'condition',
            'id_key' => 0,
            'name' => Context::getContext()->getTranslator()->trans('Condition', [], 'Modules.Facetedsearch.Shop'),
            'values' => $conditionArray,
            'filter_show_limit' => $filter['filter_show_limit'],
            'filter_type' => $filter['filter_type'],
        ];

        return $conditionBlock;
    }

    /**
     * Get the quantities filter block
     *
     * @param array $filter
     * @param array $selectedFilters
     *
     * @return array
     */
    private function getQuantitiesBlock($filter, $selectedFilters)
    {
        $filteredSearchAdapter = $this->facetedSearchAdapter->getFilteredSearchAdapter('quantity');
        $quantityArray = [
            0 => [
                'name' => Context::getContext()->getTranslator()->trans(
                    'Not available',
                    [],
                    'Modules.Facetedsearch.Shop'
                ),
                'nbr' => 0
            ],
            1 => [
                'name' => Context::getContext()->getTranslator()->trans(
                    'In stock',
                    [],
                    'Modules.Facetedsearch.Shop'
                ),
                'nbr' => 0
            ],
        ];

        if ($this->psStockManagement === null) {
            $this->psStockManagement = (bool) Configuration::get('PS_STOCK_MANAGEMENT');
        }

        if ($this->psOrderOutOfStock === null) {
            $this->psOrderOutOfStock = (bool) Configuration::get('PS_ORDER_OUT_OF_STOCK');
        }

        $results = [
            ['c' => 0],
            ['c' => 0],
        ];

        if (!$this->psStockManagement) {
            $allResults = $filteredSearchAdapter->count();
            $filteredSearchAdapter->addFilter('quantity', [0]);
            $noMoreQuantityResults = $filteredSearchAdapter->valueCount('quantity');

            $results[0]['c'] = !empty($noMoreQuantityResults) ? (int) $noMoreQuantityResults[0]['c'] : 0;
            $results[1]['c'] = (int) ($allResults - $results[0]['c']);

            if (isset($selectedFilters['quantity']) && in_array(1, $selectedFilters['quantity'])) {
                $quantityArray[1]['checked'] = true;
            }

            $count = $results[0]['c'] + $results[1]['c'];
            $quantityArray[1]['nbr'] = $count;
        } else {
            $filteredSearchAdapter->resetFilter('quantity');
            $resultsOutOfStock = $filteredSearchAdapter->valueCount('out_of_stock');
            foreach ($resultsOutOfStock as $resultOutOfStock) {
                // search count of products always available when out of stock (out_of_stock == 1)
                if (isset($resultOutOfStock['out_of_stock']) && (int) $resultOutOfStock['out_of_stock'] === 0) {
                    $results[0]['c'] += (int) $resultOutOfStock['c'];
                    continue;
                }

                // search count of products always available when out of stock (out_of_stock == 1)
                if (isset($resultOutOfStock['out_of_stock']) && (int) $resultOutOfStock['out_of_stock'] === 1) {
                    $results[1]['c'] += (int) $resultOutOfStock['c'];
                    continue;
                }

                // if $this->psOrderOutOfStock === true, product with out_of_stock == 2 are available
                if ($this->psOrderOutOfStock === true
                    && isset($resultOutOfStock['out_of_stock'])
                    && (int) $resultOutOfStock['out_of_stock'] === 2
                ) {
                    $results[1]['c'] += (int) $resultOutOfStock['c'];
                } else {
                    $results[0]['c'] -= (int) $resultOutOfStock['c'];
                }
            }

            foreach ($results as $key => $values) {
                $count = $values['c'];

                $quantityArray[$key]['nbr'] = $count;
                if (isset($selectedFilters['quantity']) && in_array($key, $selectedFilters['quantity'])) {
                    $quantityArray[$key]['checked'] = true;
                }
            }
        }

        $quantityBlock = [
            'type_lite' => 'quantity',
            'type' => 'quantity',
            'id_key' => 0,
            'name' => Context::getContext()->getTranslator()->trans('Availability', [], 'Modules.Facetedsearch.Shop'),
            'values' => $quantityArray,
            'filter_show_limit' => $filter['filter_show_limit'],
            'filter_type' => $filter['filter_type'],
        ];

        return $quantityBlock;
    }

    /**
     * Get the manufacturers filter block
     *
     * @param array $filter
     * @param array $selectedFilters
     * @param int $idLang
     *
     * @return array
     */
    private function getManufacturersBlock($filter, $selectedFilters, $idLang)
    {
        $manufacturersArray = $manufacturers = [];
        $filteredSearchAdapter = $this->facetedSearchAdapter->getFilteredSearchAdapter('id_manufacturer');

        $tempManufacturers = Manufacturer::getManufacturers(false, $idLang);
        if (empty($tempManufacturers)) {
            return $manufacturersArray;
        }

        foreach ($tempManufacturers as $key => $manufacturer) {
            $manufacturers[$manufacturer['id_manufacturer']] = $manufacturer;
        }

        $results = $filteredSearchAdapter->valueCount('id_manufacturer');
        foreach ($results as $key => $values) {
            if (!isset($values['id_manufacturer'])) {
                continue;
            }

            $id_manufacturer = $values['id_manufacturer'];
            if (empty($manufacturers[$id_manufacturer]['name'])) {
                continue;
            }

            $count = $values['c'];
            $manufacturersArray[$id_manufacturer] = [
                'name' => $manufacturers[$id_manufacturer]['name'],
                'nbr' => $count,
            ];

            if (isset($selectedFilters['manufacturer'])
                && in_array($id_manufacturer, $selectedFilters['manufacturer'])
            ) {
                $manufacturersArray[$id_manufacturer]['checked'] = true;
            }
        }

        $this->sortByKey($manufacturers, $manufacturersArray);

        $manufacturerBlock = [
            'type_lite' => 'manufacturer',
            'type' => 'manufacturer',
            'id_key' => 0,
            'name' => Context::getContext()->getTranslator()->trans('Brand', [], 'Modules.Facetedsearch.Shop'),
            'values' => $manufacturersArray,
            'filter_show_limit' => $filter['filter_show_limit'],
            'filter_type' => $filter['filter_type'],
        ];

        return $manufacturerBlock;
    }

    /**
     * Get url & meta from layered_indexable_attribute_group_lang_value table
     *
     * @param int $idAttributeGroup
     * @param int $idLang
     *
     * @return array
     */
    private function getAttributeGroupLayeredInfos($idAttributeGroup, $idLang)
    {
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow(
            'SELECT url_name, meta_title FROM ' .
            _DB_PREFIX_ . 'layered_indexable_attribute_group_lang_value WHERE id_attribute_group=' .
            (int) $idAttributeGroup . ' AND id_lang=' . (int) $idLang
        );
    }

    /**
     * Get url & meta from layered_indexable_attribute_lang_value table
     *
     * @param int $idAttribute
     * @param int $idLang
     *
     * @return array
     */
    private function getAttributeLayeredInfos($idAttribute, $idLang)
    {
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow(
            'SELECT url_name, meta_title FROM ' .
            _DB_PREFIX_ . 'layered_indexable_attribute_lang_value WHERE id_attribute=' .
            (int) $idAttribute . ' AND id_lang=' . (int) $idLang
        );
    }

    /**
     * Get url & meta from layered_indexable_feature_value_lang_value table
     *
     * @param int $idFeatureValue
     * @param int $idLang
     *
     * @return array
     */
    private function getFeatureLayeredInfos($idFeatureValue, $idLang)
    {
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow(
            'SELECT url_name, meta_title FROM ' .
            _DB_PREFIX_ . 'layered_indexable_feature_value_lang_value WHERE id_feature_value=' .
            (int) $idFeatureValue . ' AND id_lang=' . (int) $idLang
        );
    }

    /**
     * Get url & meta from layered_indexable_feature_lang_value table
     *
     * @param int $idFeature
     * @param int $idLang
     *
     * @return array
     */
    private function getFeatureValueLayeredInfos($idFeature, $idLang)
    {
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow(
            'SELECT url_name, meta_title FROM ' .
            _DB_PREFIX_ . 'layered_indexable_feature_lang_value WHERE id_feature=' .
            (int) $idFeature . ' AND id_lang=' . (int) $idLang
        );
    }

    /**
     * @param int $idLang
     * @param bool $notNull
     *
     * @return array|false|\PDOStatement|resource|null
     */
    public static function getAttributes($idLang, $notNull = true)
    {
        if (!Combination::isFeatureActive()) {
            return [];
        }

        return Db::getInstance()->executeS(
            'SELECT DISTINCT a.`id_attribute`, a.`color`, al.`name`, agl.`id_attribute_group`
            FROM `' . _DB_PREFIX_ . 'attribute_group` ag
            LEFT JOIN `' . _DB_PREFIX_ . 'attribute_group_lang` agl
            ON (ag.`id_attribute_group` = agl.`id_attribute_group` AND agl.`id_lang` = ' . (int) $idLang . ')
            LEFT JOIN `' . _DB_PREFIX_ . 'attribute` a
            ON a.`id_attribute_group` = ag.`id_attribute_group`
            LEFT JOIN `' . _DB_PREFIX_ . 'attribute_lang` al
            ON (a.`id_attribute` = al.`id_attribute` AND al.`id_lang` = ' . (int) $idLang . ')' .
            Shop::addSqlAssociation('attribute_group', 'ag') . ' ' .
            Shop::addSqlAssociation('attribute', 'a') . ' ' .
            (
                $notNull ?
                'WHERE a.`id_attribute` IS NOT NULL AND al.`name` IS NOT NULL ' .
                'AND agl.`id_attribute_group` IS NOT NULL ' :
                ''
            ) . 'ORDER BY agl.`name` ASC, a.`position` ASC'
        );
    }

    /**
     * Get all attributes groups for a given language
     *
     * @param int $idLang Language id
     *
     * @return array Attributes groups
     */
    public static function getAttributesGroups($idLang)
    {
        if (!Combination::isFeatureActive()) {
            return [];
        }

        return Db::getInstance()->executeS(
            'SELECT ag.id_attribute_group, agl.name as attribute_group_name, is_color_group
            FROM `' . _DB_PREFIX_ . 'attribute_group` ag' .
            Shop::addSqlAssociation('attribute_group', 'ag') . '
            LEFT JOIN `' . _DB_PREFIX_ . 'attribute_group_lang` agl
            ON (ag.`id_attribute_group` = agl.`id_attribute_group` AND `id_lang` = ' . (int) $idLang . ')
            GROUP BY ag.id_attribute_group ORDER BY ag.`position` ASC'
        );
    }

    /**
     * Get the attributes filter block
     *
     * @param array $filter
     * @param array $selectedFilters
     * @param int $idLang
     *
     * @return array
     */
    private function getAttributesBlock($filter, $selectedFilters, $idLang)
    {
        $attributesBlock = $attributes = $attributesGroup = [];
        $filteredSearchAdapter = null;
        $idAttributeGroup = $filter['id_value'];

        if (!empty($selectedFilters['id_attribute_group'])) {
            foreach ($selectedFilters['id_attribute_group'] as $key => $selectedFilter) {
                if ($key == $idAttributeGroup) {
                    $filteredSearchAdapter = $this->facetedSearchAdapter->getFilteredSearchAdapter('id_attribute');
                    break;
                }
            }
        }
        if (!$filteredSearchAdapter) {
            $filteredSearchAdapter = $this->facetedSearchAdapter->getFilteredSearchAdapter();
        }

        $tempAttributesGroup = self::getAttributesGroups($idLang);
        if ($tempAttributesGroup === []) {
            return $attributesBlock;
        }

        foreach ($tempAttributesGroup as $key => $attributeGroup) {
            $attributesGroup[$attributeGroup['id_attribute_group']] = $attributeGroup;
        }

        $tempAttributes = self::getAttributes($idLang, true);
        foreach ($tempAttributes as $key => $attribute) {
            $attributes[$attribute['id_attribute']] = $attribute;
        }

        $filteredSearchAdapter->addFilter('id_attribute_group', [(int) $idAttributeGroup]);
        $results = $filteredSearchAdapter->valueCount('id_attribute');

        foreach ($results as $key => $values) {
            $idAttribute = $values['id_attribute'];
            $count = $values['c'];

            $attribute = $attributes[$idAttribute];
            $idAttributeGroup = $attribute['id_attribute_group'];
            if (!isset($attributesBlock[$idAttributeGroup])) {
                $attributeGroup = $attributesGroup[$idAttributeGroup];

                list($urlName, $metaTitle) = $this->getAttributeGroupLayeredInfos($idAttributeGroup, $idLang);

                $attributesBlock[$idAttributeGroup] = [
                    'type_lite' => 'id_attribute_group',
                    'type' => 'id_attribute_group',
                    'id_key' => $idAttributeGroup,
                    'name' => $attributeGroup['attribute_group_name'],
                    'is_color_group' => (bool) $attributeGroup['is_color_group'],
                    'values' => [],
                    'url_name' => $urlName,
                    'meta_title' => $metaTitle,
                    'filter_show_limit' => $filter['filter_show_limit'],
                    'filter_type' => $filter['filter_type'],
                ];
            }

            list($urlName, $metaTitle) = $this->getAttributeLayeredInfos($idAttribute, $idLang);
            $attributesBlock[$idAttributeGroup]['values'][$idAttribute] = [
                'color' => $attribute['color'],
                'name' => $attribute['name'],
                'nbr' => $count,
                'url_name' => $urlName,
                'meta_title' => $metaTitle,
            ];

            if (array_key_exists('id_attribute_group', $selectedFilters)) {
                foreach ($selectedFilters['id_attribute_group'] as $selectedAttribute) {
                    if (in_array($idAttribute, $selectedAttribute)) {
                        $attributesBlock[$idAttributeGroup]['values'][$idAttribute]['checked'] = true;
                    }
                }
            }
        }

        foreach ($attributesBlock as $idAttributeGroup => $value) {
            $attributesBlock[$idAttributeGroup]['values'] = $this->sortByKey($attributes, $value['values']);
        }

        $attributesBlock = $this->sortByKey($attributesGroup, $attributesBlock);

        return $attributesBlock;
    }

    /**
     * Sort an array using the same key order than the sortedReferenceArray
     *
     * @param array $sortedReferenceArray
     * @param array $array
     *
     * @return array
     */
    private function sortByKey($sortedReferenceArray, $array)
    {
        $sortedArray = [];

        // iterate in the original order
        foreach ($sortedReferenceArray as $key => $value) {
            if (array_key_exists($key, $array)) {
                $sortedArray[$key] = $array[$key];
            }
        }

        return $sortedArray;
    }

    /**
     * Get the features filter block
     *
     * @param array $filter
     * @param array $selectedFilters
     * @param int $idLang
     *
     * @return array
     */
    private function getFeaturesBlock($filter, $selectedFilters, $idLang)
    {
        $features = $featureBlock = [];
        $idFeature = $filter['id_value'];
        $filteredSearchAdapter = null;

        if (!empty($selectedFilters['id_feature'])) {
            foreach ($selectedFilters['id_feature'] as $key => $selectedFilter) {
                if ($key == $idFeature) {
                    $filteredSearchAdapter = $this->facetedSearchAdapter->getFilteredSearchAdapter('id_feature_value');
                    break;
                }
            }
        }
        if (!$filteredSearchAdapter) {
            $filteredSearchAdapter = $this->facetedSearchAdapter->getFilteredSearchAdapter();
        }

        $tempFeatures = Feature::getFeatures($idLang);
        foreach ($tempFeatures as $key => $feature) {
            $features[$feature['id_feature']] = $feature;
        }

        $filteredSearchAdapter->addFilter('id_feature', [(int) $idFeature]);
        $filteredSearchAdapter->addSelectField('id_feature');
        $results = $filteredSearchAdapter->valueCount('id_feature_value');
        foreach ($results as $key => $values) {
            $idFeatureValue = $values['id_feature_value'];
            $idFeature = $values['id_feature'];
            $count = $values['c'];

            $feature = $features[$idFeature];

            if (!isset($featureBlock[$idFeature])) {
                $tempFeatureValues = FeatureValue::getFeatureValuesWithLang($idLang, $idFeature);

                foreach ($tempFeatureValues as $featureValueKey => $featureValue) {
                    $features[$idFeature]['featureValues'][$featureValue['id_feature_value']] = $featureValue;
                }

                list($urlName, $metaTitle) = $this->getFeatureLayeredInfos($idFeature, $idLang);

                $featureBlock[$idFeature] = [
                    'type_lite' => 'id_feature',
                    'type' => 'id_feature',
                    'id_key' => $idFeature,
                    'values' => [],
                    'name' => $feature['name'],
                    'url_name' => $urlName,
                    'meta_title' => $metaTitle,
                    'filter_show_limit' => $filter['filter_show_limit'],
                    'filter_type' => $filter['filter_type'],
                ];
            }

            $featureValues = $features[$idFeature]['featureValues'];
            if (!isset($featureValues[$idFeatureValue]['value'])) {
                continue;
            }

            list($urlName, $metaTitle) = $this->getFeatureValueLayeredInfos($idFeatureValue, $idLang);

            $featureBlock[$idFeature]['values'][$idFeatureValue] = [
                'nbr' => $count,
                'name' => $featureValues[$idFeatureValue]['value'],
                'url_name' => $urlName,
                'meta_title' => $metaTitle,
            ];

            if (array_key_exists('id_feature', $selectedFilters)) {
                foreach ($selectedFilters['id_feature'] as $selectedFeature) {
                    if (in_array($idFeatureValue, $selectedFeature)) {
                        $featureBlock[$feature['id_feature']]['values'][$idFeatureValue]['checked'] = true;
                    }
                }
            }
        }

        $featureBlock = $this->sortFeatureBlock($featureBlock);

        return $featureBlock;
    }

    /**
     * Natural sort multi-dimensional feature array
     *
     * @param array $featureBlock
     *
     * @return array
     */
    private function sortFeatureBlock($featureBlock)
    {
        //Natural sort
        foreach ($featureBlock as $key => $value) {
            $temp = [];
            foreach ($featureBlock[$key]['values'] as $idFeatureValue => $featureValueInfos) {
                $temp[$idFeatureValue] = $featureValueInfos['name'];
            }

            natcasesort($temp);
            $temp2 = [];

            foreach ($temp as $keytemp => $valuetemp) {
                $temp2[$keytemp] = $featureBlock[$key]['values'][$keytemp];
            }

            $featureBlock[$key]['values'] = $temp2;
        }

        return $featureBlock;
    }

    /**
     * Add the categories filter condition based on the parent and config variables
     *
     * @param InterfaceAdapter $filteredSearchAdapter
     * @param Category $parent
     */
    private function addCategoriesBlockFilters(InterfaceAdapter $filteredSearchAdapter, $parent)
    {
        if (Group::isFeatureActive()) {
            $userGroups = (Context::getContext()->customer->isLogged() ? Context::getContext()->customer->getGroups() : [
                Configuration::get(
                    'PS_UNIDENTIFIED_GROUP'
                ),
            ]);

            $filteredSearchAdapter->addFilter('id_group', $userGroups);
        }

        $depth = (int) Configuration::get('PS_LAYERED_FILTER_CATEGORY_DEPTH', null, null, null, 1);

        if ($depth) {
            $levelDepth = $parent->level_depth;
            $filteredSearchAdapter->addFilter('level_depth', [$depth + $levelDepth], '<=');
        }

        $filteredSearchAdapter->addFilter('nleft', [$parent->nleft], '>');
        $filteredSearchAdapter->addFilter('nright', [$parent->nright], '<');
    }

    /**
     * Get the categories filter block
     *
     * @param array $filter
     * @param array $selectedFilters
     * @param int $idLang
     * @param Category $parent
     *
     * @return array
     */
    private function getCategoriesBlock($filter, $selectedFilters, $idLang, $parent)
    {
        $filteredSearchAdapter = $this->facetedSearchAdapter->getFilteredSearchAdapter('id_category');
        $this->addCategoriesBlockFilters($filteredSearchAdapter, $parent);

        $categoryArray = [];
        $categories = Category::getAllCategoriesName(
            null,
            $idLang,
            true,
            null,
            true,
            '',
            'ORDER BY c.nleft, c.position'
        );
        foreach ($categories as $key => $value) {
            $categories[$value['id_category']] = $value;
        }
        $results = $filteredSearchAdapter->valueCount('id_category');

        foreach ($results as $key => $values) {
            $idCategory = $values['id_category'];
            $count = $values['c'];

            $categoryArray[$idCategory] = [
                'name' => $categories[$idCategory]['name'],
                'nbr' => $count,
            ];

            if (isset($selectedFilters['category']) && in_array($idCategory, $selectedFilters['category'])) {
                $categoryArray[$idCategory]['checked'] = true;
            }
        }

        $categoryArray = $this->sortByKey($categories, $categoryArray);

        $categoryBlock = [
            'type_lite' => 'category',
            'type' => 'category',
            'id_key' => 0,
            'name' => Context::getContext()->getTranslator()->trans('Categories', [], 'Modules.Facetedsearch.Shop'),
            'values' => $categoryArray,
            'filter_show_limit' => $filter['filter_show_limit'],
            'filter_type' => $filter['filter_type'],
        ];

        return $categoryBlock;
    }

    /**
     * Prepare price specifications to display cldr prices.
     *
     * @param Context $context
     *
     * @return array
     */
    private function preparePriceSpecifications(Context $context)
    {
        $currency = $context->currency;
        return [
            'positivePattern' => $currency->format,
            'negativePattern' => $currency->format,
            'symbol' => [
                '.',
                ',',
                ';',
                '%',
                '-',
                '+',
                'E',
                '×',
                '‰',
                '∞',
                'NaN',
            ],
            'maxFractionDigits' => $currency->precision,
            'minFractionDigits' => $currency->precision,
            'groupingUsed' => true,
            'primaryGroupSize' => 3,
            'secondaryGroupSize' => 3,
            'currencyCode' => $currency->iso_code,
            'currencySymbol' => $currency->sign,
        ];
    }
}
