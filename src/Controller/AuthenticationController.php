<?php

namespace Drupal\itkdev_openid_connect_drupal\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use ItkDev\OpenIdConnect\Security\OpenIdConfigurationProvider;
use Laminas\Diactoros\Response\RedirectResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Authentication controller.
 */
class AuthenticationController extends ControllerBase {
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
  public function __construct(FileSystemInterface $fileSystem, RequestStack $requestStack) {
    $this->fileSystem = $fileSystem;
    $this->requestStack = $requestStack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('file_system'),
      $container->get('request_stack')
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
      throw new BadRequestHttpException();
    }

    $state = $this->getSessionValue(self::STATE_NAME);
    if ($state !== $request->query->get('state')) {
      throw new BadRequestHttpException('Invalid state');
    }

    // Retrieve id_token and decode it.
    // @see https://tools.ietf.org/html/rfc7519
    $idToken = $request->query->get('id_token');
    [$jose, $payload, $signature] = array_map('base64_decode', explode('.', $idToken));
    try {
      $payload = json_decode($payload, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $jsonException) {
      throw new BadRequestHttpException('Invalid payload');
    }

    if (!isset($payload['upn'])) {
      throw new BadRequestHttpException();
    }

    $username = $payload['upn'];
    /** @var \Drupal\user\Entity\User $user */
    $user = user_load_by_name($username);
    if (!$user) {
      $user = User::create([
        'name' => $username,
      ]);
    }
    $user
      ->setEmail($payload['email'])
      ->activate();

    // Remove all user roles.
    foreach ($user->getRoles() as $role) {
      $user->removeRole($role);
    }

    // Add roles.
    $rolesKey = $options['roles_key'] ?? 'roles';
    $rolesMap = $options['roles_map'] ?? [];
    if (isset($payload[$rolesKey])) {
      foreach ((array) $payload[$rolesKey] as $role) {
        if (isset($rolesMap[$role])) {
          $user->addRole($rolesMap[$role]);
        }
      }
    }

    // Add default roles.
    if (isset($options['default_roles'])) {
      foreach ($options['default_roles'] as $role) {
        $user->addRole($role);
      }
    }

    $user->save();
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
      $options,
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
    $config = \Drupal::config('itkdev_openid_connect_drupal');
    $options = $config->get('authenticators.' . $key);

    if (empty($options)) {
      throw new \InvalidArgumentException(sprintf('Invalid authenticator: %s', $key));
    }

    return $options;
  }

}
