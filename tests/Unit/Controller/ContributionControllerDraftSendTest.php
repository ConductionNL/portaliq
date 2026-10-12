<?php

/**
 * A create with a live draft of its action sends that draft (decision 180):
 * the draft leaves status draft through OpenRegister's submit service, with
 * its owner portal:<subjectRef>, and no second object is written.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Controller\ContributionController;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\Intake\ActionDraft;
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
use OCA\Portaliq\Tests\Unit\Service\Intake\Fakes\FormSubmitFake;
use OCA\Portaliq\Service\Intake\DestinationSubmit;
use OCA\Portaliq\Tests\Unit\Service\Intake\Fakes\PortalOwnerDraft;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Sending a draft instead of creating a second object.
 *
 * @covers \OCA\Portaliq\Controller\ContributionController
 * @uses \OCA\Portaliq\Service\Intake\ActionDraft
 * @uses \OCA\Portaliq\Service\Intake\DestinationDraft
 * @uses \OCA\Portaliq\Service\Intake\DestinationSubmit
 * @uses \OCA\Portaliq\Contribution\ActionScopeResolver
 * @uses \OCA\Portaliq\Contribution\CreateActionMatcher
 * @uses \OCA\Portaliq\Contribution\CreateBody
 * @uses \OCA\Portaliq\Service\Branch\PortalBranchScope
 * @uses \OCA\Portaliq\Service\PortalCrossRefGuard
 * @uses \OCA\Portaliq\Service\PortalSessionService
 * @uses \OCA\Portaliq\Service\PortalUserDisplayNames
 * @uses \OCA\Portaliq\Service\RequiredFieldsGuard
 */
class ContributionControllerDraftSendTest extends TestCase {
	private const SUBJECT = ['subjectRef' => 'sanne', 'audience' => 'resident', 'organisation' => '', 'trust' => 'low', 'jti' => 'jti-1'];

	private const ACTION = [
		'id' => 'request',
		'type' => 'create',
		'register' => 'dossiq',
		'schema' => 'case',
		'fields' => ['title'],
		'defaults' => ['caseType' => 'ct-1'],
	];

	private FormSubmitFake $fake;

	public function testALiveDraftIsSentAndNoSecondObjectIsWritten(): void {
		$writer = $this->writer();
		$writer->expects($this->never())->method('createObject');
		$controller = $this->controller(writer: $writer, outcome: ['reference' => '2026-0042', 'id' => 'd-1', 'receivedAt' => '2026-10-11T10:00:00+00:00'], found: $this->live());

		$response = $controller->create('dossiq', 'case');
		$sent     = $this->fake->calls[1];

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('d-1', $response->getData()['object']['id']);
		$this->assertSame('2026-0042', $response->getData()['object']['reference']);
		$this->assertSame('d-1', $sent['draftId']);
		$this->assertSame('portal:sanne', $sent['owner']->value);
		$this->assertSame('key-7', $sent['idempotencyKey']);
		$this->assertSame(['title' => 'Whole', 'caseType' => 'ct-1', 'subjectRef' => 'sanne'], $sent['payload']);
	}//end testALiveDraftIsSentAndNoSecondObjectIsWritten()

	public function testARefusedSendNamesItsFieldsAndWritesNothing(): void {
		$writer = $this->writer();
		$writer->expects($this->never())->method('createObject');
		$controller = $this->controller(
			writer: $writer,
			outcome: FormSubmitFake::refusal(status: 422, findings: [['property' => 'description', 'code' => 'required', 'message' => 'Describe your request.']]),
			found: $this->live()
		);

		$response = $controller->create('dossiq', 'case');

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(['error' => 'invalid', 'errors' => ['description' => 'Describe your request.']], $response->getData());
	}//end testARefusedSendNamesItsFieldsAndWritesNothing()

	public function testWithoutADraftTheCreateWritesAsBefore(): void {
		$writer = $this->writer();
		$writer->expects($this->once())->method('createObject')->willReturn(['id' => 'new-1']);

		$response = $this->controller(writer: $writer, outcome: [], found: null)->create('dossiq', 'case');

		$this->assertSame(['object' => ['id' => 'new-1']], $response->getData());
		$this->assertCount(1, $this->fake->calls, 'only the lookup');
	}//end testWithoutADraftTheCreateWritesAsBefore()

	public function testAFailingDraftLookupWritesANewObjectAsBefore(): void {
		$writer = $this->writer();
		$writer->expects($this->once())->method('createObject')->willReturn(['id' => 'new-1']);
		$drafts = $this->createMock(ActionDraft::class);
		$drafts->method('find')->willThrowException(new \RuntimeException('lookup failed'));
		$controller = $this->controller(writer: $writer, outcome: [], found: null, drafts: $drafts);

		$this->assertSame(['object' => ['id' => 'new-1']], $controller->create('dossiq', 'case')->getData());
	}//end testAFailingDraftLookupWritesANewObjectAsBefore()

	/**
	 * A draft changed a moment ago.
	 *
	 * @return array<string, mixed>
	 */
	private function live(): array {
		return ['id' => 'd-1', 'status' => 'draft', 'data' => ['title' => 'Half'], 'updatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM)];
	}//end live()

	/**
	 * A writer double whose stamp sets the scope field.
	 */
	private function writer(): PortalObjectWriter {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('stamp')->willReturnCallback(
			static fn (string $schema, string $scopeField, string $subjectRef, string $organisation, array $data): array => array_merge($data, [$scopeField => $subjectRef])
		);

		return $writer;
	}//end writer()

	/**
	 * The controller with drafts wired.
	 *
	 * @param PortalObjectWriter              $writer  The writer double.
	 * @param array<string, mixed>|\Throwable $outcome What the submit answers.
	 * @param array<string, mixed>|null       $found   The live draft, or none.
	 */
	private function controller(PortalObjectWriter $writer, array|\Throwable $outcome, ?array $found, ?ActionDraft $drafts = null): ContributionController {
		$params  = ['actionId' => 'request', 'title' => 'Whole'];
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => ($name === 'Idempotency-Key' ? 'key-7' : 'Bearer token'));
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null) => ($params[$key] ?? $default));

		$aggregate = ['contributions' => [['app' => 'dossiq', 'collections' => [], 'actions' => [self::ACTION]]]];
		$registry  = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn($aggregate);

		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn(self::SUBJECT);

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('resolveScopeValue')->willReturn('sanne');

		$this->fake        = new FormSubmitFake(outcome: $outcome);
		$this->fake->found = $found;
		$destinations      = new PortalOwnerDraft($this->fake->container(), $this->createMock(LoggerInterface::class), new DestinationSubmit($this->fake->container(), $this->createMock(LoggerInterface::class)));

		return new ContributionController(
			request: $request,
			registry: $registry,
			session: $session,
			reader: $reader,
			writer: $writer,
			fileWriter: $this->createMock(PortalFileWriter::class),
			fileReader: $this->createMock(PortalFileReader::class),
			schemaReader: $this->createMock(PortalSchemaReader::class),
			inboxReader: $this->createMock(PortalInboxReader::class),
			auditHook: $this->createMock(PortalAuditHook::class),
			forwarder: $this->createMock(PortalActionForwarder::class),
			auditor: $this->createMock(AuditTrailService::class),
			receiptService: $this->createMock(SubmissionReceiptService::class),
			notificationDispatch: $this->createMock(NotificationDispatchService::class),
			logger: $this->createMock(LoggerInterface::class),
			drafts: ($drafts ?? new ActionDraft(registry: $registry, reader: $reader, writer: $writer, drafts: $destinations)),
			destinations: $destinations
		);
	}//end controller()
}//end class
