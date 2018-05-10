<?php

namespace Drupal\Tests\commerce_klarna_checkout\Kernel;

use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_store\StoreCreationTrait;
use Drupal\commerce_tax\Entity\TaxType;
use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;

/**
 * Provides a base class to test klarna.
 */
abstract class KlarnaKernelBase extends EntityKernelTestBase {

  use StoreCreationTrait;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface|\PHPUnit_Framework_MockObject_MockObject
   */
  protected $eventDispatcher;

  /**
   * The default store.
   *
   * @var \Drupal\commerce_store\Entity\StoreInterface
   */
  protected $store;

  /**
   * The payment gateway.
   *
   * @var \Drupal\commerce_payment\Entity\PaymentGateway
   */
  protected $gateway;

  public static $modules = [
    'state_machine',
    'address',
    'profile',
    'entity_reference_revisions',
    'path',
    'datetime',
    'views',
    'entity',
    'options',
    'inline_entity_form',
    'commerce',
    'commerce_price',
    'commerce_store',
    'commerce_tax',
    'commerce_product',
    'commerce_checkout',
    'commerce_order',
    'commerce_payment',
    'commerce_paytrail',
    'commerce_promotion',
    'commerce_klarna_checkout',
  ];

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    $this->installEntitySchema('profile');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installEntitySchema('commerce_product');
    $this->installEntitySchema('commerce_product_variation');
    $this->installEntitySchema('commerce_payment');
    $this->installEntitySchema('commerce_payment_method');
    $this->installEntitySchema('commerce_promotion');
    $this->installConfig('path');
    $this->installConfig('commerce_order');
    $this->installConfig('commerce_product');
    $this->installConfig('commerce_checkout');
    $this->installConfig('commerce_payment');
    $this->installConfig('commerce_promotion');
    $this->installConfig('commerce_paytrail');
    $this->installSchema('system', 'router');
    $this->installEntitySchema('commerce_currency');
    $this->installEntitySchema('commerce_store');
    $this->installConfig(['commerce_store']);

    $currency_importer = \Drupal::service('commerce_price.currency_importer');
    $currency_importer->import('EUR');

    $this->store = $this->createStore('Default store', 'admin@example.com', 'online', TRUE, 'FI', 'EUR');
    \Drupal::entityTypeManager()->getStorage('commerce_store')->markAsDefault($this->store);

    $this->store->set('prices_include_tax', TRUE)->save();

    TaxType::create([
      'id' => 'vat',
      'label' => 'VAT',
      'plugin' => 'european_union_vat',
      'configuration' => [
        'display_inclusive' => TRUE,
      ],
    ])->save();

    $this->gateway = PaymentGateway::create(
      [
        'id' => 'klarna_checkout',
        'label' => 'Klarna',
        'plugin' => 'klarna_checkout',
      ]
    );
    $this->gateway->getPlugin()->setConfiguration(
      [
        'live_mode' => 'test',
        'merchant_id' => '12345',
        'password' => 'testPassword',
        'terms_path' => '/',
        'language' => 'sv-se',
        'update_billing_profile' => 0,
      ]
    );
    $this->gateway->save();

    $this->eventDispatcher = $this->container->get('event_dispatcher');
    $account = $this->createUser([]);

    \Drupal::currentUser()->setAccount($account);
  }

}
