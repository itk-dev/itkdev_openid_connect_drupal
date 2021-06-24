<?php

namespace Drupal\itkdev_openid_connect_drupal\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\itkdev_openid_connect_drupal\AuthorizationManager;
use Drupal\itkdev_openid_connect_drupal\Helper\ConfigHelper;
use Drupal\itkdev_openid_connect_drupal\Helper\UserHelper;
use ItkDev\OpenIdConnect\Security\OpenIdConfigurationProvider;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Authentication controller.
 */
class AuthenticationController extends ControllerBase {
  use LoggerTrait;
  use LoggerAwareTrait;

  /**
   * Session name for storing OAuth2 state.
   */
  private const SESSION_STATE = 'itkdev_openid_connect_drupal.oauth2state';

  /**
   * Session name for storing OAuth2 nonce.
   */
  private const SESSION_NONCE = 'itkdev_openid_connect_drupal.oauth2nonce';

  /**
   * Session name for storing request query parameters.
   */
  private const SESSION_REQUEST_QUERY = 'itkdev_openid_connect_drupal.request_query';

  /**
   * The authorization manager.
   *
   * @var \Drupal\itkdev_openid_connect_drupal\AuthorizationManager
   */
  private $authorizationManager;

  /**
   * The config helper.
   *
   * @var \Drupal\itkdev_openid_connect_drupal\Helper\ConfigHelper
   */
  private $configHelper;

  /**
   * The user helper.
   *
   * @var \Drupal\itkdev_openid_connect_drupal\Helper\UserHelper
   */
  private $userHelper;

  /**
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  private $fileSystem;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  private $requestStack;

  /**
   * The cache item pool.
   *
   * @var \Drupal\itkdev_openid_connect_drupal\Cache\CacheItemPool
   */
  private $cacheItemPool;

