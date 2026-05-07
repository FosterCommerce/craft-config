<?php

namespace fostercommerce\craftconfig;

use Craft;
use craft\web\Request;
use craft\web\Response;
use yii\base\Application as BaseApplication;
use yii\base\Event;
use yii\base\Module as BaseModule;
use yii\web\Application;

class Module extends BaseModule
{
	#[\Override]
	public function init(): void
	{
		parent::init();

		// TODO add configs and set them via the appconfigbuilder
		Event::on(
			Application::class,
			BaseApplication::EVENT_BEFORE_REQUEST,
			static function (): void {
				$request = Craft::$app->getRequest();
				if ($request->getIsConsoleRequest()) {
					return;
				}

				/** @var Request $request */
				$path = $request->getPathInfo();
				$extPattern = '/\.(png|jpe?g|gif|webp|svg|woff2?|ttf|otf|eot|ico)$/i';

				if (preg_match($extPattern, $path)) {
					$fullPath = Craft::getAlias('@webroot') . '/' . ltrim($path, '/');

					if (! is_file($fullPath)) {
						$response = Craft::createObject(Response::class, [
							'format' => Response::FORMAT_RAW,
							'content' => 'Not found',
						]);

						$response->setStatusCode(404);
						$response->getHeaders()->set('Content-Type', 'text/plain');

						Craft::$app->end(response: $response);
					}
				}
			}
		);
	}
}
