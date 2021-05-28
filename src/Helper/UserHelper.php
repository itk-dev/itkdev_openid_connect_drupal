<?php

namespace Drupal\itkdev_openid_connect_drupal\Helper;

use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * User helper.
 */
class UserHelper {

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
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function buildUser(array $payload, array $options): UserInterface {
    if (!isset($options['fields']) || !is_array($options['fields'])) {
      throw new \RuntimeException('Fields must be an array');
    }
    $fields = $options['fields'];

    $nameClaim = $fields['name'] ?? NULL;
    if (!isset($payload[$nameClaim])) {
      throw new \RuntimeException(sprintf('Cannot get user name (claim: %s)', $nameClaim));
    }

    $name = $payload[$nameClaim];
    /** @var \Drupal\user\Entity\User $user */
    $user = user_load_by_name($name);
    if (!$user) {
      $user = User::create([
        'name' => $name,
      ]);
    }

    if (isset($fields['roles'])) {
      $this->setRoles($user, $payload, $fields['roles'], $options);
      // "roles" should not be set directly on user.
      unset($fields['roles']);
    }

    foreach ($fields as $fieldName => $claim) {
      if (isset($payload[$claim])) {
        $user->set($fieldName, $payload[$claim]);
      }
    }

    // Ensure that the user can actually log in.
    $user->activate();

    return $user;
  }

  /**
   * Set roles on a user.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user.
   * @param array $payload
   *   The payload.
   * @param string $rolesKey
   *   The roles key.
   * @param array $options
   *   The options.
   *
   * @return \Drupal\user\UserInterface
   *   The updated user.
   */
  public function setRoles(UserInterface $user, array $payload, string $rolesKey, array $options): UserInterface {
    // Remove all user roles.
    foreach ($user->getRoles() as $roleName) {
      $user->removeRole($roleName);
    }

    // Add roles from payload mapped to Drupal roles.
    if (isset($payload[$rolesKey])) {
      $rolesMap = $options['roles']['map'] ?? [];
      foreach ((array) $payload[$rolesKey] as $role) {
        if (isset($rolesMap[$role])) {
          foreach ((array) $rolesMap[$role] as $roleName) {
            $user->addRole($roleName);
          }
        }
      }
    }

    // Add default roles.
    $defaultRoles = $options['roles']['default'] ?? [];
    foreach ($defaultRoles as $roleName) {
      $user->addRole($roleName);
    }

    return $user;
  }

}
