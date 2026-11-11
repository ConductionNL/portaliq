<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use OCA\Portaliq\Service\Identity\AccountStatus;
use OCA\Portaliq\Service\PortalAccountService;
use PHPUnit\Framework\TestCase;

/**
 * Pins the account status vocabulary the `portalAccount` rows are stored
 * and compared with, so moving it out of PortalAccountService keeps every
 * stored and compared value the same.
 */
class AccountStatusTest extends TestCase {

	public function testTheAccountServiceKeepsTheStoredStatusValues(): void {
		self::assertSame('pending', PortalAccountService::STATUS_PENDING);
		self::assertSame('active', PortalAccountService::STATUS_ACTIVE);
		self::assertSame('void', PortalAccountService::STATUS_VOID);
		self::assertSame('self-registration', PortalAccountService::SELF_REGISTRATION);
	}

	public function testTheStatusVocabularyHoldsTheSameStoredValues(): void {
		self::assertSame(PortalAccountService::STATUS_PENDING, AccountStatus::PENDING);
		self::assertSame(PortalAccountService::STATUS_ACTIVE, AccountStatus::ACTIVE);
		self::assertSame(PortalAccountService::STATUS_VOID, AccountStatus::VOID);
		self::assertSame(PortalAccountService::SELF_REGISTRATION, AccountStatus::SELF_REGISTRATION);
	}
}
