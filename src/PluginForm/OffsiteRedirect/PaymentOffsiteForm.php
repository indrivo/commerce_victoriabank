<?php

namespace Drupal\commerce_victoriabank\PluginForm\OffsiteRedirect;

use Drupal\commerce\Response\NeedsRedirectException;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\commerce_victoriabank\Utility\VictoriabankGatewayTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_victoriabank\CommerceVictoriabankGateway;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Url;
use Fruitware\VictoriaBankGateway\VictoriaBank\Exception;

/**
 * Class PaymentOffsiteForm.
 *
 * @package Drupal\commerce_maib\PluginForm\OffsiteRedirect
 */
class PaymentOffsiteForm extends BasePaymentOffsiteForm {

  use LoggerChannelTrait;
  use MessengerTrait;
  use VictoriabankGatewayTrait;

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\Payment $payment */
    $payment = $this->entity;
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    $plugin_config = $payment_gateway_plugin->getConfiguration();

    // Get parameter for encrypt.
    $order_id = $payment->getOrderId();
    $amount = $payment->getAmount()->getNumber();
    $currency = $payment->getAmount()->getCurrencyCode();
    $description = "Order $order_id payment";
    $email = CommerceVictoriabankGateway::getClientEmail($order_id);
    $language = \Drupal::languageManager()->getCurrentLanguage()->getId();

    // Init gateway object.
    $victoria_gateway = $this->initVictoriaBankGateway($plugin_config);

    // Set parameter for live or test mode.
    if ($payment_gateway_plugin->getMode() == 'live') {
      $victoria_gateway->setGatewayUrl(CommerceVictoriabankGateway::REDIRECT_LIVE_URL);
    }
    else {
      $victoria_gateway->setGatewayUrl(CommerceVictoriabankGateway::REDIRECT_TEST_URL);
    }

    // Create authorization request.
    try {
      $this->getLogger('commerce_victoriabank')
        ->notice("Send authorization request with: order_id @order_id, amount @amount @currency, return url @return, email @email",
          [
            '@order_id' => $order_id,
            '@amount' => $amount,
            '@return' => $form['#return_url'],
            '@currency' => $currency,
            '@email' => $email,
          ]);
      $victoria_gateway->requestAuthorization($order_id, $amount, $form['#return_url'], $currency, $description, $email, $language);
    }
    catch (Exception $e) {
      $this->getLogger('commerce_victoriabank')->critical("Error to send authorization request: " . $e->getMessage());
      $this->messenger()->addError($this->t("An error occurred while contacting the payment gateway. Please contact the site administrator."));
      $error_url = Url::fromRoute("commerce_checkout.form", [
        'commerce_order' => $order_id,
        'step' => 'order_information',
      ], ['absolute' => TRUE])->toString();
      throw new NeedsRedirectException($error_url);
    }

  }

}
