<?php

namespace Drupal\itkdev_openid_connect_drupal\Helper;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserInterface;

/**
 * User helper.
 */
class UserHelper {
  /**
   * The user storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  private $userStorage;

  /**
   * Constructor.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->userStorage = $entityTypeManager->getStorage('user');
  }

  /**
   * Get user data from OpenID Connect payload.
   *
   * @param array $payload
   *   The payload.
   * @param array $options
   *   The options.
   *
   * @return array
   *   The user data.
   */
  public function getUserData(array $payload, array $options): array {
    if (!isset($options['fields']) || !is_array($options['fields'])) {
      throw new \RuntimeException('Fields must be an array');
    }
    $fields = $options['fields'];

    $nameClaim = $fields['name'] ?? NULL;
    if (!isset($payload[$nameClaim])) {
      throw new \RuntimeException(sprintf('Cannot get user name (claim: %s)', $nameClaim));
    }

    $data['name'] = $payload[$nameClaim];
    $data['roles'] = $this->getRoles($payload, $fields['roles'] ?? '', $options);

    foreach ($fields as $fieldName => $claim) {
      if ('roles' === $fieldName) {
        continue;
      }
      if (isset($payload[$claim])) {
        $data[$fieldName] = $payload[$claim];
      }
    }

    return $data;
  }

  /**
   * Get user roles from OpenID Connect payload.
   *
   * @param array $payload
   *   The payload.
   * @param string $rolesKey
   *   The roles key.
   * @param array $options
   *   The options.
   *
   * @return array
   *   The role names.
   */
  public function getRoles(array $payload, string $rolesKey, array $options): array {
    $roles = [];

    // Add roles from payload mapped to Drupal roles.
    if (isset($payload[$rolesKey])) {
      $rolesMap = $options['roles']['map'] ?? [];
      foreach ((array) $payload[$rolesKey] as $role) {
        if (isset($rolesMap[$role])) {
          foreach ((array) $rolesMap[$role] as $roleName) {
            $roles[] = $roleName;
          }
        }
      }
    }

    // Add default roles.
    $defaultRoles = $options['roles']['default'] ?? [];
    foreach ($defaultRoles as $roleName) {
      $roles[] = $roleName;
    }

    return $roles;
  }

  /**
   * Build a user from OpenID connect payload.
   *
   * @param array $payload
   *   The payload.
   * @param array $options
   *   The options.
   *
   * @return \Drupal\user\UserInterface
   *   The newly created or updated user.
   */
  public function buildUser(array $payload, array $options): UserInterface {
    $data = $this->getUserData($payload, $options);

    $name = $data['name'];
    $users = $this->userStorage->loadByProperties(['name' => $name]);
    $user = $users ? reset($users) : NULL;
    if (!$user) {
      $user = $this->userStorage->create([
        'name' => $name,
      ]);
    }

    $roles = $data['roles'] ?? NULL;
    unset($data['roles']);
    $this->setUserRoles($user, $roles);

    foreach ($data as $name => $value) {
      $user->set($name, $value);
    }

    return $user;
  }

  /**
   * Set roles on a user.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user.
   * @param array $roles
   *   The roles.
   *
   * @return \Drupal\user\UserInterface
   *   The updated user.
   */
  public function setUserRoles(UserInterface $user, array $roles): UserInterface {
    // Remove all user roles.
    foreach ($user->getRoles() as $roleName) {
      $user->removeRole($roleName);
    }

    foreach ($roles as $roleName) {
      $user->addRole($roleName);
    }

    return $user;
  }

}
