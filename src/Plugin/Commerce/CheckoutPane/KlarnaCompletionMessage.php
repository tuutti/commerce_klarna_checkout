<?php

namespace Drupal\commerce_klarna_checkout\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\commerce_klarna_checkout\KlarnaManager;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the completion message pane.
 *
 * @CommerceCheckoutPane(
 *   id = "klarna_completion_message",
 *   label = @Translation("Klarna Confirmation message"),
 *   default_step = "complete",
 * )
 */
class KlarnaCompletionMessage extends CheckoutPaneBase {

  /**
   * The klarna payment manager.
   *
   * @var \Drupal\commerce_klarna_checkout\KlarnaManager
   */
  protected $klarna;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, CheckoutFlowInterface $checkout_flow = NULL) {
    /** @var self $instance */
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition, $checkout_flow);

    // Populate via setters to avoid overriding the parent constructor.
    return $instance->setPaymentManager($container->get('commerce_klarna_checkout.payment_manager'));
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
   * {@inheritdoc}
   */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form) {
    $klarna_order = $this->klarna->getOrder($this->order);
    $snippet = $klarna_order['gui']['snippet'];

    $pane_form['klarna'] = [
      '#type' => 'inline_template',
      '#template' => "<div id='klarna-checkout-form'>{$snippet}</div>",
      '#context' => ['snippet' => $snippet],
    ];

    return $pane_form;
  }

  /**
   * {@inheritdoc}
   */
  public function isVisible() {
    /** @noinspection PhpUndefinedFieldInspection */
    // @todo debug, why the order can be empty, if payment fails!?!
    if ($this->order && $this->order->hasField('payment_gateway') && !$this->order->payment_gateway->isEmpty()) {
      /** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface $payment_gateway */
      /** @noinspection PhpUndefinedFieldInspection */
      $payment_gateway = $this->order->payment_gateway->entity;
      if ($payment_gateway && $payment_gateway->getPluginId() == 'klarna_checkout') {
        return TRUE;
      }
    }
    return FALSE;
  }

}
