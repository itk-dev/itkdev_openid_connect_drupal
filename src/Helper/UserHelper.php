<?php

namespace Drupal\itkdev_openid_connect_drupal\Helper;

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
    $username = $payload['upn'];
    /** @var \Drupal\user\Entity\User $user */
    $user = user_load_by_name($username);
    if (!$user) {
      $user = User::create([
        'name' => $username,
      ]);
    }
    // Use username as default email. May be overridden by “fields“.
    $user->set('mail', $username);

    if (isset($options['fields']) && is_array($options['fields'])) {
      foreach ($options['fields'] as $claim => $fieldName) {
        if (isset($payload[$claim])) {
          $user->set($fieldName, $payload[$claim]);
        }
      }
    }

    $this->setRoles($user, $payload, $options);

    // Ensure that the user can actually log in.
    $user->activate();

    $user->save();

    return $user;
  }

  /**
   * Set roles on a user.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user.
   * @param array $payload
   *   The payload.
   * @param array $options
   *   The options.
   *
   * @return \Drupal\user\UserInterface
   *   The updated user.
   */
  public function setRoles(UserInterface $user, array $payload, array $options): UserInterface {
    // Remove all user roles.
    foreach ($user->getRoles() as $roleName) {
      $user->removeRole($roleName);
    }

    // Add roles from payload mapped to Drupal roles.
    $rolesKey = $options['roles_key'] ?? 'roles';
    $rolesMap = $options['roles_map'] ?? [];
    if (isset($payload[$rolesKey])) {
      foreach ((array) $payload[$rolesKey] as $role) {
        if (isset($rolesMap[$role])) {
          foreach ((array) $rolesMap[$role] as $roleName) {
            $user->addRole($roleName);
          }
        }
      }
    }

    // Add default roles.
    if (isset($options['default_roles'])) {
      foreach ($options['default_roles'] as $roleName) {
        $user->addRole($roleName);
      }
    }

    return $user;
  }

}
