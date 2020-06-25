<?php

namespace Drupal\commerce_victoriabank\Controller;

use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsNotificationsInterface;
use Drupal\commerce_victoriabank\CommerceVictoriabankGateway;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\AccessException;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides the endpoint for payment notifications.
 */
class PaymentNotificationController extends ControllerBase {

  /**
   * Provides the "notify" page.
   *
   * Also called the "IPN", "status", "webhook" page by payment providers.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   Response represents an HTTP response.

   */
  public function notifyPage(Request $request) {
    try {
      $victoriabank_commerce_payments = $this->entityTypeManager()->getStorage("commerce_payment_gateway")
        ->loadByProperties(["plugin" => CommerceVictoriabankGateway::GATEWAY_ID]);
    }
    catch (\Exception $e) {
      $this->getLogger('commerce_victoriabank')->critical("IPN controller error: can't load payment plugin @plugin_id",
        ['@plugin_id' => CommerceVictoriabankGateway::GATEWAY_ID]);
      throw new PluginNotFoundException(CommerceVictoriabankGateway::GATEWAY_ID, "Can't load payment plugin");
    }
    // If victoriabank_redirect plugin has more payments gateway implements.
    if (count($victoriabank_commerce_payments) > 1) {
      $response_data = $request->request->all();
      $commerce_payment_gateway = '';
      foreach ($victoriabank_commerce_payments as $payment_gateway) {
        $payments = $this->entityTypeManager()->getStorage('commerce_payment')
          ->loadByProperties([
            'order_id' => $response_data['ORDER'], 'payment_gateway' => $payment_gateway->id()
          ]);
        // If exist payment with order_id and payment gateway id, set it.
        if (!empty($payments)) {
          $commerce_payment_gateway = $payment_gateway;
          break;
        }
      }
    }
    // If exist just one victoriabank_redirect plugin set it.
    else {
      $commerce_payment_gateway = reset($victoriabank_commerce_payments);
    }

    if (!$commerce_payment_gateway) {
      $this->getLogger('commerce_victoriabank')->warning($this->t("IPN request received, but no payment method was configured."));
      return new Response('', 200);
    }

    $payment_gateway_plugin = $commerce_payment_gateway->getPlugin();
    if (!$payment_gateway_plugin instanceof SupportsNotificationsInterface) {
      $this->getLogger('commerce_victoriabank')->critical($this->t("IPN controller error: Payment plugin doesn't support notification interface."));
      throw new AccessException('Invalid payment gateway provided.');
    }
    // Call onNotify method from payment gateway plugin.
    $response = $payment_gateway_plugin->onNotify($request);

    if (!$response) {
      // VB does not accepts any http response.
      $response = new Response('', 200);
    }

    return $response;
  }

}
