<?php

/**
 * Tests for DomainReverificationJob: every published portal is re-checked,
 * one portal's failure does not stop the others, and an unbinding is logged.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\BackgroundJob
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

namespace OCA\Portaliq\Tests\Unit\BackgroundJob;

use OCA\Portaliq\BackgroundJob\DomainReverificationJob;
use OCA\Portaliq\Service\Cms\PortalDomainVerifier;
use OCA\Portaliq\Service\PortalResolver;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @spec openspec/changes/portal-scoping-and-auth/specs/portal-scoping-and-auth/spec.md#requirement-a-custom-domain-must-be-verified-before-it-serves
 */
class DomainReverificationJobTest extends TestCase {

	/**
	 * Each portal with a slug is re-checked once; a throwing one is logged and skipped.
	 *
	 * @return void
	 */
	public function testEveryPublishedPortalIsRecheckedAndAnUnbindingIsLogged(): void {
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('allPublishedPortals')->willReturn([['slug' => 'a'], ['title' => 'no slug'], ['slug' => 'broken'], ['slug' => 'c']]);

		$asked    = [];
		$verifier = $this->createMock(PortalDomainVerifier::class);
		$verifier->method('reverify')->willReturnCallback(function (string $slug) use (&$asked): ?array {
			$asked[] = $slug;
			if ($slug === 'broken') {
				throw new RuntimeException('down');
			}

			return ['unbound' => ($slug === 'c' ? ['c.example.com'] : []), 'missing' => []];
		});

		$warnings = [];
		$logger   = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context = []) use (&$warnings): void {
			$warnings[] = [$message, $context];
		});

		$job = new DomainReverificationJob($this->createMock(ITimeFactory::class), $portals, $verifier, $logger);
		$run = new \ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, null);

		$this->assertSame(['a', 'broken', 'c'], $asked);
		$this->assertCount(2, $warnings);
		$this->assertSame(['portal' => 'broken', 'reason' => 'down'], $warnings[0][1]);
		$this->assertSame(['portal' => 'c', 'hostname' => 'c.example.com'], $warnings[1][1]);
	}//end testEveryPublishedPortalIsRecheckedAndAnUnbindingIsLogged()
}//end class
