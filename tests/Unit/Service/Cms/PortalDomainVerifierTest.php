<?php

/**
 * The record a portal's domain publishes, and the check that reads it
 * (portal-cms-admin-ui).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/portal-cms-admin-ui/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Controller\CmsDomainController;
use OCA\Portaliq\Service\Cms\PortalDomainVerifier;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\Tenancy\PortalAdminScope;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PortalDomainVerifierTest extends TestCase {

	/**
	 * The verifier over a one-portal store and a DNS that answers `$dns`.
	 *
	 * @param array<string, array<int, string>> $dns  Record name to its values.
	 * @param array<string, mixed>              $row  The stored portal.
	 * @param array<int, array<string, mixed>>  $saved Filled with each write.
	 * @param int|null                          $ends  The certificate end the probe returns; null for no answer.
	 *
	 * @return PortalDomainVerifier
	 */
	private function verifier(array $dns, array $row, array &$saved, ?int $ends = null): PortalDomainVerifier {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(static function () use (&$row): array {
			return [$row];
		});
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('updateObject')->willReturnCallback(
			static function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, array $data) use (&$saved, &$row): array {
				$saved[] = $data;
				$row = $data + $row;

				return $row;
			}
		);

		return new PortalDomainVerifier($reader, $writer, $this->createMock(LoggerInterface::class), static fn (string $name): array => ($dns[$name] ?? []), static fn (string $host): ?int => $ends);
	}

	private static function portal(array $domains): array {
		return ['id' => 'p1', 'slug' => 'open-tilburg', 'domains' => $domains];
	}

	public function testThePendingDomainShowsItsExactRecordAndKeepsItsToken(): void {
		$saved    = [];
		$verifier = $this->verifier([], self::portal([['hostname' => 'portal.example.com']]), $saved);

		$records = $verifier->records('open-tilburg');

		$this->assertSame('_portaliq-verify.portal.example.com', $records[0]['name']);
		$this->assertStringStartsWith('portaliq-site-verification=', $records[0]['value']);
		$this->assertFalse($records[0]['verified']);
		$this->assertCount(1, $saved, 'the new token is kept');
		$this->assertSame($records[0]['value'], $verifier->records('open-tilburg')[0]['value'], 'asking again shows the same token');
		$this->assertCount(1, $saved, 'and writes nothing more');
	}

	public function testNoPropagationYetIsPendingAndCanBeRunAgain(): void {
		$saved = [];
		$dns   = [];
		$row   = self::portal([['hostname' => 'portal.example.com', 'verificationToken' => 'portaliq-site-verification=abc']]);
		$verifier = $this->verifier($dns, $row, $saved);

		$this->assertSame('pending', $verifier->verify('open-tilburg', 'portal.example.com'));
		$this->assertSame([], $saved, 'a pending check changes nothing');
		$this->assertSame('pending', $verifier->verify('open-tilburg', 'portal.example.com'), 'and can be run again');

		$verified = $this->verifier(['_portaliq-verify.portal.example.com' => ['other', ' portaliq-site-verification=abc ']], $row, $saved);
		$this->assertSame('verified', $verified->verify('open-tilburg', 'portal.example.com'));
		$this->assertTrue($saved[0]['domains'][0]['verified']);
		$this->assertNotEmpty($saved[0]['domains'][0]['verifiedAt']);
	}

	public function testAnotherPortalsTokenDoesNotVerify(): void {
		$saved = [];
		$row   = self::portal([['hostname' => 'portal.example.com', 'verificationToken' => 'portaliq-site-verification=mine']]);
		$verifier = $this->verifier(['_portaliq-verify.portal.example.com' => ['portaliq-site-verification=theirs']], $row, $saved);

		$this->assertSame('pending', $verifier->verify('open-tilburg', 'portal.example.com'));
		$this->assertSame([], $saved);
	}

	public function testADomainTheyDoNotListOrABadSlugIsRefused(): void {
		$saved = [];
		$row   = self::portal([['hostname' => 'portal.example.com', 'verificationToken' => 't']]);
		$verifier = $this->verifier(['_portaliq-verify.evil.example' => ['t']], $row, $saved);

		$this->assertSame('not_found', $verifier->verify('open-tilburg', 'evil.example'));
		$this->assertNull($verifier->verify('../x', 'portal.example.com'));
		$this->assertNull($verifier->records('Bad Slug'));
	}

	public function testTheEndpointsAnswerInWords(): void {
		$saved = [];
		$row   = self::portal([['hostname' => 'portal.example.com', 'verificationToken' => 'tok']]);
		$l10n       = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters=[]): string => vsprintf($text, $parameters));
		$scope = $this->getMockBuilder(PortalAdminScope::class)->disableOriginalConstructor()->onlyMethods(['mayAdministerSlug'])->getMock();
		$scope->method('mayAdministerSlug')->willReturn(true);
		$controller = new CmsDomainController('portaliq', $this->createMock(IRequest::class), $this->verifier([], $row, $saved), $l10n, $scope);

		$this->assertSame('_portaliq-verify.portal.example.com', $controller->records('open-tilburg')->getData()['domains'][0]['name']);
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->records('nergens-x')->getStatus());
		$this->assertSame(['status' => 'pending', 'message' => 'The record of portal.example.com is not there yet.'], $controller->verify('open-tilburg', 'portal.example.com')->getData());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->verify('open-tilburg', 'evil.example')->getStatus());
	}

	public function testAVerifiedDomainStoresTheEndOfItsCertificate(): void {
		$saved = [];
		$row   = self::portal([['hostname' => 'portal.example.com', 'verificationToken' => 'tok']]);
		$ends  = gmmktime(12, 0, 0, 1, 14, 2027);
		$verifier = $this->verifier(['_portaliq-verify.portal.example.com' => ['tok']], $row, $saved, $ends);

		$this->assertSame('verified', $verifier->verify('open-tilburg', 'portal.example.com'));
		$this->assertSame('2027-01-14T12:00:00+00:00', $saved[0]['domains'][0]['certificateValidUntil']);
		$this->assertNotEmpty($saved[0]['domains'][0]['certificateCheckedAt']);

		$records = $verifier->records('open-tilburg');
		$this->assertSame('2027-01-14T12:00:00+00:00', $records[0]['certificateValidUntil']);
	}

	public function testAHostThatDoesNotAnswerKeepsTheEarlierEndDate(): void {
		$saved = [];
		$row   = self::portal([['hostname' => 'portal.example.com', 'verificationToken' => 'tok', 'certificateValidUntil' => '2026-12-01T00:00:00+00:00']]);
		$verifier = $this->verifier(['_portaliq-verify.portal.example.com' => ['tok']], $row, $saved, null);

		$this->assertSame('verified', $verifier->verify('open-tilburg', 'portal.example.com'));
		$this->assertSame('2026-12-01T00:00:00+00:00', $saved[0]['domains'][0]['certificateValidUntil']);
		$this->assertNotEmpty($saved[0]['domains'][0]['certificateCheckedAt']);
	}

	public function testAPendingDomainIsNotProbed(): void {
		$saved = [];
		$row   = self::portal([['hostname' => 'portal.example.com', 'verificationToken' => 'tok']]);
		$verifier = $this->verifier([], $row, $saved, 1);

		$this->assertSame('pending', $verifier->verify('open-tilburg', 'portal.example.com'));
		$this->assertSame([], $saved);
	}

	public function testThePrivateHostIsNeverContacted(): void {
		$this->assertNull(PortalDomainVerifier::probeCertificate('localhost'));
	}
}
