<?php

namespace Drupal\itkdev_openid_connect_drupal\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\itkdev_openid_connect_drupal\Helper\ConfigHelper;
use Drupal\itkdev_openid_connect_drupal\Helper\UserHelper;
use ItkDev\OpenIdConnect\Security\OpenIdConfigurationProvider;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
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
  private const STATE_NAME = 'oauth2state';

  /**
   * Session name for storing request query parameters.
   */
  private const PARAMETERS_NAME = 'oauth2params';

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
  public function __construct(ConfigHelper $configHelper, UserHelper $userHelper, FileSystemInterface $fileSystem, RequestStack $requestStack, LoggerChannelFactoryInterface $logger) {
    $this->configHelper = $configHelper;
    $this->userHelper = $userHelper;
    $this->fileSystem = $fileSystem;
    $this->requestStack = $requestStack;
    $this->setLogger($logger->get('itkdev_openid_connect_drupal'));
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('itkdev_openid_connect_drupal.config_helper'),
      $container->get('itkdev_openid_connect_drupal.user_helper'),
      $container->get('file_system'),
      $container->get('request_stack'),
      $container->get('logger.factory')
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

    if ($request->query->has('state')) {
      return $this->process($key);
    }

    return $this->start($key);
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
    $options = $this->getOptions($key);

    $providerOptions = [
      'redirectUri' => $this->getUrl(
        'itkdev_openid_connect_drupal.openid_connect',
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

    if ($options['debug'] ?? FALSE) {
      $this->debug('Provider options', ['options' => $providerOptions]);
    }

    $request = $this->requestStack->getCurrentRequest();
    $this->setSessionValue(self::PARAMETERS_NAME, ['query' => $request->query->all()]);

    $provider = new OpenIdConfigurationProvider($providerOptions);

    $authorizationUrl = $provider->getAuthorizationUrl();

    $this->setSessionValue(self::STATE_NAME, $provider->getState());

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
   */
  private function process(string $key): Response {
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

    if ($options['debug'] ?? FALSE) {
      $this->debug('Payload', ['payload' => $payload]);
    }

    try {
      $user = $this->userHelper->buildUser($payload, $options);
      $user->save();
    }
    catch (EntityStorageException $exception) {
      $this->error('Cannot create user', ['exception' => $exception]);
      throw new BadRequestHttpException('Cannot create user', $exception);
    }

    user_login_finalize($user);

    $parameters = $this->getSessionValue(self::PARAMETERS_NAME);
    $location = $parameters['query']['location'] ?? $this->getUrl('<front>');

    return new RedirectResponse($location);
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

    $this->error('Error', ['query' => $request->query->all()]);

    // @todo use a template for this.
    return [
      'error' => [
        '#markup' => $options['name'] ?? $request->query->get('error'),
        '#prefix' => '<h1>',
        '#suffix' => '</h1>',
      ],
      'error_description' => [
        '#markup' => $request->query->get('error_description'),
        '#prefix' => '<pre>',
        '#suffix' => '</pre>',
      ],
      'authenticate' => [
        '#type' => 'link',
        '#title' => $this->t('Try again'),
        '#url' => Url::fromRoute(
          'itkdev_openid_connect_drupal.openid_connect',
          [
            'key' => $key,
          ]
        ),
      ],
    ];
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
