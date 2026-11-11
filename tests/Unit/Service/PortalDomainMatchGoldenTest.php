<?php

/**
 * PortalDomainMatch::find(): which portal and domain a host matches, and
 * what is logged on the way.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Service
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

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalDomainMatch;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The host match, as recorded.
 */
class PortalDomainMatchGoldenTest extends TestCase {
	/**
	 * The log lines written, in order.
	 *
	 * @var array<int, string>
	 */
	private array $logged = [];

	/**
	 * A match whose logger records each line.
	 *
	 * @return PortalDomainMatch
	 */
	private function match(): PortalDomainMatch {
		$this->logged = [];
		$logger       = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(function (string $message, array $context) {
			$this->logged[] = $message . ' ' . json_encode($context);
		});

		return new PortalDomainMatch($logger);
	}//end match()

	/**
	 * Unverified entries are logged and skipped, the first verified one
	 * wins, case and padding of the stored name do not matter, and an empty
	 * host matches nothing.
	 *
	 * @return void
	 */
	public function testEveryHostMatchIsAsRecorded(): void {
		$verified = ['hostname' => ' Loket.Example.NL ', 'verified' => true];
		$sites    = [
			['slug' => 'a', 'domains' => ['junk', ['hostname' => 'loket.example.nl'], ['hostname' => 'other.nl', 'verified' => true]]],
			['domains' => [['hostname' => 'loket.example.nl', 'verified' => 'yes']]],
			['slug' => 'b', 'domains' => [$verified, ['hostname' => 'loket.example.nl', 'verified' => true]]],
			['slug' => 'c', 'domains' => [['hostname' => 'loket.example.nl', 'verified' => true]]],
		];

		$this->assertSame(['site' => $sites[2], 'domain' => $verified], $this->match()->find(host: 'loket.example.nl', sites: $sites));
		$this->assertSame(
			[
				'Portaliq: host matched an UNVERIFIED domain; refusing to serve {"host":"loket.example.nl","site":"a"}',
				'Portaliq: host matched an UNVERIFIED domain; refusing to serve {"host":"loket.example.nl","site":null}',
			],
			$this->logged
		);

		$this->assertNull($this->match()->find(host: '', sites: $sites));
		$this->assertNull($this->match()->find(host: 'nowhere.nl', sites: $sites));
		$this->assertNull($this->match()->find(host: 'loket.example.nl', sites: [['slug' => 'x'], ['slug' => 'y', 'domains' => [['hostname' => '', 'verified' => true]]]]));
		$this->assertSame([], $this->logged);
	}//end testEveryHostMatchIsAsRecorded()
}//end class
