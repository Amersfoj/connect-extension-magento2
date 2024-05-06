<?php

declare(strict_types=1);

namespace Worldline\Connect\Gateway\Command;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Command\CommandException;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Model\MethodInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Worldline\Connect\Model\Config;
use Worldline\Connect\Model\Worldline\Action\MerchantAction;
use Worldline\Connect\Model\Worldline\Api\ClientInterface;
use Worldline\Connect\Model\Worldline\StatusInterface;
use Worldline\Connect\Model\Worldline\Token\TokenService;
use Worldline\Connect\Sdk\V1\Domain\CreatePaymentResponse;
use Worldline\Connect\Sdk\V1\Domain\Payment as PaymentDefinition;

// phpcs:ignore SlevomatCodingStandard.Namespaces.UnusedUses.UnusedUse

class AuthorizeCommand implements CommandInterface
{
    /**
     * @var ClientInterface
     */
    // phpcs:ignore SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint
    private $client;

    /**
     * @var MerchantAction
     */
    // phpcs:ignore SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint
    private $merchantAction;

    /**
     * @var OrderRepositoryInterface
     */
    // phpcs:ignore SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint
    private $orderRepository;

    /**
     * @var Order\Email\Sender\OrderSender
     */
    // phpcs:ignore SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint
    private $orderSender;

    /**
     * @var LoggerInterface
     */
    // phpcs:ignore SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint
    private $logger;

    /**
     * @var Config
     */
    // phpcs:ignore SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint
    private $config;

    /**
     * @var CreateHostedCheckoutRequestBuilder
     */
    // phpcs:ignore SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint
    private $createHostedCheckoutRequestBuilder;

    /**
     * @var CreatePaymentRequestBuilder
     */
    // phpcs:ignore SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint
    private $createPaymentRequestBuilder;

    /**
     * @var TokenService
     */
    // phpcs:ignore SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint
    private $tokenService;

    public function __construct(
        ClientInterface $client,
        MerchantAction $merchantAction,
        OrderRepositoryInterface $orderRepository,
        Order\Email\Sender\OrderSender $orderSender,
        LoggerInterface $logger,
        Config $config,
        CreateHostedCheckoutRequestBuilder $createHostedCheckoutRequestBuilder,
        CreatePaymentRequestBuilder $createPaymentRequestBuilder,
        TokenService $tokenService
    ) {
        $this->client = $client;
        $this->merchantAction = $merchantAction;
        $this->orderRepository = $orderRepository;
        $this->orderSender = $orderSender;
        $this->logger = $logger;
        $this->config = $config;
        $this->createHostedCheckoutRequestBuilder = $createHostedCheckoutRequestBuilder;
        $this->createPaymentRequestBuilder = $createPaymentRequestBuilder;
        $this->tokenService = $tokenService;
    }

    // phpcs:disable SlevomatCodingStandard.TypeHints.DisallowArrayTypeHintSyntax.DisallowedArrayTypeHintSyntax
    /**
     * @param mixed[] $commandSubject
     * @throws CommandException
     * @throws LocalizedException
     */
    // phpcs:enable SlevomatCodingStandard.TypeHints.DisallowArrayTypeHintSyntax.DisallowedArrayTypeHintSyntax
    // phpcs:ignore SlevomatCodingStandard.Functions.FunctionLength.FunctionLength
    public function execute(array $commandSubject): mixed
    {
        /** @var Payment $payment */
        $payment = $commandSubject['payment']->getPayment();

        $order = $payment->getOrder();
        switch ($payment->getMethodInstance()->getConfigData('payment_flow')) {
            case Config::CONFIG_WORLDLINE_CHECKOUT_TYPE_OPTIMIZED_FLOW:
                $request = $this->createPaymentRequestBuilder->build(
                    $payment,
                    $payment->getMethodInstance()->getConfigData('payment_action') === MethodInterface::ACTION_AUTHORIZE
                );
                $order->addCommentToStatusHistory($request->toJson());
                $response = $this->client->createPayment($request);
                $order->addCommentToStatusHistory($response->toJson());

                $this->handleCreatePaymentResponse($response, $payment);
                break;
            case Config::CONFIG_WORLDLINE_CHECKOUT_TYPE_HOSTED_CHECKOUT:
                $request = $this->createHostedCheckoutRequestBuilder->build(
                    $payment,
                    $commandSubject['paymentAction'] === MethodInterface::ACTION_AUTHORIZE
                );
                $order->addCommentToStatusHistory($request->toJson());
                $response = $this->client->createHostedCheckout($request, $order->getStoreId());
                $order->addCommentToStatusHistory($response->toJson());

                $checkoutSubdomain = $this->config->getHostedCheckoutSubDomain($order->getStoreId());
                $worldlineRedirectUrl = $checkoutSubdomain . $response->partialRedirectUrl;

                $payment->setData('order_state', Order::STATE_PENDING_PAYMENT);
                $payment->setIsTransactionClosed(false);
                $payment->setIsTransactionPending(true);

                $payment->setTransactionId($response->hostedCheckoutId);
                $payment->setAdditionalInformation(Config::REDIRECT_URL_KEY, $worldlineRedirectUrl);
                $payment->setAdditionalInformation(Config::HOSTED_CHECKOUT_ID_KEY, $response->hostedCheckoutId);
                $payment->setAdditionalInformation(Config::RETURNMAC_KEY, $response->RETURNMAC);
                break;
        }

        return null;
    }

