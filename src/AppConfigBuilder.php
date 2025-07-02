<?php

namespace fostercommerce\craftconfig;

use Craft;
use craft\helpers\App;
use craft\helpers\MailerHelper;
use craft\log\Dispatcher;
use craft\log\MonologTarget;
use craft\web\Request;
use Hidehalo\Nanoid\Client as NanoidClient;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\log\Logger as YiiLogger;

class AppConfigBuilder
{
	/**
	 * @var array<array-key, string>
	 */
	public const DEFAULT_EXCLUDE_MESSAGE_PATTERNS = [
		'/^(.*plugin loaded$).*/',
		'/^(.*module loaded$).*/',
		'/^(.*module bootstrapped$).*/',
		'/^(.*Updating search indexes).*/',
	];

	/**
	 * @var int
	 */
	private const DEFAULT_QUEUE_TTR = 7200;

	/**
	 * @var int
	 */
	private const DEFAULT_REDIS_DATABASE = 0;

	/**
	 * @var array<int, string>
	 */
	private const DEFAULT_LOGGER_EXCEPT = [
		// Exclude logs by category
		\craft\elements\Asset::class . '::getDimensions',
		\craft\elements\User::class . '::_validateUserAgent',
		\craft\elements\User::class . '::getIdentityAndDurationFromCookie',
		\yii\db\Connection::class . '::*',
		\yii\web\Session::class . '::*',
		\yii\web\User::class . '::loginByCookie',
		\yii\web\User::class . '::login',
		\yii\web\User::class . '::logout',
		\yii\web\User::class . '::renewAuthStatus',
		\yii\web\User::class . '::getIdentityAndDurationFromCookie',
		'nystudio107\seomatic\*',
		'blitz',
	];

	/**
	 * @var array<int, string>
	 */
	private const DEFAULT_LOGGER_EXCEPT_ERROR = [
		// Exclude logs in the error log target
		\yii\web\HttpException::class . ':404',
	];

	/**
	 * @var callable(string[]): bool
	 */
	private $loggerFilterFn;

	/**
	 * @var callable(string[]): bool
	 */
	private $loggerFilterErrorFn;

	private ?string $appId = null;

	/**
	 * @var array<non-empty-string, class-string>
	 */
	private array $modules = [];

	/**
	 * @var array<array-key, mixed>
	 */
	private array $components = [];

	private ?string $appEnvironment = null;

	private ?string $requestId = null;

	private ?string $loggingAppName = null;

	private ?bool $isConsoleRequest = null;

	/**
	 * @var array<int, int>
	 */
	private array $logLevels = [];

	private ?Logger $logger = null;

	/**
	 * @var array<int, string>
	 */
	private array $loggerExceptError = self::DEFAULT_LOGGER_EXCEPT_ERROR;

	/**
	 * @var array<int, string>
	 */
	private array $loggerExcept = self::DEFAULT_LOGGER_EXCEPT;

	/**
	 * @var array<non-empty-string, callable(): array{0: class-string, 1: array<array-key, mixed>}>
	 */
	private array $mailTransportConfigs = [];

	/**
	 * @throws Exception
	 * @throws InvalidConfigException
	 */
	public static function create(?string $appId = null, ?string $environment = null): self
	{
		$instance = new self();

		/** @var string $id */
		$id = $appId ?? (App::env('CRAFT_APP_ID') ?: 'CraftCMS');
		$instance->appId = $id;

		/** @var string $appEnvironment */
		$appEnvironment = $environment ?? App::env('CRAFT_ENVIRONMENT');
		$instance->appEnvironment = $appEnvironment;

		$instance->loggingAppName = $instance->appId;

		$instance->isConsoleRequest = PHP_SAPI === 'cli';

		$instance
			->generateRequestId()
			->configureDeprecator()
			->configureQueue()
			->configureRedis()
			->configureLogging();

		return $instance;
	}

	/**
	 * @return array<array-key, mixed>
	 * @throws Exception
	 */
	public function build(): array
	{
		// Do this here so that changes to the filter and except functions are applied.
		$this->configureLogTargets();

		$components = $this->components;

		if ($this->mailTransportConfigs !== []) {
			$components['mailer'] = function () {
				// Get the default component config:
				$config = App::mailerConfig();

				$mailTransportConfigFn = $this->mailTransportConfigs[App::env('MAIL_MAILER')];
				$mailTransportConfig = $mailTransportConfigFn();
				$adapter = MailerHelper::createTransportAdapter($mailTransportConfig[0], $mailTransportConfig[1]); // @phpstan-ignore-line We can't specify the TransportInterfaceAdapter dynamically
				$config['transport'] = $adapter->defineTransport();

				return Craft::createObject($config);
			};
		}

		return [
			'id' => $this->appId,
			'modules' => $this->modules,
			'bootstrap' => array_keys($this->modules),
			'components' => $components,
		];
	}

