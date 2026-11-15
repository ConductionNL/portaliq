<?php

/**
 * Unit tests for TrafficServerToken when the token cannot be stored.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Traffic;

use OCA\Portaliq\Service\PortalRegisterContext;
use OCA\Portaliq\Service\PublishedPortals;
use OCA\Portaliq\Service\Traffic\TrafficServerToken;
use OCA\Portaliq\Service\TrafficConfigResolver;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A token is only handed out once its hash is stored: a portal without a
 * uuid, a register context that cannot be applied, or a failing save all
 * answer null. A malformed traffic block is replaced, not kept.
 *
 * @covers \OCA\Portaliq\Service\Traffic\TrafficServerToken
 * @uses   \OCA\Portaliq\Service\TrafficConfigResolver
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficOutcomeDefinitions
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficSegments
 */
class TrafficServerTokenStoreFailuresTest extends TestCase {

	/**
	 * What saveObject received, or null.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $saved = null;

	/**
	 * Record a save.
	 *
	 * @param array<string, mixed> $saved The saved record.
	 *
	 * @return void
	 */
	public function record(array $saved): void {
		$this->saved = $saved;
	}//end record()

	/**
	 * A traffic block that is not an object is replaced by one holding only the hash.
	 *
	 * @return void
	 */
	public function testAMalformedTrafficBlockIsReplaced(): void {
		$token = $this->service(portal: ['uuid' => 'p-2', 'slug' => 'gemeente', 'traffic' => 'on'])->issue(slug: 'gemeente');

		$this->assertSame('minted', $token);
		$this->assertSame('p-2', $this->saved['uuid']);
		$this->assertSame(['serverToken' => hash('sha256', 'minted')], $this->saved['traffic']);
	}//end testAMalformedTrafficBlockIsReplaced()

	/**
	 * A portal without any uuid or id mints nothing and saves nothing.
	 *
	 * @return void
	 */
	public function testAPortalWithoutAUuidMintsNothing(): void {
		$this->assertNull($this->service(portal: ['slug' => 'gemeente', '@self' => ['uuid' => '  ']])->issue(slug: 'gemeente'));
		$this->assertNull($this->saved);
	}//end testAPortalWithoutAUuidMintsNothing()

	/**
	 * When the register context cannot be applied, nothing is saved and nothing is returned.
	 *
	 * @return void
	 */
	public function testARefusedRegisterContextMintsNothing(): void {
		$this->assertNull($this->service(portal: ['id' => 'p-3', 'slug' => 'gemeente'], contextApplies: false)->issue(slug: 'gemeente'));
		$this->assertNull($this->saved);
	}//end testARefusedRegisterContextMintsNothing()

	/**
	 * A failing save is logged without the token and answers null.
	 *
	 * @return void
	 */
	public function testAFailingSaveIsLoggedAndMintsNothing(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Portaliq: traffic server token could not be stored',
			['reason' => 'database gone']
		);

		$this->assertNull($this->service(portal: ['@self' => ['id' => 'p-4'], 'slug' => 'gemeente'], saveThrows: true, logger: $logger)->issue(slug: 'gemeente'));
	}//end testAFailingSaveIsLoggedAndMintsNothing()

	/**
	 * The service over one published portal.
	 *
	 * @param array<string, mixed> $portal         The portal.
	 * @param bool                 $contextApplies Whether the register context applies.
	 * @param bool                 $saveThrows     Whether the save throws.
	 * @param LoggerInterface|null $logger         The logger.
	 *
	 * @return TrafficServerToken
	 */
	private function service(array $portal, bool $contextApplies = true, bool $saveThrows = false, ?LoggerInterface $logger = null): TrafficServerToken {
		$portals = $this->createMock(PublishedPortals::class);
		$portals->method('allPublishedPortals')->willReturn([$portal]);
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('minted');

		$objectService = new class($this, $saveThrows) {
			/**
			 * Constructor.
			 *
			 * @param TrafficServerTokenStoreFailuresTest $test   Records the save.
			 * @param bool                                $throws Whether to throw.
			 */
			public function __construct(private TrafficServerTokenStoreFailuresTest $test, private bool $throws) {
			}

			/**
			 * Save, or throw.
			 *
			 * @param array       $object        The record.
			 * @param string      $register      The register.
			 * @param string      $schema        The schema.
			 * @param string|null $uuid          The uuid.
			 * @param bool        $_rbac         RBAC.
			 * @param bool        $_multitenancy Multitenancy.
			 *
			 * @return array
			 */
			public function saveObject(array $object, string $register, string $schema, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->throws === true) {
					throw new RuntimeException('database gone');
				}

				$this->test->record(saved: ['uuid' => $uuid] + $object);
				return $object;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$context = $this->createMock(PortalRegisterContext::class);
		$context->method('apply')->willReturn($contextApplies);

		return new TrafficServerToken($portals, new TrafficConfigResolver(), $random, $container, $context, ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end service()
}//end class
