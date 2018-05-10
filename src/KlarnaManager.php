<?php

namespace Drupal\commerce_klarna_checkout;

use Drupal\commerce_klarna_checkout\Event\Events;
use Drupal\commerce_klarna_checkout\Event\TransactionAlterEvent;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Calculator;
use Drupal\Component\Utility\SortArray;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Url;
use Klarna_Checkout_Connector;
use Klarna_Checkout_Order;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Service used for making API calls using Klarna Checkout library.
 */
class KlarnaManager {

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The api connector.
   *
   * @var \Klarna_Checkout_ConnectorInterface
   */
  protected $connector;

  /**
   * Constructs a new instance.
   *
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher.
   * @param \Klarna_Checkout_ConnectorInterface $connector
   *   The connector.
   */
  public function __construct(EventDispatcherInterface $eventDispatcher, \Klarna_Checkout_ConnectorInterface $connector = NULL) {
    $this->eventDispatcher = $eventDispatcher;
    $this->connector = $connector;
  }

  /**
   * Builds the order data.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array
   *   Builds the create order request.
   */
  public function buildOrderData(OrderInterface $order) {
    $data['cart']['items'] = [];

    // Add order item data.
    foreach ($order->getItems() as $item) {
      $tax_rate = 0;
      foreach ($item->getAdjustments() as $adjustment) {
        if ($adjustment->getType() == 'tax') {
          $tax_rate = $adjustment->getPercentage();
        }
      }
      $item_amount = $item->getUnitPrice();
      $data['cart']['items'][] = [
        'reference' => $item->getTitle(),
        'name' => $item->getTitle(),
        'quantity' => (int) $item->getQuantity(),
        'unit_price' => (int) $item_amount->multiply('100')->getNumber(),
        'tax_rate' => $tax_rate ? (int) Calculator::multiply($tax_rate, '10000') : 0,
      ];
    }

    // Add adjustments (excluding tax).
    $adjustments = [];
    foreach ($order->collectAdjustments() as $adjustment) {
      $type = $adjustment->getType();
      $source_id = $adjustment->getSourceId();
      if ($type != 'tax') {
        if (empty($source_id)) {
          // Adjustments without a source ID are always shown standalone.
          $key = count($adjustments);
        }
        else {
          // Adjustments with the same type and source ID are combined.
          $key = $type . '_' . $source_id;
        }

        if (empty($adjustments[$key])) {
          $label_string = $adjustment->getLabel();
          if (method_exists($label_string, 'getUntranslatedString')) {
            $label_string = $label_string->getUntranslatedString();
          }
          $adjustments[$key] = [
            'reference' => $label_string,
            'name' => $label_string,
            'quantity' => 1,
            'unit_price' => (int) $adjustment->getAmount()->multiply('100')->getNumber(),
            'tax_rate' => 0,
          ];

          // Cart item object type (Klarna).
          if ($type == 'promotion') {
            $adjustments[$key]['type'] = 'discount';
          }
          elseif ($type == 'shipping') {
            $adjustments[$key]['type'] = 'shipping_fee';
          }
        }
        else {
          $adjustments[$key]['unit_price'] += (int) $adjustment->getAmount()->multiply('100')->getNumber();
        }
      }
    }
    $plugin = $this->getPlugin($order);
    // Sort the adjustments by weight.
    uasort($adjustments, [SortArray::class, 'sortByWeightElement']);
    // Merge adjustments to cart item objects (Klarna).
    $data['cart']['items'] = array_values(array_merge($data['cart']['items'], $adjustments));

    $data['purchase_country'] = $this->getCountryFromLocale($plugin->getLanguage());
    $data['purchase_currency'] = $order->getTotalPrice()->getCurrencyCode();
    $data['locale'] = $plugin->getLanguage();
    $data['merchant_reference'] = ['orderid1' => $order->id()];
    $data['merchant'] = [
      'id' => $plugin->getMerchantId(),
      'terms_uri' => $plugin->getTermsUrl(),
      'checkout_uri' => $this->getReturnUrl($order, 'commerce_payment.checkout.cancel'),
      'confirmation_uri' => $this->getReturnUrl($order, 'commerce_payment.checkout.return'),
      'push_uri' => $this->getReturnUrl($order, 'commerce_payment.notify', 'complete'),
      'back_to_store_uri' => $this->getReturnUrl($order, 'commerce_payment.checkout.cancel'),
    ];

    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function buildTransaction(OrderInterface $order) {
    $build = $this->buildOrderData($order);

    /** @var \Drupal\commerce_klarna_checkout\Event\TransactionAlterEvent $event */
    $event = $this->eventDispatcher
      ->dispatch(Events::TRANSACTION_ALTER, new TransactionAlterEvent($order, 'create', $build));

    // Allow other modules to alter values.
    $create = $event->getValues();

    // Attempt to update existing order.
    if ($klarna_order = $this->getOrder($order)) {
      $klarna_order->update($create);
    }
    else {
      $klarna_order = new Klarna_Checkout_Order($this->getConnector($order));
      $klarna_order->create($create);
    }
    $klarna_order->fetch();

    return $klarna_order;
  }

  /**
   * Get return url for given type and checkout step.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param string $type
   *   Return type.
   * @param string $step
   *   Step id.
   *
   * @return \Drupal\Core\GeneratedUrl|string
   *   Return absolute return url.
   */
  protected function getReturnUrl(OrderInterface $order, $type, $step = 'payment') {
    $arguments = [
      'commerce_order' => $order->id(),
      'step' => $step,
      'commerce_payment_gateway' => 'klarna_checkout',
    ];
    $url = new Url($type, $arguments, [
      'absolute' => TRUE,
    ]);

    return $url->toString();
  }

  /**
   * Get Klarna Connector.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Klarna_Checkout_ConnectorInterface
   *   Klarna Connector.
   */
  public function getConnector(OrderInterface $order) {
    if (!$this->connector instanceof \Klarna_Checkout_ConnectorInterface) {
      $plugin = $this->getPlugin($order);

      return Klarna_Checkout_Connector::create(
        $plugin->getPassword(),
        $plugin->getApiUri()
      );
    }
    return $this->connector;
  }

  /**
   * Get order details from Klarna.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Klarna_Checkout_Order|null
   *   Klarna order.
   */
  public function getOrder(OrderInterface $order) {
    try {
      $klarna_order = new Klarna_Checkout_Order($this->getConnector($order), $order->getData('klarna_id'));
      $klarna_order->fetch();

      return $klarna_order;
    }
    catch (\Klarna_Checkout_ApiErrorException $e) {
      // @todo Remove these.
      debug($e->getMessage(), TRUE);
      debug($e->getPayload(), TRUE);
    }

    return NULL;
  }

  /**
   * Update order's billing profile.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param array $klarna_billing_address
   *   Klarna billing address.
   */
  public function updateBillingProfile(OrderInterface $order, array $klarna_billing_address) {
    if ($billing_profile = $order->getBillingProfile()) {
      $street_address = '';

      if (array_key_exists('street_address', $klarna_billing_address)) {
        $street_address = $klarna_billing_address['street_address'];
      }
      elseif (array_key_exists('street_name', $klarna_billing_address)) {
        $street_address = sprintf('%s %s', $klarna_billing_address['street_name'], $klarna_billing_address['street_number']);
      }

      $billing_profile->get('address')->first()->setValue([
        'given_name' => $klarna_billing_address['given_name'],
        'family_name' => $klarna_billing_address['family_name'],
        // Only in Sweden, Norway and Finland: Street address.
        // Only in Germany and Austria: Street name and Street number.
        'address_line1' => $street_address,
        'postal_code' => $klarna_billing_address['postal_code'],
        'locality' => $klarna_billing_address['city'],
        'country_code' => Unicode::strtoupper($klarna_billing_address['country']),
      ]);
      $billing_profile->save();
    }
  }

  /**
   * Get payment gateway configuration.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return \Drupal\commerce_klarna_checkout\Plugin\Commerce\PaymentGateway\KlarnaCheckout
   *   Plugin configuration.
   */
  protected function getPlugin(OrderInterface $order) {
    /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface $payment_gateway */
    $payment_gateway = $order->payment_gateway->entity;

    return $payment_gateway->getPlugin();
  }

  /**
   * Gets the country code from language setting.
   *
   * @param string $language
   *   The language.
   *
   * @return bool|string
   *   The language or FALSE if not found.
   */
  protected function getCountryFromLocale($language) {
    $country_codes = [
      'sv-se' => 'SE',
      'fi-fi' => 'FI',
      'sv-fi' => 'FI',
      'nb-no' => 'NO',
      'de-de' => 'DE',
      'de-at' => 'AT',
    ];

    return empty($country_codes[$language]) ? FALSE : $country_codes[$language];
  }

}