	/**
	 * Add modules to the app config.
	 *
	 * Modules added here will automatically be bootstrapped as well.
	 *
	 * @param array<non-empty-string, ?class-string> $modules
	 * @return $this
	 */
	public function withModules(array $modules): self
	{
		$this->modules = array_filter($modules);
		return $this;
	}

	/**
	 * Add a custom error log filter function.
	 *
	 * Returning false from the filter function will exclude the log message from error logs.
	 *
	 * @param callable(string[]): bool $filterFn
	 * @return $this
	 */
	public function withLoggerFilterFn(callable $filterFn): self
	{
		$this->loggerFilterFn = $filterFn;

		return $this;
	}

	/**
	 * Add a custom log filter function.
	 *
	 * Returning false from the filter function will exclude the log message from logs.
	 *
	 * @param callable(string[]): bool $filterFn
	 * @return $this
	 */
	public function withLoggerFilterErrorFn(callable $filterFn): self
	{
		$this->loggerFilterErrorFn = $filterFn;

		return $this;
	}

	/**
	 * Excludes logs with the given categories.
	 *
	 * @param array<int, string> $except
	 * @param bool $merge If `false`, the default except array will be replaced.
	 * @return $this
	 */
	public function withLoggerExcept(array $except, bool $merge = true): self
	{
		$this->loggerExcept = $merge
			? [
				...$this->loggerExcept,
				...$except,
			]
			: $except;

		return $this;
	}

	/**
	 * Excludes error logs with the given categories from the error log.
	 *
	 * @param array<int, string> $except
	 * @param bool $merge If `false`, the default except array will be replaced.
	 * @return $this
	 */
	public function withLoggerExceptError(array $except, bool $merge = true): self
	{
		$this->loggerExceptError = $merge
			? [
				...$this->loggerExceptError,
				...$except,
			]
			: $except;

		return $this;
	}

	/**
	 * Include mail transport configuration
	 */
	public function withMailTransport(MailTransport $transport): self
	{
		$this->mailTransportConfigs[$transport->value] = static fn (): array => $transport->getConfiguration();

		return $this;
	}

	private function getRequestPath(): ?string
	{
		if ($this->isConsoleRequest !== true) {
			/** @var Request $request */
			$request = Craft::$app->getRequest();
			return $request->getPathInfo();
		}

		return null;
	}

	private function generateRequestId(): self
	{
		$client = new NanoidClient();
		$this->requestId = $client->formatedId('0123456789abcdefg', 8);
		return $this;
	}

	/**
	 * If REDIS_HOST is set, this will configure Redis and use Redis for cache and sessions.
	 *
	 * **Note** that when using this, it is recommended to use redis as the session save_handler as well.
	 *
	 * Requires the following environment variables to be set
	 * - REDIS_HOST
	 * - REDIS_PORT
	 * - REDIS_PASSWORD
	 * - REDIS_DEFAULT_DURATION
	 * - REDIS_KEY_PREFIX
	 *
	 * @throws \yii\base\Exception
	 */
	private function configureRedis(): self
	{
		// Conditionally Add Redis Support if enabled
		/** @var ?string $redisHost */
		$redisHost = App::env('REDIS_HOST') ?: null;
		if ($redisHost !== null) {
			$database = App::env('REDIS_DATABASE') ?: self::DEFAULT_REDIS_DATABASE;

			$this->components['redis'] = [
				'class' => \yii\redis\Connection::class,
				'hostname' => App::env('REDIS_HOST'),
				'port' => App::env('REDIS_PORT'),
				'database' => $database,
			];

			if (App::env('REDIS_PASSWORD')) {
				$this->components['redis']['password'] = App::env('REDIS_PASSWORD');
			}

			$this->components['cache'] = [
				'class' => \yii\redis\Cache::class,
				'defaultDuration' => App::env('REDIS_DEFAULT_DURATION'),
				'keyPrefix' => App::env('REDIS_KEY_PREFIX'),
			];

			if (App::env('REDIS_MUTEX_ENABLED') === true) {
				$this->components['mutex'] = [
					'class' => \yii\redis\Mutex::class,
					'redis' => [
						...$this->components['redis'],
						'database' => App::env('REDIS_MUTEX_DATABASE') ?: $database,
					],
				];
			}

			if (App::env('REDIS_SESSION_ENABLED') === true) {
				$this->components['session'] = [
					'class' => \yii\redis\Session::class,
					'as session' => \craft\behaviors\SessionBehavior::class,
					'redis' => [
						...$this->components['redis'],
						'database' => App::env('REDIS_SESSION_DATABASE') ?: $database,
					],
				];
			}
		}

		return $this;
	}

	private function configureLogging(): self
	{
		$channel = $this->loggingAppName . '-' . ($this->isConsoleRequest === true ? 'console' : 'web');

		if (App::env('REMOTE_LOGGING_ENABLED') === true) {
			$this->logger = new Logger($channel);

			$this->loggerFilterErrorFn = static fn (): bool => true;
			$this->loggerFilterFn = static fn (): bool => true;

			return $this
				->setLogLevels()
				->configureSyslogUdpLogHandler();
			//			->configureHoneycombLogHandler();
		}

		return $this;
	}

