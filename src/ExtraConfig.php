<?php

namespace fostercommerce\craftconfig;

use craft\base\Model;

class ExtraConfig extends Model
{
	/**
	 * Override the default dev mode config.
	 */
	public ?bool $devMode = null;

	/**
	 * Override the default production mode config.
	 */
	public ?bool $isProduction = null;

	/**
	 * Override the default primary site URL.
	 */
	public ?string $primarySiteUrl = null;

	/**
	 * Define any extra aliases
	 *
	 * @var array<non-empty-string, ?string>
	 */
	public array $aliases = [];

	/**
	 * If true, any extra aliases will be merged with the existing aliases.
	 *
	 * Otherwise, aliases defined here will replace existing aliases.
	 */
	public bool $mergeAliases = true;
}
