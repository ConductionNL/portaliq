<?php

/**
 * A landing-page form submits straight into the destination it names
 * (decision 179, submit-creates-the-case-directly 3.2): OpenRegister's submit
 * service creates the object in the request, the form's tracking values ride
 * as server values, and nothing is written in portaliq.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/submit-creates-the-case-directly/specs/landing-page-provisioning/spec.md#requirement-a-landing-pages-form-is-submittable-with-no-portal-session
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use OCA\Portaliq\Controller\ContributionController;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\Intake\DestinationIntake;
use OCA\Portaliq\Service\Intake\DestinationMapping;
use OCA\Portaliq\Service\Intake\DestinationSubmit;
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
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Landing-page forms into their destination.
 *
 * @covers \OCA\Portaliq\Controller\ContributionController
 * @uses \OCA\Portaliq\Service\Intake\DestinationIntake
 * @uses \OCA\Portaliq\Service\Intake\DestinationMapping
 * @uses \OCA\Portaliq\Service\Intake\DestinationSubmit
 * @uses \OCA\Portaliq\Contribution\ActionConfigNormaliser
 * @uses \OCA\Portaliq\Contribution\ActionFormShape
 * @uses \OCA\Portaliq\Contribution\ActionOptionsNormaliser
 * @uses \OCA\Portaliq\Contribution\ActionSummaryNormaliser
 * @uses \OCA\Portaliq\Contribution\CitizenWriteConfigNormaliser
 * @uses \OCA\Portaliq\Contribution\CollectionConfigNormaliser
 * @uses \OCA\Portaliq\Contribution\CollectionSchemaLabels
 * @uses \OCA\Portaliq\Contribution\ConfirmationMailKeys
 * @uses \OCA\Portaliq\Contribution\CreateActionMatcher
 * @uses \OCA\Portaliq\Contribution\CreateBody
 * @uses \OCA\Portaliq\Contribution\CrossRefConfigNormaliser
 * @uses \OCA\Portaliq\Contribution\FieldWidgetNormaliser
 * @uses \OCA\Portaliq\Contribution\FormStepsNormaliser
 * @uses \OCA\Portaliq\Contribution\GuestActionConfigNormaliser
 * @uses \OCA\Portaliq\Contribution\InboxReplyConfigNormaliser
 * @uses \OCA\Portaliq\Contribution\ItemListConfigNormaliser
 * @uses \OCA\Portaliq\Contribution\ManifestValueNormaliser
 * @uses \OCA\Portaliq\Contribution\PortalManifestNormaliser
 * @uses \OCA\Portaliq\Contribution\PortalPageResolver
 * @uses \OCA\Portaliq\Contribution\RequiredFieldsNormaliser
 * @uses \OCA\Portaliq\Contribution\RowActionInputs
 * @uses \OCA\Portaliq\Contribution\RowActionResolver
 * @uses \OCA\Portaliq\Contribution\SchemaInputHintNormaliser
 * @uses \OCA\Portaliq\Contribution\StartTileNormaliser
 * @uses \OCA\Portaliq\Contribution\ThemeTagKeys
 * @uses \OCA\Portaliq\Contribution\TimedTaskConfigNormaliser
 * @uses \OCA\Portaliq\Contribution\ValueLabelsNormaliser
 * @uses \OCA\Portaliq\Service\PortalJwtService
 * @uses \OCA\Portaliq\Service\PortalUserDisplayNames
 * @uses \OCA\Portaliq\Service\RequiredFieldsGuard
 */
class ContributionControllerLandingDestinationTest extends TestCase {

	private const ACTION = [
		'id' => 'submit-form-a',
		'type' => 'create',
		'anonymous' => true,
		'register' => 'portaliq',
		'schema' => 'landingPageSubmission',
		'fields' => ['email', 'name', 'utmFirstTouch'],
		'destination' => ['register' => 'pipelinq', 'schema' => 'lead', 'fixed' => ['source' => 'landing']],
		'intakeScope' => 'open-tilburg//webinar',
		'server' => ['formId' => 'form-a', 'pageRoute' => '/webinar', 'nonce' => 'n-1', 'campaign' => []],
	];

	private FormSubmitFake $fake;

	public function testTheFormCreatesItsDestinationWithServerValuesAndWritesNothingInPortaliq(): void {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->never())->method('createAnonymousObject');
		$writer->expects($this->never())->method('createObject');

