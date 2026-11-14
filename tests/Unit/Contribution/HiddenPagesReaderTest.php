<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\HiddenPagesReader;
use OCA\Portaliq\Service\Identity\PortalAccountLookup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The pages a subject hid, read once per subject reference; a failing lookup
 * is logged and hides nothing.
 */
#[CoversClass(HiddenPagesReader::class)]
class HiddenPagesReaderTest extends TestCase {

	public function testWithoutLookupOrReferenceNothingIsHidden(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$this->assertSame([], (new HiddenPagesReader(null, $logger))->forSubject(['subjectRef' => 'bsn:1']));

		$accounts = $this->createMock(PortalAccountLookup::class);
		$accounts->expects($this->never())->method('bySubjectRef');
		$this->assertSame([], (new HiddenPagesReader($accounts, $logger))->forSubject([]));
	}//end testWithoutLookupOrReferenceNothingIsHidden()

	public function testOnlyStringPagesAreKeptAndTheAccountIsReadOnce(): void {
		$accounts = $this->createMock(PortalAccountLookup::class);
		$accounts->expects($this->once())->method('bySubjectRef')->with('bsn:1')
			->willReturn(['hiddenPages' => ['news', 3, null, 'agenda']]);
		$reader = new HiddenPagesReader($accounts, $this->createMock(LoggerInterface::class));

		$this->assertSame(['news', 'agenda'], $reader->forSubject(['subjectRef' => 'bsn:1']));
		$this->assertSame(['news', 'agenda'], $reader->forSubject(['subjectRef' => 'bsn:1']));
	}//end testOnlyStringPagesAreKeptAndTheAccountIsReadOnce()

	public function testAFailingLookupIsLoggedAndHidesNothing(): void {
		$accounts = $this->createMock(PortalAccountLookup::class);
		$accounts->method('bySubjectRef')->willThrowException(new RuntimeException('register down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Portaliq: hidden pages not read', ['reason' => 'register down']);

		$this->assertSame([], (new HiddenPagesReader($accounts, $logger))->forSubject(['subjectRef' => 'bsn:2']));
	}//end testAFailingLookupIsLoggedAndHidesNothing()
}//end class
