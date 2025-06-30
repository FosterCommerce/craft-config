# Craft Config

A configuration wrapper package for CraftCMS that provides opinionated, pre-configured settings with a clean interface to customize your site configuration.

## Installation

Install the package via Composer:

```bash
composer require fostercommerce/craft-config
```

## Features

- Pre-configured, opinionated defaults for CraftCMS sites
- Simplified configuration interface for general.php and app.php
- Redis configuration support
- Queue configuration
- Remote logging capabilities for staging and production environments
- Mail transport configuration

## Usage

### General Configuration

In your `config/general.php` file:

```php
<?php

use craft\services\Config;
use fostercommerce\craftconfig\GeneralConfig;

/** @var Config $this */
$generalConfig = GeneralConfig::configure(__DIR__, $this, null);

// $generalConfig is an instance of craft\config\GeneralConfig, so you can use all the same methods as you would in a regular config file.
return $generalConfig
    ->setPasswordRequestPath('/account/password-reset')
    ->setPasswordPath('/account/password-new')
    ->setPasswordSuccessPath('/account/password-confirmed')
    ->loginPath('/account/login')
    ->postLoginRedirect('/account')
    ->invalidUserTokenPath('/account/email-invalid')
    ->verifyEmailPath('/account/email-verify')
    ->verifyEmailSuccessPath('/account/email-verified')
    ->activateAccountSuccessPath('/account/email-verified')
    ->verificationCodeDuration('P2W');
```

#### Extra Configuration

You can provide additional configuration options using the `ExtraConfig` class:

```php
<?php

use craft\services\Config;
use fostercommerce\craftconfig\GeneralConfig;
use fostercommerce\craftconfig\ExtraConfig;

$extraConfig = new ExtraConfig([
    'devMode' => true,
    'primarySiteUrl' => 'https://example.com',
    'aliases' => [
        '@images' => '/path/to/images',
    ],
]);

/** @var Config $this */
return GeneralConfig::configure(__DIR__, $this, $extraConfig)
    ->loginPath('/account/login')
    ->postLoginRedirect('/account');
```

### App Configuration

In your `config/app.php` file:

```php
<?php

use craft\helpers\App;
use fostercommerce\craftconfig\AppConfigBuilder;
use fostercommerce\craftconfig\MailTransport;
use modules\site\Module as SiteModule;

return AppConfigBuilder::create()
    ->withModules([
        'site' => SiteModule::class,
    ])
    ->withMailTransport(MailTransport::SMTP)
    ->build();
```

## Configuration Options

### AppConfigBuilder

The `AppConfigBuilder` provides methods to configure your CraftCMS application:

- `create(?string $appId = null, ?string $environment = null)`: Create a new builder instance with optional app ID and environment
- `withModules(array $modules)`: Add modules to the app config (will be automatically bootstrapped)
- `withMailTransport(MailTransport $transport)`: Configure mail transport
- `withLoggerFilterFn(callable $filterFn)`: Add a custom log filter function
- `withLoggerFilterErrorFn(callable $filterFn)`: Add a custom error log filter function
- `withLoggerExcept(array $except, bool $merge = true)`: Exclude logs with the given categories
- `withLoggerExceptError(array $except, bool $merge = true)`: Exclude error logs with the given categories
- `build()`: Build the final configuration array

#### Deprecator

The `deprecator` component is automatically configured to throw exceptions if the `DEV_MODE` environment variable is set to `true`.

To disable this behavior, set the `DEV_MODE` environment variable to `false`, or override the `deprecator` component in your app config.

### Mail Transport

#### SMTP

```php
$appConfigBuilder->withMailTransport(MailTransport::SMTP);
```

Required environment variables for SMTP:
- `MAIL_HOST`
- `MAIL_PORT`
- `MAIL_USE_AUTHENTICATION`
- `MAIL_USERNAME`
- `MAIL_PASSWORD`

### Redis Configuration

Redis configuration is automatically included if the `REDIS_HOST` environment variable is set.

To configure Redis, set the following environment variables:

- `REDIS_HOST`
- `REDIS_PORT`
- `REDIS_PASSWORD` (optional)
- `REDIS_DEFAULT_DURATION`
- `REDIS_KEY_PREFIX`
- `REDIS_DATABASE`

#### Redis Mutex

Redis-backed mutex component is automatically configured if the `REDIS_MUTEX_ENABLED` environment variable is set to `true`.

To use a different Redis database for the mutex component, set the `REDIS_MUTEX_DATABASE` environment variable.

#### Redis Session

Redis-backed sessions are automatically configured if the `REDIS_SESSION_ENABLED` environment variable is set to `true`.

To use a different Redis database for sessions, set the `REDIS_SESSION_DATABASE` environment variable.

### Logging Configuration

Remote logging can be enabled by setting `REMOTE_LOGGING_ENABLED` to `true` and then setting the following environment variables:

- `REMOTE_DEBUG_LOGGING=true` (for including trace level logs)
- `SYSLOG_UDP_HOST`
- `SYSLOG_UDP_PORT` (defaults to 514)
- `SYSLOG_UDP_TOKEN`

When remote logging is enabled, this package splits the logs into two streams, one for all logs and one for error logs only. This makes it possible to filter out noisy logs in one stream without affecting the other.

#### Custom Log Filtering

You can exclude specific log categories and filter log messages based on content of the log message records.

For example, here we exclude logs based on category and filter out logs based on some regex patterns:

```php
// Define patterns to exclude from logging
$excludeMessagePatterns = [
    '/^Excluded translation message:.*/',
    '/^Cache cleared for.*/'
];

// Configure the AppConfigBuilder with custom logging filters
$builder
	// Exclude logs by category
	->withLoggerExcept([
        \yii\base\View::class . '::renderFile',
        \yii\db\Command::class . '::*',
        \craft\queue\QueueLogBehavior::class . '::*',
        // Exclude logs from specific plugins
        'nystudio107\imageoptimize\ImageOptimize::init',
        'fruitstudios\linkit\Linkit::init'
    ])
	// Filter log messages based on content of the log message record
    ->withLoggerFilterFn(function (array $record) use ($excludeMessagePatterns): bool {
        // Position 0 is the message
        // Position 1 is the category
        foreach ($excludeMessagePatterns as $excludeMessagePattern) {
            if (preg_match($excludeMessagePattern, (string) $record[0])) {
                return false;
            }
        }

        return true;
    });

return $builder->build();
```

The `withLoggerFilterErrorFn` and `withLoggerErrorExcept` methods work the same way as `withLoggerFilterFn` and `withLoggerExcept`, but they filter error logs instead of all logs.

## Default Behaviors

- Sets Monday as the default week start day
- Uses email as username
- Omits script name in URLs
- Sets indefinite user session duration
- Prevents user enumeration
- Disables sending the "Powered by" header
- Generates transforms before page load
- Sets verification code duration to 2 days by default
- Sets maximum upload file size to 64MB
- Disallows robots on non-production environments

## License

MIT License