		$response = $this->controller(writer: $writer, outcome: ['reference' => 'LEAD-7', 'id' => 'l-1'])
			->create('portaliq', 'landingPageSubmission');
		$call     = $this->fake->calls[0];

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('LEAD-7', $response->getData()['object']['reference']);
		$this->assertSame(['register' => 'pipelinq', 'schema' => 'lead'], $call['destination']);
		$this->assertNull($call['subject']);
		$this->assertSame(['email' => 'ada@example.nl', 'name' => 'Ada'], $call['payload']);
		$this->assertSame('landing', $call['mapping']['fixed']['source']);
		$this->assertSame('form-a', $call['mapping']['fixed']['formId']);
		$this->assertSame('/webinar', $call['mapping']['fixed']['pageRoute']);
		$this->assertArrayNotHasKey('nonce', $call['mapping']['fixed'], 'a value the destination does not declare is not sent');
		$this->assertSame('open-tilburg//webinar', $call['scope']);
		$this->assertSame('key-1', $call['idempotencyKey']);
	}//end testTheFormCreatesItsDestinationWithServerValuesAndWritesNothingInPortaliq()

	public function testARefusedAnswerComesBackOnItsField(): void {
		$response = $this->controller(
			writer: $this->createMock(PortalObjectWriter::class),
			outcome: FormSubmitFake::refusal(status: 422, findings: [['property' => 'email', 'code' => 'format', 'message' => 'Fill in an e-mail address.']])
		)->create('portaliq', 'landingPageSubmission');

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(['email' => 'Fill in an e-mail address.'], $response->getData()['errors']);
	}//end testARefusedAnswerComesBackOnItsField()

	public function testWithoutTheIntakeTheFormSaysTryAgainLater(): void {
		$response = $this->controller(writer: $this->createMock(PortalObjectWriter::class), outcome: [], wired: false)->create('portaliq', 'landingPageSubmission');

		$this->assertSame(503, $response->getStatus());
		$this->assertSame(['error' => 'try_again_later'], $response->getData());
	}//end testWithoutTheIntakeTheFormSaysTryAgainLater()

	/**
	 * The controller for an anonymous visitor, the action passed through the real normaliser.
	 *
	 * @param array<string, mixed>|\Throwable $outcome What the submit answers.
	 */
	private function controller(PortalObjectWriter $writer, array|\Throwable $outcome, bool $wired = true): ContributionController {
		$params  = ['actionId' => 'submit-form-a', 'email' => 'ada@example.nl', 'name' => 'Ada', 'isAdmin' => true];
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => ($name === 'Idempotency-Key' ? 'key-1' : ''));
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null) => ($params[$key] ?? $default));

		$schemas = $this->createMock(PortalSchemaReader::class);
		$schemas->method('readSchema')->willReturn(['required' => [], 'properties' => ['email' => ['type' => 'string'], 'name' => ['type' => 'string'], 'formId' => ['type' => 'string'], 'pageRoute' => ['type' => 'string'], 'source' => ['type' => 'string']]]);
		$normalised = (new PortalManifestNormaliser($schemas))->normalise(['collections' => [], 'actions' => [self::ACTION]]);
		$aggregate  = ['contributions' => [['app' => 'portaliq', 'collections' => [], 'actions' => $normalised['actions']]]];

		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateAnonymous')->willReturn($aggregate);
		$registry->method('aggregateFor')->willReturn($aggregate);

		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn(null);

		$this->fake = new FormSubmitFake(outcome: $outcome);
		$intake     = null;
		if ($wired === true) {
			$intake = new DestinationIntake(
				submitter: new DestinationSubmit($this->fake->container(), $this->createMock(LoggerInterface::class)),
				mapping: new DestinationMapping(),
				schemas: $schemas
			);
		}

		return new ContributionController(
			request: $request,
			registry: $registry,
			session: $session,
			reader: $this->createMock(PortalObjectReader::class),
			writer: $writer,
			fileWriter: $this->createMock(PortalFileWriter::class),
			fileReader: $this->createMock(PortalFileReader::class),
			schemaReader: $schemas,
			inboxReader: $this->createMock(PortalInboxReader::class),
			auditHook: $this->createMock(PortalAuditHook::class),
			forwarder: $this->createMock(PortalActionForwarder::class),
			auditor: $this->createMock(AuditTrailService::class),
			receiptService: $this->createMock(SubmissionReceiptService::class),
			notificationDispatch: $this->createMock(NotificationDispatchService::class),
			logger: $this->createMock(LoggerInterface::class),
			intake: $intake
		);
	}//end controller()
}//end class
