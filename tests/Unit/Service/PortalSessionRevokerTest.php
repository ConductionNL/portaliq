<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalSessionRevoker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Revoke-all for an organisation: it reads every live session page by page,
 * revokes each, audits each as `admin-revoke`, and reports `complete: false`
 * whenever it could not reach or write every row.
 *
 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#3.2
 */
class PortalSessionRevokerTest extends TestCase {
	/**
	 * The reader.
	 *
	 * @var MockObject&PortalObjectReader
	 */
	private $reader;

	/**
	 * The writer.
	 *
	 * @var MockObject&PortalObjectWriter
	 */
	private $writer;

	/**
	 * The auditor.
	 *
	 * @var MockObject&AuditTrailService
	 */
	private $auditor;

	/**
	 * The revoker under test.
	 *
	 * @var PortalSessionRevoker
	 */
	private PortalSessionRevoker $revoker;

	protected function setUp(): void {
		$this->reader  = $this->createMock(PortalObjectReader::class);
		$this->writer  = $this->createMock(PortalObjectWriter::class);
		$this->auditor = $this->createMock(AuditTrailService::class);
		$this->revoker = new PortalSessionRevoker($this->reader, $this->writer, $this->auditor, $this->createMock(LoggerInterface::class));
	}//end setUp()

	public function testAnEmptyOrganisationRevokesNothingAndReadsNothing(): void {
		$this->reader->expects($this->never())->method('readScopedPage');
		$this->auditor->expects($this->never())->method('record');

		$this->assertSame(['revoked' => 0, 'failed' => 0, 'complete' => true], $this->revoker->revokeAll('', 'admin'));
	}//end testAnEmptyOrganisationRevokesNothingAndReadsNothing()

	public function testAnUnreadableStoreIsIncompleteAndAudited(): void {
		$this->reader->method('readScopedPage')->willReturn(null);
		$this->writer->expects($this->never())->method('updateObject');
		$this->auditor->expects($this->once())->method('record')->with(
			$this->identicalTo('admin-revoke'),
			$this->anything(),
			$this->anything(),
			$this->anything(),
			$this->anything(),
			$this->anything(),
			$this->anything(),
			$this->anything(),
			$this->callback(static fn (array $d): bool => $d['complete'] === 'no' && $d['revoked'] === '0')
		);

		$this->assertSame(['revoked' => 0, 'failed' => 0, 'complete' => false], $this->revoker->revokeAll('org-1', 'admin'));
	}//end testAnUnreadableStoreIsIncompleteAndAudited()

	public function testEveryLiveSessionIsRevokedAndAnAlreadyRevokedOneIsSkipped(): void {
		$this->reader->method('readScopedPage')->willReturn(
			[
				'rows' => [
					['uuid' => 'a', 'jti' => 'ja', 'subjectRef' => 'party:1'],
					['id' => 'b', 'jti' => 'jb', 'subjectRef' => 'party:2', 'revoked' => 'false'],
					['uuid' => 'c', 'jti' => 'jc', 'revoked' => 'true'],
				],
				'read' => 3,
			]
		);
		$this->writer->expects($this->exactly(2))->method('updateObject')->willReturn(['ok' => true]);
		// Two per-session entries plus the one for the call.
		$this->auditor->expects($this->exactly(3))->method('record');

		$this->assertSame(['revoked' => 2, 'failed' => 0, 'complete' => true], $this->revoker->revokeAll('org-1', 'admin'));
	}//end testEveryLiveSessionIsRevokedAndAnAlreadyRevokedOneIsSkipped()

	public function testOneAccountsRevokeTouchesOnlyItsOwnSessionsAndKeepsTheOrganisationAuditOut(): void {
		$this->reader->method('readScopedPage')->willReturn(
			[
				'rows' => [
					['uuid' => 'a', 'jti' => 'ja', 'subjectRef' => 'email:tom'],
					['uuid' => 'b', 'jti' => 'jb', 'subjectRef' => 'email:ann'],
				],
				'read' => 2,
			]
		);
		$this->writer->expects($this->once())->method('updateObject')->willReturn(['ok' => true]);
		// Only the one session's own entry; the organisation-wide entry is not written.
		$this->auditor->expects($this->once())->method('record');

		$this->assertSame(['revoked' => 1, 'failed' => 0, 'complete' => true], $this->revoker->revokeAll('org-1', 'admin', 'email:tom'));
	}//end testOneAccountsRevokeTouchesOnlyItsOwnSessionsAndKeepsTheOrganisationAuditOut()

	public function testAFailedWriteAndARowWithoutAnIdentifierMakeTheResultIncomplete(): void {
		$this->reader->method('readScopedPage')->willReturn(
			[
				'rows' => [
					['uuid' => 'a', 'jti' => 'ja'],
					['jti' => 'no-id'],
				],
				'read' => 2,
			]
		);
		$this->writer->expects($this->once())->method('updateObject')->willReturn(null);
		$this->auditor->expects($this->once())->method('record');

		$this->assertSame(['revoked' => 0, 'failed' => 2, 'complete' => false], $this->revoker->revokeAll('org-1', 'admin'));
	}//end testAFailedWriteAndARowWithoutAnIdentifierMakeTheResultIncomplete()

	public function testTheLastPageEndsTheReadAndTheOffsetAdvances(): void {
		$full = [];
		for ($i = 0; $i < 500; $i++) {
			$full[] = ['uuid' => 'u' . $i, 'revoked' => true];
		}

		$offsets = [];
		$this->reader->method('readScopedPage')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $scopeValue, string $organisation, array $filter, int $limit, int $offset) use ($full, &$offsets): array {
				$offsets[] = $offset;
				if ($offset === 0) {
					return ['rows' => $full, 'read' => 500];
				}

				return ['rows' => [], 'read' => 0];
			}
		);
		$this->writer->expects($this->never())->method('updateObject');

		$this->assertSame(['revoked' => 0, 'failed' => 0, 'complete' => true], $this->revoker->revokeAll('org-1', 'admin'));
		$this->assertSame([0, 500], $offsets);
	}//end testTheLastPageEndsTheReadAndTheOffsetAdvances()

	public function testMoreThanTheCapOfPagesIsReportedIncompleteNotDone(): void {
		$this->reader->expects($this->exactly(2000))->method('readScopedPage')->willReturn(['rows' => [], 'read' => 500]);
		$this->writer->expects($this->never())->method('updateObject');

		$this->assertSame(['revoked' => 0, 'failed' => 0, 'complete' => false], $this->revoker->revokeAll('org-1', 'admin'));
	}//end testMoreThanTheCapOfPagesIsReportedIncompleteNotDone()

	public function testTheIdentifierIsReadFlatOrFromTheSelfBlock(): void {
		$this->assertSame('x', $this->revoker->rowId(['@self' => ['uuid' => 'x']]));
		$this->assertSame('7', $this->revoker->rowId(['@self' => ['id' => 7]]));
		$this->assertSame('u', $this->revoker->rowId(['uuid' => 'u', '@self' => ['uuid' => 'x']]));
		$this->assertNull($this->revoker->rowId(['@self' => ['uuid' => '']]));
		$this->assertNull($this->revoker->rowId(null));
	}//end testTheIdentifierIsReadFlatOrFromTheSelfBlock()
}//end class
