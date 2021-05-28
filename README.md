# OpenID Connect

A simple OpenID Connect module for Drupal.

## Installation

```sh
composer require itk-dev/itkdev_openid_connect_drupal
vendor/bin/drush pm:enable itkdev_openid_connect_drupal
```

### Configuration

```php
$config['itkdev_openid_connect_drupal']['authenticators']['generic'] = [
  // Optional name.
  'name' => 'Azure B2C',
  // Optional. Default: FALSE
  'show_on_login_form' => TRUE,

  // Optional. Default: FALSE
  'debug => TRUE,

  // Required OpenID Connect Discovery url (cf. https://swagger.io/docs/specification/authentication/openid-connect-discovery/)
  'openid_connect_discovery_url' => …,
  // Required client id.
  'client_id' => …,
  // Required client secret.
  'client_secret' => …,

  // Required map from user field to claim name.
  'fields' => [
    // Mapping `name` is required.
    'name' => 'upn',
    // Mapping `mail` is required.
    'mail' => 'email',

    // Additional user fields.
    'field_first_name' => 'given_name',
    'field_last_name' => 'family_name',

    // Mapping `roles` is optional, but recommended.
    'roles' => 'role',
  ],

  'roles => [
    // Optional map from OpenID role name to list of Drupal role (machine) names (or a single name).
    'map' => [
      'admin' => ['administrator', 'user_manager'],
      'user' => 'authenticated',
    ],

    // Optional default Drupal role (machine) names that users will always get.
    'default => [
      'employee',
    ],
  ],
];

$config['itkdev_openid_connect_drupal']['authenticators']['userid'] = [
  'openid_connect_discovery_url' => …,
  'client_id' => …,
  'client_secret' => …,
  …,
  'default_roles' => [
    'user',
  ],
];
```

## Usage

To authenticate using one of the defined authenticators, the user must be sent
to `/itkdev_openid_connect_drupal/authenticate/«key»`, where `«key»` is one of
the authenticators defined in config (i.e. `generic` or `userid` in the example
above).

Generate the authentication url with code like

```php
Url::fromRoute('itkdev_openid_connect_drupal.openid_connect, ['key' => $key])
```

## Development

### Coding standards

```sh
composer install
composer coding-standards-check
composer coding-standards-apply
```
