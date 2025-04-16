<?php

namespace fostercommerce\craftconfig;

use samdark\log\PsrTarget;

class LogTarget extends PsrTarget
{
	/**
	 * A custom filter function that can be used to filter log messages by the content of the message record.
	 *
	 * In the array of strings passed into the filter function:
	 * - Index 0 is the message
	 * - Index 1 is the category
	 *
	 * If the function returns `false`, the message will be excluded from the log.
	 *
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
