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
 * prices are then rebuilt from the catalogrule_product table, and the rule objects
 * belong to a collection only this method sees. Even so, a released rule is left
 * whole: its conditions and actions go back to their serialized form, so
 * getConditions(), getMatchingProductIds() or save() on it behave as on a freshly
 * loaded rule.
 *
 * The method body mirrors OpenMage 20.x core; if core's applyAll() changes, carry
 * the change over here.
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
        // getConditions()/getActions() unset the *_serialized data once they have
        // built the tree, so put it back before dropping the tree. Otherwise the
        // rule would come back with no conditions (matching every product), and
        // saving it would wipe them.
        if ($rule->_conditions && !$rule->hasConditionsSerialized()) {
            $rule->setConditionsSerialized(serialize($rule->_conditions->asArray()));
        }
        if ($rule->_actions && !$rule->hasActionsSerialized()) {
            $rule->setActionsSerialized(serialize($rule->_actions->asArray()));
        }
        $rule->_conditions = null;
        $rule->_actions    = null;
        $rule->unsetData('collected_attributes');
        // The condition tree points back at the rule, so the cycle needs collecting.
        gc_collect_cycles();
    }
}
