<?php

namespace Drupal\Tests\commerce_klarna_checkout\Kernel;

use Drupal\commerce_klarna_checkout\Event\TransactionAlterEvent;
use Drupal\commerce_klarna_checkout\KlarnaManager;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_price\Price;
use Drupal\profile\Entity\Profile;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * KlarnaManager unit tests.
 *
 * @group commerce_klarna_checkout
 * @coversDefaultClass \Drupal\commerce_klarna_checkout\KlarnaManager
 */
class KlarnaManagerTest extends KlarnaKernelBase {

  /**
   * The order.
   *
   * @var \Drupal\commerce_order\Entity\OrderInterface
   */
  protected $order;

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    $orderItem = OrderItem::create([
      'type' => 'default',
      'title' => 'Test product',
    ]);
    $orderItem->setUnitPrice(new Price('11', 'EUR'));

    $this->order = Order::create([
      'type' => 'default',
      'store_id' => $this->store,
      'payment_gateway' => $this->gateway,
    ]);
    $this->order->addItem($orderItem);
    $this->order->save();
  }

  /**
   * Tests buildTransaction() method.
   *
   * @covers ::buildTransaction
   * @covers ::buildOrderData
   * @covers ::getReturnUrl
   * @covers ::getConnector
   * @covers ::getPlugin
   * @covers ::getCountryFromLocale
   */
  public function testBuildTransaction() {
    $expected = [
      'cart' => [
        'items' => [
          [
            'reference' => 'Test product',
            'name' => 'Test product',
            'quantity' => 1,
            'unit_price' => 1100,
            'tax_rate' => 2400,
          ],
        ],
      ],
      'purchase_country' => 'SE',
      'purchase_currency' => 'EUR',
      'locale' => 'sv-se',
      'merchant_reference' => [
        'orderid1' => $this->order->id(),
      ],
      'merchant' => [
        'id' => '12345',
        'terms_uri' => 'http://localhost/',
        'checkout_uri' => 'http://localhost/checkout/1/payment/cancel?commerce_payment_gateway=klarna_checkout',
        'confirmation_uri' => 'http://localhost/checkout/1/payment/return?commerce_payment_gateway=klarna_checkout',
        'push_uri' => 'http://localhost/payment/notify/klarna_checkout?commerce_order=1&step=complete',
        'back_to_store_uri' => 'http://localhost/checkout/1/payment/cancel?commerce_payment_gateway=klarna_checkout',
      ],
    ];

    $eventDispatcher = $this->getMockBuilder(EventDispatcherInterface::class)
      ->getMock();

    $eventDispatcher->expects($this->at(0))
      ->method('dispatch')
      ->willReturn(new TransactionAlterEvent($this->order, 'create', $expected));

    $eventDispatcher->expects($this->at(1))
      ->method('dispatch')
      // Override purchase_country when calling for the second time.
      ->willReturn(new TransactionAlterEvent($this->order, 'create', ['purchase_country' => 'FI'] + $expected));

    $mock = $this->getMockBuilder(\Klarna_Checkout_ConnectorInterface::class)
      ->getMock();

    $sut = new KlarnaManager($eventDispatcher, $mock);
    $build = $sut->buildOrderData($this->order);

    $this->assertEquals($expected, $build);

    $mock->expects($this->any())
      ->method('apply')
      ->willReturnCallback(function ($method, \Klarna_Checkout_ResourceInterface $resource, $options) {
        // Don't override data values when calling ::fetch().
        if (empty($options['data'])) {
          return;
        }
        $resource->parse($options['data'] + [
          'status' => 'checkout_incomplete',
        ]);
      });

    $transaction = $sut->buildTransaction($this->order);
    $this->assertInstanceOf(\Klarna_Checkout_ResourceInterface::class, $transaction);

    foreach ($expected as $key => $value) {
      $this->assertEquals($expected[$key], $transaction[$key]);
    }
    $this->assertEquals('checkout_incomplete', $transaction['status']);

    // Test that we can alter values through event.
    $transaction = $sut->buildTransaction($this->order);
    $this->assertEquals('FI', $transaction['purchase_country']);
  }

  /**
   * Test that we can update billling profile.
   *
   * @covers ::updateBillingProfile
   * @dataProvider billingProfileData
   */
  public function testUpdateBillingProfile(array $update, array $expected) {
    $profile = Profile::create([
      'type' => 'customer',
      'uid' => $this->order->getCustomerId(),
    ]);

    $profile->set('address', [
      'given_name' => 'Firstname',
      'family_name' => 'Lastname',
      'address_line1' => 'Fredrikinkatu 34',
      'postal_code' => '00100',
      'locality' => 'Helsinki',
      'country_code' => 'FI',
    ]);
    $this->order->setBillingProfile($profile)->save();

    /** @var \Drupal\commerce_klarna_checkout\KlarnaManager $sut */
    $sut = $this->container->get('commerce_klarna_checkout.payment_manager');
    $sut->updateBillingProfile($this->order, $update);

    /** @var \Drupal\address\AddressInterface $billing */
    $billing = $this->order->getBillingProfile()->get('address')->first();

    $this->assertEquals($expected['given_name'], $billing->getGivenName());
    $this->assertEquals($expected['family_name'], $billing->getFamilyName());
    $this->assertEquals($expected['address_line1'], $billing->getAddressLine1());
    $this->assertEquals($expected['postal_code'], $billing->getPostalCode());
    $this->assertEquals($expected['locality'], $billing->getLocality());
    $this->assertEquals($expected['country_code'], $billing->getCountryCode());
  }

  /**
   * Data provider for testUpdateBillingProfile().
   *
   * @return array
   *   The data.
   */
  public function billingProfileData() {
    return [
      [
        [
          'given_name' => 'Firstname1',
          'family_name' => 'Lastname1',
          'street_name' => 'Erottajankatu',
          'street_number' => '5',
          'postal_code' => '00120',
          'city' => 'Helsinki',
          'country' => 'FI',
        ],
        [
          'given_name' => 'Firstname1',
          'family_name' => 'Lastname1',
          'address_line1' => 'Erottajankatu 5',
          'postal_code' => '00120',
          'locality' => 'Helsinki',
          'country_code' => 'FI',
        ],
      ],
      [
        [
          'given_name' => 'Firstname2',
          'family_name' => 'Lastname2',
          'street_address' => 'Teststreet 23',
          'postal_code' => '00150',
          'city' => 'Stockholm',
          'country' => 'SE',
        ],
        [
          'given_name' => 'Firstname2',
          'family_name' => 'Lastname2',
          'address_line1' => 'Teststreet 23',
          'postal_code' => '00150',
          'locality' => 'Stockholm',
          'country_code' => 'SE',
        ],
      ],
      [
        [
          'given_name' => 'Firstname2',
          'family_name' => 'Lastname2',
          'postal_code' => '00150',
          'city' => 'Stockholm',
          'country' => 'SE',
        ],
        [
          'given_name' => 'Firstname2',
          'family_name' => 'Lastname2',
          'address_line1' => '',
          'postal_code' => '00150',
          'locality' => 'Stockholm',
          'country_code' => 'SE',
        ],
      ],
    ];
  }

}
