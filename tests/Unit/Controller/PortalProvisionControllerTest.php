<?php

/**
 * PortalProvisionControllerTest
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Controller
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

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalProvisionController;
use OCA\Portaliq\Kernel\Contracts\PortalProvisioningServiceInterface;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * operate-portals-per-organisation T07: the "New portal" dialog's route hands
 * the four fields to the provisioning service, answers 201 with the DNS
 * record, and words every refusal with its own status; instance admins only.
 *
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t07
 */
#[CoversClass(PortalProvisionController::class)]
class PortalProvisionControllerTest extends TestCase {
	/**
	 * A controller over a service that answers the given result, recording what it was asked.
	 *
	 * @param array{status: string, dns: array{name: string, value: string}|null} $result The service's answer.
	 * @param array<string, string>|null                                          $asked  Filled with the call's arguments.
	 *
	 * @return PortalProvisionController
	 */
	private function controller(array $result, ?array &$asked = null): PortalProvisionController {
		$service = $this->createMock(PortalProvisioningServiceInterface::class);
		$service->expects($this->once())->method('provision')->willReturnCallback(
			static function (string $organisation, string $slug, string $title, string $host) use ($result, &$asked): array {
				$asked = ['organisation' => $organisation, 'slug' => $slug, 'title' => $title, 'host' => $host];
				return $result;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'NL:' . $text);

		return new PortalProvisionController('portaliq', $this->createMock(IRequest::class), $service, $l10n);
	}//end controller()

	public function testACreatedPortalAnswers201WithItsDnsRecord(): void {
		$asked = null;
		$dns = ['name' => '_portaliq.mijn.zeist.example', 'value' => 'portaliq-site-verification=abc'];
		$c = $this->controller(['status' => PortalProvisioningServiceInterface::CREATED, 'dns' => $dns], $asked);

		$response = $c->provision(organisation: 'org-b', slug: ' zeist ', title: 'Gemeente Zeist', host: 'mijn.zeist.example');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['status' => 'created', 'slug' => 'zeist', 'dns' => $dns], $response->getData());
		$this->assertSame(['organisation' => 'org-b', 'slug' => ' zeist ', 'title' => 'Gemeente Zeist', 'host' => 'mijn.zeist.example'], $asked);
	}//end testACreatedPortalAnswers201WithItsDnsRecord()

	/**
	 * @return array<string, array{string, int, string, string}>
	 */
	public static function refusals(): array {
		return [
			'host taken'  => [PortalProvisioningServiceInterface::HOST_TAKEN, 409, 'host_taken', 'NL:This web address is already used by another portal.'],
			'slug taken'  => [PortalProvisioningServiceInterface::SLUG_TAKEN, 409, 'slug_taken', 'NL:This portal slug is already used by another portal.'],
			'invalid'     => [
				PortalProvisioningServiceInterface::INVALID,
				400,
				'invalid',
				'NL:Give the organisation, a title, a slug of lower case letters, digits and hyphens, and a web address without https:// or a port.',
			],
			'unavailable' => [PortalProvisioningServiceInterface::UNAVAILABLE, 503, 'unavailable', 'NL:OpenRegister is not available, so nothing was written.'],
			'failed'      => [PortalProvisioningServiceInterface::FAILED, 500, 'failed', 'NL:The portal could not be written. Nothing else was created.'],
			'unknown'     => ['something-else', 500, 'failed', 'NL:The portal could not be written. Nothing else was created.'],
		];
	}//end refusals()

	#[DataProvider('refusals')]
	public function testARefusalAnswersItsStatusAndSentence(string $outcome, int $status, string $error, string $message): void {
		$response = $this->controller(['status' => $outcome, 'dns' => null])->provision('org-c', 'zeist', 'Zeist', 'mijn.zeist.example');

		$this->assertSame($status, $response->getStatus());
		$this->assertSame(['error' => $error, 'message' => $message], $response->getData());
	}//end testARefusalAnswersItsStatusAndSentence()

	public function testACreatedOutcomeWithoutARecordIsAFailure(): void {
		$response = $this->controller(['status' => PortalProvisioningServiceInterface::CREATED, 'dns' => null])->provision('org-c', 'zeist', 'Zeist', 'h.example');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame('failed', $response->getData()['error']);
	}//end testACreatedOutcomeWithoutARecordIsAFailure()

	public function testTheRouteIsForInstanceAdministratorsOnly(): void {
		$method = new ReflectionMethod(PortalProvisionController::class, 'provision');
		$names = array_map(static fn ($a): string => $a->getName(), $method->getAttributes());

		$this->assertNotContains('OCP\\AppFramework\\Http\\Attribute\\NoAdminRequired', $names);
		$this->assertNotContains('OCP\\AppFramework\\Http\\Attribute\\PublicPage', $names);
		$this->assertNotContains('OCP\\AppFramework\\Http\\Attribute\\NoCSRFRequired', $names);
	}//end testTheRouteIsForInstanceAdministratorsOnly()
}//end class
