<?php

namespace Drupal\itkdev_openid_connect_drupal\Cache;

use Psr\Cache\CacheItemInterface;

/**
 * Cache item implementation.
 */
class CacheItem implements CacheItemInterface {
  /**
   * The key.
   *
   * @var string
   */
  private $key;

  /**
   * The value.
   *
   * @var mixed
   */
  private $value;

  /**
   * The is hit.
   *
   * @var bool
   */
  private $isHit;

  /**
   * The expiry.
   *
   * @var float|null
   */
  private $expiry;

  /**
   * CacheItem constructor.
   *
   * @param string $key
   *   The key.
   * @param mixed $data
   *   The data.
   * @param bool $isHit
   *   The is hit.
   */
  public function __construct(string $key, $data, bool $isHit) {
    $this->key   = $key;
    $this->value = $data;
    $this->isHit = $isHit;
  }

  /**
   * {@inheritdoc}
   */
  public function getKey(): string {
    return $this->key;
  }

  /**
   * {@inheritdoc}
   */
  public function get() {
    return $this->value;
  }

  /**
   * {@inheritdoc}
   */
  public function isHit(): bool {
    return $this->isHit;
  }

  /**
   * {@inheritdoc}
   */
  public function set($value): self {
    $this->value = $value;

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function expiresAt($expiration): self {
    if ($expiration === NULL) {
      $this->expiry = NULL;
    }
    elseif ($expiration instanceof DateTimeInterface) {
      $this->expiry = (float) $expiration->format('U.u');
    }
    else {
      throw new \RuntimeException(sprintf(
        'Expected $expiration to be an instance of DateTimeInterface or null, got %s',
        is_object($expiration) ? get_class($expiration) : gettype($expiration)
      ));
    }

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function expiresAfter($time): self {
    if ($time === NULL) {
      $this->expiry = NULL;
    }
    elseif ($time instanceof DateInterval) {
      $this->expiry = microtime(TRUE) + DateTime::createFromFormat('U', 0)->add($time)->format('U.u');
    }
    elseif (is_int($time)) {
      $this->expiry = $time + microtime(TRUE);
    }
    else {
      throw new \RuntimeException(sprintf(
        'Expected $time to be either an integer, an instance of DateInterval or null, got %s',
        is_object($time) ? get_class($time) : gettype($time)
      ));
    }

    return $this;
  }

}
