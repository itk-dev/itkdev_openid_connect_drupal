<?php

namespace Drupal\itkdev_openid_connect_drupal\Cache;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Cache item pool implementation.
 */
class CacheItemPool implements CacheItemPoolInterface {
  /**
   * The cache.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  private $cache;

  /**
   * Constructor.
   */
  public function __construct(CacheBackendInterface $cache) {
    $this->cache = $cache;
  }

  /**
   * {@inheritdoc}
   */
  public function getItem($key) {
    $value = $this->cache->get($this->getCid($key));

    return $value !== FALSE
      ? new CacheItem($key, $value->data, TRUE)
      : new CacheItem($key, NULL, FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function getItems(array $keys = []) {
    throw new \RuntimeException(__METHOD__ . ' not implemented!');
  }

  /**
   * {@inheritdoc}
   */
  public function hasItem($key) {
    throw new \RuntimeException(__METHOD__ . ' not implemented!');
  }

  /**
   * {@inheritdoc}
   */
  public function clear() {
    throw new \RuntimeException(__METHOD__ . ' not implemented!');
  }

  /**
   * {@inheritdoc}
   */
  public function deleteItem($key) {
    throw new \RuntimeException(__METHOD__ . ' not implemented!');
  }

  /**
   * {@inheritdoc}
   */
  public function deleteItems(array $keys) {
    throw new \RuntimeException(__METHOD__ . ' not implemented!');
  }

  /**
   * {@inheritdoc}
   */
  public function save(CacheItemInterface $item) {
    $this->cache->set(
      $this->getCid($item->getKey()),
      $item->get(),
      Cache::PERMANENT,
      ['itkdev_openid_connect_drupal']
    );
  }

  /**
   * {@inheritdoc}
   */
  public function saveDeferred(CacheItemInterface $item) {
    throw new \RuntimeException(__METHOD__ . ' not implemented!');
  }

  /**
   * {@inheritdoc}
   */
  public function commit() {
    throw new \RuntimeException(__METHOD__ . ' not implemented!');
  }

  /**
   * Get cache id.
   */
  private function getCid(string $key) {
    return 'itkdev_openid_connect_drupal:' . $key;
  }

}
