<?php
namespace GeneralImportExport\Export;

use MultipleFullfilmentCenters\Model\FulfilmentCenterProductsQuery;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\Join;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\Image\ImageEvent;
use Thelia\ImportExport\Export\AbstractExport;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Model\Map\AttributeAvI18nTableMap;
use Thelia\Model\Map\AttributeAvTableMap;
use Thelia\Model\Map\BrandI18nTableMap;
use Thelia\Model\Map\CategoryI18nTableMap;
use Thelia\Model\Map\CurrencyTableMap;
use Thelia\Model\Map\ProductCategoryTableMap;
use Thelia\Model\Map\ProductI18nTableMap;
use Thelia\Model\Map\ProductImageTableMap;
use Thelia\Model\Map\ProductPriceTableMap;
use Thelia\Model\Map\ProductSaleElementsTableMap;
use Thelia\Model\Map\ProductTableMap;
use Thelia\Model\Map\RewritingUrlTableMap;

/**
 * Class BilligerProductExport
 * 
 * @author Emanuel Plopu <emanuel.plopu@sepa.at>
 */
class BilligerProductExport extends AbstractExport
{

    const FILE_NAME = 'catalog_billiger';

    private $url_site;

    protected $orderAndAliases = [
        'productID' => 'aid/sku',
        'product_i18nTITLE' => 'name',
        'product_pricePRICE' => 'price',
        'rewriting_urlURL' => 'link',
        'category_i18nTITLE' => 'shop_cat',
        'brand_i18nTITLE' => 'brand',
        'productREF' => 'mpn(r)',
        ProductSaleElementsTableMap::EAN_CODE => 'EAN',
        'product_imageFILE' => 'image',
        'product_i18nDescription' => 'desc'
    ];

    /**
     * Apply order and aliases on data
     *
     * @param array $data
     *            Raw data
     *            
     * @return array Ordered and aliased data
     */
    public function applyOrderAndAliases(array $data)
    {
        if ($this->orderAndAliases === null) {
            return $data;
        }
        
        $processedData = [];
        
        foreach ($this->orderAndAliases as $key => $value) {
            if (is_integer($key)) {
                $fieldName = $value;
                $fieldAlias = $value;
            } else {
                $fieldName = $key;
                $fieldAlias = $value;
            }
            
            $processedData[$fieldAlias] = null;
            if (array_key_exists($fieldName, $data)) {
                $processedData[$fieldAlias] = $data[$fieldName];
            }
        }
        
        if ($this->url_site == null)
            $this->url_site = ConfigQuery::read('url_site');
        $processedData['link'] = $this->url_site . "/" . $processedData['link'];
        $processedData['image'] = $this->url_site . "/cache/images/product/" . $processedData['image'];
        
        // set delivery time
        $allCentersProduct = null;
        // check if fulfilmentCenterProductsQuery exists
        if (class_exists('FulfilmentCenterProductsQuery'))
            $allCentersProduct = FulfilmentCenterProductsQuery::create()->addSelfSelectColumns()
                ->filterByProductId($data['product_sale_elements.PRODUCT_ID'])
                ->where('`fulfilment_center_products`.PRODUCT_STOCK ', Criteria::ISNOTNULL)
                ->findOne();
        
        if ($allCentersProduct) {
            $processedData['dlv_time'] = "Sofort versandfertig";
        } else
            $processedData['dlv_time'] = "2-3 Arbeitstagen";
        
        $processedData['dlv_cost_at'] = "0";
        $processedData['price'] = number_format((float) ($processedData['price'] * 1.2), 2, '.', '');
        
        return $processedData;
    }

    public function getImageUrl($safe_filename)
    {
        // Tlog::getInstance()->error("subdir ".$subdir." source_file ".$filename." safe ".sprintf("%s/%s-%s", $path, $hashed_options, $safe_filename));
        // $path = '/cache/';
        // $image = new Image($fileManager)
        // return URL::getInstance()->absoluteUrl(sprintf("%s/%s", $path, $safe_filename), null, URL::PATH_TO_FILE);
        $event = new ImageEvent();
        
        $baseSourceFilePath = ConfigQuery::read('images_library_path');
        if ($baseSourceFilePath === null) {
            $baseSourceFilePath = THELIA_LOCAL_DIR . 'media' . DS . 'images';
        } else {
            $baseSourceFilePath = THELIA_ROOT . $baseSourceFilePath;
        }
        
        $sourceFilePath = sprintf('%s/%s/%s', $baseSourceFilePath, $this->objectType, $result->getFile());
        
        $event->setSourceFilepath($sourceFilePath);
        $event->setCacheSubdirectory($this->objectType);
        $this->dispatcher->dispatch(TheliaEvents::IMAGE_PROCESS, $event);
    }

