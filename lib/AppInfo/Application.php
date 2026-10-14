<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\WhistleCheck\AppInfo;

use OCA\WhistleCheck\Notification\Notifier;
use OCA\WhistleCheck\Repair\BackupBeforeUpdate;
use OCA\WhistleCheck\Repair\EnsureWhistleCheckDefaults;
use OCA\WhistleCheck\Repair\EnsureWhistleCheckSchema;
use OCA\WhistleCheck\Repair\UninstallDropTables;
use OCA\WhistleCheck\Service\OfficerKeyService;
use OCA\WhistleCheck\Service\UpgradeBackupService;
use OCA\WhistleCheck\Support\NavGate;
use OCP\App\IAppManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\Lock\ILockingProvider;
use Psr\Log\LoggerInterface;

class Application extends App implements IBootstrap
{
	public const APP_ID = 'whistlecheck';

	public function __construct()
	{
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void
	{
		$context->registerNotifierService(Notifier::class);

		// Repair steps — explicit registration, full constructor args
		// (repair-step-di rule: never rely on autowiring here).
		$context->registerService(EnsureWhistleCheckSchema::class, function ($c): EnsureWhistleCheckSchema {
			return new EnsureWhistleCheckSchema(
				$c->get(IDBConnection::class),
				$c->get(IConfig::class),
			);
		});
		$context->registerService(EnsureWhistleCheckDefaults::class, function ($c): EnsureWhistleCheckDefaults {
			return new EnsureWhistleCheckDefaults(
				$c->get(IGroupManager::class),
			);
		});
		$context->registerService(UpgradeBackupService::class, function ($c): UpgradeBackupService {
			return new UpgradeBackupService(
				$c->get(IDBConnection::class),
				$c->get(IConfig::class),
				$c->get(IRootFolder::class),
				$c->get(IAppManager::class),
				$c->get(ILockingProvider::class),
				$c->get(LoggerInterface::class),
			);
		});
		$context->registerService(BackupBeforeUpdate::class, function ($c): BackupBeforeUpdate {
			return new BackupBeforeUpdate(
				$c->get(UpgradeBackupService::class),
			);
		});
		$context->registerService(UninstallDropTables::class, function ($c): UninstallDropTables {
			return new UninstallDropTables(
				$c->get(IDBConnection::class),
				$c->get(IConfig::class),
				$c->get(IRootFolder::class),
			);
		});
	}

	public function boot(IBootContext $context): void
	{
		$app = $context->getAppContainer();
		// Menu entry only for officers — non-officers would land on a 403.
		NavGate::register(
			$app,
			'whistlecheck.page.index',
			'app.svg',
			'WhistleCheck',
			2,
			static fn(string $uid): bool => $app->get(OfficerKeyService::class)->isOfficer($uid),
		);
	}
}
