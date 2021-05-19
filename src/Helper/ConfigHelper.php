<?php

namespace Drupal\itkdev_openid_connect_drupal\Helper;

/**
 * Config helper.
 */
class ConfigHelper {

  /**
   * Get authenticators.
   */
  public function getAuthenticators(): array {
    $config = \Drupal::config('itkdev_openid_connect_drupal');

    $authenticators = $config->get('authenticators');
    if (!is_array($authenticators)) {
      $authenticators = [];
    }

    return $authenticators;
  }

  /**
   * Get authenticator.
   */
  public function getAuthenticator(string $key) {
    $authenticators = $this->getAuthenticators();

    if (!isset($authenticators[$key])) {
      throw new \OutOfBoundsException(sprintf('No such authenticator: %s', $key));
    }

    return $authenticators[$key];
  }

}
