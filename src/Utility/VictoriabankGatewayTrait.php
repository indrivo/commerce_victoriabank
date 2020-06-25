<?php

namespace Drupal\commerce_victoriabank\Utility;

use Drupal\commerce_victoriabank\CommerceVictoriabankGateway;
use Fruitware\VictoriaBankGateway\VictoriaBank\Response;
use Fruitware\VictoriaBankGateway\VictoriaBankGateway;

/**
 * Trait CommerceTrait.
 * @package Drupal\commerce_victoriabank\Utility
 */
trait VictoriabankGatewayTrait {

  /**
   * Init Victoriabank gateway with config parameters.
   *
   * @param array $plugin_config
   *   Configs from payment gateway.
   *
   * @return VictoriaBankGateway
   *   VictoriabankGateway object.
   */
  public function initVictoriaBankGateway(array $plugin_config) {
    $victoriaBank_gateway = new VictoriaBankGateway();
    $victoriaBank_gateway
      ->setMerchantId($plugin_config['merchant'])
      ->setMerchantTerminal($plugin_config['terminal'])
      ->setMerchantUrl($plugin_config['merchant_url'])
      ->setMerchantName($plugin_config['merchant_name'])
      ->setMerchantAddress($plugin_config['merchant_address'])
      ->setCountryCode(CommerceVictoriabankGateway::getStoreCountryCode())
      ->setDefaultCurrency(CommerceVictoriabankGateway::getStoreDefaultCurrencyCode());

    // Prepare security data options.
    $signature_first = CommerceVictoriabankGateway::SIGNATURE_FIRST;
    $signature_prefix = CommerceVictoriabankGateway::SIGNATURE_PREFIX;
    $signature_padding = CommerceVictoriabankGateway::SIGNATURE_PADDING;
    $public_key_path = $plugin_config['public_key_path'];
    $private_key_path = $plugin_config['private_key_path'];
    $bank_public_key_path = $plugin_config['bank_public_key'];
    $private_key_pass = $plugin_config['private_key_password'];
    $victoriaBank_gateway
      ->setSecurityOptions($signature_first, $signature_prefix, $signature_padding, $public_key_path, $private_key_path, $bank_public_key_path, $private_key_pass);
    $debug = (bool)$plugin_config['debug'];
//     $victoriaBank_gateway->setDebug($debug);
    $victoriaBank_gateway->setSslVerify(TRUE);

    // Set gateway URL to be used
    if ($plugin_config['mode'] == 'live') {
      $victoriaBank_gateway->setGatewayUrl(CommerceVictoriabankGateway::REDIRECT_LIVE_URL);
    }
    else {
      $victoriaBank_gateway->setGatewayUrl(CommerceVictoriabankGateway::REDIRECT_TEST_URL);
    }

    // Set Response require parameter.
    Response::$bankPublicKeyPath = $plugin_config['bank_public_key'];
    Response::$signaturePrefix = CommerceVictoriabankGateway::SIGNATURE_PREFIX;

    return $victoriaBank_gateway;
  }

}
