<?php

namespace fostercommerce\craftconfig;

use samdark\log\PsrTarget;

class LogTarget extends PsrTarget
{
	/**
	 * @var ?callable(string[]): bool
	 */
	public $filterFn;

	#[\Override]
	public function export(): void
	{
		if ($this->filterFn !== null) {
			$this->messages = array_filter($this->messages, $this->filterFn);
		}

		parent::export();
	}
}
