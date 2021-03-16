<?php

namespace Drupal\itkdev_openid_connect_drupal\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\itkdev_openid_connect_drupal\Helper\ConfigHelper;
use Drupal\itkdev_openid_connect_drupal\Helper\UserHelper;
use ItkDev\OpenIdConnect\Security\OpenIdConfigurationProvider;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Authentication controller.
 */
class AuthenticationController extends ControllerBase {
  use LoggerTrait;
  use LoggerAwareTrait;

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
   * {@inheritdoc}
   */
  public function __construct(ConfigHelper $configHelper, UserHelper $userHelper, FileSystemInterface $fileSystem, RequestStack $requestStack, LoggerInterface $logger) {
    $this->configHelper = $configHelper;
    $this->userHelper = $userHelper;
    $this->fileSystem = $fileSystem;
    $this->requestStack = $requestStack;
    $this->setLogger($$logger);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get(ConfigHelper::class),
      $container->get(UserHelper::class),
      $container->get('file_system'),
      $container->get('request_stack'),
      $container->get('logger')
    );
  }

  /**
   * Authenticate.
   */
  public function authenticate(string $key) {
    $options = $this->getOptions($key);

    $providerOptions = [
      'redirectUri' => $this->getUrl(
        'itkdev_openid_connect_drupal.authorize',
        [
          'key' => $key,
        ],
        ['absolute' => TRUE]
      ),
      'urlConfiguration' => $options['openid_connect_discovery_url'],
      'clientId' => $options['client_id'],
      'clientSecret' => $options['client_secret'],
    ];
    $providerOptions['cachePath'] = $this->fileSystem->getTempDirectory() . '/itkdev_openid_connect_drupal-' . $key . '-' . md5($providerOptions['redirectUri']) . '-cache.php';

    $request = $this->requestStack->getCurrentRequest();
    $this->setSessionValue(self::PARAMETERS_NAME, ['query' => $request->query->all()]);

    $provider = new OpenIdConfigurationProvider($providerOptions);

    $authorizationUrl = $provider->getAuthorizationUrl();

    $this->setSessionValue(self::STATE_NAME, $provider->getState());

    return new TrustedRedirectResponse($authorizationUrl);
  }

  /**
   * Authorize.
   */
  public function authorize(string $key) {
    $options = $this->getOptions($key);

    $request = $this->requestStack->getCurrentRequest();

    if (!$request->query->has('state') || !$request->query->has('id_token')) {
      $this->error('Missing state or id_token in response', ['query' => $request->query->all()]);
      throw new BadRequestHttpException('Missing state or id_token in response');
    }

    $state = $this->getSessionValue(self::STATE_NAME);
    if ($state !== $request->query->get('state')) {
      $this->error('Invalid state', ['state' => $request->query->get('state')]);
      throw new BadRequestHttpException('Invalid state');
    }

    // Retrieve id_token and decode it.
    // @see https://tools.ietf.org/html/rfc7519
    $idToken = $request->query->get('id_token');
    [$jose, $payload, $signature] = array_map('base64_decode', explode('.', $idToken));
    $payload = json_decode($payload, TRUE);

    if (!isset($payload['upn'])) {
      $this->error('Invalid payload', ['payload' => $payload]);
      throw new BadRequestHttpException('Invalid payload');
    }

    try {
      $user = $this->userHelper->buildUser($payload, $options);
    }
    catch (EntityStorageException $exception) {
      $this->error('Cannot create user', ['exception' => $exception]);
      throw new BadRequestHttpException('Cannot create user', $exception);
    }
    if (!$user) {
      $this->error('User not created', ['payload' => $payload]);
      throw new BadRequestHttpException('User not created');
    }

    user_login_finalize($user);

    $parameters = $this->getSessionValue(self::PARAMETERS_NAME);
    $location = $parameters['query']['location'] ?? $this->getUrl('<front>');

    return new RedirectResponse($location);
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

  private const STATE_NAME = 'oauth2state';
  private const PARAMETERS_NAME = 'oauth2params';

  /**
   * Get session.
   */
  private function getSession(): SessionInterface {
    return $this->requestStack->getCurrentRequest()->getSession();
  }

  /**
   * Set session value.
   */
  private function setSessionValue(string $name, $value) {
    $this->getSession()->set($name, $value);
  }

  /**
   * Get session value.
   */
  private function getSessionValue(string $name, bool $peek = FALSE) {
    $value = $this->getSession()->get($name);
    if (!$peek) {
      $this->getSession()->remove($name);
    }

    return $value;
  }

  /**
   * Get options.
   */
  private function getOptions(string $key): array {
    return $this->configHelper->getAuthenticator($key);
  }

  /**
   * {@inheritdoc}
   */
  public function log($level, $message, array $context = []) {
    if (NULL !== $this->logger) {
      $this->logger->log($level, $message, array $context = []);
    }
  }

}
