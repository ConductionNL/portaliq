<?php

/**
 * The edges of the domain check: a portal row the writer cannot address, a
 * lookup or certificate probe that throws (portal-cms-admin-ui).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/portal-cms-admin-ui/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\PortalDomainVerifier;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Portaliq\Service\Cms\PortalDomainVerifier
 */
class PortalDomainVerifierEdgeTest extends TestCase {

	private PortalObjectWriter&MockObject $writer;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->writer = $this->createMock(PortalObjectWriter::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function verifier(array $row, \Closure $lookup, \Closure $probe): PortalDomainVerifier {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturn([$row]);

		return new PortalDomainVerifier($reader, $this->writer, $this->logger, $lookup, $probe);
	}

	public function testANewTokenThatCannotBeSavedAnswersNothing(): void {
		$this->writer->expects($this->never())->method('updateObject');
		$verifier = $this->verifier(
			['slug' => 'open-tilburg', 'domains' => [['hostname' => 'portal.example.com']]],
			static fn (string $name): array => [],
			static fn (string $host): ?int => null
		);

		$this->assertNull($verifier->records('open-tilburg'));
		$this->assertNull($verifier->verify('open-tilburg', 'portal.example.com'));
	}

	public function testAPublishedRecordOnARowWithoutIdIsNotReportedVerified(): void {
		$this->writer->expects($this->never())->method('updateObject');
		$verifier = $this->verifier(
			['slug' => 'open-tilburg', 'domains' => [['hostname' => 'portal.example.com', 'verificationToken' => 'tok']]],
			static fn (string $name): array => ($name === '_portaliq-verify.portal.example.com' ? ['tok'] : []),
			static fn (string $host): ?int => null
		);

		$this->assertNull($verifier->verify('open-tilburg', 'portal.example.com'));
	}

	public function testAFailingLookupIsPendingAndLogged(): void {
		$this->writer->expects($this->never())->method('updateObject');
		$this->logger->expects($this->once())->method('info')
			->with('Portaliq: domain record lookup failed', ['reason' => 'resolver timed out']);
		$verifier = $this->verifier(
			['id' => 'p1', 'slug' => 'open-tilburg', 'domains' => [['hostname' => 'portal.example.com', 'verificationToken' => 'tok']]],
			static function (string $name): array {
				throw new RuntimeException('resolver timed out');
			},
			static fn (string $host): ?int => null
		);

		$this->assertSame(PortalDomainVerifier::PENDING, $verifier->verify('open-tilburg', 'portal.example.com'));
	}

	public function testAFailingCertificateProbeStillVerifiesWithoutAnEndDate(): void {
		$saved = null;
		$this->writer->expects($this->once())->method('updateObject')->willReturnCallback(
			static function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, array $data) use (&$saved): array {
				$saved = [$id, $data];
				return $data;
			}
		);
		$this->logger->expects($this->once())->method('info')
			->with('Portaliq: certificate probe failed', ['reason' => 'handshake refused']);
		$verifier = $this->verifier(
			['@self' => ['uuid' => 'p-uuid'], 'slug' => 'open-tilburg', 'domains' => [['hostname' => 'Portal.Example.com', 'verificationToken' => 'tok']]],
			static fn (string $name): array => ['tok'],
			static function (string $host): ?int {
				throw new RuntimeException('handshake refused');
			}
		);

		$this->assertSame(PortalDomainVerifier::VERIFIED, $verifier->verify('open-tilburg', 'portal.example.com'));
		$this->assertSame('p-uuid', $saved[0]);
		$domain = $saved[1]['domains'][0];
		$this->assertTrue($domain['verified']);
		$this->assertArrayNotHasKey('certificateValidUntil', $domain);
		$this->assertNotEmpty($domain['certificateCheckedAt']);
	}
}
