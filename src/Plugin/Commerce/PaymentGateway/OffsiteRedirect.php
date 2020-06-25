<?php

namespace Drupal\commerce_victoriabank\Plugin\Commerce\PaymentGateway;

use Drupal\commerce\Response\NeedsRedirectException;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Annotation\CommercePaymentGateway;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_victoriabank\CommerceVictoriabankGateway;
use Drupal\commerce_victoriabank\Utility\VictoriabankGatewayTrait;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\Url;
use Fruitware\VictoriaBankGateway\VictoriaBank\Exception as VictoriabankException;
use Fruitware\VictoriaBankGateway\VictoriaBankGateway;
use Fruitware\VictoriaBankGateway\VictoriaBank\Response;
use Fruitware\VictoriaBankGateway\VictoriaBank\Completion\CompletionResponse;
use Fruitware\VictoriaBankGateway\VictoriaBank\Reversal\ReversalResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Provides the Off-site Redirect payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "victoriabank_redirect",
 *   label = "VictoriaBank (Off-site redirect)",
 *   display_label = "VictoriaBank",
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_victoriabank\PluginForm\OffsiteRedirect\PaymentOffsiteForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "mastercard", "visa",
 *   },
 *   requires_billing_information = TRUE,
 * )
 */
class OffsiteRedirect extends OffsitePaymentGatewayBase implements SupportsAuthorizationsInterface, SupportsRefundsInterface {

  const TRANSACTION_CAPTURE = 'capture';

  const TRANSACTION_AUTHORIZE = 'authorize';

  const PAYMENT_AUTHORIZATION = 'authorization';

  const PAYMENT_REFUNDED = 'refunded';

  const PAYMENT_COMPLETED = 'completed';

  const PAYMENT_AUTHORIZATION_VOIDED = 'authorization_voided';

  use LoggerChannelTrait;
  use VictoriabankGatewayTrait;