	private function setLogLevels(): self
	{
		$logLevels = [
			YiiLogger::LEVEL_WARNING,
			YiiLogger::LEVEL_INFO,
		];

		if (App::env('REMOTE_DEBUG_LOGGING') === true) {
			$logLevels = array_merge($logLevels, [
				YiiLogger::LEVEL_TRACE,
			]);
		}

		$this->logLevels = $logLevels;

		return $this;
	}

	private function configureSyslogUdpLogHandler(): self
	{
		/** @var string $udpHost */
		$udpHost = App::env('SYSLOG_UDP_HOST');

		/** @var string $udpPort */
		$udpPort = App::env('SYSLOG_UDP_PORT') ?: 514;
		$udpPort = (int) $udpPort;

		/** @var ?string $token */
		$token = App::env('SYSLOG_UDP_TOKEN');

		// Only configure the handler if the token is set
		if ($token !== null) {
			$syslogHandler = new SyslogUdpHandler(
				$udpHost,
				$udpPort,
				LOG_USER,
				Level::Debug,
				true,
				$token,
			);
			$syslogHandler->setFormatter(new JsonFormatter());
			$this->logger?->pushHandler($syslogHandler);

			$this->logger?->pushProcessor(
				fn (LogRecord $record): LogRecord => new LogRecord(
					datetime: $record->datetime,
					channel: $record->channel,
					level: $record->level,
					message: $record->message,
					context: [
						...$record->context,
					],
					extra: [
						...$record->extra,
						'rid' => $this->requestId,
						'environment' => $this->appEnvironment,
						'path' => $this->getRequestPath(),
					],
					formatted: $record->formatted,
				)
			);
		}

		return $this;
	}

	private function configureLogTargets(): self
	{
		if (App::env('REMOTE_LOGGING_ENABLED') === true) {
			// This... should be unnecessary. But here we are. See comments in the 'target' array below.
			$disabledCraftLogTarget = [
				'class' => MonologTarget::class,
				'enabled' => false,
				'name' => 'disabled',
			];

			$loggerFilterFn = function (array $message): bool {
				// Exclude logs by filtering out messages
				// Position 0 is the message
				// Position 1 is the category
				foreach (self::DEFAULT_EXCLUDE_MESSAGE_PATTERNS as $excludeMessagePattern) {
					if (preg_match($excludeMessagePattern, (string) $message[0])) {
						return false;
					}
				}

				$loggerFilterFn = $this->loggerFilterFn;
				return $loggerFilterFn($message);
			};

			$loggerFilterErrorFn = function (array $message): bool {
				// placeholder if we need to filter any error logs
				$loggerFilterErrorFn = $this->loggerFilterErrorFn;
				return $loggerFilterErrorFn($message);
			};

			$this->components['log'] = [
				'targets' => [
					/**
					 * Overwrite the default log targets added by Craft.
					 * Craft merges targets set up here with its own logging which can only be disabled if you override the
					 * configuration for those targets. Simply passing in monologTargetConfig configuration is not enough to
					 * implement a fully custom logging solution.
					 * Additionally, the default MonologTarget that Craft implements doesn't support setting a custom logger
					 * such as the SyslogUdpHandler.
					 *
					 * @see Dispatcher
					 * @see MonologTarget
					 */
					Dispatcher::TARGET_WEB => $disabledCraftLogTarget,
					Dispatcher::TARGET_CONSOLE => $disabledCraftLogTarget,
					Dispatcher::TARGET_QUEUE => $disabledCraftLogTarget,
					[
						'class' => LogTarget::class,
						'logger' => $this->logger,
						'levels' => [YiiLogger::LEVEL_ERROR],
						'except' => $this->loggerExceptError,
						'filterFn' => $loggerFilterErrorFn,
						// Remove the default logvars (_SESSION, _HTTP, etc)
						'logVars' => [],
						'addTimestampToContext' => true,
					],
					// Split the targets to specifically exclude classes which spam the logs, while
					// ensuring errors thrown in those classes are still logged.
					[
						'class' => LogTarget::class,
						'logger' => $this->logger,
						'levels' => $this->logLevels,
						'except' => $this->loggerExcept,
						'filterFn' => $loggerFilterFn,
						// Remove the default logvars (_SESSION, _HTTP, etc)
						'logVars' => [],
						'addTimestampToContext' => true,
					],
				],
			];
		}

		return $this;
	}

	private function configureDeprecator(): self
	{
		$this->components['deprecator'] = [
			'throwExceptions' => App::devMode(),
		];
		return $this;
	}

	private function configureQueue(): self
	{
		$this->components['queue'] = [
			'ttr' => App::env('QUEUE_TTR') ?: self::DEFAULT_QUEUE_TTR,
		];
		return $this;
	}
}
