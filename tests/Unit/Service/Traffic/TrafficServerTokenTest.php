<?php

/**
 * Unit tests for TrafficServerToken.
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
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\Traffic\TrafficServerToken;
use OCA\Portaliq\Service\TrafficConfigResolver;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Minting stores a hash and shows the token; verifying accepts the
 * token, and refuses a wrong one, an empty one and a portal without one.
 */
class TrafficServerTokenTest extends TestCase {

	/**
	 * The object the fake object service saved last.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $saved = null;

	/**
	 * The service over one published portal and a fake object service.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return TrafficServerToken The service.
	 */
	private function service(array $portal): TrafficServerToken {
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('allPublishedPortals')->willReturn([$portal]);

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('minted-token-with-enough-entropy-for-a-test');

		$objectService = new class($this) {
			public function __construct(private TrafficServerTokenTest $test) {
			}

			public function saveObject(array $object, string $register, string $schema, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->test->record(saved: ['uuid' => $uuid, 'schema' => $schema] + $object);

				return $object;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$context = $this->createMock(PortalRegisterContext::class);
		$context->method('apply')->willReturn(true);

		return new TrafficServerToken($portals, new TrafficConfigResolver(), $random, $container, $context, $this->createMock(LoggerInterface::class));
	}//end service()


	/**
	 * Keep what the fake object service saved.
	 *
	 * @param array<string, mixed> $saved The saved object.
	 *
	 * @return void
	 */
	public function record(array $saved): void {
		$this->saved = $saved;
	}//end record()


	/**
	 * @return void
	 */
	public function testIssuingStoresTheHashAndReturnsTheToken(): void {
		$portal = ['@self' => ['uuid' => 'p-1'], 'slug' => 'open-tilburg', 'status' => 'published', 'traffic' => ['enabled' => true]];
		$service = $this->service($portal);

		$token = $service->issue(slug: 'open-tilburg');

		$this->assertSame('minted-token-with-enough-entropy-for-a-test', $token);
		$this->assertSame('p-1', $this->saved['uuid']);
		$this->assertSame('portal', $this->saved['schema']);
		$this->assertSame(hash('sha256', $token), $this->saved['traffic']['serverToken']);
		$this->assertTrue($this->saved['traffic']['enabled'], 'the rest of the block survives');
		$this->assertArrayNotHasKey('@self', $this->saved, 'the envelope travels as the uuid argument, not as data');
		$this->assertStringNotContainsString($token, json_encode($this->saved), 'the token itself is never stored');
		$this->assertNull($service->issue(slug: 'nope'), 'an unknown portal mints nothing');
	}//end testIssuingStoresTheHashAndReturnsTheToken()


	/**
	 * @return void
	 */
	public function testVerifyingAcceptsTheTokenAndRefusesEverythingElse(): void {
		$service = $this->service(['slug' => 'x']);
		$withHash = ['slug' => 'x', 'traffic' => ['serverToken' => hash('sha256', 'right')]];

		$this->assertTrue($service->verify(portal: $withHash, token: 'right'));
		$this->assertTrue($service->verify(portal: $withHash, token: ' right '), 'surrounding whitespace is not part of the token');
		$this->assertFalse($service->verify(portal: $withHash, token: 'wrong'));
		$this->assertFalse($service->verify(portal: $withHash, token: ''));
		$this->assertFalse($service->verify(portal: ['slug' => 'x', 'traffic' => ['enabled' => true]], token: 'right'), 'no token issued, nothing verifies');
		$this->assertFalse($service->verify(portal: ['slug' => 'x', 'traffic' => ['serverToken' => 'not-a-hash']], token: 'not-a-hash'), 'a stored value that is not a hash is not compared');
	}//end testVerifyingAcceptsTheTokenAndRefusesEverythingElse()
}//end class
