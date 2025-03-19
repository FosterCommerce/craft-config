<?php

namespace fostercommerce\craftconfig;

use craft\base\Model;

class ExtraConfig extends Model
{
	public ?bool $devMode = null;

	public ?string $primarySiteUrl = null;

	/**
	 * @var array<non-empty-string, ?string>
	 */
	public array $aliases = [];

	public bool $mergeAliases = true;
}
