<?php

/**
 * InboxDeletion: which inbox collections let a resident delete their own messages.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Kernel
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

namespace OCA\Portaliq\Tests\Unit\Kernel;

use OCA\Portaliq\Kernel\Contracts\PortalInboxReaderInterface;
use OCA\Portaliq\Kernel\InboxDeletion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The inbox deletion rule.
 */
#[CoversClass(InboxDeletion::class)]
class InboxDeletionTest extends TestCase {
	/**
	 * Inbox collections and whether a resident may delete from them.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: bool}>
	 */
	public static function collections(): array {
		$own = PortalInboxReaderInterface::OWN_MESSAGES;
		return [
			'portaliq notices'                      => [['register' => $own['register'], 'schema' => $own['schema']], true],
			'the full own-messages collection'      => [$own, true],
			'own notices even when marked fixed'    => [['register' => $own['register'], 'schema' => $own['schema'], 'deletable' => false], true],
			'own register, other schema'            => [['register' => $own['register'], 'schema' => 'portalNotice'], false],
			'own schema, other register'            => [['register' => 'zaken', 'schema' => $own['schema']], false],
			'own register, no schema'               => [['register' => $own['register']], false],
			'own names in other case'               => [['register' => strtoupper($own['register']), 'schema' => $own['schema']], false],
			'an app collection marked deletable'    => [['register' => 'zaken', 'schema' => 'bericht', 'deletable' => true], true],
			'deletable without register or schema'  => [['deletable' => true], true],
			'own register, other schema, deletable' => [['register' => $own['register'], 'schema' => 'x', 'deletable' => true], true],
			'an app collection without the flag'    => [['register' => 'zaken', 'schema' => 'bericht'], false],
			'an app collection marked fixed'        => [['register' => 'zaken', 'schema' => 'bericht', 'deletable' => false], false],
			'the string true'                       => [['register' => 'zaken', 'schema' => 'bericht', 'deletable' => 'true'], false],
			'the number one'                        => [['register' => 'zaken', 'schema' => 'bericht', 'deletable' => 1], false],
			'a null flag'                           => [['register' => 'zaken', 'schema' => 'bericht', 'deletable' => null], false],
			'an empty collection'                   => [[], false],
		];
	}//end collections()

	/**
	 * Portaliq's own notices are always deletable; any other collection only with `deletable: true` exactly.
	 *
	 * @param array<string, mixed> $collection The inbox collection.
	 * @param bool                 $mayDelete  Whether the resident may delete from it.
	 *
	 * @return void
	 */
	#[DataProvider('collections')]
	public function testWhetherAResidentMayDelete(array $collection, bool $mayDelete): void {
		$this->assertSame(expected: $mayDelete, actual: InboxDeletion::residentMayDelete(collection: $collection));
	}//end testWhetherAResidentMayDelete()
}//end class
