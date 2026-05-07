<?php

namespace fostercommerce\craftconfig;

use Craft;
use craft\web\Response;
use yii\base\Application as BaseApplication;
use yii\base\Event;
use yii\base\Module as BaseModule;
use yii\base\Response as BaseResponse;
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
			function (): void {
				$path = Craft::$app->getRequest()->getPathInfo();
				$extPattern = '/\.(png|jpe?g|gif|webp|svg|woff2?|ttf|otf|eot|ico)$/i';

				if (preg_match($extPattern, $path)) {
					$fullPath = Craft::getAlias('@webroot') . '/' . ltrim($path, '/');

					if (! is_file($fullPath)) {
						$response = Craft::createObject(BaseResponse::class, [
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
