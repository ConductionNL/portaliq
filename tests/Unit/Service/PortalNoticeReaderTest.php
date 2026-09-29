<?php

/**
 * Tests for PortalNoticeReader.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Portaliq\Service\PortalNoticeReader;
use OCA\Portaliq\Service\PortalRegisterContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * operate-maintenance-notice T02 (REQ-OMN-001): the server decides which
 * notices are active.
 *
 * @spec openspec/changes/operate-maintenance-notice/specs/portal-notices/spec.md#requirement-a-notice-shows-on-every-page-during-its-window-req-omn-001
 */
class PortalNoticeReaderTest extends TestCase {

	/**
	 * The filters the object service was asked for.
	 *
	 * @var array<int, array>
	 */
	private array $asked = [];


	/**
	 * A published warning for gemeente on both surfaces, running now.
	 *
	 * @param array $over Overrides.
	 *
	 * @return array The row.
	 */
	private static function row(array $over = []): array {
		return array_merge(
			[
				'@self'    => ['id' => 'n-1'],
				'portal'   => 'gemeente',
				'message'  => 'Saturday from 22:00 to 02:00 you cannot submit requests.',
				'level'    => 'warning',
				'startsAt' => '2026-10-03T10:00:00+00:00',
				'endsAt'   => '2026-10-04T02:00:00+00:00',
				'surfaces' => ['site', 'portal'],
				'status'   => 'published',
			],
			$over
		);
	}//end row()


	/**
	 * Now, inside the default window.
	 *
	 * @return DateTimeImmutable Now.
	 */
	private static function now(): DateTimeImmutable {
		return new DateTimeImmutable('2026-10-03T21:00:00+00:00');
	}//end now()


	/**
	 * A reader over the given rows, with an empty cache.
	 *
	 * @param array<int, array> $rows The rows OpenRegister holds.
	 *
	 * @return PortalNoticeReader The reader.
	 */
	private function reader(array $rows): PortalNoticeReader {
		$test    = $this;
		$objects = new class($rows, $test) {
			/**
			 * @param array             $rows The rows.
			 * @param PortalNoticeReaderTest $test The test, to record the filters.
			 */
			public function __construct(private array $rows, private PortalNoticeReaderTest $test) {
			}

			public function setSchema(mixed $schema): void {
			}

			public function setRegister(mixed $register): void {
			}

			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->test->record(config: $config, rbac: $_rbac);
				return $this->rows;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$context = $this->createMock(PortalRegisterContext::class);
		$context->method('apply')->willReturn(true);
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(null);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(self::now());

		return new PortalNoticeReader($container, $factory, $this->createMock(LoggerInterface::class), $context, $time);
	}//end reader()


	/**
	 * Record one object-service call.
	 *
	 * @param array $config The config.
	 * @param bool  $rbac   Whether RBAC was asked for.
	 *
	 * @return void
	 */
	public function record(array $config, bool $rbac): void {
		$this->asked[] = ['filters' => $config['filters'] ?? [], 'rbac' => $rbac];
	}//end record()


	/**
	 * A running published notice is active, shaped for the client.
	 *
	 * @return void
	 */
	public function testARunningNoticeIsActive(): void {
		$active = $this->reader(rows: [self::row(over: ['linkLabel' => 'More', 'linkUrl' => 'https://example.nl/onderhoud'])])->active(portal: 'gemeente', surface: 'site');

		$this->assertSame(
			[
				[
					'id'        => 'n-1',
					'message'   => 'Saturday from 22:00 to 02:00 you cannot submit requests.',
					'level'     => 'warning',
					'linkLabel' => 'More',
					'linkUrl'   => 'https://example.nl/onderhoud',
					'endsAt'    => '2026-10-04T02:00:00+00:00',
				],
			],
			$active
		);
		$this->assertSame([['filters' => ['portal' => 'gemeente', 'status' => 'published'], 'rbac' => false]], $this->asked);
	}//end testARunningNoticeIsActive()


	/**
	 * A draft is not active, even inside its window.
	 *
	 * @return void
	 */
	public function testDraftIsNotActive(): void {
		$this->assertSame([], $this->reader(rows: [self::row(over: ['status' => 'draft'])])->active(portal: 'gemeente', surface: 'site'));
	}//end testDraftIsNotActive()


	/**
	 * Before its start and at or after its end a notice is not active.
	 *
	 * @return void
	 */
	public function testOutsideWindowIsNotActive(): void {
		$rows = [
			self::row(over: ['@self' => ['id' => 'later'], 'startsAt' => '2026-10-03T22:00:00+00:00']),
			self::row(over: ['@self' => ['id' => 'over'], 'startsAt' => '2026-10-02T10:00:00+00:00', 'endsAt' => '2026-10-03T21:00:00+00:00']),
			self::row(over: ['@self' => ['id' => 'no-end'], 'endsAt' => null]),
		];

		$this->assertSame([], PortalNoticeReader::select(rows: $rows, portal: 'gemeente', surface: 'site', now: self::now()));
	}//end testOutsideWindowIsNotActive()


	/**
	 * Another portal's notice, or one not meant for this surface, is not active.
	 *
	 * @return void
	 */
	public function testOtherPortalIsNotActive(): void {
		$rows = [
			self::row(over: ['portal' => 'buurgemeente']),
			self::row(over: ['@self' => ['id' => 'site-only'], 'surfaces' => ['site']]),
		];

		$this->assertSame([], PortalNoticeReader::select(rows: $rows, portal: 'gemeente', surface: 'portal', now: self::now()));
		$this->assertSame(['site-only'], array_column(PortalNoticeReader::select(rows: $rows, portal: 'gemeente', surface: 'site', now: self::now()), 'id'));
	}//end testOtherPortalIsNotActive()


	/**
	 * Newest start first, at most three, and only an https link survives.
	 *
	 * @return void
	 */
	public function testNewestFirstAtMostThreeHttpsLinksOnly(): void {
		$rows = [];
		foreach (['a' => '08', 'b' => '12', 'c' => '09', 'd' => '11'] as $id => $hour) {
			$rows[] = self::row(over: ['@self' => ['id' => $id], 'startsAt' => "2026-10-03T{$hour}:00:00+00:00", 'linkUrl' => 'javascript:alert(1)', 'level' => 'loud']);
		}

		$active = PortalNoticeReader::select(rows: $rows, portal: 'gemeente', surface: 'site', now: self::now());

		$this->assertSame(['b', 'd', 'c'], array_column($active, 'id'));
		$this->assertSame(['', '', ''], array_column($active, 'linkUrl'));
		$this->assertSame(['info', 'info', 'info'], array_column($active, 'level'));
	}//end testNewestFirstAtMostThreeHttpsLinksOnly()


	/**
	 * No portal, no read.
	 *
	 * @return void
	 */
	public function testNoPortalReadsNothing(): void {
		$this->assertSame([], $this->reader(rows: [self::row()])->active(portal: '', surface: 'site'));
		$this->assertSame([], $this->asked);
	}//end testNoPortalReadsNothing()
}//end class
