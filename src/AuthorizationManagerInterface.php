<?php

namespace Drupal\itkdev_openid_connect_drupal;

use Drupal\Core\Url;
use Drupal\user\UserInterface;

/**
 * Authorization manager interface.
 */
interface AuthorizationManagerInterface {

  /**
   * Get authenticators.
   *
   * @return array
   *   The authenticators.
   */
  public function getAuthenticators(): array;

  /**
   * Get authentication url.
   *
   * @param array $provider
   *   The provider.
   * @param array $query
   *   The query.
   *
   * @return \Drupal\Core\Url|null
   *   The login url.
   */
  public function getAuthenticationUrl(array $provider, array $query = []): ?Url;

  /**
   * Authorize a user.
   */
  public function authorize(UserInterface $user, string $provider, array $providerData);

  /**
   * Decide if the current user is authorized by a provider.
   *
   * @param string $provider
   *   The provider id.
   *
   * @return bool
   *   True if the current user is authorized by the specified provider.
   */
  public function isAuthorizedByProvider(string $provider): bool;

}
