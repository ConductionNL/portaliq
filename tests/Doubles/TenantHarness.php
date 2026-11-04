<?php

/**
 * A container for tests of portal reads and writes that act for an
 * organisation: it answers OpenRegister's object service, an organisation
 * lookup that knows `gemeente-a` (uuid-a) and `gemeente-b` (uuid-b), and an
 * event dispatcher whose claim checks admit.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Doubles
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Doubles;

use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Builds the container; the using class is a PHPUnit TestCase.
 */
trait TenantHarness {

	/**
	 * The container over this object service.
	 *
	 * @param object $objectService The object service.
	 *
	 * @return ContainerInterface
	 */
	private function tenantContainer(object $objectService): ContainerInterface {
		$organisations = $this->createMock(originalClassName: PortalOrganisationConfigService::class);
		$organisations->method('findOrganisationBySlug')->willReturnCallback(
			static function (string $slug): ?array {
				$known = ['gemeente-a' => 'uuid-a', 'gemeente-b' => 'uuid-b'];
				if (isset($known[$slug]) === false) {
					return null;
				}

				return ['uuid' => $known[$slug], 'name' => $slug];
			}
		);

		$services = [
			'OCA\\OpenRegister\\Service\\ObjectService' => $objectService,
			PortalOrganisationConfigService::class    => $organisations,
			IEventDispatcher::class                   => $this->createMock(originalClassName: IEventDispatcher::class),
		];

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): object {
				if (isset($services[$id]) === true) {
					return $services[$id];
				}

				throw new RuntimeException('no service: '.$id);
			}
		);

		return $container;
	}//end tenantContainer()
}//end trait
