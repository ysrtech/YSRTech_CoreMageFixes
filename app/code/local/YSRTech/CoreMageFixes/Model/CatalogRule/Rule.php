<?php
/**
 * Lets Apply Rules (and the nightly catalogrule_apply_all cron job) free each
 * rule's matching data as soon as that rule is written, instead of holding every
 * rule's data until the last one is done.
 *
 * Core's applyAll() walks the rule collection, and matching a rule leaves two
 * catalog-sized structures on that rule object: _productIds (one entry per product
 * in the catalog, matched or not) and the condition tree, which caches every
 * product's value for each attribute the conditions test. The collection keeps all
 * rule objects alive, so memory grows by that much for every active rule. On a
 * catalog of ~19,000 products it is about 9MB per rule; 15 active rules took the
 * cron job past the CLI's default 128M memory_limit, so it died every night and was
 * left as "running" in cron_schedule.
 *
 * Only applyAll() is overridden. It does what core's does, with the release added
 * after each rule. Nothing reads that data after the rule is written: the rule
 * prices are then rebuilt from the catalogrule_product table, and getConditions()
 * rebuilds from conditions_serialized if anything asks again.
 */
class YSRTech_CoreMageFixes_Model_CatalogRule_Rule extends Mage_CatalogRule_Model_Rule
{
    /**
     * Apply all price rules, invalidate related cache and refresh price index
     *
     * @return void
     */
    public function applyAll()
    {
        $resource = $this->_getResource();
        foreach ($this->getResourceCollection() as $rule) {
            $resource->updateRuleProductData($rule);
            $this->_releaseMatchingData($rule);
        }
        $resource->applyAllRules();
        $this->_invalidateCache();
        $indexProcess = Mage::getSingleton('index/indexer')->getProcessByCode('catalog_product_price');
        if ($indexProcess) {
            $indexProcess->changeStatus(Mage_Index_Model_Process::STATUS_REQUIRE_REINDEX);
            $indexProcess->reindexAll();
        }
    }

    /**
     * Drop what a rule cached while matching products.
     *
     * @param Mage_CatalogRule_Model_Rule $rule
     */
    protected function _releaseMatchingData(Mage_CatalogRule_Model_Rule $rule)
    {
        $rule->_productIds = null;
        $rule->_conditions = null;
        $rule->_actions    = null;
        $rule->unsetData('collected_attributes');
        // The condition tree points back at the rule, so the cycle needs collecting.
        gc_collect_cycles();
    }
}
