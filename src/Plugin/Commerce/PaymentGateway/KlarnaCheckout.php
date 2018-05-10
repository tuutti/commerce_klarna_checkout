<?php

namespace Drupal\commerce_klarna_checkout\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_klarna_checkout\Event\Events;
use Drupal\commerce_klarna_checkout\Event\TransactionAlterEvent;
use Drupal\commerce_klarna_checkout\KlarnaManager;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsNotificationsInterface;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides the Off-site Redirect payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "klarna_checkout",
 *   label = "Klarna Checkout",
 *   display_label = "Klarna Checkout",
 *    forms = {
 *     "offsite-payment" = "Drupal\commerce_klarna_checkout\PluginForm\OffsiteRedirect\KlarnaCheckoutForm",
 *   },
 * )
 */
class KlarnaCheckout extends OffsitePaymentGatewayBase implements SupportsNotificationsInterface {

  /**
   * Service used for making API calls using Klarna Checkout library.
   *
   * @var \Drupal\commerce_klarna_checkout\KlarnaManager
   */
  protected $klarna;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var self $instance */
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    // Populate via setters to avoid overriding the parent constructor.
    $instance->setPaymentManager($container->get('commerce_klarna_checkout.payment_manager'))
      ->setEventDispatcher($container->get('event_dispatcher'))
      ->setLogger($container->get('logger.factory')->get('commerce_klarna_checkout'));