    // phpcs:ignore SlevomatCodingStandard.Functions.FunctionLength.FunctionLength
    private function handleCreatePaymentResponse(
        CreatePaymentResponse $createPaymentResponse,
        Payment $payment
    ) {
        $payment->setLastTransId($createPaymentResponse->payment->id);
        $payment->setTransactionId($createPaymentResponse->payment->id);

        $order = $payment->getOrder();
        $order->addRelatedObject($payment);

        if ($createPaymentResponse->merchantAction && $createPaymentResponse->merchantAction->actionType) {
            $this->merchantAction->handle($payment, $createPaymentResponse);
        }

        $paymentResponse = $createPaymentResponse->payment;
        if ($paymentResponse === null) {
            return;
        }

        $amount = $order->getBaseGrandTotal();

        switch ($paymentResponse->status) {
            case StatusInterface::REDIRECTED:
                $payment->setData('order_state', Order::STATE_PENDING_PAYMENT);
                break;
            case StatusInterface::PENDING_APPROVAL:
            case StatusInterface::AUTHORIZATION_REQUESTED:
                $order->setState(Order::STATE_PENDING_PAYMENT);
                $order->setStatus(Order::STATE_PENDING_PAYMENT);

                $this->tokenService->createByOrderAndPayment($order, $paymentResponse);

                $payment->registerAuthorizationNotification($amount);
                break;
            case StatusInterface::CAPTURE_REQUESTED:
                $order->setState(Order::STATE_PROCESSING);
                $order->setStatus(Order::STATE_PROCESSING);

                $this->tokenService->createByOrderAndPayment($order, $paymentResponse);

                $payment->registerCaptureNotification($amount);
                break;
        }

        $this->processOrder($paymentResponse, $payment);

        $this->orderRepository->save($order);

        /**
         * Send new Order Email
         */
        try {
            $this->orderSender->send($order);
        // phpcs:ignore SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly.ReferenceViaFullyQualifiedName
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }

    /**
     * @param PaymentDefinition $createPaymentResponse
     * @param Payment $payment
     * @throws LocalizedException
     */
    private function processOrder(PaymentDefinition $createPaymentResponse, Payment $payment)
    {
        $paymentId = $createPaymentResponse->id;
        $paymentStatus = $createPaymentResponse->status;
        $paymentStatusCode = $createPaymentResponse->statusOutput->statusCode;

        $payment->setLastTransId($paymentId);
        $payment->setTransactionId($paymentId);
        $payment->setAdditionalInformation(Config::PAYMENT_ID_KEY, $paymentId);
        $payment->setAdditionalInformation(Config::PAYMENT_STATUS_KEY, $paymentStatus);
        $payment->setAdditionalInformation('payment_status', $paymentStatus);
        $payment->setAdditionalInformation(Config::PAYMENT_STATUS_CODE_KEY, $paymentStatusCode);
    }
}
