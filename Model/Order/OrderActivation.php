<?php

namespace Paytrail\PaymentService\Model\Order;

use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Framework\DB\TransactionFactory;
use Paytrail\PaymentService\Gateway\Config\Config;
use Psr\Log\LoggerInterface;

class OrderActivation
{
    /**
     * OrderActivation constructor.
     *
     * @param OrderRepositoryInterface $orderRepository
     * @param TransactionRepositoryInterface $transactionRepository
     * @param InvoiceService $invoiceService
     * @param TransactionFactory $transactionFactory
     * @param Config $gatewayConfig
     * @param LoggerInterface $logger
     */
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private InvoiceService $invoiceService,
        private TransactionFactory $transactionFactory,
        private Config $gatewayConfig,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Activate order.
     *
     * @param string $orderId
     *
     * @return void
     * @throws InputException
     * @throws LocalizedException
     */
    public function activateOrder($orderId): void
    {
        $order = $this->orderRepository->get($orderId);

        /**
         * Loop through order items and set canceled items as ordered
         */
        foreach ($order->getItems() as $item) {
            $item->setQtyCanceled('0.0000');
        }

        /**
         * Force order status change to 'processing'.
         */
        if ($order->getState() != Order::STATE_PROCESSING) {
            $order->setState(Order::STATE_PROCESSING);
            $order->setStatus($this->gatewayConfig->getDefaultOrderStatus());
        }

        $this->orderRepository->save($order);
        $this->processInvoice($order);
    }

    /**
     * Is canceled value.
     *
     * @param string $orderId
     * @return bool
     * @throws InputException
     */
    public function isCanceled($orderId): bool
    {
        $order = $this->orderRepository->get($orderId);
        $i = 0;

        foreach ($order->getItems() as $item) {
            if ((int)$item->getQtyCanceled() > 0) {
                $i++;
            }
        }

        $transactionId = $this->getCaptureTransaction($order);

        if ($i > 0 && $transactionId) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Get capture transaction.
     *
     * @param OrderInterface $order
     * @return false
     * @throws InputException
     */
    private function getCaptureTransaction($order): bool
    {
        $transactionId = false;
        $paymentId =  $order->getPayment()->getId();
        /* For backwards compatibility, e.g. Magento 2.2.9 requires 3 parameters. */
        $transaction = $this->transactionRepository->getByTransactionType('capture', $paymentId, $order->getId());
        if ($transaction) {
            $transactionId = $transaction->getTransactionId();
        }
        return $transactionId;
    }

    /**
     * Process invoice.
     *
     * @param OrderInterface $order
     * @return void
     * @throws InputException
     * @throws LocalizedException
     */
    private function processInvoice($order): void
    {
        $transactionId = $this->getCaptureTransaction($order);

        if ($order->canInvoice() && $transactionId) {
            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
            $invoice->setTransactionId($transactionId);
            $invoice->register();
            $this->saveInvoiceAndOrder($invoice, $order);
        }
    }

    /**
     * Save invoice and order.
     *
     * @param InvoiceInterface $invoice
     * @param OrderInterface $order
     * @return void
     * @throws LocalizedException
     */
    private function saveInvoiceAndOrder(InvoiceInterface $invoice, OrderInterface $order): void
    {
        try {
            /** @var \Magento\Framework\DB\Transaction $transactionSave */
            $transactionSave = $this->transactionFactory->create();
            $transactionSave->addObject(
                $invoice
            )->addObject(
                $order
            )->save();
        } catch (\Exception $e) {
            $message = __(
                'Paytrail unable to save re-active order from admin: %error',
                ['error' => $e->getMessage()]
            );
            $this->logger->critical($message);

            throw new LocalizedException($message);
        }
    }
}
