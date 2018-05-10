<?php

namespace Drupal\Tests\commerce_klarna_checkout\Kernel;

use Drupal\commerce_klarna_checkout\KlarnaManager;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_price\Price;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * KlarnaCheckout unit tests.
 *
 * @group commerce_klarna_checkout
 * @coversDefaultClass \Drupal\commerce_klarna_checkout\Plugin\Commerce\PaymentGateway\KlarnaCheckout
 */
class KlarnaCheckoutTest extends KlarnaKernelBase {

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
   * @covers ::isLive
   * @covers ::getLanguage
   * @covers ::getMerchantId
   * @covers ::getPassword
   * @covers ::getApiUri
   * @covers ::getTermsUrl
   * @covers ::updateBillingProfile
   * @dataProvider updateConfigurationData
   */
  public function testUpdateConfiguration(array $update, array $expected) {
    /** @var \Drupal\commerce_klarna_checkout\Plugin\Commerce\PaymentGateway\KlarnaCheckout $plugin */
    $plugin = $this->gateway->getPlugin();
    $plugin->setConfiguration($update);
    $this->gateway->save();

    $this->assertEquals($expected['merchant_id'], $plugin->getMerchantId());
    $this->assertEquals($expected['password'], $plugin->getPassword());
    $this->assertEquals($expected['live_mode'], $plugin->isLive());
    $this->assertEquals($expected['language'], $plugin->getLanguage());
    $this->assertEquals($expected['terms_path'], $plugin->getTermsUrl());
    $this->assertEquals($expected['update_billing_profile'], $plugin->updateBillingProfile());
    $this->assertEquals($expected['api_url'], $plugin->getApiUri());
  }

  /**
   * Data provider for testUpdateConfiguration().
   */
  public function updateConfigurationdata() {
    return [
      [
        [
          'merchant_id' => '123',
          'password' => 'test',
          'live_mode' => 'live',
          'terms_path' => 'http://google.com',
          'language' => 'fi-fi',
          'update_billing_profile' => 0,
        ],
        [
          'merchant_id' => '123',
          'password' => 'test',
          'live_mode' => TRUE,
          'terms_path' => 'http://google.com',
          'language' => 'fi-fi',
          'update_billing_profile' => FALSE,
          'api_url' => \Klarna_Checkout_Connector::BASE_URL,
        ],
      ],
      [
        [
          'merchant_id' => '54321',
          'password' => 'test1',
          'live_mode' => 'test',
          'terms_path' => '/node/1',
          'language' => 'sv-fi',
          'update_billing_profile' => 1,
        ],
        [
          'merchant_id' => '54321',
          'password' => 'test1',
          'live_mode' => FALSE,
          'terms_path' => 'http://localhost/node/1',
          'language' => 'sv-fi',
          'update_billing_profile' => TRUE,
          'api_url' => \Klarna_Checkout_Connector::BASE_TEST_URL,
        ],
      ],
    ];
  }

  /**
   * @covers ::onReturn
   * @covers ::setPaymentManager
   * @covers ::createPayment
   * @covers ::getPayment
   */
  public function testOnReturn() {
    /** @var \Drupal\commerce_klarna_checkout\Plugin\Commerce\PaymentGateway\KlarnaCheckout $sut */
    $sut = $this->gateway->getPlugin();

    $connector = $this->getMockBuilder(\Klarna_Checkout_ConnectorInterface::class)
      ->getMock();
    $klarna_order = new \Klarna_Checkout_Order($connector);
    $klarna_order->parse(['status' => 'checkout_complete']);

    $mock = $this->getMockBuilder(KlarnaManager::class)
      ->disableOriginalConstructor()
      ->getMock();

    $mock->expects($this->at(0))
      ->method('getOrder')
      ->willReturn(NULL);

    $mock->expects($this->at(1))
      ->method('getOrder')
      ->willReturn(['status' => 'checkout_incomplete']);

    $sut->setPaymentManager($mock);

    $request = Request::createFromGlobals();

    // onReturn() should fail twice (empty order and invalid order status).
    for ($i = 0; $i < 2; $i++) {
      try {
        $sut->onReturn($this->order, $request);
        $this->fail('PaymentGatewayException expected.');
      }
      catch (PaymentGatewayException $e) {
      }
    }
    $mock->expects($this->any())
      ->method('getOrder')
      ->willReturn($klarna_order);

    $sut->onReturn($this->order, $request);
    $sut->onReturn($this->order, $request);
  }

  /**
   * @covers ::onNotify
   */
  public function testOnNotify() {
    $request = Request::createFromGlobals();

    /** @var \Drupal\commerce_klarna_checkout\Plugin\Commerce\PaymentGateway\KlarnaCheckout $sut */
    $sut = $this->gateway->getPlugin();

    // Test empty commerce_order parameter.
    $response = $sut->onNotify($request);
    $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    $this->assertTrue(strpos($response->getContent(), 'Notify callback called for an invalid order') !== FALSE);
  }

  /**
   * @covers ::completeKlarnaCheckout
   * @dataProvider getResourceData
   */
  public function testCompleteKlarnaCheckout($status, $message, array $expected) {
    $request = Request::createFromGlobals();
    $request->query->set('commerce_order', $this->order->id());

    /** @var \Drupal\commerce_klarna_checkout\Plugin\Commerce\PaymentGateway\KlarnaCheckout $sut */
    $sut = $this->gateway->getPlugin();

    $connector = $this->getMockBuilder(\Klarna_Checkout_ConnectorInterface::class)
      ->getMock();

    $order = $this->getMockBuilder(\Klarna_Checkout_Order::class)
      ->setConstructorArgs([$connector, '123'])
      ->getMock();

    foreach ($expected as $delta => $value) {
      $order->expects($this->at($delta))
        ->method('offsetGet')
        ->willReturn($value);
    }

    $mock = $this->getMockBuilder(KlarnaManager::class)
      ->disableOriginalConstructor()
      ->getMock();

    $mock->expects($this->any())
      ->method('getOrder')
      ->willReturn($order);

    $sut->setPaymentManager($mock);

    $response = $sut->onNotify($request);
    $this->assertEquals($status, $response->getStatusCode());
    $this->assertTrue(strpos($response->getContent(), $message) !== FALSE, $response->getContent());
  }

  public function getResourceData() {
    return [
      [
        Response::HTTP_BAD_REQUEST,
        'No order details returned from Klarna to order',
        [],
      ],
      [
        Response::HTTP_BAD_REQUEST,
        'Invalid order status (checkout_incomplete) received from Klarna for order',
        [
          'checkout_incomplete',
          'checkout_incomplete',
        ],
      ],
      [
        Response::HTTP_BAD_REQUEST,
        'Push notification for Order 1 [state: ]',
        [
          'checkout_complete',
          'checkout_complete',
        ],
      ],
    ];
  }

}
