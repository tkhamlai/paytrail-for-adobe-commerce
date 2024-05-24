<?php

namespace Paytrail\PaymentService\Model\Payment\PaymentDataProvider;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Paytrail\PaymentService\Model\Payment\RoundingFixer;
use Paytrail\SDK\Model\Item;
use Magento\Sales\Model\ResourceModel\Order\Tax\Item as TaxItem;
use Paytrail\PaymentService\Model\Payment\DiscountApply;

class OrderItem
{
    /**
     * OrderItem constructor.
     *
     * @param DiscountApply $discountApply
     * @param TaxItem $taxItem
     */
    public function __construct(
        private DiscountApply $discountApply,
        private TaxItem       $taxItem,
        private RoundingFixer $roundingFixer
    ) {
    }

    /**
     * GetOrderLines function
     *
     * @param Order $order
     *
     * @return array|Item[]
     */
    public function getOrderLines(Order $order): array
    {
        $orderItems = $this->getItemsData($order);

        return array_map(
            function ($item) {
                return $this->mapToSdkItems($item);
            },
            $orderItems
        );
    }

    /**
     * CreateOrderItems function
     *
     * @param array $item
     *
     * @return Item
     */
    private function mapToSdkItems($item): Item
    {
        $paytrailItem = new Item();

        $paytrailItem->setUnitPrice(round($item['price'] * 100))
            ->setUnits($item['amount'])
            ->setVatPercentage($item['vat'])
            ->setProductCode($item['code'])
            ->setDeliveryDate(date('Y-m-d'))
            ->setDescription($item['title']);

        return $paytrailItem;
    }

    /**
     * Get items data from order
     *
     * @param Order $order
     *
     * @return array
     * @throws LocalizedException
     */
    private function getItemsData($order): array
    {
        $items = [];

        foreach ($order->getAllItems() as $item) {
            $qtyOrdered = $item->getQtyOrdered();

            $discountInclTax = $this->formatPrice(
                $item->getDiscountAmount() * (($item->getTaxPercent() / 100) + 1)
            );

            $rowTotalInclDiscount  = $item->getRowTotalInclTax() - $discountInclTax;
            $itemPriceInclDiscount = $this->formatPrice($rowTotalInclDiscount / $qtyOrdered);

            $paytrailItem = [
                'title'  => $item->getName(),
                'code'   => $item->getSku(),
                'amount' => $qtyOrdered,
                'price'  => $itemPriceInclDiscount,
                'vat'    => $item->getTaxPercent()
            ];

            $items [] = $paytrailItem;
        }

        if (!$order->getIsVirtual()) {
            $items[] = $this->getShippingItem($order);
        }

        $items = $this->discountApply->process($items, $order);
        $this->roundingFixer->correctRoundingErrors($items, $order->getGrandTotal(), $this->getItemTotal($items));

        return $items;
    }

    /**
     * GetShippingItem function
     *
     * @param Order $order
     *
     * @return array
     */
    private function getShippingItem(Order $order): array
    {
        $taxDetails = [];
        $price      = 0;

        if ($order->getShippingAmount()) {
            foreach ($this->taxItem->getTaxItemsByOrderId($order->getId()) as $detail) {
                if (isset($detail['taxable_item_type']) && $detail['taxable_item_type'] == 'shipping') {
                    $taxDetails = $detail;
                    break;
                }
            }

            $price = $order->getShippingAmount();
            $price -= $order->getShippingDiscountAmount();
            $price += $order->getShippingTaxAmount();
            $price += $order->getShippingDiscountTaxCompensationAmount();
        }

        return [
            'title'  => $order->getShippingDescription() ?: __('Shipping'),
            'code'   => 'shipping-row',
            'amount' => 1,
            'price'  => floatval($price),
            'vat'    => $taxDetails['tax_percent'] ?? 0,
        ];
    }

    /**
     * Calculate item total
     *
     * @param array $items
     *
     * @return float
     */
    public function getItemTotal(array $items): float
    {
        $total = 0;
        foreach ($items as $item) {
            $total += $item['price'] * $item['quantity'];
        }
        return $total;
    }

    /**
     * Format price.
     *
     * @param numeric $amount
     *
     * @return string
     */
    private function formatPrice($amount): string
    {
        return number_format(floatval($amount), 2, '.', '');
    }
}
