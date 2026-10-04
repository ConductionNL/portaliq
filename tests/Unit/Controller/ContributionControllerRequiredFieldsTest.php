<?php

/**
 * ContributionController refuses a submit that leaves an action's required
 * field empty (site-multi-step-forms REQ-SMF-024), on the three paths it
 * owns: a signed-in create, an anonymous create and an endpoint action.
 *
 * The actions go through the real PortalManifestNormaliser first, as the
 * registry does in production, so the test reads the same required flags
 * the form shows. The writer and the HTTP client are the doubles that would
 * see a write or a forward: neither may be reached on a refusal.
 *
 * @category Tests
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

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use OCA\Portaliq\Controller\ContributionController;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\InstanceLoopback;
use OCA\Portaliq\Service\NotificationDispatchService;
use OCA\Portaliq\Service\PortalActionForwarder;
use OCA\Portaliq\Service\PortalAuditHook;
use OCA\Portaliq\Service\PortalFileReader;
use OCA\Portaliq\Service\PortalFileWriter;
use OCA\Portaliq\Service\PortalInboxReader;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalSchemaReader;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\SubmissionReceiptService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The server never writes or forwards an empty required field.
 */
class ContributionControllerRequiredFieldsTest extends TestCase {
	private const SUBJECT = ['subjectRef' => 'guardian-1', 'audience' => 'parent', 'organisation' => '', 'trust' => 'substantial', 'jti' => 'jti-1'];

	/**
	 * learniq's `bookConferenceSlot`, as declared.
	 *
	 * @return array<string, mixed>
	 */
	private function booking(): array {
		return [
			'id'             => 'bookConferenceSlot',
			'type'           => 'create',
			'register'       => 'learniq',
			'schema'         => 'conference-signup',
			'fields'         => ['learnerRef', 'slotId', 'notes'],
			'requiredFields' => ['learnerRef', 'slotId'],
			'fieldConfigs'   => ['slotId' => ['requiredMessage' => 'Kies een tijd']],
		];
	}//end booking()

	/**
	 * dossiq's Woo request, an endpoint action without a schema.
	 *
	 * @return array<string, mixed>
	 */
	private function woo(): array {
		return [
			'id'             => 'startWooVerzoek',
			'endpoint'       => '/index.php/apps/dossiq/api/portal/woo-verzoek',
			'method'         => 'POST',
			'fields'         => ['onderwerp', 'omschrijving', 'periodeTot'],
			'requiredFields' => ['onderwerp', 'omschrijving'],
		];
	}//end woo()

	/**
	 * A signed-in create without a slot: 400 naming `slotId` with the app's
	 * words, and nothing is written.
	 *
	 * @return void
	 */
	public function testACreateWithAnEmptyRequiredFieldIsRefusedBeforeTheWrite(): void {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->never())->method('createObject');
		$controller = $this->controller(
			params: ['actionId' => 'bookConferenceSlot', 'learnerRef' => 'vera-1', 'slotId' => '  '],
			action: $this->booking(),
			writer: $writer
		);

