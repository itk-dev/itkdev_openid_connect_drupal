<?php

namespace Drupal\itkdev_openid_connect_drupal;

use Drupal\Core\Url;
use Drupal\externalauth\AuthmapInterface;
use Drupal\externalauth\ExternalAuthInterface;
use Drupal\itkdev_openid_connect_drupal\Helper\ConfigHelper;
use Drupal\user\UserInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Authorization helper.
 */
class AuthorizationManager implements AuthorizationManagerInterface {
  use LoggerTrait;
  use LoggerAwareTrait;

  /**
   * Session name for storing provider (id).
   */
  private const SESSION_PROVIDER = 'itkdev_openid_connect_drupal.provider';

  /**
   * The config helper.
   *
   * @var \Drupal\itkdev_openid_connect_drupal\Helper\ConfigHelper
   */
  private $configHelper;

  /**
   * The external auth.
   *
   * @var \Drupal\externalauth\ExternalAuthInterface
   */
  private $externalAuth;

  /**
   * The authmap.
   *
   * @var \Drupal\externalauth\AuthmapInterface
   */
  private $authMap;

  /**
   * The session.
   *
   * @var \Symfony\Component\HttpFoundation\Session\SessionInterface
   */
  private $session;

  /**
   * Constructor.
   */
  public function __construct(ConfigHelper $configHelper, ExternalAuthInterface $externalAuth, AuthmapInterface $authMap, SessionInterface $session, LoggerInterface $logger) {
    $this->configHelper = $configHelper;
    $this->externalAuth = $externalAuth;
    $this->authMap = $authMap;
    $this->session = $session;
    $this->setLogger($logger);
  }

  /**
   * {@inheritdoc}
   */
  public function getAuthenticationUrl(array $provider, array $query = []): ?Url {
    if ('itkdev_openid_connect_drupal' === $provider['module']) {
      return Url::fromRoute('itkdev_openid_connect_drupal.openid_connect', $query + ['key' => $provider['key']]);
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function authorize(UserInterface $user, string $key, array $providerData) {
    // To update user data and handle roles on every login, we use
    // ExternalAuthInterface::userLoginFinalize and update the auth map
    // ourselves rather than using ExternalAuthInterface::loginRegister or
    // similar.
    $provider = ConfigHelper::MODULE . '.' . $key;
    $this->externalAuth->userLoginFinalize($user, $user->getAccountName(), $provider);
    $this->authMap->save($user, $provider, $user->getAccountName(), $providerData);
    $this->session->set(self::SESSION_PROVIDER, $provider);
  }

  /**
   * {@inheritdoc}
   */
  public function isAuthorizedByProvider(string $provider): bool {
    return $this->session->get(self::SESSION_PROVIDER) === $provider;
  }

  /**
   * {@inheritdoc}
   */
  public function getAuthenticators(): array {
    $authenticators = $this->configHelper->getAuthenticators();

    foreach ($authenticators as $key => &$authenticator) {
      $authenticator['module'] = 'itkdev_openid_connect_drupal';
      $authenticator['key'] = $key;
      $authenticator['id'] = $authenticator['module'] . '.' . $authenticator['key'];
    }

    return $authenticators;
  }

  /**
   * {@inheritdoc}
   */
  public function log($level, $message, array $context = []) {
    if (NULL !== $this->logger) {
      $this->logger->log($level, $message, $context);
    }
  }

}
