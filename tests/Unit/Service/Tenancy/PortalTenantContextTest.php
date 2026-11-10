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

use OCA\OpenRegister\Service\Object\ObjectFindTarget;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
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

	/**
	 * Aknown slug acts for its organisation on every call.
	 *
	 * @return void
	 */
	public function testAKnownSlugActsForItsOrganisationOnEveryCall(): void {
		$objectService = new ActingObjectService();
		$scoped = $this->context(objectService: $objectService)->objectServiceFor(organisation: 'gemeente-a');

		$this->assertInstanceOf(expected: OrganisationScopedObjectService::class, actual: $scoped);
		$scoped->setRegister(register: 'portaliq');
		$scoped->setSchema(schema: 'portalMessage');
		$scoped->findAll(config: ['limit' => 3], _rbac: false, _multitenancy: false);

		$this->assertSame(
			expected: [['setRegister', 'uuid-a'], ['setSchema', 'uuid-a'], ['findAll', 'uuid-a']],
			actual: $objectService->calls
		);
		$this->assertNull(actual: $objectService->actingFor, message: 'the context is left after each call');
	}//end testAKnownSlugActsForItsOrganisationOnEveryCall()

	/**
	 * Arguments and the answer pass through unchanged.
	 *
	 * @return void
	 */
	public function testArgumentsAndTheAnswerPassThroughUnchanged(): void {
		$objectService = new ActingObjectService();
		$scoped = $this->context(objectService: $objectService)->objectServiceFor(organisation: 'gemeente-a');

		$saved = $scoped->saveObject(
			object: ['title' => 'X'],
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: 'portaliq', schema: 'portalMessage'), uuid: 'row-1'),
			_rbac: false
		);
		$positional = $scoped->saveObject(['title' => 'Y']);

		$this->assertSame(expected: ['title' => 'X', 'id' => 'row-1'], actual: $saved);
		$this->assertSame(expected: ['title' => 'Y', 'id' => 'new-1'], actual: $positional);
		$this->assertSame(expected: [['title' => 'X'], ['title' => 'Y']], actual: $objectService->saves);
	}//end testArgumentsAndTheAnswerPassThroughUnchanged()

	/**
	 * An exception of the call reaches the caller.
	 *
	 * @return void
	 */
	public function testAnExceptionOfTheCallReachesTheCaller(): void {
		$objectService = new class extends ActingObjectService {
			/**
			 * Find all.
			 *
			 * @param array $config The config.
			 * @param bool $_rbac The _rbac.
			 * @param bool $_multitenancy The _multitenancy.
			 *
			 * @return array
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				throw new RuntimeException('store down');
			}
		};
		$scoped = $this->context(objectService: $objectService)->objectServiceFor(organisation: 'gemeente-a');

		$this->expectExceptionMessage(message: 'store down');
		$scoped->findAll();
	}//end testAnExceptionOfTheCallReachesTheCaller()

	/**
	 * Aslug that names no organisation gets no service.
	 *
	 * @return void
	 */
	public function testASlugThatNamesNoOrganisationGetsNoService(): void {
		$objectService = new ActingObjectService();

		$this->assertNull(actual: $this->context(objectService: $objectService)->objectServiceFor(organisation: 'onbekend'));
		$this->assertSame(expected: [], actual: $objectService->calls);
	}//end testASlugThatNamesNoOrganisationGetsNoService()

	/**
	 * No organisation gets the plain service.
	 *
	 * @return void
	 */
	public function testNoOrganisationGetsThePlainService(): void {
		$objectService = new ActingObjectService();
		$context = $this->context(objectService: $objectService);

		$this->assertSame(expected: $objectService, actual: $context->objectServiceFor(organisation: ''));
		$this->assertSame(expected: $objectService, actual: $context->objectServiceForUuid(organisationUuid: ''));
		$this->assertSame(expected: [], actual: $this->lookups);
	}//end testNoOrganisationGetsThePlainService()

	/**
	 * An open register that cannot act for an organisation gets the plain service.
	 *
	 * @return void
	 */
	public function testAnOpenRegisterThatCannotActForAnOrganisationGetsThePlainService(): void {
		$objectService = new stdClass();
		$context = $this->context(objectService: $objectService);

		$this->assertSame(expected: $objectService, actual: $context->objectServiceFor(organisation: 'gemeente-a'));
		$this->assertSame(expected: $objectService, actual: $context->objectServiceForUuid(organisationUuid: 'uuid-a'));
	}//end testAnOpenRegisterThatCannotActForAnOrganisationGetsThePlainService()

	/**
	 * Without open register there is no service.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterThereIsNoService(): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no openregister'));
		$context = new PortalTenantContext(container: $container, logger: new NullLogger());

		$this->assertNull(actual: $context->objectServiceFor(organisation: 'gemeente-a'));
		$this->assertNull(actual: $context->objectServiceForUuid(organisationUuid: 'uuid-a'));
	}//end testWithoutOpenRegisterThereIsNoService()

	/**
	 * Without the organisation lookup the plain service is used.
	 *
	 * @return void
	 */
	public function testWithoutTheOrganisationLookupThePlainServiceIsUsed(): void {
		$objectService = new ActingObjectService();
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService): object {
				if ($id === self::OS) {
					return $objectService;
				}

				throw new RuntimeException('no service: '.$id);
			}
		);

		$context = new PortalTenantContext(container: $container, logger: new NullLogger());

		$this->assertSame(expected: $objectService, actual: $context->objectServiceFor(organisation: 'gemeente-a'));
	}//end testWithoutTheOrganisationLookupThePlainServiceIsUsed()

	/**
	 * Auuid acts for that organisation without alookup.
	 *
	 * @return void
	 */
	public function testAUuidActsForThatOrganisationWithoutALookup(): void {
		$objectService = new ActingObjectService();
		$scoped = $this->context(objectService: $objectService)->objectServiceForUuid(organisationUuid: 'uuid-b');

		$scoped->findAll();

		$this->assertSame(expected: ['uuid-b'], actual: $objectService->actedFor(method: 'findAll'));
		$this->assertSame(expected: [], actual: $this->lookups);
	}//end testAUuidActsForThatOrganisationWithoutALookup()

	/**
	 * Aslug is looked up once per context.
	 *
	 * @return void
	 */
	public function testASlugIsLookedUpOncePerContext(): void {
		$context = $this->context(objectService: new ActingObjectService());

		$context->objectServiceFor(organisation: 'gemeente-a');
		$context->objectServiceFor(organisation: 'gemeente-a');
		$context->objectServiceFor(organisation: 'onbekend');
		$context->objectServiceFor(organisation: 'onbekend');

		$this->assertSame(expected: ['gemeente-a' => 1, 'onbekend' => 1], actual: $this->lookups);
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
		$organisations = $this->createMock(originalClassName: PortalOrganisationConfigService::class);
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

		$container = $this->createMock(originalClassName: ContainerInterface::class);
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
