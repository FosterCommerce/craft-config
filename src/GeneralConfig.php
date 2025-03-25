<?php

namespace fostercommerce\craftconfig;

use craft\config\GeneralConfig as CraftGeneralConfig;
use craft\helpers\App;
use craft\services\Config as CraftConfig;

class GeneralConfig
{
	public const MONDAY = 1;

	/**
	 * Indefinite session duration
	 */
	public const SESSION_DURATION_INDEFINITE = 0;

	/**
	 * The duration for verification codes
	 */
	public const VERIFICATION_CODE_DURATION = 'P2D'; // 2 days

	/**
	 * The default maximum file upload size
	 */
	public const DEFAULT_MAX_FILE_UPLOAD_SIZE = '64M'; // 64MB

	/**
	 * Create an opinionated, pre-configured Craft general config
	 */
	public static function configure(string $baseDir, ?CraftConfig $config, ?ExtraConfig $extraConfig): CraftGeneralConfig
	{
		// $config is derived from the `CraftConfig::getConfigFromFile` method.
		if (! $config instanceof CraftConfig) {
			throw new \RuntimeException('$config cannot be not be null');
		}

		$extraConfig ??= new ExtraConfig();

		$isDev = $extraConfig->devMode ?? $config->env === 'dev';

		$isProduction = $extraConfig->isProduction ?? $config->env === 'production';

		$primarySiteUrl = $extraConfig->primarySiteUrl ?? App::env('PRIMARY_SITE_URL');

		/** @var array<non-empty-string, ?string> */
		$aliases = array_filter($extraConfig->mergeAliases
			? [
				'@webroot' => dirname($baseDir) . '/web',
				'@web' => $primarySiteUrl, // Excluded if null
				...$extraConfig->aliases,
			]
			: $extraConfig->aliases);

		return CraftGeneralConfig::create()
			->useEmailAsUsername()
			->defaultWeekStartDay(self::MONDAY)
			->omitScriptNameInUrls()
			->userSessionDuration(self::SESSION_DURATION_INDEFINITE)
			->preventUserEnumeration()
			->devMode($isDev)
			->allowAdminChanges($isDev)
			->runQueueAutomatically($isDev)
			->sendPoweredByHeader(false)
			->preloadSingles(false)
			->generateTransformsBeforePageLoad()
			->verificationCodeDuration(self::VERIFICATION_CODE_DURATION)
			->maxUploadFileSize(self::DEFAULT_MAX_FILE_UPLOAD_SIZE)
			->disallowRobots(! $isProduction)
			->aliases($aliases);
	}
}
