<?php

namespace Drupal\commerce_klarna_checkout\PluginForm\OffsiteRedirect;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Cache\Cache;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Provides the Klarna checkout payment form.
 */
class KlarnaCheckoutForm extends BasePaymentOffsiteForm {

  use MessengerTrait;
  use StringTranslationTrait;
  use DependencySerializationTrait;

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    /** @var \Drupal\commerce_klarna_checkout\Plugin\Commerce\PaymentGateway\KlarnaCheckout $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();

    if (!isset($form['#cache']['tags'])) {
      $form['#cache']['tags'] = [];
    }
    $form['#cache']['tags'] = Cache::mergeTags($form['#cache']['tags'], $payment->getPaymentGateway()->getCacheTags());

    if (!$order = $payment->getOrder()) {
      $this->messenger()->addError($this->t('The provided payment has no order referenced. Please contact store administration if the problem persists.'));

      return $form;
    }

    $error = $this->t('Failed to render Klarna payment form. Please contact store administration if the problem persists.');

    try {
      // Add cart items and create a checkout order.
      $klarna_order = $payment_gateway_plugin->createKlarnaCheckout($payment);
    }
    catch (\Exception $e) {
      $this->messenger()->addError($error);

      return $form;
    }
    // Get checkout snippet.
    $snippet = isset($klarna_order['gui']['snippet']) ? $klarna_order['gui']['snippet'] : NULL;

    if (!$snippet) {
      $this->messenger()->addError($error);

      return $form;
    }

    // Embed snippet to plugin form (no redirect needed).
    $form['klarna'] = [
      '#type' => 'inline_template',
      '#template' => "<div id='klarna-checkout-form'>{$snippet}</div>",
      '#context' => ['snippet' => $snippet],
    ];

    return $form;
  }

}