    protected function getData()
    {
        $locale = $this->language->getLocale();
        
        $urlJoin = new Join(ProductTableMap::ID, RewritingUrlTableMap::VIEW_ID, Criteria::LEFT_JOIN);
        $productJoin = new Join(ProductTableMap::ID, ProductI18nTableMap::ID, Criteria::LEFT_JOIN);
        $attributeAvJoin = new Join(AttributeAvTableMap::ID, AttributeAvI18nTableMap::ID, Criteria::LEFT_JOIN);
        $brandJoin = new Join(ProductTableMap::BRAND_ID, BrandI18nTableMap::ID, Criteria::LEFT_JOIN);
        // $categoryJoin = new Join(ProductTableMap::ID, ProductCategoryTableMap::PRODUCT_ID, Criteria::LEFT_JOIN);
        $categoryJoin = new Join(ProductCategoryTableMap::CATEGORY_ID, CategoryI18nTableMap::ID, Criteria::LEFT_JOIN);
        $imageJoin = new Join(ProductTableMap::ID, ProductImageTableMap::PRODUCT_ID, Criteria::LEFT_JOIN);
        
        $query = ProductSaleElementsQuery::create()->addSelfSelectColumns()
            ->useProductPriceQuery()
            ->useCurrencyQuery()
            ->withColumn(CurrencyTableMap::CODE)
            ->endUse()
            ->withColumn(ProductPriceTableMap::PRICE)
            ->withColumn(ProductPriceTableMap::PROMO_PRICE)
            ->withColumn(ProductPriceTableMap::LISTEN_PRICE)
            ->endUse()
            ->useProductQuery()
            ->where(ProductTableMap::VISIBLE." = ?","1")
            ->useProductCategoryQuery()
            ->addJoinObject($categoryJoin, 'category_join')
            ->addJoinCondition('category_join', CategoryI18nTableMap::LOCALE . ' = ?', $locale, null, \PDO::PARAM_STR)
            ->withColumn(CategoryI18nTableMap::TITLE)
            ->endUse()
            ->addJoinObject($productJoin, 'product_join')
            ->addJoinCondition('product_join', ProductI18nTableMap::LOCALE . ' = ?', $locale, null, \PDO::PARAM_STR)
            ->withColumn(ProductI18nTableMap::TITLE)
            ->withColumn(ProductTableMap::ID)
            ->withColumn(ProductTableMap::REF)
            ->withColumn(ProductTableMap::VISIBLE)
            ->addJoinObject($urlJoin, 'rewriting_url_join')
            ->addJoinCondition('rewriting_url_join', RewritingUrlTableMap::VIEW_LOCALE . ' = ?', $locale, null, \PDO::PARAM_STR)
            ->withColumn(RewritingUrlTableMap::URL)
            ->addJoinCondition('rewriting_url_join', RewritingUrlTableMap::VIEW . ' = ?', (new Product())->getRewrittenUrlViewName(), null, \PDO::PARAM_STR)
            ->addJoinCondition('rewriting_url_join', 'ISNULL(' . RewritingUrlTableMap::REDIRECTED . ')')
            ->addJoinObject($productJoin, 'product_join')
            ->addJoinCondition('product_join', ProductI18nTableMap::LOCALE . ' = ?', $locale, null, \PDO::PARAM_STR)
            ->
        addJoinObject($imageJoin, 'product_image_join')
            ->addJoinCondition('product_image_join', ProductImageTableMap::POSITION . '= ?', "1", null, \PDO::PARAM_INT)
            ->withColumn(ProductImageTableMap::FILE)
            ->
        // ->addJoinCondition('product_image_join,')
        
        addJoinObject($brandJoin, 'brand_join')
            ->addJoinCondition('brand_join', BrandI18nTableMap::LOCALE . ' = ?', $locale, null, \PDO::PARAM_STR)
            ->withColumn(BrandI18nTableMap::TITLE)
            ->endUse()
            ->
        useAttributeCombinationQuery(null, Criteria::LEFT_JOIN)
            ->useAttributeAvQuery(null, Criteria::LEFT_JOIN)
            ->addJoinObject($attributeAvJoin, 'attribute_av_join')
            ->addJoinCondition('attribute_av_join', AttributeAvI18nTableMap::LOCALE . ' = ?', $locale, null, \PDO::PARAM_STR)
            ->addAsColumn('attribute_av_i18n_ATTRIBUTES', 'GROUP_CONCAT(DISTINCT ' . AttributeAvI18nTableMap::TITLE . ')')
            ->endUse()
            ->endUse()
            ->orderBy(ProductSaleElementsTableMap::ID)
            ->groupBy(ProductSaleElementsTableMap::ID)
            ->
        where('`product_sale_elements`.EAN_CODE ', Criteria::ISNOTNULL);
        
        return $query;
    }
}