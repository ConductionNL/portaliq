<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\OrganisationTypeController;
use OCA\Portaliq\Service\OrganisationTypeOptions;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * The organisation type picker is routed, admin-only, and answers what the
 * concept register holds (portal-identity-from-the-admin REQ-PIA-003).
 *
 * @covers \OCA\Portaliq\Controller\OrganisationTypeController
 *
 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
 */
class OrganisationTypeControllerTest extends TestCase {

	public function testTheRouteNamesTheMethodAndOnlyAnAdministratorPasses(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$named  = [];
		foreach ((array)($routes['routes'] ?? []) as $route) {
			$named[(string)$route['name']] = (string)$route['verb'] . ' ' . (string)$route['url'];
		}

		$this->assertSame('GET /api/organisation-types', ($named['organisationType#index'] ?? ''));
		$method = (new ReflectionClass(OrganisationTypeController::class))->getMethod('index');
		$this->assertEmpty($method->getAttributes(PublicPage::class));
		$this->assertEmpty($method->getAttributes(NoAdminRequired::class));
	}//end testTheRouteNamesTheMethodAndOnlyAnAdministratorPasses()

	public function testWithoutTheSchemeThePickerHearsNotInstalled(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$options = new OrganisationTypeOptions($this->createMock(ContainerInterface::class), $config, new NullLogger());

		$response = (new OrganisationTypeController($this->createMock(IRequest::class), $options))->index();

		$this->assertSame(['installed' => false, 'options' => []], $response->getData());
	}//end testWithoutTheSchemeThePickerHearsNotInstalled()
}//end class
