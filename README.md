# Drupal commerce payment gateway plugin for Victoriabank


REQUIREMENTS
------------

>Before start using this module you need to request merchant details from **Victoriabank**.


This module requires:

* **Module**: Commerce v2 (https://www.drupal.org/project/commerce)
* **Library**: Fruitware/VictoriaBankGateway (https://github.com/Fruitware/VictoriaBankGateway)


INSTALLATION
------------

 * Install as you would normally install a contributed Drupal module. Visit
   https://www.drupal.org/node/1897420 for further information.

CONFIGURATION
-------------
 
 * Create payment gateway: /admin/commerce/config/payment-gateways
 * Set payment plugin name. *Ex **Victoriabank***.
 * Select **VictoriaBank (Off-site redirect)** from plugins list.
 * Fill all required fields.
 * Select Transaction type.
 * Add condition for order restricted by currency Moldovan Leu.

IPN
---

 * This module can create/update payments from bank post responses (useful for testing and development) or based on direct notifications from bank (recommended for production).
 * In order to receive IPNs following URL should be provided to bank: http[s]://your-site.com/commerce-victoriabank/ipn

MAINTAINERS
-----------

Current maintainers:
 * Indrivo - https://github.com/indrivo
