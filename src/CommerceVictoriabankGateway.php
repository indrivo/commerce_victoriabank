<?php

namespace Drupal\commerce_victoriabank;

use Drupal\commerce_order\Entity\Order;
use Drupal\Core\Datetime\DrupalDateTime;

/**
 * Provides Victoriabank related constants.
 *
 * @package Drupal\commerce_victoriabank
 */
class CommerceVictoriabankGateway {

  const REDIRECT_TEST_URL = 'https://ecomt.victoriabank.md/cgi-bin/cgi_link';

  const REDIRECT_LIVE_URL = 'https://egateway.victoriabank.md/cgi-bin/cgi_link';

  const SIGNATURE_FIRST = '0001';

  const SIGNATURE_PREFIX = '3020300C06082A864886F70D020505000410';

  const SIGNATURE_PADDING = '00';

  const GATEWAY_ID = 'victoriabank_redirect';

  /**
   * Get format timestamp.
   *
   * @return string
   *   Current format timestamp.
   */
  public static function getTimestamp() {
    $date_time = new DrupalDateTime();
    return $date_time->format('YmdHis');
  }

  /**
   * Get store country code.
   *
   * @return string
   *   Two letter country code.
   */
  public static function getStoreCountryCode() {
    /** @var \Drupal\commerce_store\Entity\Store $store */
    $store = \Drupal::entityTypeManager()
      ->getStorage('commerce_store')->loadDefault();
    /** @var \Drupal\address\AddressInterface $store_address */
    $store_address = $store->getAddress();
    return $store_address->getCountryCode();
  }

  /**
   * Get store default currency code.
   *
   * @return string
   *   Currency code..
   */
  public static function getStoreDefaultCurrencyCode() {
    /** @var \Drupal\commerce_store\Entity\Store $store */
    $store = \Drupal::entityTypeManager()
      ->getStorage('commerce_store')->loadDefault();
    return $store->getDefaultCurrencyCode();
  }


  /**
   * Get client email.
   *
   * @param string $order_id
   *   Order id.
   *
   * @return string
   *   Client email.
   */
  public static function getClientEmail($order_id) {
    $order = Order::load($order_id);
    return $order->getEmail() ?? \Drupal::config('system.site')->get('mail');
  }
}
