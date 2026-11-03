<?php

/**
 * Unit test for the constructor bundles of the metrics endpoint and the traffic ingest.
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

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\MetricsSources;
use OCA\Portaliq\Service\Traffic\TrafficIngestPipeline;
use OCA\Portaliq\Service\Traffic\TrafficRecordDerivers;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The container builds a bundle from its constructor types, so each parameter
 * must stay a class it can resolve, and the optional reader stays optional.
 *
 * @coversNothing
 */
class ConstructorBundlesTest extends TestCase {
	/**
	 * @return void
	 */
	public function testTheContainerCanBuildEachBundleFromItsTypes(): void {
		$expected = [
			MetricsSources::class => ['SettingsService', 'AuditTrailService', 'TrafficMetrics', 'CmsReader'],
			TrafficIngestPipeline::class => ['PortalResolver', 'TrafficConfigResolver', 'TrafficEventValidator', 'TrafficEventStore'],
			TrafficRecordDerivers::class => ['VisitorHasher', 'UserAgentClassifier', 'ReferrerClassifier', 'GeoResolverInterface'],
		];
		foreach ($expected as $bundle => $types) {
			$actual = [];
			foreach ((new ReflectionClass($bundle))->getConstructor()->getParameters() as $parameter) {
				$type = (string)$parameter->getType();
				$this->assertTrue(class_exists(ltrim($type, '?')) === true || interface_exists(ltrim($type, '?')) === true, $type);
				$actual[] = substr(strrchr($type, '\\'), 1);
			}

			$this->assertSame($types, $actual);
		}

		$cms = (new ReflectionClass(MetricsSources::class))->getConstructor()->getParameters()[3];
		$this->assertTrue($cms->allowsNull());
		$this->assertNull($cms->getDefaultValue());
	}//end testTheContainerCanBuildEachBundleFromItsTypes()
}//end class
