<?php

/**
 * Example data for DqZaakArchivering on the Lindelaan Woo case: who held the
 * case (KCC, then Woo en informatie) and an open takeover request from
 * Juridische zaken.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The Lindelaan case's holders and takeover request.
 */
class ZuiddrechtLindelaanCustodyTest extends TestCase {

	/**
	 * Two holders in order, the second still open, and one pending takeover.
	 *
	 * @return void
	 */
	public function testTheChainEndsWithWooAndATakeoverIsPending(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$objects = array_column($set['objects'], null, 'key');

		$kcc = $objects['woo-fietspad-bij-kcc']['data'];
		$woo = $objects['woo-fietspad-bij-woo']['data'];
		$this->assertSame(expected: [1, false, '{{cast:kcc.gid}}'], actual: [$kcc['sequence'], $kcc['open'], $kcc['organisationUnit']]);
		$this->assertSame(expected: [2, true, '{{cast:woo-en-informatie.gid}}'], actual: [$woo['sequence'], $woo['open'], $woo['organisationUnit']]);
		$this->assertSame(expected: $kcc['until'], actual: $woo['from']);

		$takeover = $objects['woo-fietspad-overname-juridisch']['data'];
		$this->assertSame(expected: ['pending', '{{cast:marloes.uid}}'], actual: [$takeover['status'], $takeover['requestedBy']]);
	}//end testTheChainEndsWithWooAndATakeoverIsPending()
}//end class