    return $instance;
  }

  /**
   * Sets the event dispatcher.
   *
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher.
   *
   * @return $this
   *   The self.
   */
  public function setEventDispatcher(EventDispatcherInterface $eventDispatcher) {
    $this->eventDispatcher = $eventDispatcher;
    return $this;
  }

  /**
   * Sets the payment manager.
   *
   * @param \Drupal\commerce_klarna_checkout\KlarnaManager $manager
   *   The klarna manager.
   *
   * @return $this
   *   The self.
   */
  public function setPaymentManager(KlarnaManager $manager) {
    $this->klarna = $manager;
    return $this;
  }

  /**
   * Sets the logger.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   *
   * @return $this
   *   The self.
   */
  public function setLogger(LoggerInterface $logger) {
    $this->logger = $logger;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'live_mode' => 'test',
      'merchant_id' => '',
      'password' => '',
      'terms_path' => '',
      'language' => 'sv-se',
      'update_billing_profile' => 0,
    ] + parent::defaultConfiguration();
  }

  /**
   * Gets the live mode status.
   *
   * @return bool
   *   Boolean indicating whether we are operating in live mode.
   */
  public function isLive() {
    return $this->configuration['live_mode'] === 'live';
  }

  /**
   * Get the language.
   *
   * @return string
   *   The language.
   */
  public function getLanguage() {
    return $this->configuration['language'];
  }

  /**
   * Gets the merchant id.
   *
   * @return string
   *   The merchant id.
   */
  public function getMerchantId() {
    return $this->configuration['merchant_id'];
  }

  /**
   * Gets the password.
   *
   * @return string
   *   The password.
   */
  public function getPassword() {
    return $this->configuration['password'];
  }

  /**
   * Whether we should update the billing profile or not.
   *
   * @return bool
   *   TRUE if we should update billing profile automatically, FALSE if not.
   */
  public function updateBillingProfile() {
    return $this->configuration['update_billing_profile'] == 1;
  }

  /**
   * Gets the api uri.
   *
   * @return string
   *   The api uri.
   */
  public function getApiUri() {
    return $this->isLive() ? \Klarna_Checkout_Connector::BASE_URL : \Klarna_Checkout_Connector::BASE_TEST_URL;
  }

  /**
   * Gets the terms uri.
   *
   * @return \Drupal\Core\GeneratedUrl|string
   *   The url.
   */
  public function getTermsUrl() {
    $path = $this->configuration['terms_path'];

    $is_external = UrlHelper::isExternal($path);

    if (!$is_external) {
      $path = Url::fromUserInput($path, ['absolute' => TRUE])->toString();
    }
    return $path;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['merchant_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Merchant ID'),
      '#default_value' => $this->configuration['merchant_id'],
    ];

    $form['password'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Password'),
      '#default_value' => $this->configuration['password'],
    ];

    $form['terms_path'] = [
      '#type'           => 'textfield',
      '#title'          => $this->t('Path to terms and conditions page'),
      '#default_value'  => $this->configuration['terms_path'],
      '#required'       => TRUE,
    ];

    $form['language'] = [
      '#type'           => 'select',
      '#title'          => $this->t('Language'),
      '#default_value'  => $this->configuration['language'],
      '#required'       => TRUE,
      '#options'        => [
        'sv-se'         => $this->t('Swedish'),
        'nb-no'         => $this->t('Norwegian'),
        'fi-fi'         => $this->t('Finnish'),
        'sv-fi'         => $this->t('Swedish (Finland)'),
        'de-de'         => $this->t('German'),
        'de-at'         => $this->t('German (Austria)'),
      ],
    ];

    $form['update_billing_profile'] = [
      '#type'           => 'select',
      '#title'          => $this->t('Update billing profile using information from Klarna'),
      '#description'    => $this->t('Using this option, you most probably want to hide Payment information from the Checkout panes programmatically.'),
      '#options'        => [
        0 => $this->t('No'),
        1 => $this->t('Yes'),
      ],
      '#default_value'  => $this->configuration['update_billing_profile'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);

    $terms_path = $form_state->getValue($form['#parents'])['terms_path'];

    try {
      $is_external = UrlHelper::isExternal($terms_path);

      if (!UrlHelper::isValid($terms_path, $is_external)) {
        throw new \InvalidArgumentException('Uri is not valid.');
      }
    }
    catch (\InvalidArgumentException $e) {
      $form_state->setError($form['terms_path'], $this->t('Invalid terms path: @error', [
        '@error' => $e->getMessage(),
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['live_mode'] = $this->getMode();
      $this->configuration['merchant_id'] = $values['merchant_id'];
      $this->configuration['password'] = $values['password'];
      $this->configuration['terms_path'] = $values['terms_path'];
      $this->configuration['language'] = $values['language'];
      $this->configuration['update_billing_profile'] = $values['update_billing_profile'];
    }
  }

  /**
   * Creates a new payment for given order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The commerce order.
   * @param \Klarna_Checkout_ResourceInterface $klarna_order
   *   The klarna order.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentInterface
   *   The payment.
   */
  protected function createPayment(OrderInterface $order, \Klarna_Checkout_ResourceInterface $klarna_order) {
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payment = $payment_storage->create([
      'state' => 'authorization',
      'amount' => $order->getTotalPrice(),
      'payment_gateway' => $this->entityId,
      'order_id' => $order->id(),
      'test' => $this->getMode() == 'test',
      'remote_id' => $klarna_order['id'],
      'remote_state' => 'paid',
      'authorized' => $this->time->getRequestTime(),
    ]);
    $payment->save();

    return $payment;
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    $klarna_order = $this->klarna->getOrder($order);

    if (!isset($klarna_order['status']) || $klarna_order['status'] !== 'checkout_complete') {
      $this->logger->error(
        $this->t('Confirmation failed for order @order [@ref]', [
          '@order' => $order->id(),
          '@ref' => $order->getData('klarna_id'),
        ])
      );

      throw new PaymentGatewayException();
    }

    // Create payment only if no payment exist yet.
    if (!$this->getPayment($order)) {
      $this->createPayment($order, $klarna_order);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onNotify(Request $request) {
    $commerce_order = NULL;

    if ($order_id = $request->query->get('commerce_order')) {
      $commerce_order = Order::load($order_id);
    }

    if (!$commerce_order instanceof OrderInterface) {
      $error = $this->t('Notify callback called for an invalid order @order [@values]', [
        '@order' => $request->query->get('commerce_order'),
        '@values' => print_r($request->query->all(), TRUE),
      ]);

      $this->logger->notice($error);

      return new Response($error, Response::HTTP_BAD_REQUEST);
    }
    try {
      $this->completeKlarnaCheckout($commerce_order);
    }
    catch (\InvalidArgumentException $e) {
      return new Response($e->getMessage(), Response::HTTP_BAD_REQUEST);
    }

    return new Response();
  }

  /**
   * Completes the Klarna checkout for given order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   */
  public function completeKlarnaCheckout(OrderInterface $order) {
    // Get order from Klarna.
    $klarna_order = $this->klarna->getOrder($order);

    if (!$klarna_order instanceof \Klarna_Checkout_ResourceInterface) {
      $error = $this->t('No order details returned from Klarna to order @order_id', [
        '@order_id' => $order->id(),
      ]);
      $this->logger->error($error);

      throw new \InvalidArgumentException($error);
    }

    if ($klarna_order['status'] !== 'checkout_complete') {
      $error = $this->t('Invalid order status (@status) received from Klarna for order @order_id', [
        '@status' => $klarna_order['status'],
        '@order_id' => $order->id(),
      ]);
      $this->logger->error($error);

      throw new \InvalidArgumentException($error);
    }

    // Validate commerce order and acknowledge order to Klarna.
    if (!$payment = $this->getPayment($order)) {
      // Create new payment if no payment exist yet. This usually happens when
      // user doesn't return from the payment gateway or when Klarna's IPN
      // completes the payment before the customer is redirected back from the
      // payment gateway.
      $payment = $this->createPayment($order, $klarna_order);
    }
    // Update billing profile (if enabled).
    if ($this->updateBillingProfile() && isset($klarna_order['billing_address'])) {
      $this->klarna->updateBillingProfile($order, $klarna_order['billing_address']);
    }
    /** @var \Drupal\commerce_klarna_checkout\Event\TransactionAlterEvent $event */
    $event = $this->eventDispatcher
      ->dispatch(Events::TRANSACTION_ALTER, new TransactionAlterEvent($order, 'created', [
        'status' => 'created',
      ]));

    // Allow other modules to alter values.
    $update = $event->getValues();

    // Update Klarna order status.
    $klarna_order->update($update);

    if ($klarna_order['status'] !== 'created') {
      $error = $this->t('Push notification for Order @order [state: @state, ref: @ref] ignored. Klarna order status not updated.', [
        '@order' => $order->id(),
        '@ref' => $order->getData('klarna_id'),
        '@state' => $order->getState()->value,
      ]);
      // Please note that Klarna will send the push notifications every two
      // hours for a total of 48 hours or until order has been confirmed.
      $this->logger->notice($error);

      throw new \InvalidArgumentException($error);
    }
    // Mark payment as captured.
    $payment->setState('completed');
    $payment->save();

    // Validate commerce order.
    $transition = $order->getState()
      ->getWorkflow()
      ->getTransition('validate');

    if (isset($transition)) {
      $order->getState()->applyTransition($transition);
    }
    // Save order changes.
    $order->save();
  }

  /**
   * Add cart items and create checkout order.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment.
   *
   * @return \Klarna_Checkout_Order
   *   The Klarna order.
   */
  public function createKlarnaCheckout(PaymentInterface $payment) {
    $order = $payment->getOrder();

    $klarna_order = $this->klarna->buildTransaction($order);

    if (!isset($klarna_order['id'])) {
      throw new \InvalidArgumentException('Failed to fetch order id.');
    }
    $order->setData('klarna_id', $klarna_order['id']);
    $order->save();

    return $klarna_order;
  }

  /**
   * Get payment for the given order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return bool|\Drupal\commerce_payment\Entity\PaymentInterface
   *   The payment.
   */
  protected function getPayment(OrderInterface $order) {
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface[] $payments */
    $payments = $this->entityTypeManager
      ->getStorage('commerce_payment')
      ->loadByProperties(['order_id' => $order->id()]);

    if (empty($payments)) {
      return FALSE;
    }
    foreach ($payments as $payment) {
      if ($payment->getPaymentGateway()->getPluginId() !== $this->pluginId || $payment->getAmount()->compareTo($order->getTotalPrice()) !== 0) {
        continue;
      }
      $klarna_payment = $payment;
    }
    return empty($klarna_payment) ? FALSE : $klarna_payment;
  }

}
