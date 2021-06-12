<?php

namespace Drupal\itkdev_openid_connect_drupal\Helper;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Config helper.
 */
class ConfigHelper {
  public const MODULE = 'itkdev_openid_connect_drupal';

  /**
   * The itkdev_openid_connect_drupal config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  private $config;

  /**
   * Constructor.
   */
  public function __construct(ConfigFactoryInterface $configFactory) {
    $this->config = $configFactory->get('itkdev_openid_connect_drupal');
  }

  /**
   * Get authenticators.
   */
  public function getAuthenticators(): array {
    $authenticators = $this->config->get('authenticators');
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
