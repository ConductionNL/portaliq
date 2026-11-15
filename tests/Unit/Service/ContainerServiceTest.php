<?php

/**
 * ContainerService and CaseListSource: an optional service from the
 * container, and the provenance stamp of a case list source.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Service
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

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\CaseListSource;
use OCA\Portaliq\Service\ContainerService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use stdClass;

/**
 * The container service and the case list source.
 */
class ContainerServiceTest extends TestCase {
	/**
	 * A service the container has is returned.
	 *
	 * @return void
	 */
	public function testAnAvailableServiceIsReturned(): void {
		$service   = new stdClass();
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->with('OCA\\OpenRegister\\Service\\ObjectService')->willReturn($service);
		$this->assertSame(expected: $service, actual: (new ContainerService(container: $container))->get(id: 'OCA\\OpenRegister\\Service\\ObjectService'));
	}//end testAnAvailableServiceIsReturned()

	/**
	 * A missing app, or a value that is not an object, reads as no service.
	 *
	 * @return void
	 */
	public function testAMissingServiceIsNull(): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id): mixed {
				if ($id === 'missing') {
					throw new RuntimeException('app not installed');
				}

				return 'a string';
			}
		);
		$services = new ContainerService(container: $container);
		$this->assertNull(actual: $services->get(id: 'missing'));
		$this->assertNull(actual: $services->get(id: 'scalar'));
	}//end testAMissingServiceIsNull()

	/**
	 * A case list source stamps the app, label and collection a row came from.
	 *
	 * @return void
	 */
	public function testACaseListSourceStampsItsProvenance(): void {
		$source = new CaseListSource(
			contribution: ['app' => 'dossiq', 'label' => 'Zaken'],
			collection: ['id' => 'cases', 'register' => 'zaken', 'schema' => 'zaak', 'fields' => ['title']]
		);
		$this->assertSame(
			expected: ['appId' => 'dossiq', 'label' => 'Zaken', 'register' => 'zaken', 'schema' => 'zaak', 'collection' => 'cases'],
			actual: $source->stamp()
		);
	}//end testACaseListSourceStampsItsProvenance()

	/**
	 * Without a label the app id names the source; a bare collection stamps empty strings.
	 *
	 * @return void
	 */
	public function testACaseListSourceFallsBackToTheAppId(): void {
		$source = new CaseListSource(contribution: ['app' => 'pipelinq'], collection: []);
		$this->assertSame(expected: 'pipelinq', actual: $source->label);
		$this->assertSame(
			expected: ['appId' => 'pipelinq', 'label' => 'pipelinq', 'register' => '', 'schema' => '', 'collection' => ''],
			actual: $source->stamp()
		);
		$this->assertSame(expected: '', actual: (new CaseListSource(contribution: [], collection: []))->label);
	}//end testACaseListSourceFallsBackToTheAppId()
}//end class
