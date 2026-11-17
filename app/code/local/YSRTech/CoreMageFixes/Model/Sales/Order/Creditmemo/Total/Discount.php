<?php
/**
 * Stops a credit memo fataling on a division by zero.
 *
 * Mage_Sales_Model_Order_Creditmemo_Total_Discount::collect() divides in two
 * places without checking the divisor:
 *
 *   1. The shipping discount is apportioned over the order's base shipping
 *      amount. The guard above it tests the *credit memo's* shipping amount,
 *      not the order's, so an order whose base shipping is zero while the
 *      credit memo carries a shipping amount divides by zero.
 *
 *   2. A partial refund apportions each item's discount over the quantity
 *      still available to refund, which is the invoiced quantity minus the
 *      quantity already refunded. That difference is zero once an item has
 *      been fully refunded.
 *
 * Before PHP 8 both produced INF or NAN and a warning, and the credit memo
 * still opened. On PHP 8 they raise DivisionByZeroError, which is not caught
 * anywhere on the path and returns a 500 from
 * Mage_Adminhtml_Sales_Order_CreditmemoController::newAction(), so the credit
 * memo screen cannot be reached at all for the affected order.
 *
 * Both are still present upstream as of OpenMage 20.18.0 - this is not a
 * regression from a local change, and the same file in the project's main
 * branch is byte-identical. The core's own shipping collector guards the
 * equivalent division with "if ($baseShipping != 0)", so guarding here follows
 * the surrounding convention rather than inventing one.
 *
 * With no shipping on the order there is no shipping discount to apportion,
 * and with nothing left to refund on an item there is no discount to spread
 * across it, so zero is the right answer in both cases rather than an error.
 *
 * Everything else is the core method unchanged. Drop this override once the
 * fix is upstream.
 *
 * @see Mage_Sales_Model_Order_Creditmemo_Total_Discount::collect()
 */
class YSRTech_CoreMageFixes_Model_Sales_Order_Creditmemo_Total_Discount extends Mage_Sales_Model_Order_Creditmemo_Total_Discount
{
    /**
     * @return $this
     */
    public function collect(Mage_Sales_Model_Order_Creditmemo $creditmemo)
    {
        $creditmemo->setDiscountAmount(0);
        $creditmemo->setBaseDiscountAmount(0);

        $order = $creditmemo->getOrder();

        $totalDiscountAmount = 0;
        $baseTotalDiscountAmount = 0;

        /**
         * Calculate how much shipping discount should be applied
         * basing on how much shipping should be refunded.
         */
        $baseShippingAmount = $creditmemo->getBaseShippingAmount();
        $baseOrderShippingAmount = (float) $order->getBaseShippingAmount();

        // Differs from core: the order's shipping amount is the divisor, so it
        // is what has to be non-zero, not the credit memo's.
        if ($baseShippingAmount && $baseOrderShippingAmount) {
            $baseShippingDiscount = $baseShippingAmount * $order->getBaseShippingDiscountAmount() / $baseOrderShippingAmount;
            $shippingDiscount = $order->getShippingAmount() * $baseShippingDiscount / $baseOrderShippingAmount;
            $totalDiscountAmount += $shippingDiscount;
            $baseTotalDiscountAmount += $baseShippingDiscount;
        }

        /** @var Mage_Sales_Model_Order_Invoice_Item $item */
        foreach ($creditmemo->getAllItems() as $item) {
            $orderItem = $item->getOrderItem();

            if ($orderItem->isDummy()) {
                continue;
            }

            $orderItemDiscount      = (float) $orderItem->getDiscountInvoiced();
            $baseOrderItemDiscount  = (float) $orderItem->getBaseDiscountInvoiced();
            $orderItemQty           = $orderItem->getQtyInvoiced();

            if ($orderItemDiscount && $orderItemQty) {
                $discount = $orderItemDiscount - $orderItem->getDiscountRefunded();
                $baseDiscount = $baseOrderItemDiscount - $orderItem->getBaseDiscountRefunded();

                if (!$item->isLast()) {
                    $availableQty = $orderItemQty - $orderItem->getQtyRefunded();

                    // Differs from core: nothing left to refund on this item
                    // means there is no discount to spread across it.
                    if ($availableQty) {
                        $discount = $creditmemo->roundPrice(
                            $discount / $availableQty * $item->getQty(),
                            'regular',
                            true,
                        );
                        $baseDiscount = $creditmemo->roundPrice(
                            $baseDiscount / $availableQty * $item->getQty(),
                            'base',
                            true,
                        );
                    } else {
                        $discount = 0;
                        $baseDiscount = 0;
                    }
                }

                $totalDiscountAmount += $discount;
                $baseTotalDiscountAmount += $baseDiscount;

                $item->setDiscountAmount($discount);
                $item->setBaseDiscountAmount($baseDiscount);
            }
        }

        $creditmemo->setDiscountAmount(-$totalDiscountAmount);
        $creditmemo->setBaseDiscountAmount(-$baseTotalDiscountAmount);

        $creditmemo->setGrandTotal($creditmemo->getGrandTotal() - $totalDiscountAmount);
        $creditmemo->setBaseGrandTotal($creditmemo->getBaseGrandTotal() - $baseTotalDiscountAmount);

        return $this;
    }
}
