<?php

/**
 * Tests for PortalDomainVerifier::reverify(): a verified domain whose record
 * stays gone is unbound; a record that is there, or a lookup that fails,
 * keeps it bound.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Cms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\PortalDomainVerifier;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @spec openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-a-custom-domain-must-be-verified-before-it-serves
 */
class PortalDomainReverifyTest extends TestCase {

	private const TOKEN = 'portaliq-site-verification=abc';

	private const NAME = '_portaliq-verify.portal.example.com';

	/**
	 * A record gone three re-checks in a row unbinds the domain; the first two
	 * only count, and the request then resolves nothing for it.
	 *
	 * @return void
	 */
	public function testARemovedRecordUnbindsTheDomainAtTheThirdMiss(): void {
		$saved    = [];
		$verifier = $this->verifier(lookup: static fn (string $name): array => [], row: self::portal(), saved: $saved);

		$this->assertSame(['unbound' => [], 'missing' => ['portal.example.com']], $verifier->reverify(slug: 'open-tilburg'));
		$this->assertSame(1, $saved[0]['domains'][0]['reverifyMisses']);
		$this->assertTrue($saved[0]['domains'][0]['verified']);

		$this->assertSame(['unbound' => [], 'missing' => ['portal.example.com']], $verifier->reverify(slug: 'open-tilburg'));
		$this->assertSame(2, $saved[1]['domains'][0]['reverifyMisses']);

		$this->assertSame(['unbound' => ['portal.example.com'], 'missing' => []], $verifier->reverify(slug: 'open-tilburg'));
		$domain = $saved[2]['domains'][0];
		$this->assertFalse($domain['verified']);
		$this->assertSame(0, $domain['reverifyMisses']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $domain['unboundAt']);
		$this->assertSame(self::TOKEN, $domain['verificationToken'], 'the token is kept so the same record verifies it again');
	}//end testARemovedRecordUnbindsTheDomainAtTheThirdMiss()

	/**
	 * A record that is still there keeps the domain bound, writes nothing,
	 * and resets an earlier miss.
	 *
	 * @return void
	 */
	public function testAPresentRecordKeepsTheDomainAndResetsAMiss(): void {
		$saved    = [];
		$present  = static fn (string $name): array => ($name === self::NAME ? [' ' . self::TOKEN . ' '] : []);
		$verifier = $this->verifier(lookup: $present, row: self::portal(), saved: $saved);

		$this->assertSame(['unbound' => [], 'missing' => []], $verifier->reverify(slug: 'open-tilburg'));
		$this->assertSame([], $saved, 'nothing changed, so nothing is written');

		$missed   = $this->verifier(lookup: $present, row: self::portal(misses: 2), saved: $saved);
		$this->assertSame(['unbound' => [], 'missing' => []], $missed->reverify(slug: 'open-tilburg'));
		$this->assertSame(0, $saved[0]['domains'][0]['reverifyMisses']);
		$this->assertTrue($saved[0]['domains'][0]['verified']);
	}//end testAPresentRecordKeepsTheDomainAndResetsAMiss()

	/**
	 * Another portal's token is not this domain's record: it counts as gone.
	 *
	 * @return void
	 */
	public function testAnotherPortalsTokenCountsAsGone(): void {
		$saved    = [];
		$verifier = $this->verifier(lookup: static fn (string $name): array => ['portaliq-site-verification=other'], row: self::portal(misses: 2), saved: $saved);

		$this->assertSame(['unbound' => ['portal.example.com'], 'missing' => []], $verifier->reverify(slug: 'open-tilburg'));
		$this->assertFalse($saved[0]['domains'][0]['verified']);
	}//end testAnotherPortalsTokenCountsAsGone()

	/**
	 * A lookup that fails says nothing: no miss is counted and nothing is written.
	 *
	 * @return void
	 */
	public function testAFailedLookupCountsForNothing(): void {
		$saved    = [];
		$verifier = $this->verifier(
			lookup: static function (string $name): array {
				throw new RuntimeException('resolver down');
			},
			row: self::portal(misses: 2),
			saved: $saved
		);

		$this->assertSame(['unbound' => [], 'missing' => []], $verifier->reverify(slug: 'open-tilburg'));
		$this->assertSame([], $saved);
	}//end testAFailedLookupCountsForNothing()

	/**
	 * Only domains verified through their record are re-checked: a pending one,
	 * or one marked verified without a token, is not looked up or changed.
	 *
	 * @return void
	 */
	public function testOnlyRecordVerifiedDomainsAreRechecked(): void {
		$saved   = [];
		$looked  = [];
		$row     = ['id' => 'p1', 'slug' => 'open-tilburg', 'domains' => [
			['hostname' => 'pending.example.com', 'verified' => false, 'verificationToken' => self::TOKEN],
			['hostname' => 'seeded.example.com', 'verified' => true],
		]];
		$verifier = $this->verifier(
			lookup: static function (string $name) use (&$looked): array {
				$looked[] = $name;
				return [];
			},
			row: $row,
			saved: $saved
		);

		$this->assertSame(['unbound' => [], 'missing' => []], $verifier->reverify(slug: 'open-tilburg'));
		$this->assertSame([], $looked);
		$this->assertSame([], $saved);
	}//end testOnlyRecordVerifiedDomainsAreRechecked()

	/**
	 * An unknown portal is null and looks nothing up.
	 *
	 * @return void
	 */
	public function testAnUnknownPortalIsNull(): void {
		$saved    = [];
		$verifier = $this->verifier(lookup: static fn (string $name): array => [], row: self::portal(), saved: $saved);

		$this->assertNull($verifier->reverify(slug: 'someone-else'));
		$this->assertNull($verifier->reverify(slug: 'Not A Slug'));
		$this->assertSame([], $saved);
	}//end testAnUnknownPortalIsNull()

	/**
	 * A verifier over one portal row, recording every save.
	 *
	 * @param callable                         $lookup The TXT lookup.
	 * @param array<string, mixed>             $row    The portal row.
	 * @param array<int, array<string, mixed>> $saved  Collects the saved data.
	 *
	 * @return PortalDomainVerifier
	 */
	private function verifier(callable $lookup, array $row, array &$saved): PortalDomainVerifier {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(static function () use (&$row): array {
			return [$row];
		});
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('updateObject')->willReturnCallback(
			static function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, array $data) use (&$saved, &$row): array {
				$saved[] = $data;
				$row     = $data + $row;

				return $row;
			}
		);

		return new PortalDomainVerifier($reader, $writer, $this->createMock(LoggerInterface::class), \Closure::fromCallable($lookup), static fn (string $host): ?int => null);
	}//end verifier()

	/**
	 * A portal with one verified domain.
	 *
	 * @param int $misses The earlier misses recorded on it.
	 *
	 * @return array<string, mixed>
	 */
	private static function portal(int $misses = 0): array {
		$domain = ['hostname' => 'portal.example.com', 'verified' => true, 'verificationToken' => self::TOKEN];
		if ($misses > 0) {
			$domain['reverifyMisses'] = $misses;
		}

		return ['id' => 'p1', 'slug' => 'open-tilburg', 'domains' => [$domain]];
	}//end portal()
}//end class
