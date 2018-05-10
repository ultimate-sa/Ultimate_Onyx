<?php

namespace Ultimate\Onyx\Api;

use GuzzleHttp\Client;
use Magento\Framework\App\ObjectManager;
use Stichoza\GoogleTranslate\TranslateClient;

/**
 * Onyx-magento products management
 */
trait ProductsTrait
{
    public function getOnyxProducts()
    {
        $onyxClient = new Client([
            // 'base_uri' => 'http://196.218.192.248:2000/OnyxShopMarket/Service.svc/'
            'base_uri' => 'http://10.0.95.95/OnyxShopMarket/Service.svc/'
        ]);

        $response = $onyxClient->request(
            'GET',
            'GetItemsOnlineList',
            [
                'query' => [
                    'type'               => 'ORACLE',
                    'year'               => 2016,
                    'activityNumber'     => 70,
                    'languageID'         => 1,
                    'groupCode'          => -1,
                    'mainGroupCode'      => -1,
                    'subGroupCode'       => -1,
                    'assistantGroupCode' => -1,
                    'detailGroupCode'    => -1,
                    'warehouseCode'      => -1,
                    'searchValue'        => -1,
                    'pageNumber'         => -1,
                    'rowsCount'          => 10, // get it back to -1
                    'orderBy'            => -1,
                    'sortDirection'      => -1
                ]
            ]
        );

        $products = json_decode($response->getBody())->MultipleObjectHeader;

        // echo json_encode($products);
        return $products;
    }

    public function getStoreProducts()
    {
        $products = ObjectManager::getInstance()->get('Magento\Catalog\Model\ProductFactory')
                                                ->create()
                                                ->getCollection()
                                                ->addAttributeToSelect(['*']);

        return $products;
    }

    public function getStoreProduct($sku)
    {
        $product = ObjectManager::getInstance()->get('Magento\Catalog\Model\ProductFactory')
                                               ->create()
                                               ->getCollection()
                                               ->addAttributeToFilter('sku', $sku)
                                               ->addAttributeToSelect(['*'])
                                               ->getFirstItem();

        if ($product->getId()) {
            return $product;
        }

        return null;
    }

    public function syncProducts($logger)
    {
        foreach ($this->getOnyxProducts() as $product) {
            $storeProduct = $this->getStoreProduct($product->Code . '-' . $product->Unit);
            // if existed, then update
            if ($storeProduct) {
                // update qty and price
                $oldPrice = $storeProduct->getPrice();
                $oldQty = ObjectManager::getInstance()->get('Magento\CatalogInventory\Api\StockStateInterface')
                                                      ->getStockQty($storeProduct->getId());

                if ($oldPrice !== $product->Price || $oldQty !== $product->AvailableQuantity) {
                    $storeProduct->setPrice($product->Price);

                    $storeProduct->setStockData([
                        'is_in_stock' => $product->AvailableQuantity > 0 ? true : false, // qty with reserved ?
                        'qty'         => $product->AvailableQuantity
                    ]);

                    try {
                        $storeProduct->save();

                        $newQty = ObjectManager::getInstance()->get('Magento\CatalogInventory\Api\StockStateInterface')
                                                              ->getStockQty($storeProduct->getId());
                        $logger->info(
                            'Item with sku `' . $storeProduct->getSku() . '` has been updated, ' .
                            'Price -> from: ' . $oldPrice . ' to: ' . $storeProduct->getPrice() .
                            ', Qty -> from: ' . $oldQty . ' to: ' . $newQty
                        );
                    } catch (\Exception $e) {
                        $logger->error($e->getMessage());
                    }
                }
            } else {
                // else create
                $this->createStoreProduct($product, $logger);
            }
        }
    }

    public function createStoreProduct($product, $logger)
    {
        // Code -> GroupCode -> MainGroupCode -> SubGroupCode -> AssistantGroupCode

        $catalogProduct = ObjectManager::getInstance()->create('Magento\Catalog\Model\Product');

        $catalogProduct->setSku($product->Code . '-' . $product->Unit);
        $catalogProduct->setName($product->Name);
        $catalogProduct->setAttributeSetId(4);
        $catalogProduct->setStatus(1); // Status on product enabled/ disabled 1/0
        $catalogProduct->setVisibility(4);
        $catalogProduct->setTypeId('simple'); // type of product (simple/virtual/downloadable/configurable)
        $catalogProduct->setPrice($product->Price);

        $url = $this->formProductUrl($product);
        $category = $this->getStoreCategoryByUrl($url);

        if ($category) {
            $catalogProduct->setCategoryIds([$category->getId()]);
        }

        $catalogProduct->setStockData([
            'is_in_stock' => $product->AvailableQuantity > 0 ? true : false,
            'qty'         => $product->AvailableQuantity // qty with reserved?
        ]);

        $catalogProduct->setUrlKey(
            $product->Code . '-' .
            TranslateClient::translate('ar', 'en', $product->Unit) . '-' .
            TranslateClient::translate('ar', 'en', $product->Name) . '-' .
            random_int(1, 9999999)
        );

        // $catalogProduct->setStoreId(1); // $this->storeManagerInterface->getStore()->getId()
        // $catalogProduct->setWebsiteIds([1]); // $this->storeManagerInterface->getStore()->getWebsiteId()

        try {
            $catalogProduct->save();
            $logger->info('Item with SKU: `' . $catalogProduct->getSku() . '` has been created.');
        } catch (\Exception $e) {
            $logger->error($e->getMessage());
        }
    }

    public function formProductUrl($product)
    {
        $url = $product->GroupCode;

        if ($product->MainGroupCode) {
            $url = $url . '-' . $product->MainGroupCode;
        }

        if ($product->SubGroupCode) {
            $url = $url . '-' . $product->SubGroupCode;
        }

        if ($product->DetailGroupCode) {
            $url = $url . '-' . $product->DetailGroupCode;
        }

        if ($product->AssistantGroupCode) {
            $url = $url . '-' . $product->AssistantGroupCode;
        }

        return $url;
    }

    public function deleteStoreProducts($logger)
    {
        $products = ObjectManager::getInstance()->get('Magento\Catalog\Model\ProductFactory')
                                                  ->create()
                                                  ->getCollection();
        // $objectManager->get('Magento\Framework\Registry')->register('isSecureArea', true);

        foreach ($products as $product) {
            if ($product->getId() <= 2) {
                continue;
            }

            try {
                $product->delete();
                $logger->info('Product with name: ' . $product->getName() . ' has been deleted.');
            } catch (\Exception $e) {
                $logger->error($e->getMessage());
            }
        }
    }
}