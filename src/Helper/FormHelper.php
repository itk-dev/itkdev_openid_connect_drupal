<?php

namespace Drupal\itkdev_openid_connect_drupal\Helper;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\itkdev_openid_connect_drupal\AuthorizationManager;

/**
 * Form helper.
 */
class FormHelper {
  use StringTranslationTrait;

  /**
   * The authorization manager.
   *
   * @var \Drupal\itkdev_openid_connect_drupal\AuthorizationManager
   */
  private $authorizationManager;

  /**
   * Constructor.
   */
  public function __construct(AuthorizationManager $authorizationManager) {
    $this->authorizationManager = $authorizationManager;
  }

  /**
   * Implement hook_form_alter().
   */
  public function alterForm(array &$form, FormStateInterface $formState, string $formId) {
    switch ($formId) {
      case 'user_login_form':
        return $this->alterUserLoginForm($form, $formState);
    }
  }

  /**
   * Alter user login form.
   */
  public function alterUserLoginForm(array &$form, FormStateInterface $formState) {
    $authenticators = array_filter(
      $this->authorizationManager->getAuthenticators(),
      static function (array $authenticator) {
        return $authenticator['show_on_login_form'] ?? FALSE;
      }
    );

    if (!empty($authenticators)) {
      $form['itkdev_openid_connect_drupal_authenticators'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Sign in with'),
        '#weight' => -9999,
        '#attributes' => [
          'class' => ['itkdev-openid-connect-drupal-authenticators'],
        ],
      ];

      foreach ($authenticators as $key => $authenticator) {
        $form['itkdev_openid_connect_drupal_authenticators'][$key] = [
          '#title' => $authenticator['name'] ?? $key,
          '#type' => 'link',
          '#url' => Url::fromRoute('itkdev_openid_connect_drupal.openid_connect', [
            'key' => $key,
          ]),
          '#attributes' => [
            'class' => [
              'itkdev-openid-connect-drupal-authenticator',
              Html::cleanCssIdentifier($key),
              'button',
            ],
          ],
        ];
      }
    }
  }

}
