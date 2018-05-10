<?php

namespace Drupal\commerce_klarna_checkout\Event;

/**
 * Defines the Klarna checkout events.
 */
class Events {

  /**
   * An event to alter transaction values before sendimg them to Klarna.
   *
   * @var string
   */
  const TRANSACTION_ALTER = 'commerce_klarna_checkout.transaction_alter';

}
