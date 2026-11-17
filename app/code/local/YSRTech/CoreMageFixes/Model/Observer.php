<?php

/**
 * Admin sales item lists (order, invoice, shipment and credit memo pages): a thumbnail
 * next to each item, the item name linked to the product's edit page, and a link to the
 * product page in the store the order was placed in.
 *
 * Added to the output of Mage_Adminhtml_Block_Sales_Items_Column_Name (and the renderers
 * that extend it, e.g. grouped and gift card items), so no admin template is copied.
 * The image of the ordered variant is used when it has one (configurable products),
 * else the parent's. A product deleted since the order keeps its name, without link.
 */
class YSRTech_CoreMageFixes_Model_Observer
{
    const THUMBNAIL_SIZE = 64;

    /** @var array product id + store id => product, or false when it no longer exists */
    protected $_products = [];

    public function addSalesItemImageAndLink(Varien_Event_Observer $observer)
    {
        $block = $observer->getEvent()->getBlock();
        if (!$block instanceof Mage_Adminhtml_Block_Sales_Items_Column_Name) {
            return;
        }
        $item = $block->getItem();
        $orderItem = $item instanceof Mage_Sales_Model_Order_Item ? $item : ($item ? $item->getOrderItem() : null);
        if (!$orderItem || !$orderItem->getId()) {
            return;
        }
        $transport = $observer->getEvent()->getTransport();
        $html = (string) $transport->getHtml();
        if ($html === '' || strpos($html, 'ysr-sales-item') !== false) {
            return;
        }

        $storeId = (int) $orderItem->getStoreId();
        $product = $this->_getProduct($orderItem->getProductId(), $storeId);
        $variant = null;
        foreach ($orderItem->getChildrenItems() ?: [] as $child) {
            $variant = $this->_getProduct($child->getProductId(), $storeId);
            break;
        }

        $image = $this->_getImageUrl($variant) ?: $this->_getImageUrl($product);
        $editUrl = $product && Mage::getSingleton('admin/session')->isAllowed('catalog/products')
            ? Mage::helper('adminhtml')->getUrl('adminhtml/catalog_product/edit', ['id' => $product->getId()])
            : '';
        $storeUrl = $this->_getStoreUrl($product, $storeId);

        $escape = Mage::helper('core');
        $size = self::THUMBNAIL_SIZE;
        $box = "display:block;width:{$size}px;height:{$size}px;border:1px solid #ddd;background:#fff;";
        if ($image) {
            $img = sprintf(
                '<img src="%s" width="%d" height="%d" alt="%s" style="display:block;width:%2$dpx;height:%3$dpx;object-fit:contain" loading="lazy" />',
                $escape->escapeHtml($image),
                $size,
                $size,
                $escape->escapeHtml($orderItem->getName()),
            );
            $thumb = $editUrl
                ? sprintf('<a href="%s" target="_blank" style="%s">%s</a>', $escape->escapeHtml($editUrl), $box, $img)
                : sprintf('<span style="%s">%s</span>', $box, $img);
        } else {
            $thumb = sprintf('<span style="%sbackground:#f5f5f5"></span>', $box);
        }

        if ($editUrl) {
            $html = preg_replace(
                '#(<span id="order_item_\d+_title">)(.*?)(</span>)#s',
                '$1<a href="' . $escape->escapeHtml($editUrl) . '" target="_blank" title="'
                    . $escape->escapeHtml(Mage::helper('catalog')->__('Edit Product')) . '">$2</a>$3',
                $html,
                1,
            );
        }
        if ($storeUrl) {
            $link = sprintf(
                '<div class="ysr-sales-item__store" style="font-size:11px;margin:2px 0 4px"><a href="%s" target="_blank">%s</a></div>',
                $escape->escapeHtml($storeUrl),
                $escape->escapeHtml(Mage::helper('catalog')->__('View in store')),
            );
            $html = preg_replace('#(</h5>)#', '$1' . $link, $html, 1, $count);
            if (!$count) {
                $html = $link . $html;
            }
        }

        $transport->setHtml(sprintf(
            '<div class="ysr-sales-item" style="display:flex;gap:10px;align-items:flex-start">'
            . '<div style="flex:0 0 auto">%s</div><div style="flex:1 1 auto;min-width:0">%s</div></div>',
            $thumb,
            $html,
        ));
    }

    /**
     * @return Mage_Catalog_Model_Product|null
     */
    protected function _getProduct($productId, $storeId)
    {
        if (!$productId) {
            return null;
        }
        $key = $productId . '/' . $storeId;
        if (!isset($this->_products[$key])) {
            $product = Mage::getModel('catalog/product')->setStoreId($storeId)->load($productId);
            $this->_products[$key] = $product->getId() ? $product : false;
        }
        return $this->_products[$key] ?: null;
    }

    protected function _getImageUrl(?Mage_Catalog_Model_Product $product = null)
    {
        if (!$product) {
            return '';
        }
        foreach (['thumbnail', 'small_image', 'image'] as $attribute) {
            $file = $product->getData($attribute);
            if ($file && $file !== 'no_selection') {
                try {
                    return (string) Mage::helper('catalog/image')->init($product, $attribute)
                        ->resize(self::THUMBNAIL_SIZE * 2);
                } catch (Exception $e) {
                    return '';
                }
            }
        }
        return '';
    }

    /**
     * The product page in the order's store, when the product is enabled, visible on
     * its own and assigned to that store's website.
     */
    protected function _getStoreUrl(?Mage_Catalog_Model_Product $product, $storeId)
    {
        if (!$product || !$storeId
            || (int) $product->getStatus() !== Mage_Catalog_Model_Product_Status::STATUS_ENABLED
            || !$product->isVisibleInSiteVisibility()
        ) {
            return '';
        }
        $store = Mage::app()->getStore($storeId);
        if (!in_array((int) $store->getWebsiteId(), array_map('intval', $product->getWebsiteIds()), true)) {
            return '';
        }
        $rewrite = Mage::getModel('core/url_rewrite')->setStoreId($storeId)
            ->loadByIdPath('product/' . $product->getId());
        $path = $rewrite->getId() ? $rewrite->getRequestPath() : 'catalog/product/view/id/' . $product->getId();
        return $store->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK) . ltrim($path, '/');
    }
}
