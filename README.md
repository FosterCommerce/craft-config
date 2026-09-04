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

### Queue Configuration

The `queue` component is automatically configured with a default TTR of 7200 seconds.

To configure the TTR, set the `QUEUE_TTR` environment variable.

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

`REDIS_KEY_PREFIX` is applied to mutex lock keys as well, so sites sharing a Redis database do not collide.

To update the mutex expiration time, set the following environment variables:

- `REDIS_MUTEX_EXPIRE_CONSOLE` (defaults to 900 seconds)
- `REDIS_MUTEX_EXPIRE_WEB` (defaults to 30 seconds)

A Redis lock is dropped once its expiry passes, even if the work it was protecting is still running. See [Distributed Locks with Redis](https://redis.io/docs/latest/develop/clients/patterns/distributed-locks/).

#### Redis Session

Redis-backed sessions are automatically configured if the `REDIS_SESSION_ENABLED` environment variable is set to `true`.

To use a different Redis database for sessions, set the `REDIS_SESSION_DATABASE` environment variable.

### Logging Configuration

Remote logging is disabled unless `REMOTE_LOGGING_ENABLED` is set to `true` and `SYSLOG_UDP_TOKEN` is set.

Set the following environment variables:

- `REMOTE_DEBUG_LOGGING=true` (for including trace level logs)
- `SYSLOG_UDP_HOST`
- `SYSLOG_UDP_PORT` (defaults to 514)
- `SYSLOG_UDP_TOKEN`
- `REMOTE_LOGGING_INCLUDE_EXTRA_ERROR_CATEGORIES=true` (optional; also ignores 400 and 403 errors)

The package sends logs to two streams:

- The normal stream includes info and warning logs. It also includes trace logs when `REMOTE_DEBUG_LOGGING=true`.
- The error stream includes error logs.

#### Non-error Logs Ignored by Default

These filters apply to the normal stream: info, warning, and trace logs when `REMOTE_DEBUG_LOGGING=true`.

The normal stream ignores these messages:

- Messages ending in `plugin loaded`
- Messages ending in `module loaded`
- Messages ending in `module bootstrapped`
- Messages containing `Updating search indexes`

The normal stream also ignores logs from these categories:

- `craft\\elements\\Asset::getDimensions`
- `craft\\elements\\User::_validateUserAgent`
- `craft\\elements\\User::getIdentityAndDurationFromCookie`
- `craft\\services\\ProjectConfig::*`
- `craft\\queue\\QueueLogBehavior::*`
- `yii\\base\\View::renderFile`
- `yii\\db\\Connection::*`
- `yii\\filters\\RateLimiter::beforeAction`
- `yii\\web\\Session::*`
- `yii\\web\\User::loginByCookie`
- `yii\\web\\User::login`
- `yii\\web\\User::logout`
- `yii\\web\\User::renewAuthStatus`
- `yii\\web\\User::getIdentityAndDurationFromCookie`
- `nystudio107\\seomatic\\*`
- `nystudio107\\retour\\*`
- `nystudio107\\vite\\*`
- `nystudio107\\typogrify\\*`
- `nystudio107\\cookies\\*`
- `blitz`

These exclusions do not affect error logs. Errors from these categories still go to the error stream.

#### Error Logs Ignored by Default

The error stream ignores messages containing `User is not authorized to perform this action`.

It also ignores this category:

- `yii\\web\\HttpException:404`

When `REMOTE_LOGGING_INCLUDE_EXTRA_ERROR_CATEGORIES=true`, the error stream also ignores:

- `yii\\web\\HttpException:403`
- `yii\\web\\HttpException:400`

#### Custom Log Filtering

You can add category exclusions or filter messages by their contents. The default exclusions stay in place unless you pass `false` as the second argument to `withLoggerExcept()` or `withLoggerExceptError()`.

The filter receives an array with the message at index `0`, the level at index `1`, and the category at index `2`. Returning `false` drops the log.

For example:

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
        // Position 1 is the level
        // Position 2 is the category
        foreach ($excludeMessagePatterns as $excludeMessagePattern) {
            if (preg_match($excludeMessagePattern, (string) $record[0])) {
                return false;
            }
        }

        return true;
    });

return $builder->build();
```

The `withLoggerFilterErrorFn()` and `withLoggerExceptError()` methods work the same way, but apply to the error stream.

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