  /**
   * Logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Lock service.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  protected $lock;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);
    $this->logger = $this->getLogger('commerce_victoriabank');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var \Drupal\commerce_victoriabank\Plugin\Commerce\PaymentGateway\OffsiteRedirect $vb */
    $vb = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $vb->lock = $container->get('lock');
    return $vb;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'public_key_path' => '',
        'private_key_path' => '',
        'private_key_password' => '',
        'bank_public_key' => '',
        'merchant_name' => '',
        'merchant_url' => \Drupal::request()->getSchemeAndHttpHost(),
        'merchant' => '',
        'terminal' => '',
        'merchant_address' => '',
        'intent' => '',
        'use_ipn' => 0,
        'debug' => '',
      ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['public_key_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Public PEM file'),
      '#description' => $this->t('Path to the certificate PEM file containing public key'),
      '#default_value' => $this->configuration['public_key_path'],
      '#required' => TRUE,
      '#weight' => -6,
    ];

    $form['private_key_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Private PEM file'),
      '#description' => $this->t('Path to the certificate PEM file containing private key'),
      '#default_value' => $this->configuration['private_key_path'],
      '#required' => TRUE,
      '#weight' => -6,
    ];

    $form['private_key_password'] = [
      '#type' => 'password',
      '#title' => $this->t('Password for private pem key.'),
      '#description' => $this->t('Enter the password only if your private key has the password.'),
      '#default_value' => $this->configuration['private_key_password'],
      '#weight' => -6,
    ];

    $form['bank_public_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Bank public key'),
      '#description' => $this->t('Public key is provided by VictoriaBank'),
      '#default_value' => $this->configuration['bank_public_key'],
      '#required' => TRUE,
      '#weight' => -6,
    ];

    $form['merchant_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Merchant Name'),
      '#description' => $this->t('Merchant name (recognizable by cardholder)'),
      '#default_value' => $this->configuration['merchant_name'],
      '#required' => TRUE,
      '#weight' => -5,
    ];

    $form['merchant'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Merchant'),
      '#description' => $this->t('Merchant ID assigned by bank'),
      '#default_value' => $this->configuration['merchant'],
      '#required' => TRUE,
      '#weight' => -4,
    ];

    $form['terminal'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Terminal'),
      '#description' => $this->t('Merchant Terminal ID assigned by bank'),
      '#default_value' => $this->configuration['terminal'],
      '#required' => TRUE,
      '#weight' => -3,
    ];

    $form['merchant_address'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Merchant Address'),
      '#description' => $this->t('Merchant company registered office address'),
      '#default_value' => $this->configuration['merchant_address'],
      '#required' => TRUE,
      '#weight' => -2,
    ];

    $form['intent'] = [
      '#type' => 'radios',
      '#title' => $this->t('Transaction type'),
      '#required' => TRUE,
      '#options' => [
        self::TRANSACTION_CAPTURE => $this->t("Capture (capture payment immediately after customer's approval)"),
        self::TRANSACTION_AUTHORIZE => $this->t('Authorize (requires manual or automated capture after checkout)'),
      ],
      '#description' => $this->t('For more information on capturing a prior authorization,'
        . 'please refer to <a href=":url" target="_blank">Capture an authorization</a>.',
        [':url' => 'https://docs.drupalcommerce.org/commerce2/user-guide/payments/capture']),
      '#default_value' => $this->configuration['intent'] ?: self::TRANSACTION_CAPTURE,
    ];

    $form['use_ipn'] = [
      '#type' => 'radios',
      '#title' => $this->t('Use Bank IPNs to manage payment updates'),
      '#required' => TRUE,
      '#options' => [
        0 => $this->t("No, update payments from direct bank responses (useful for local development, testing or IPNs not working)"),
        1 => $this->t('Yes, use IPNs (recommended)'),
        2 => $this->t('Both, use IPNs and direct responses'),
      ],
      '#default_value' => $this->configuration['use_ipn'],
    ];

    $form['debug'] = [
      '#type' => 'radios',
      '#title' => $this->t('Debug'),
      '#options' => [
        'true' => $this->t("Yes"),
        'false' => $this->t("No"),
      ],
      '#description' => $this->t("If you enable debugging, before being redirected to Victoriabank you will see the form with all the parameters used to request."),
      '#default_value' => $this->configuration['debug'] ?: 'false',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);
    // Validate public key.
    if (!file_exists($values['public_key_path'])) {
      $form_state->setErrorByName('public_key_path', $this->t("Incorrect path to public key"));
    }
    else {
      $rsaKey = file_get_contents($values['public_key_path']);
      openssl_get_publickey($rsaKey) ?: $form_state->setErrorByName('public_key_path', $this->t("Can't get public key from file"));
    }
    // Validate private key.
    if (!file_exists($values['private_key_path'])) {
      $form_state->setErrorByName('private_key_path', $this->t("Incorrect path to private key"));
    }
    else {
      $rsaKey = file_get_contents($values['private_key_path']);
      openssl_get_privatekey($rsaKey, $values['private_key_password']) ?: $form_state->setErrorByName('private_key_path', $this->t("Can't get private key from file"));
    }
    // Validate bank public key.
    if (!file_exists($values['bank_public_key'])) {
      $form_state->setErrorByName('bank_public_key', $this->t("Incorrect path to bank public key"));
    }
    else {
      $rsaKey = file_get_contents($values['bank_public_key']);
      openssl_get_publickey($rsaKey) ?: $form_state->setErrorByName('bank_public_key', $this->t("Can't get bank public key from file"));
    }

  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration = $this->defaultConfiguration();
      $this->configuration['public_key_path'] = $values['public_key_path'];
      $this->configuration['private_key_path'] = $values['private_key_path'];
      $this->configuration['bank_public_key'] = $values['bank_public_key'];
      $this->configuration['private_key_password'] = $values['private_key_password'];
      $this->configuration['merchant_name'] = $values['merchant_name'];
      $this->configuration['merchant'] = $values['merchant'];
      $this->configuration['terminal'] = $values['terminal'];
      $this->configuration['merchant_address'] = $values['merchant_address'];
      $this->configuration['intent'] = $values['intent'];
      $this->configuration['use_ipn'] = (int)$values['use_ipn'];
      $this->configuration['debug'] = $values['debug'];
    }
  }

  /**
   * Is IPN used for payment states.
   * 
   * @return bool
   */
  public function useIpn() {
    return $this->configuration['use_ipn'] ?? 0;
  }

  /**
   * {@inheritdoc}
   */
  public function onNotify(Request $request) {
    if (!$this->useIpn()) {
      return;
    }

    $response_data = $request->request->all();
    if ($this->configuration['debug']) {
      $this->logger->notice("IPN: request @request", ['@request' => Json::encode($response_data)]);
    }

    $victoriabank_gateway = $this->initVictoriabankGateway($this->configuration);
    try {
      $bank_response = $victoriabank_gateway->getResponseObject($response_data);
    }
    catch (\Exception $e) {
      $this->logger->warning("IPN: invalid data for IPN request @request", ['@request' => Json::encode($response_data)]);
      return;
    }

    if (!$bank_response->isValid()) {
      $this->logger->critical('IPN: invalid bank auth response.' . Json::encode($bank_response->getErrors()));
      return;
    }

    // Load order based on posted info.
    $order_id = (int)$bank_response->{Response::ORDER};
    $order = $order_id ? $this->entityTypeManager->getStorage('commerce_order')->load($order_id) : NULL;
    if (!$order) {
      $this->logger->critical("IPN: Can't load ORDER: @order_id", ['@order_id' => $order_id]);
      return;
    }

    $rrn = $bank_response->{Response::RRN};
    $int_ref = $bank_response->{Response::INT_REF};
    $remote_id = "$rrn|$int_ref";

    switch ($bank_response->{Response::TRTYPE}) {
      // The bank answer was authorization, need to set the payment status authorization too.
      // Payment has only been blocked, to complete the transfer capture should be invoked.
      case VictoriaBankGateway::TRX_TYPE_AUTHORIZATION:
        // Check if IPN amount is correct for this order.
        if (!$this->isIPNOK($bank_response, $order)) {
          $this->logger->critical("IPN: Received amount is not equal to amount from order. @request", [
            '@request' => Json::encode($response_data)]);
          return;
        }

        $payment = $this->getPaymentByRemotePost($order, $bank_response);
        if (!$payment) {
          $this->logger->critical("IPN: Can't create payment for ORDER: @order_id", ['@order_id' => $order->id()]);
        }
        break;

      // Transfer was finished successful, need to set payment state as 'completed'.
      case VictoriaBankGateway::TRX_TYPE_COMPLETION:
        $payment = $this->loadPayment($remote_id, $rrn);
        if (!$payment) {
          $this->logger->critical("IPN: Can't find payment @payment for completion ORDER: @order_id", [
            '@payment' => $remote_id, '@order_id' => $order->id()]);
          return;
        }

        try {
          $this->finalizeCapturedPayment($payment, $bank_response);
        }
        catch (\Exception $e) {
          // Dont't stress bank with errors.
        }
        break;

      // The reversal operation has been completed.
      case VictoriaBankGateway::TRX_TYPE_REVERSAL:
        $payment = $this->loadPayment($remote_id);
        if (!$payment) {
          $this->logger->critical("IPN: Can't find payment @payment for reversal ORDER: @order_id", [
            '@payment' => $remote_id, '@order_id' => $order->id()]);
          return;
        }

        $payment_state = $payment->getState()->getId();
        try {
          if ($payment_state == self::PAYMENT_COMPLETED) {
            // Refund opperation.
            $this->finalizeRefundPayment($payment, $bank_response);
          }
          elseif ($payment_state == self::PAYMENT_AUTHORIZATION) {
            // Void authorization.
            $this->finalizeVoidPayment($payment, $bank_response);
          }
          else {
            // ...
          }
        }
        catch (\Exception $e) {
          // Dont't stress bank with errors.
        }
        break;

      default:
        throw new VictoriabankException('Unknown bank response transaction type');
    }
  }

  /**
   * Check if amount received from IPN is identical to amount from order.
   * 
   * @param Response $bank_response
   * @param OrderInterface $order
   * @return bool
   */
  public function isIPNOK(Response $bank_response, OrderInterface $order) {
    $order_amount = (float)$order->getTotalPrice()->getNumber();
    $order_currency = $order->getTotalPrice()->getCurrencyCode();
    $amount = (float)$bank_response->{Response::AMOUNT};
    $currency = $bank_response->{Response::CURRENCY};
    return $order_amount == $amount && $order_currency == $currency;
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    $response_data = $request->request->all();
    if ($this->configuration['debug']) {
      $this->logger->notice("Return: request @request", ['@request' => Json::encode($response_data)]);
    }

    if (1 == $this->useIpn()) {
      // Payment should be created only on IPN request.
      return;
    }

    $victoriabank_gateway = $this->initVictoriabankGateway($this->configuration);

    try {
      $bank_response = $victoriabank_gateway->getResponseObject($response_data);
    }
    catch (\Exception $e) {
      $this->logger->warning("Return: invalid post data from external server @request", ['@request' => Json::encode($response_data)]);
      $this->messenger()->addError($this->t("An error occurred while contacting the payment gateway. Please select another payment method or contact the site administrator."));
      $this->errorRedirectPage($order->id());
    }
    if (!$bank_response->isValid()) {
      $this->logger->critical('Return: invalid auth response.' . Json::encode($bank_response->getErrors()));
      $this->messenger()->addError($this->t("An error occurred while contacting the payment gateway. Please select another payment method or contact the site administrator."));
      // Redirect to checkout step: Order information.
      $this->errorRedirectPage($order->id());
    }

    // Load/create payment based on posted info.
    $payment = $this->getPaymentByRemotePost($order, $bank_response);

    if (!$payment) {
      $this->logger->critical("Return: can't load payment for ORDER: @order_id", ['@order_id' => $order->id()]);
      $this->messenger()->addError($this->t("An error occurred while loading payment. Please contact the site administrator."));
      $this->errorRedirectPage($order->id());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment) {
    $this->assertPaymentState($payment, [self::PAYMENT_AUTHORIZATION]);
    // Perform the void request here, throw an exception if it fails.
    try {
      $order_id = $payment->getOrderId();
      $amount_number = $payment->getAmount()->getNumber();
      $amount_currency = $payment->getAmount()->getCurrencyCode();
      $remote_id = $payment->getRemoteId();
      list($rrn, $int_ref) = explode('|', $remote_id);
      $victoriabank_gateway = $this->initVictoriabankGateway($this->configuration);
      $html_response = $victoriabank_gateway->requestReversal($order_id, $amount_number, $rrn, $int_ref, $amount_currency);
      $response_data = $this->getFormDataFromHtml($html_response);
      if ($this->configuration['debug']) {
        $this->logger->notice("Void response: @request", ['@request' => Json::encode($response_data)]);
      }

      if (1 == $this->useIpn()) {
        return;
      }

      $bank_response = new ReversalResponse($response_data);
      if ($bank_response->isValid()) {
        $this->finalizeVoidPayment($payment, $bank_response);
      }
      else {
        throw new \Exception('Invalid bank Auth response.' . Json::encode($bank_response->getErrors()));
      }
    }
    catch (\Exception $e) {
      $this->logger
        ->critical("Victoriabank error: exception on voidPayment, Payment id: @payment, order id: @order, ERROR: @error",
          [
            '@payment' => $payment->id(), '@order' => $payment->getOrderId(),
            '@error' => $e->getMessage()
          ]);
      throw new PaymentGatewayException('Problem with reversal request on void payment');
    }
  }

  /**
   * Change payment state to voided after reversal request.
   *
   * @param PaymentInterface $payment
   *   Payment object
   * @param ReversalResponse $response
   *   Posted data
   */
  public function finalizeVoidPayment(PaymentInterface $payment, ReversalResponse $response) {
    $lid = 'commerce_victoriabank_payment_' . $payment->id();
    if (!$this->lock->lockMayBeAvailable($lid)) {
      $this->lock->wait($lid);
    }
    if ($this->lock->acquire($lid)
      && ($payment = $this->entityTypeManager->getStorage('commerce_payment')->loadUnchanged($payment->id()))
      && $payment->getState()->getId() != self::PAYMENT_AUTHORIZATION_VOIDED) {
      $payment->setState(self::PAYMENT_AUTHORIZATION_VOIDED);
      $payment->save();
      $this->logger->notice('Voided payment @payment for order @order.',
        [
          '@payment' => $payment->id(),
          '@order' => $payment->getOrder()->id(),
        ]);
    }
    $this->lock->release($lid);
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL) {
    // Check if amount can be refunded.
    try {
      $this->assertPaymentState($payment, [self::PAYMENT_COMPLETED]);
      $amount = $amount ?: $payment->getAmount();
      $this->assertRefundAmount($payment, $amount);
    }
    catch (PaymentGatewayException $e) {
      throw new VictoriabankException($this->t('Refund error: @error', ['@error' => $e->getMessage()]));
    }

    // Get value from payment.
    try {
      $order_id = $payment->getOrderId();
      $amount_number = $amount->getNumber();
      $amount_currency = $amount->getCurrencyCode();
      $remote_id = $payment->getRemoteId();
      list($rrn, $int_ref) = explode('|', $remote_id);
      $victoria_bank_gateway = $this->initVictoriabankGateway($this->configuration);
      $html_response = $victoria_bank_gateway->requestReversal($order_id, $amount_number, $rrn, $int_ref, $amount_currency);
      $response_data = $this->getFormDataFromHtml($html_response);
      if ($this->configuration['debug']) {
        $this->logger->notice("Refund response: @request", ['@request' => Json::encode($response_data)]);
      }

      if (1 == $this->useIpn()) {
        return;
      }

      $bank_response = new ReversalResponse($response_data);
      if ($bank_response->isValid()) {
        $this->finalizeRefundPayment($payment, $bank_response);
      }
      else {
        throw new \Exception('Invalid bank Auth response.' . Json::encode($bank_response->getErrors()));
      }
    }
    catch (\Exception $e) {
      $this->logger->critical("Victoriabank error: Refund payment error PAYMENT: @payment_id", ['@payment_id' => $payment->id()]);
      throw new VictoriabankException($this->t("Victoriabank error: Can't get payment values @error", ['@error' => $e->getMessage()]));
    }
  }

  /**
   * Change payment state to refunded after reversal request.
   *
   * @param PaymentInterface $payment
   *   Payment object
   * @param ReversalResponse $response
   *   Posted data
   */
  public function finalizeRefundPayment(PaymentInterface $payment, ReversalResponse $response) {
    $lid = 'commerce_victoriabank_payment_' . $payment->id();
    if (!$this->lock->lockMayBeAvailable($lid)) {
      $this->lock->wait($lid);
    }
    if ($this->lock->acquire($lid)
      && ($payment = $this->entityTypeManager->getStorage('commerce_payment')->loadUnchanged($payment->id()))
      && $payment->getState()->getId() != self::PAYMENT_REFUNDED) {
      $amount = $response->{Response::AMOUNT};
      $currency = $response->{Response::CURRENCY};
      $payment->setState(self::PAYMENT_REFUNDED);
      $payment->setRefundedAmount(new Price($amount, $currency));
      $payment->save();
      $this->logger->notice('Refunded @amount @currency payment @payment for order @order.',
        [
          '@amount' => $amount,
          '@currency' => $currency,
          '@payment' => $payment->id(),
          '@order' => $payment->getOrder()->id(),
        ]);
    }
    $this->lock->release($lid);
  }

  /**
   * Retrieve payment based on order and posted data from payment gateway.
   * If payment is missing - creates one.
   * If payment method intent is capture - send capture request to gateway.
   *
   * @param OrderInterface $order
   * @param Response $bank_response
   * @return PaymentInterface $payment
   */
  public function getPaymentByRemotePost(OrderInterface $order, Response $bank_response) {
    // This method is called from IPN (if used) or after return,
    // lock is used to prevent multiple payments creation.
    $lid = 'commerce_victoriabank_' . $order->id();
    if (!$this->lock->lockMayBeAvailable($lid)) {
      $this->lock->wait($lid);
    }
    if ($this->lock->acquire($lid)) {

      $amount = $bank_response->{Response::AMOUNT};
      $currency = $bank_response->{Response::CURRENCY};
      $rrn = $bank_response->{Response::RRN};
      $int_ref = $bank_response->{Response::INT_REF};
      $remote_id = "$rrn|$int_ref";
      $rc = $bank_response->{Response::RC}; // @TODO: not required

      $payment = $this->loadPayment($remote_id);
      if (!$payment) {
        $payment = $this->createPayment(self::PAYMENT_AUTHORIZATION, $amount, $currency, $order->id(), $remote_id, $rc);
      }
    }
    $this->lock->release($lid);

    // Capture payment if so is requested by payment method configuration.
    if ($payment && $payment->getState()->getId() == self::PAYMENT_AUTHORIZATION
      && $this->configuration['intent'] == self::TRANSACTION_CAPTURE) {
      $this->capturePayment($payment);
    }

    return $payment ?? NULL;
  }

  /**
   * @inheritDoc
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {
    $this->assertPaymentState($payment, [self::PAYMENT_AUTHORIZATION]);
    // If not specified, capture the entire amount.
    /** @var Price $amount */
    $amount = $amount ?: $payment->getAmount();
    $amount_number = $amount->getNumber();
    $amount_currency = $amount->getCurrencyCode();
    $order_id = $payment->getOrderId();
    $remote_id = $payment->getRemoteId();
    list($rrn, $int_ref) = explode('|', $remote_id);

    try {
      $victoriabank_gateway = $this->initVictoriabankGateway($this->configuration);
      $html_response = $victoriabank_gateway->requestCompletion($order_id, $amount_number, $rrn, $int_ref, $amount_currency);
      $response_data = $this->getFormDataFromHtml($html_response);
      if ($this->configuration['debug']) {
        $this->logger->notice("Capture response: @request", ['@request' => Json::encode($response_data)]);
      }

      if (1 == $this->useIpn()) {
        return;
      }

      $bank_response = new CompletionResponse($response_data);
      if ($bank_response->isValid()) {
        $this->finalizeCapturedPayment($payment, $bank_response);
      }
      else {
        throw new \Exception('Invalid bank Auth response.' . Json::encode($bank_response->getErrors()));
      }
    }
    catch (\Exception $e) {
      $this->logger->critical("Victoriabank error: Capture request error PAYMENT: @payment_id", ['@payment_id' => $payment->id()]);
      throw new PaymentGatewayException($this->t("Victoriabank error: Capture payment @error", ['@error' => $e->getMessage()]));
    }
  }

  /**
   * Change payment state to completed if valid response received after capture request.
   * 
   * @param PaymentInterface $payment
   *   Payment object
   * @param CompletionResponse $response
   *   Posted data
   */
  public function finalizeCapturedPayment(PaymentInterface $payment, CompletionResponse $response) {
    $lid = 'commerce_victoriabank_payment_' . $payment->id();
    if (!$this->lock->lockMayBeAvailable($lid)) {
      $this->lock->wait($lid);
    }
    if ($this->lock->acquire($lid)
      && ($payment = $this->entityTypeManager->getStorage('commerce_payment')->loadUnchanged($payment->id()))
      && $payment->getState()->getId() != self::PAYMENT_COMPLETED) {
      // INT_REF will change after capture, remote_id will be updated to contain it also.
      $new_int_ref = $response->{Response::INT_REF};
      list($rrn, $old_int_ref) = explode('|', $payment->getRemoteId());
      $new_remote_id = "$rrn|$new_int_ref|$old_int_ref";
      $payment->setRemoteId($new_remote_id);
      $payment->setState(self::PAYMENT_COMPLETED);
      $payment->save();
      $this->logger->notice('Captured @amount @currency payment @payment for order @order.',
        [
          '@amount' => $payment->getAmount()->getNumber(),
          '@currency' => $payment->getAmount()->getCurrencyCode(),
          '@payment' => $payment->id(),
          '@order' => $payment->getOrder()->id(),
        ]);
    }
    $this->lock->release($lid);
  }

  /**
   * Create payment by response data.
   *
   * @param string $state
   *   Payment state.
   * @param string|int|float $amount
   *   Payment amount.
   * @param string $currency
   *   Payment currency.
   * @param string $order_id
   *   Order id.
   * @param string $remote_id
   *   E-Commerce gateway internal reference number.
   * @param string $remote_state
   *   Merchant bank’s retrieval reference number (ISO-8583 Field 37).
   *
   * @return EntityInterface
   *   Payment entity.
   * @see https://docs.drupalcommerce.org/commerce2/developer-guide/payments/payments-information-structure
   */
  public function createPayment($state, $amount, $currency, $order_id, $remote_id, $remote_state) {
    $payment = $this->entityTypeManager->getStorage('commerce_payment')->create([
      'state' => $state,
      'amount' => new Price($amount, $currency),
      'payment_gateway' => $this->parentEntity->id(),
      'order_id' => $order_id,
      'test' => $this->getMode() == 'test',
      'remote_id' => $remote_id,
      'remote_state' => $remote_state,
      'authorized' => $this->time->getRequestTime(),
    ]);
    $payment->save();
    return $payment;
  }

  /**
   * Get payment by bank internal reference number.
   *
   * @param string $remote_id
   *   Merchant bank’s retrieval reference number and  gateway internal reference number.
   * @param string $rrn
   *   If provided - search for payment only by rrn.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentInterface|NULL
   *   Return payment if exist.
   */
  public function loadPayment($remote_id, $rrn = NULL) {
    $payments_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payments_query = $payments_storage->getQuery();
    $payments_query->accessCheck(FALSE);
    $payments_query->condition('payment_gateway', (array) $this->getPaymentGatewayIds(), 'IN');
    $payments_query->condition('remote_id', $rrn ? $rrn . '|' : $remote_id, 'STARTS_WITH');
    $result = $payments_query->execute();
    $payments = $result ? $payments_storage->loadMultiple($result) : [];

//  $payments = $this->entityTypeManager->getStorage('commerce_payment')
//      ->loadByProperties([
//        'remote_id' => $remote_id,
//        'payment_gateway' => $this->getPaymentGatewayIds()
//      ]);
//     if (empty($payments)) {
//       $this->logger->critical("Can't load payment by remote id INT_REF: $int_ref.");
//       return NULL;
//     }
    /** @var Payment $payment */
    return reset($payments);
  }

  /**
   * Get payment gateway ids base on victoriabank_redirect plugin.
   *
   * @return array
   *   Payment gateway ids.
   */
  public function getPaymentGatewayIds() {
    $victoria_bank_gatewaies = $this->entityTypeManager->getStorage('commerce_payment_gateway')
      ->loadByProperties(['plugin' => CommerceVictoriabankGateway::GATEWAY_ID]);
    $ids = [];
    foreach ($victoria_bank_gatewaies as $bank_gateway) {
      $ids[] = $bank_gateway->id();
    }
    return $ids;
  }

  /**
   * Redirect to checkout step at error.
   *
   * @param string $order_id
   *   Order id.
   * @param string $step
   *   Checkout step id.
   *
   * @throws NeedsRedirectException
   */
  public function errorRedirectPage($order_id, $step = 'order_information') {
    $error_url = Url::fromRoute("commerce_checkout.form", [
      'commerce_order' => $order_id,
      'step' => $step,
    ], ['absolute' => TRUE])->toString();
    throw new NeedsRedirectException($error_url);
  }

  /**
   * Gets the redirect URL.
   *
   * @return string
   *   The redirect URL.
   */
  public function getRedirectUrl() {
    if ($this->getMode() == 'test') {
      return CommerceVictoriabankGateway::REDIRECT_TEST_URL;
    }
    else {
      return CommerceVictoriabankGateway::REDIRECT_LIVE_URL;
    }
  }

  /**
   * Get payment gateway id.
   *
   * @return string
   *   Payment gateway id.
   */
  public function getPaymentGatewayId(): string {
    return $this->parentEntity->id();
  }

  /**
   * Retrieves data from form inputs in a html string.
   * 
   * @param string $html
   *    Source html
   * @return array
   *    Array of values keyed by input names
   */
  public function getFormDataFromHtml($html) {
    $data = [];
    if (preg_match_all('/<input[^>]* name="([^"]+)" value="([^"]+)"/i', $html, $matches)) {
      $data = array_combine($matches[1], $matches[2]);
    }
    return $data;
  }
}
