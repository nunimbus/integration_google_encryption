<?php

namespace OCA\GoogleEncryption\Middleware;

use OCP\AppFramework\Middleware;
use OCP\AppFramework\Http\Response;
use OCA\Google\BackgroundJob\ImportDriveJob;
use OCA\Google\BackgroundJob\ImportPhotosJob;
use OCA\Google\Controller\ConfigController;
use OCA\Google\Controller\GoogleAPIController;
use OCA\Google\Service\UserScopeService;
use OCA\Google\Service\GoogleAPIService;
use OCA\Google\AppInfo\Application as GoogleApplication;
use OCA\GoogleEncryption\Service\GoogleDriveAPIServiceCustom;
use OCA\GoogleEncryption\Service\GooglePhotosAPIServiceCustom;
use OC;

class GoogleEncryptionMiddleware extends Middleware {
	public function __construct() {
	}

	public function beforeOutput($controller, $methodName, $output){
		return $output;
	}

	public function beforeController($controller, $methodName) {
		if (! ($controller instanceof GoogleAPIController && ($methodName == 'importDrive' || $methodName == 'importPhotos'))) {
			return;
		}

		$userId = OC::$server->getUserSession()->getUser()->getUID();
		$loggerInterface = \OC::$server->get(\Psr\Log\LoggerInterface::class);
		$appId = GoogleApplication::APP_ID;
		$logger = new OC\AppFramework\ScopedPsrLogger($loggerInterface, $appId);
		
		$root = OC::$server->getLazyRootFolder();
		$config = OC::$server->getConfig();
		$jobList = OC::$server->getJobList();
		$userScopeService = OC::$server->get(UserScopeService::class);
		$userScopeService->setUserScope($userId);
		$userScopeService->setFilesystemScope($userId);

		$googleApiService = OC::$server->get(GoogleAPIService::class);

		if ($methodName == 'importDrive') {
			$config->setUserValue($userId, GoogleApplication::APP_ID, 'drive_import_running', '0');
			$service = new GoogleDriveAPIServiceCustom($appId, $logger, $config, $root, $jobList, $userScopeService, $googleApiService);
			$response = $service->startImportDrive($userId);
		}
		else if ($methodName == 'importPhotos') {
			$config->setUserValue($userId, GoogleApplication::APP_ID, 'photos_import_running', '0');
			$service = new GooglePhotosAPIServiceCustom($appId, $logger, $config, $root, $jobList, $userScopeService, $googleApiService);
			$response = $service->startImportPhotos($userId);
		}

		ob_start();
		echo json_encode($response);
		$size = ob_get_length();
		header("Content-Encoding: none");
		header("Content-Length: {$size}");
		header("Connection: close");
		ob_end_flush();
		flush();

		if (session_id()) {
			session_write_close();
		}

		if ($methodName == 'importDrive') {
			$service->importDriveJob($userId);
		}

		else if ($methodName == 'importPhotos') {
			$service->importPhotosJob($userId);
		}

		exit();
	}
	public function afterController($controller, $methodName, Response $response): Response {
		return $response;
	}
}
