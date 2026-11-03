<?php

/**
 * The object service a portal read or write gets acts for the portal's
 * organisation, and a slug that names no organisation gets none.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Tenancy
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-acts-for-its-organisation/specs/portal-tenancy/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Tenancy;

use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCA\Portaliq\Service\Tenancy\OrganisationScopedObjectService;
use OCA\Portaliq\Service\Tenancy\PortalTenantContext;
use OCA\Portaliq\Tests\Doubles\ActingObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use stdClass;

/**
 * @covers \OCA\Portaliq\Service\Tenancy\PortalTenantContext
 * @covers \OCA\Portaliq\Service\Tenancy\OrganisationScopedObjectService
 */
class PortalTenantContextTest extends TestCase {

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * How often the organisation lookup was asked, per slug.
	 *
	 * @var array<string, int>
	 */
	private array $lookups = [];

	public function testAKnownSlugActsForItsOrganisationOnEveryCall(): void {
		$objectService = new ActingObjectService();
		$scoped = $this->context(objectService: $objectService)->objectServiceFor(organisation: 'gemeente-a');

		$this->assertInstanceOf(OrganisationScopedObjectService::class, $scoped);
		$scoped->setRegister(register: 'portaliq');
		$scoped->setSchema(schema: 'portalMessage');
		$scoped->findAll(config: ['limit' => 3], _rbac: false, _multitenancy: false);

		$this->assertSame(
			[['setRegister', 'uuid-a'], ['setSchema', 'uuid-a'], ['findAll', 'uuid-a']],
			$objectService->calls
		);
		$this->assertNull($objectService->actingFor, 'the context is left after each call');
	}//end testAKnownSlugActsForItsOrganisationOnEveryCall()

	public function testArgumentsAndTheAnswerPassThroughUnchanged(): void {
		$objectService = new ActingObjectService();
		$scoped = $this->context(objectService: $objectService)->objectServiceFor(organisation: 'gemeente-a');

		$saved = $scoped->saveObject(object: ['title' => 'X'], register: 'portaliq', schema: 'portalMessage', uuid: 'row-1', _rbac: false);
		$positional = $scoped->saveObject(['title' => 'Y']);

		$this->assertSame(['title' => 'X', 'id' => 'row-1'], $saved);
		$this->assertSame(['title' => 'Y', 'id' => 'new-1'], $positional);
		$this->assertSame([['title' => 'X'], ['title' => 'Y']], $objectService->saves);
	}//end testArgumentsAndTheAnswerPassThroughUnchanged()

	public function testAnExceptionOfTheCallReachesTheCaller(): void {
		$objectService = new class extends ActingObjectService {
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				throw new RuntimeException('store down');
			}
		};
		$scoped = $this->context(objectService: $objectService)->objectServiceFor(organisation: 'gemeente-a');

		$this->expectExceptionMessage('store down');
		$scoped->findAll();
	}//end testAnExceptionOfTheCallReachesTheCaller()

	public function testASlugThatNamesNoOrganisationGetsNoService(): void {
		$objectService = new ActingObjectService();

		$this->assertNull($this->context(objectService: $objectService)->objectServiceFor(organisation: 'onbekend'));
		$this->assertSame([], $objectService->calls);
	}//end testASlugThatNamesNoOrganisationGetsNoService()

	public function testNoOrganisationGetsThePlainService(): void {
		$objectService = new ActingObjectService();
		$context = $this->context(objectService: $objectService);

		$this->assertSame($objectService, $context->objectServiceFor(organisation: ''));
		$this->assertSame($objectService, $context->objectServiceForUuid(organisationUuid: ''));
		$this->assertSame([], $this->lookups);
	}//end testNoOrganisationGetsThePlainService()

	public function testAnOpenRegisterThatCannotActForAnOrganisationGetsThePlainService(): void {
		$objectService = new stdClass();
		$context = $this->context(objectService: $objectService);

		$this->assertSame($objectService, $context->objectServiceFor(organisation: 'gemeente-a'));
		$this->assertSame($objectService, $context->objectServiceForUuid(organisationUuid: 'uuid-a'));
	}//end testAnOpenRegisterThatCannotActForAnOrganisationGetsThePlainService()

	public function testWithoutOpenRegisterThereIsNoService(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no openregister'));
		$context = new PortalTenantContext(container: $container, logger: new NullLogger());

		$this->assertNull($context->objectServiceFor(organisation: 'gemeente-a'));
		$this->assertNull($context->objectServiceForUuid(organisationUuid: 'uuid-a'));
	}//end testWithoutOpenRegisterThereIsNoService()

	public function testWithoutTheOrganisationLookupThePlainServiceIsUsed(): void {
		$objectService = new ActingObjectService();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService): object {
				if ($id === self::OS) {
					return $objectService;
				}

				throw new RuntimeException('no service: '.$id);
			}
		);

		$context = new PortalTenantContext(container: $container, logger: new NullLogger());

		$this->assertSame($objectService, $context->objectServiceFor(organisation: 'gemeente-a'));
	}//end testWithoutTheOrganisationLookupThePlainServiceIsUsed()

	public function testAUuidActsForThatOrganisationWithoutALookup(): void {
		$objectService = new ActingObjectService();
		$scoped = $this->context(objectService: $objectService)->objectServiceForUuid(organisationUuid: 'uuid-b');

		$scoped->findAll();

		$this->assertSame(['uuid-b'], $objectService->actedFor(method: 'findAll'));
		$this->assertSame([], $this->lookups);
	}//end testAUuidActsForThatOrganisationWithoutALookup()

	public function testASlugIsLookedUpOncePerContext(): void {
		$context = $this->context(objectService: new ActingObjectService());

		$context->objectServiceFor(organisation: 'gemeente-a');
		$context->objectServiceFor(organisation: 'gemeente-a');
		$context->objectServiceFor(organisation: 'onbekend');
		$context->objectServiceFor(organisation: 'onbekend');

		$this->assertSame(['gemeente-a' => 1, 'onbekend' => 1], $this->lookups);
	}//end testASlugIsLookedUpOncePerContext()

	/**
	 * A context over the object service and a lookup that knows `gemeente-a`
	 * (uuid-a) and `gemeente-b` (uuid-b).
	 *
	 * @param object $objectService The object service.
	 *
	 * @return PortalTenantContext
	 */
	private function context(object $objectService): PortalTenantContext {
		$organisations = $this->createMock(PortalOrganisationConfigService::class);
		$organisations->method('findOrganisationBySlug')->willReturnCallback(
			function (string $slug): ?array {
				$this->lookups[$slug] = (($this->lookups[$slug] ?? 0) + 1);
				$known = ['gemeente-a' => 'uuid-a', 'gemeente-b' => 'uuid-b'];
				if (isset($known[$slug]) === false) {
					return null;
				}

				return ['uuid' => $known[$slug], 'name' => $slug];
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService, $organisations): object {
				if ($id === self::OS) {
					return $objectService;
				}

				if ($id === PortalOrganisationConfigService::class) {
					return $organisations;
				}

				throw new RuntimeException('no service: '.$id);
			}
		);

		return new PortalTenantContext(container: $container, logger: new NullLogger());
	}//end context()
}//end class
