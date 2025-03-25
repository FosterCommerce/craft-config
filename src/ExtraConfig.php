<?php

namespace fostercommerce\craftconfig;

use craft\base\Model;

class ExtraConfig extends Model
{
	public ?bool $devMode = null;

	public ?bool $isProduction = null;

	public ?string $primarySiteUrl = null;

	/**
	 * Define any extra aliases
	 *
	 * @var array<non-empty-string, ?string>
	 */
	public array $aliases = [];

	/**
	 * If true, any extra aliases will be merged with the existing aliases. Otherwise, aliases defined here will replace existing aliases.
	 */
	public bool $mergeAliases = true;
}
