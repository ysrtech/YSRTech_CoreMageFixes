<?php
/**
 * Ignores any per-product custom label / use_default override on a configurable
 * product's super attribute and always shows the attribute's own live per-store
 * label instead. Prevents a frozen/stale label (baked in when a configurable
 * product's attributes were assigned, whether via Admin or an importer) from
 * ever hiding a later translation made on the attribute itself.
 *
 * Applied via a class rewrite (see etc/config.xml) rather than a full core
 * file copy, so it only ever touches getLabel() and inherits everything else
 * from the real core class unchanged, on any current or future Magento 1 /
 * OpenMage version.
 */
class YSRTech_CoreMageFixes_Model_Catalog_Product_Type_Configurable_Attribute
    extends Mage_Catalog_Model_Product_Type_Configurable_Attribute
{
    /**
     * Retrieve attribute label
     *
     * @return string
     */
    public function getLabel()
    {
        if ($this->getProductAttribute()) {
            return $this->getProductAttribute()->getStoreLabel();
        }

        return $this->getData('label');
    }
}
