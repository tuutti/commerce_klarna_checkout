<?php

namespace Drupal\commerce_klarna_checkout\Event;

use Drupal\commerce_order\Entity\OrderInterface;
use Symfony\Component\EventDispatcher\Event;

/**
 * Defines an event to alter order transaction.
 */
class TransactionAlterEvent extends Event {

  /**
   * The klarna order values.
   *
   * @var array
   */
  protected $values;

  /**
   * The commerce order.
   *
   * @var \Drupal\commerce_order\Entity\OrderInterface
   */
  protected $order;

  /**
   * The klarna step (create, created).
   *
   * @var string
   */
  protected $status;

  /**
   * Constructs a new instance.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param string $step
   *   The step.
   * @param array $values
   *   The klarna order values.
   */
  public function __construct(OrderInterface $order, $step, array $values) {
    $this->order = $order;
    $this->status = $step;
    $this->values = $values;
  }

  /**
   * Gets the step.
   *
   * @return string
   *   The step.
   */
  public function getStatus() {
    return $this->status;
  }

  /**
   * Gets the order.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface
   *   The order.
   */
  public function getOrder() {
    return $this->order;
  }

  /**
   * Gets the values.
   *
   * @return array
   *   The values.
   */
  public function getValues() {
    return $this->values;
  }

  /**
   * Sets the values.
   *
   * @param array $values
   *   The values.
   */
  public function setValues(array $values) {
    $this->values = $values;
  }

}
