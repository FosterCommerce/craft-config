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
	private const int QUEUE_TTR = 7200;

	/**
	 * @var callable(array<array-key, mixed>): bool
	 */
	public $loggerFilterFn;

	/**
	 * @var callable(array<array-key, mixed>): bool
	 */
	public $loggerFilterErrorFn;

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
	private array $loggerExceptError = [];

	/**
	 * @var array<int, string>
	 */
	private array $loggerExcept = [];

	/**
	 * @var array<non-empty-string, callable(): array{0: class-string, 1: array<array-key, mixed>}>
	 */
	private array $mailTransportConfigs = [];

	/**
	 * @throws Exception
	 * @throws InvalidConfigException
	 */
	public static function create(): self
	{
		$instance = new self();

		/** @var string $id */
		$id = App::env('CRAFT_APP_ID') ?: 'CraftCMS';
		$instance->appId = $id;

		/** @var string $environment */
		$environment = App::env('CRAFT_ENVIRONMENT');
		$instance->appEnvironment = $environment;

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
	 * @param array<non-empty-string, class-string> $modules
	 * @return $this
	 */
	public function withModules(array $modules): self
	{
		$this->modules = $modules;
		return $this;
	}

	/**
	 * @param callable(array<array-key, mixed>): bool $filterFn a callback which returns an array of items to exclude from the logs
	 * @return $this
	 */
	public function withLoggerFilterFn(callable $filterFn): self
	{
		$this->loggerFilterFn = $filterFn;

		return $this;
	}

	/**
	 * @param callable(array<array-key, mixed>): bool $filterFn a callback which returns an array of items to exclude from the _error_ logs
	 * @return $this
	 */
	public function withLoggerFilterErrorFn(callable $filterFn): self
	{
		$this->loggerFilterErrorFn = $filterFn;

		return $this;
	}

	/**
	 * @param array<int, string> $except
	 * @return $this
	 */
	public function withLoggerExcept(array $except): self
	{
		$this->loggerExcept = $except;

		return $this;
	}

	/**
	 * @param array<int, string> $except
	 * @return $this
	 */
	public function withLoggerExceptError(array $except): self
	{
		$this->loggerExceptError = $except;

		return $this;
	}

	public function withMailTransportSmtp(): self
	{
		//*
		$this->mailTransportConfigs['smtp'] = static fn (): array => [
			\craft\mail\transportadapters\Smtp::class,
			[
				'host' => App::env('MAIL_HOST'),
				'port' => App::env('MAIL_PORT'),
				'useAuthentication' => App::env('MAIL_USE_AUTHENTICATION'),
				'username' => App::env('MAIL_USERNAME'),
				'password' => App::env('MAIL_PASSWORD'),
			],
		];

		return $this;
	}

	private function getRequestPath(): ?string
	{
		if ($this->isConsoleRequest === true) {
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
			$this->components['redis'] = [
				'class' => \yii\redis\Connection::class,
				'hostname' => App::env('REDIS_HOST'),
				'port' => App::env('REDIS_PORT'),
			];

			if (App::env('REDIS_PASSWORD')) {
				$this->components['redis']['password'] = App::env('REDIS_PASSWORD');
			}

			$this->components['cache'] = [
				'class' => \yii\redis\Cache::class,
				'defaultDuration' => App::env('REDIS_DEFAULT_DURATION'),
				'keyPrefix' => App::env('REDIS_KEY_PREFIX'),
			];

			$this->components['session'] = [
				'class' => \yii\redis\Session::class,
				'as session' => \craft\behaviors\SessionBehavior::class,
			];
		}

		return $this;
	}

	private function configureLogging(): self
	{
		$channel = $this->loggingAppName . '-' . ($this->isConsoleRequest === true ? 'console' : 'web');

		if (getenv('REMOTE_LOGGING_ENABLED') === 'true') {
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

		if (getenv('REMOTE_DEBUG_LOGGING') === 'true') {
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
				fn (LogRecord $record): LogRecord => // Using $logRecord->with() does the same thing with a little bit of overhead.
					// It also caused an issue, so this resolves it.
				new LogRecord(
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
		if (getenv('REMOTE_LOGGING_ENABLED') === 'true') {
			// This... should be unnecessary. But here we are. See comments in the 'target' array below.
			$disabledCraftLogTarget = [
				'class' => MonologTarget::class,
				'enabled' => false,
				'name' => 'disabled',
			];

			$loggerFilterErrorFn = $this->loggerFilterErrorFn;
			$loggerFilterFn = $this->loggerFilterFn;

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
			'ttr' => self::QUEUE_TTR,
		];
		return $this;
	}
}