  /**
   * {@inheritdoc}
   */
  public function __construct(AuthorizationManager $authorizationManager, ConfigHelper $configHelper, UserHelper $userHelper, RequestStack $requestStack, CacheItemPoolInterface $cacheItemPool, LoggerInterface $logger) {
    $this->authorizationManager = $authorizationManager;
    $this->configHelper = $configHelper;
    $this->userHelper = $userHelper;
    $this->requestStack = $requestStack;
    $this->cacheItemPool = $cacheItemPool;
    $this->setLogger($logger);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('itkdev_openid_connect_drupal.authorization_manager'),
      $container->get('itkdev_openid_connect_drupal.config_helper'),
      $container->get('itkdev_openid_connect_drupal.user_helper'),
      $container->get('request_stack'),
      $container->get('drupal_psr6_cache.cache_item_pool'),
      $container->get('logger.channel.itkdev_openid_connect_drupal')
    );
  }

  /**
   * Main controller action.
   *
   * @param string $key
   *   The authorizer key.
   *
   * @return \Symfony\Component\HttpFoundation\Response|array
   *   The response.
   */
  public function main(string $key) {
    $request = $this->requestStack->getCurrentRequest();

    if ($request->query->has('error')) {
      return $this->diplayError($key);
    }

    try {
      if ($request->query->has('state')) {
        return $this->process($key);
      }

      return $this->start($key);
    }
    catch (\Exception $exception) {
      return $this->displayException($key, $exception);
    }
  }

  /**
   * Start OpenID Connect flow.
   *
   * @param string $key
   *   The authenticator key.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  private function start(string $key): Response {
    $request = $this->requestStack->getCurrentRequest();
    $this->setSessionValue(self::SESSION_REQUEST_QUERY, ['query' => $request->query->all()]);

    $provider = $this->getOpenIdConfigurationProvider($key);
    $state = $provider->generateState();
    $nonce = $provider->generateNonce();

    $this->setSessionValue(static::SESSION_STATE, $state);
    $this->setSessionValue(static::SESSION_NONCE, $nonce);

    $authorizationUrl = $provider->getAuthorizationUrl([
      'state' => $state,
      'nonce' => $nonce,
    ]);

    $this->setSessionValue(self::SESSION_STATE, $provider->getState());

    return new TrustedRedirectResponse($authorizationUrl);
  }

  /**
   * Process OpenID Connect response.
   *
   * @param string $key
   *   The authenticator key.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  private function process(string $key): Response {
    $options = $this->getOptions($key);

    $request = $this->requestStack->getCurrentRequest();

    if (!$request->query->has('state') || !$request->query->has('id_token')) {
      $this->error('Missing state or id_token in response', ['query' => $request->query->all()]);
      throw new BadRequestHttpException('Missing state or id_token in response');
    }

    $state = $this->getSessionValue(self::SESSION_STATE);
    if ($state !== $request->query->get('state')) {
      $this->error('Invalid state', ['state' => $request->query->get('state')]);
      throw new BadRequestHttpException('Invalid state');
    }

    $provider = $this->getOpenIdConfigurationProvider($key);

    $payload = (array) $provider->validateIdToken($request->query->get('id_token'), $this->getSessionValue(static::SESSION_NONCE));

    // Authentication successful.
    if ($options['debug'] ?? FALSE) {
      $this->debug('Payload', ['payload' => $payload]);
    }

    $user = $this->userHelper->buildUser($payload, $options);
    $user->save();
    $this->authorizationManager->authorize($user, $key, $payload);

    $parameters = $this->getSessionValue(self::SESSION_REQUEST_QUERY);
    $location = $parameters['query']['location']
      ?? $options['default_location']
      ?? $this->getUrl('<front>');

    return new LocalRedirectResponse($location);
  }

  /**
   * Render error.
   *
   * @param string $key
   *   The authenticator key.
   *
   * @return array
   *   The render array.
   */
  private function diplayError(string $key): array {
    $options = $this->getOptions($key);
    $request = $this->requestStack->getCurrentRequest();
    $this->error('Error', [
      'query' => $request->query->all(),
    ]);

    return $this->renderError($key, [
      'message' => $request->query->get('error'),
      'description' => $request->query->get('error_description'),
    ]);
  }

  /**
   * Render error.
   *
   * @param string $key
   *   The authenticator key.
   * @param \Exception $exception
   *   The exception.
   *
   * @return array
   *   The render array.
   */
  private function displayException(string $key, \Exception $exception): array {
    $request = $this->requestStack->getCurrentRequest();

    $this->error('Exception: ' . $exception->getMessage(), [
      'query' => $request->query->all(),
      'exception' => $exception,
    ]);

    return $this->renderError($key, [
      'message' => $exception->getMessage(),
    ]);
  }

  /**
   * Render an error message.
   */
  private function renderError(string $key, array $error): array {
    $request = $this->requestStack->getCurrentRequest();
    $options = $this->getOptions($key);

    $title = $error['message'] ?? $error['error'] ?? $options['name'];
    $description = $error['description'] ?? NULL;

    return [
      'error' => [
        '#markup' => $title,
        '#prefix' => '<h1>',
        '#suffix' => '</h1>',
      ],
      'error_description' => [
        '#markup' => $description,
        '#prefix' => '<pre>',
        '#suffix' => '</pre>',
        '#access' => NULL !== $description,
      ],
      'authenticate' => [
        '#type' => 'link',
        '#title' => $this->t('Try again'),
        '#url' => Url::fromRoute(
          'itkdev_openid_connect_drupal.openid_connect',
          array_filter([
            'key' => $key,
            'location' => $request->query->get('location'),
          ])
        ),
      ],
    ];
  }

  /**
   * Get an OpenIdConfigurationProvider instance.
   */
  private function getOpenIdConfigurationProvider(string $key): OpenIdConfigurationProvider {
    $options = $this->getOptions($key);

    $providerOptions = [
      'redirectUri' => $this->getUrl(
        'itkdev_openid_connect_drupal.openid_connect',
        [
          'key' => $key,
        ],
        ['absolute' => TRUE]
      ),
      'openIDConnectMetadataUrl' => $options['openid_connect_discovery_url'],
      'cacheItemPool' => $this->cacheItemPool,
      'clientId' => $options['client_id'],
      'clientSecret' => $options['client_secret'],
    ];

    if ($options['debug'] ?? FALSE) {
      $this->debug('Provider options', ['options' => $providerOptions]);
    }

    return new OpenIdConfigurationProvider($providerOptions);
  }

  /**
   * Get url from a route.
   *
   * Wrapper around Url::fromRoute which does some weird bubbling stuff.
   *
   * @param string $name
   *   The route name.
   * @param array $parameters
   *   The route parameters.
   * @param array $options
   *   The options.
   *
   * @return string
   *   The url.
   */
  private function getUrl(string $name, array $parameters = [], array $options = []) {
    return Url::fromRoute(
      $name,
      $parameters,
      $options
    )
      // Prevent bubble error. @todo documentation!
      ->toString(TRUE)->getGeneratedUrl();
  }

  /**
   * Get session.
   */
  private function getSession(): SessionInterface {
    return $this->requestStack->getCurrentRequest()->getSession();
  }

  /**
   * Set session attribute value.
   *
   * @param string $name
   *   The session attribute name.
   * @param mixed $value
   *   The session attribute value.
   */
  private function setSessionValue(string $name, $value) {
    $this->getSession()->set($name, $value);
  }

  /**
   * Get and remove session attribute value.
   *
   * By default this function removes the value from the session, but it's
   * possible to just peek at the value of need be.
   *
   * @param string $name
   *   The session attribute name.
   * @param bool $peek
   *   If set, the session value will not be removed.
   *
   * @return mixed
   *   The session value.
   */
  private function getSessionValue(string $name, bool $peek = FALSE) {
    $value = $this->getSession()->get($name);
    if (!$peek) {
      $this->getSession()->remove($name);
    }

    return $value;
  }

  /**
   * Get options for an authenticator.
   *
   * @param string $key
   *   The authenticator key.
   *
   * @return array
   *   The authenticator options.
   */
  private function getOptions(string $key): array {
    try {
      return $this->configHelper->getAuthenticator($key);
    }
    catch (\Exception $exception) {
      $this->error(sprintf('Cannot get options for key $s', $key));
    }

    return [];
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
