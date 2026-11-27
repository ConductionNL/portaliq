<?php

/**
 * ContributionController files a create from a case page about that case
 * (case-page-objection-and-complaint REQ-COC-003/004): the server writes the
 * case id into the cross-reference field over the body, a case the resident
 * cannot read is refused, and a closed window answers 409. Nothing is stored
 * on a refusal.
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
 *
 * @spec openspec/changes/archive/2026-11-24-case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-the-server-fills-in-the-case-and-the-guard-still-runs-req-coc-004
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use OCA\Portaliq\Controller\ContributionController;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\InstanceLoopback;
use OCA\Portaliq\Service\InternalBaseUrl;
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
use OCA\Portaliq\Kernel\Support\SubjectAssertionIssuer;
use OCA\Portaliq\Service\SubmissionReceiptService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use OCA\Portaliq\Service\CaseActionsResolver;
use OCA\Portaliq\Service\OnCaseCreate;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use OCA\Portaliq\Service\CaseRowMarker;
use OCA\Portaliq\Contribution\CreateActionMatcher;
use OCA\Portaliq\Contribution\CreateBody;
use OCA\Portaliq\Contribution\InboxMessageFields;
use OCA\Portaliq\Contribution\PageChoice;
use OCA\Portaliq\Service\RequestedPortal;
use OCA\Portaliq\Service\RequiredFieldsGuard;
use OCA\Portaliq\Contribution\RowIdentifier;
use OCA\Portaliq\Contribution\ThemeChoice;
use OCA\Portaliq\Service\VisibleFromGate;
use OCA\Portaliq\Service\WriteRefusal;

/**
 * @covers \OCA\Portaliq\Controller\ContributionController
 * @covers \OCA\Portaliq\Service\OnCaseCreate
 * @uses   \OCA\Portaliq\Service\CaseActionsResolver
 */
class ContributionControllerOnCaseTest extends TestCase {
	private const SUBJECT = ['subjectRef' => 'guardian-1', 'audience' => 'citizen', 'organisation' => '', 'trust' => 'substantial', 'jti' => 'jti-1'];

	private const OWN_CASE = 'zaak-own';

	private const FOREIGN_CASE = 'zaak-foreign';

	/**
	 * The stored bodies.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $stored = [];

	private function bezwaar(): array {
		return [
			'id'        => 'createBezwaar',
			'type'      => 'create',
			'label'     => 'Bezwaar maken',
			'register'  => 'dossiq',
			'schema'    => 'portaalVerzoek',
			'fields'    => ['grounds', 'againstCaseId'],
			'crossRefs' => ['againstCaseId' => ['register' => 'zaken', 'schema' => 'zaak', 'scopeField' => 'portalSubject']],
			'onCase'    => [
				'crossRef'   => 'againstCaseId',
				'order'      => 10,
				'window'     => ['from' => 'decisionSentAt', 'days' => 42],
				'closedText' => 'De termijn voor bezwaar is voorbij.',
			],
		];
	}//end bezwaar()

	/**
	 * A reader that knows the resident's own case and another person's.
	 *
	 * @param string $decisionSentAt The own case's decision date.
	 *
	 * @return PortalObjectReader
	 */
	private function reader(string $decisionSentAt = '2026-10-02'): PortalObjectReader {
		$cases  = [
			self::OWN_CASE => ['id' => self::OWN_CASE, 'portalSubject' => 'guardian-1', 'decisionSentAt' => $decisionSentAt],
			self::FOREIGN_CASE => ['id' => self::FOREIGN_CASE, 'portalSubject' => 'someone-else', 'decisionSentAt' => '2026-10-02'],
		];
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('resolveScopeValue')->willReturn('guardian-1');
		$reader->method('readObject')->willReturnCallback(
			static function (string $register, string $schema, string $scopeField, string $subjectRef, string $id) use ($cases): ?array {
				$row = ($cases[$id] ?? null);
				if ($register !== 'zaken' || $schema !== 'zaak' || $row === null || ($row[$scopeField] ?? null) !== $subjectRef) {
					return null;
				}

				return $row;
			}
		);

		return $reader;
	}//end reader()

	private function resolver(): CaseActionsResolver {
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturn((new \DateTimeImmutable('2026-10-20 12:00:00', new \DateTimeZone('Europe/Amsterdam')))->getTimestamp());
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new CaseActionsResolver(time: $clock, l10n: $l10n);
	}//end resolver()

	private function writer(): PortalObjectWriter {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('createObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data): array {
				$this->stored[] = $data;
				return array_merge($data, ['id' => 'bezwaar-1']);
			}
		);

