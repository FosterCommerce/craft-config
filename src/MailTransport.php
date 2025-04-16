<?php

namespace fostercommerce\craftconfig;

use craft\helpers\App;

enum MailTransport: string
{
	/**
	 * SMTP transport.
	 */
	case SMTP = 'smtp';

	/**
	 * @return array{0: class-string, 1: array<array-key, mixed>}
	 */
	public function getConfiguration(): array
	{
		return match ($this) {
			self::SMTP => [
				\craft\mail\transportadapters\Smtp::class,
				[
					'host' => App::env('MAIL_HOST'),
					'port' => App::env('MAIL_PORT'),
					'useAuthentication' => App::env('MAIL_USE_AUTHENTICATION'),
					'username' => App::env('MAIL_USERNAME'),
					'password' => App::env('MAIL_PASSWORD'),
				],
			],
		};
	}
}