		$response = $controller->create('learniq', 'conference-signup');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'required_missing', 'errors' => ['slotId' => 'Kies een tijd']], $response->getData());
	}//end testACreateWithAnEmptyRequiredFieldIsRefusedBeforeTheWrite()

	/**
	 * The same create with both fields filled reaches the writer once.
	 *
	 * @return void
	 */
	public function testAFilledCreateIsWritten(): void {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->once())->method('createObject')->willReturn(['id' => 'signup-1']);
		$controller = $this->controller(
			params: ['actionId' => 'bookConferenceSlot', 'learnerRef' => 'vera-1', 'slotId' => 'slot-9'],
			action: $this->booking(),
			writer: $writer
		);

		$response = $controller->create('learniq', 'conference-signup');

		$this->assertSame(200, $response->getStatus());
	}//end testAFilledCreateIsWritten()

	/**
	 * An anonymous create (a landing page form) is held to the same rule.
	 *
	 * @return void
	 */
	public function testAnAnonymousCreateIsRefusedToo(): void {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->never())->method('createAnonymousObject');
		$form = [
			'id'             => 'submit-lead',
			'type'           => 'create',
			'anonymous'      => true,
			'register'       => 'portaliq',
			'schema'         => 'landingPageSubmission',
			'fields'         => ['name', 'email'],
			'requiredFields' => ['email'],
		];
		$controller = $this->controller(
			params: ['actionId' => 'submit-lead', 'name' => 'Jan'],
			action: $form,
			writer: $writer,
			subject: null
		);

		$response = $controller->create('portaliq', 'landingPageSubmission');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'required_missing', 'errors' => ['email' => '']], $response->getData());
	}//end testAnAnonymousCreateIsRefusedToo()

	/**
	 * An endpoint action without its subject is refused before the audit and
	 * before any outbound call.
	 *
	 * @return void
	 */
	public function testAnEndpointActionIsRefusedBeforeTheForward(): void {
		$sent    = [];
		$auditor = $this->createMock(AuditTrailService::class);
		$auditor->expects($this->never())->method('record');
		$controller = $this->controller(
			params: ['omschrijving' => 'Alle besluiten over de brug'],
			action: $this->woo(),
			sent: $sent,
			auditor: $auditor
		);

		$response = $controller->action('dossiq', 'startWooVerzoek');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'required_missing', 'errors' => ['onderwerp' => '']], $response->getData());
		$this->assertSame([], $sent, 'no outbound call');
	}//end testAnEndpointActionIsRefusedBeforeTheForward()

	/**
	 * Filled, the endpoint action is forwarded with exactly its fields.
	 *
	 * @return void
	 */
	public function testAFilledEndpointActionIsForwarded(): void {
		$sent       = [];
		$controller = $this->controller(
			params: ['onderwerp' => 'Brug', 'omschrijving' => 'Alle besluiten'],
			action: $this->woo(),
			sent: $sent
		);

		$response = $controller->action('dossiq', 'startWooVerzoek');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['onderwerp' => 'Brug', 'omschrijving' => 'Alle besluiten'], json_decode($sent['body'], true));
	}//end testAFilledEndpointActionIsForwarded()

	/**
	 * A controller whose registry serves one normalised action.
	 *
	 * @param array<string, mixed> $params The request params.
	 * @param array<string, mixed> $action The declared action.
	 * @param PortalObjectWriter|null $writer The writer double.
	 * @param array<string, mixed> $sent Receives the outbound call's options.
	 * @param AuditTrailService|null $auditor The auditor double.
	 * @param array<string, mixed>|null $subject The session subject, null for anonymous.
	 *
	 * @return ContributionController
	 */
	private function controller(
		array $params,
		array $action,
		?PortalObjectWriter $writer = null,
		array &$sent = [],
		?AuditTrailService $auditor = null,
		?array $subject = self::SUBJECT,
	): ContributionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn($subject === null ? '' : 'Bearer token');
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null) => ($params[$key] ?? $default));

		$schemas = $this->createMock(PortalSchemaReader::class);
		$schemas->method('readSchema')->willReturn(['required' => [], 'properties' => []]);
		$normalised = (new PortalManifestNormaliser($schemas))->normalise(['collections' => [], 'actions' => [$action]]);
		$app        = ($action['register'] ?? 'dossiq') === 'portaliq' ? 'portaliq' : (($action['register'] ?? null) === null ? 'dossiq' : 'learniq');
		$aggregate  = ['contributions' => [['app' => $app, 'collections' => [], 'actions' => $normalised['actions']]]];

		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn($aggregate);
		$registry->method('aggregateAnonymous')->willReturn($aggregate);

		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn($subject);
		$session->method('issueAssertion')->willReturn('ASSERTION_JWT_HERE');

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('{"identifier":"2026-0003"}');
		$response->method('getStatusCode')->willReturn(200);
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $uri, array $options) use (&$sent, $response) {
				$sent = $options;
				return $response;
			}
		);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(fn (string $path) => 'https://cloud.example' . $path);

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('resolveScopeValue')->willReturn('guardian-1');

		return new ContributionController(
			$request,
			$registry,
			$session,
			$reader,
			($writer ?? $this->createMock(PortalObjectWriter::class)),
			$this->createMock(PortalFileWriter::class),
			$this->createMock(PortalFileReader::class),
			$schemas,
			$this->createMock(PortalInboxReader::class),
			$this->createMock(PortalAuditHook::class),
			new PortalActionForwarder($request, new InstanceLoopback($clientService, $urls, $this->createMock(IAppConfig::class), $this->createMock(LoggerInterface::class)), $session),
			($auditor ?? $this->createMock(AuditTrailService::class)),
			$this->createMock(SubmissionReceiptService::class),
			$this->createMock(NotificationDispatchService::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()
}//end class