		return $writer;
	}//end writer()

	public function testTheCaseIsFilledInOverAForgedBody(): void {
		$controller = $this->controller(
			params: ['actionId' => 'createBezwaar', 'onCase' => 'zaken/zaak/' . self::OWN_CASE, 'grounds' => 'Onjuist besluit', 'againstCaseId' => self::FOREIGN_CASE],
			action: $this->bezwaar(),
			writer: $this->writer()
		);

		$response = $controller->create('dossiq', 'portaalVerzoek');

		$this->assertSame(200, $response->getStatus());
		$this->assertCount(1, $this->stored);
		$this->assertSame(self::OWN_CASE, $this->stored[0]['againstCaseId']);
		$this->assertSame('Onjuist besluit', $this->stored[0]['grounds']);
	}//end testTheCaseIsFilledInOverAForgedBody()

	public function testSomeoneElsesCaseIsRefused(): void {
		$controller = $this->controller(
			params: ['actionId' => 'createBezwaar', 'onCase' => 'zaken/zaak/' . self::FOREIGN_CASE, 'grounds' => 'x'],
			action: $this->bezwaar(),
			writer: $this->writer()
		);

		$response = $controller->create('dossiq', 'portaalVerzoek');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'cross_ref_refused', 'field' => 'againstCaseId'], $response->getData());
		$this->assertSame([], $this->stored);
	}//end testSomeoneElsesCaseIsRefused()

	public function testAClosedWindowAnswers409(): void {
		$controller = $this->controller(
			params: ['actionId' => 'createBezwaar', 'onCase' => 'zaken/zaak/' . self::OWN_CASE, 'grounds' => 'x'],
			action: $this->bezwaar(),
			writer: $this->writer(),
			reader: $this->reader(decisionSentAt: '2026-08-01')
		);

		$response = $controller->create('dossiq', 'portaalVerzoek');

		$this->assertSame(409, $response->getStatus());
		$this->assertSame(['error' => 'case_action_closed', 'message' => 'De termijn voor bezwaar is voorbij.'], $response->getData());
		$this->assertSame([], $this->stored);
	}//end testAClosedWindowAnswers409()

	public function testAnOnCaseOfAnotherCollectionIsRefused(): void {
		$controller = $this->controller(
			params: ['actionId' => 'createBezwaar', 'onCase' => 'zaken/melding/' . self::OWN_CASE, 'grounds' => 'x'],
			action: $this->bezwaar(),
			writer: $this->writer()
		);

		$response = $controller->create('dossiq', 'portaalVerzoek');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame([], $this->stored);
	}//end testAnOnCaseOfAnotherCollectionIsRefused()

	public function testAMalformedOnCaseIsABadRequest(): void {
		$controller = $this->controller(
			params: ['actionId' => 'createBezwaar', 'onCase' => 'zaken/zaak', 'grounds' => 'x'],
			action: $this->bezwaar(),
			writer: $this->writer()
		);

		$response = $controller->create('dossiq', 'portaalVerzoek');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'on_case_invalid'], $response->getData());
		$this->assertSame([], $this->stored);
	}//end testAMalformedOnCaseIsABadRequest()

	public function testWithoutOnCaseTheCreateIsUnchanged(): void {
		$controller = $this->controller(
			params: ['actionId' => 'createBezwaar', 'grounds' => 'x', 'againstCaseId' => self::OWN_CASE],
			action: $this->bezwaar(),
			writer: $this->writer()
		);

		$this->assertSame(200, $controller->create('dossiq', 'portaalVerzoek')->getStatus());
		$this->assertSame(self::OWN_CASE, $this->stored[0]['againstCaseId']);
	}//end testWithoutOnCaseTheCreateIsUnchanged()

	private function controller(
		array $params,
		array $action,
		?PortalObjectWriter $writer = null,
		array &$sent = [],
		?AuditTrailService $auditor = null,
		?array $subject = self::SUBJECT,
		?PortalObjectReader $reader = null,
	): ContributionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn($subject === null ? '' : 'Bearer token');
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null) => ($params[$key] ?? $default));

		$schemas = $this->createMock(PortalSchemaReader::class);
		$schemas->method('readSchema')->willReturn(['required' => [], 'properties' => []]);
		$normalised = (new PortalManifestNormaliser($schemas))->normalise(['collections' => [], 'actions' => [$action]]);
		$app        = 'dossiq';
		$aggregate  = ['contributions' => [['app' => $app, 'collections' => [], 'actions' => $normalised['actions']]]];

		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn($aggregate);
		$registry->method('aggregateAnonymous')->willReturn($aggregate);

		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn($subject);
		$assertions = $this->createMock(SubjectAssertionIssuer::class);
		$assertions->method('issueAssertion')->willReturn('ASSERTION_JWT_HERE');

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

		$reader ??= $this->reader();

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
			new PortalActionForwarder($request, new InstanceLoopback($clientService, $urls, $this->createMock(InternalBaseUrl::class), $this->createMock(LoggerInterface::class)), $assertions),
			($auditor ?? $this->createMock(AuditTrailService::class)),
			$this->createMock(SubmissionReceiptService::class),
			$this->createMock(NotificationDispatchService::class),
			$this->createMock(LoggerInterface::class),
			new CaseRowMarker(),
			new CreateActionMatcher(),
			new CreateBody(),
			new InboxMessageFields(),
			new PageChoice(),
			new RequestedPortal(),
			new RequiredFieldsGuard(),
			new RowIdentifier(),
			new ThemeChoice(),
			new VisibleFromGate(),
			new WriteRefusal(),
			onCase: new OnCaseCreate(reader: $reader, actions: $this->resolver())
		);
	}//end controller()
}//end class
