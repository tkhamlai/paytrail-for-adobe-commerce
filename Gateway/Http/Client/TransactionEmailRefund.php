<?php

namespace Paytrail\PaymentService\Gateway\Http\Client;

use GuzzleHttp\Exception\RequestException;
use Magento\Payment\Gateway\Data\OrderAdapterInterface;
use Magento\Payment\Gateway\Http\ClientInterface;
use Paytrail\PaymentService\Model\RefundCallback;
use Paytrail\SDK\Request\EmailRefundRequest;
use \Paytrail\PaymentService\Logger\PaytrailLogger;
use Magento\Payment\Gateway\Http\TransferInterface;
use Magento\Payment\Gateway\Command\Result\ArrayResultFactory;
use Magento\Payment\Gateway\Command\ResultInterface;
use \Paytrail\PaymentService\Model\Adapter\Adapter;
use Paytrail\SDK\Response\EmailRefundResponse;

class TransactionEmailRefund implements ClientInterface
{
    /**
     * TransactionRefund constructor.
     *
     * @param PaytrailLogger     $log
     * @param Adapter            $paytrailAdapter
     * @param EmailRefundRequest $emailRefundRequest
     * @param RefundCallback     $refundCallback
     */
    public function __construct(
        private readonly PaytrailLogger $log,
        private readonly Adapter $paytrailAdapter,
        private readonly EmailRefundRequest $emailRefundRequest,
        private readonly RefundCallback $refundCallback
    ) {
    }

    /**
     * PlaceRequest function
     *
     * @param TransferInterface $transferObject
     *
     * @return array|false
     */
    public function placeRequest(TransferInterface $transferObject)
    {
        $request       = $transferObject->getBody();

        return $this->emailRefund(
            $request['order'],
            $request['amount'],
            $request['parent_transaction_id']
        );
    }

    /**
     * EmailRefund function
     *
     * @param OrderAdapterInterface|null $order
     * @param float|null                 $amount
     * @param string|null                $transactionId
     *
     * @return array
     */
    public function emailRefund(
        OrderAdapterInterface $order = null,
        float $amount = null,
        string $transactionId = null
    ) {
        $response= [];

        try {
            $paytrailClient = $this->paytrailAdapter->initPaytrailMerchantClient();

            $this->log->debugLog(
                'request',
                \sprintf(
                    'Creating %s request to Paytrail API %s',
                    'email_refund',
                    isset($order) ? 'With order id: ' . $order->getId() : ''
                )
            );

            // Handle request
            $this->setEmailRefundRequestData($this->emailRefundRequest, $amount, $order);

            $emailRefundResponse = $paytrailClient->emailRefund($this->emailRefundRequest, $transactionId);
            $response = $this->formatResponse($emailRefundResponse);

            $this->log->debugLog(
                'response',
                sprintf(
                    'Response for email_refund. Transaction Id: %s',
                    $emailRefundResponse->getTransactionId()
                )
            );
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $this->log->error(\sprintf(
                    'Connection error to Paytrail Payment Service API: %s Error Code: %s',
                    $e->getMessage(),
                    $e->getCode()
                ));
                $response["error"]  = $e->getMessage();
            }
        } catch (\Exception $e) {
            $this->log->error(
                \sprintf(
                    'A problem occurred during Paytrail Api connection: %s',
                    $e->getMessage()
                ),
                $e->getTrace()
            );
            $response["error"]  = $e->getMessage();
        }

        return $response;
    }

    /**
     * CreateRefundCallback function
     *
     * @param EmailRefundRequest    $paytrailEmailRefund
     * @param float                 $amount
     * @param OrderAdapterInterface $order
     */
    private function setEmailRefundRequestData(
        EmailRefundRequest $paytrailEmailRefund,
        float $amount,
        OrderAdapterInterface $order
    ) {
        $paytrailEmailRefund->setEmail($order->getBillingAddress()->getEmail());

        $paytrailEmailRefund->setAmount(round($amount * 100));

        $callback = $this->refundCallback->createRefundCallback();
        $paytrailEmailRefund->setCallbackUrls($callback);
    }

    /**
     * Format Response for Validator
     *
     * @param array $response
     *
     * @return array
     */
    public function formatResponse(EmailRefundResponse $response): array
    {
        return [
            'status'         => $response->getStatus(),
            'provider'       => $response->getProvider(),
            'transaction_id' => $response->getTransactionId()
        ];
    }
}

