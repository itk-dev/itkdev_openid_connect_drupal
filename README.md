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
  // Required OpenID Connect Discovery url (cf. https://swagger.io/docs/specification/authentication/openid-connect-discovery/)
  'openid_connect_discovery_url' => …,
  // Required client id.
  'client_id' => …,
  // Required client secret.
  'client_secret' => …,
  // Optional roles key. Default: 'roles'
  'roles_key' => 'role',
  // Optional map from OpenID role name to Drupal role (machine) name.
  'roles_map' => [
    'admin' => 'administrator',
    'user' => 'authenticated',
  ],
  // Default Drupal role (machine) names that users will allways get.
  'default_roles' => [
    'employee',
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
