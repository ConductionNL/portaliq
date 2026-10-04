<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
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
use OCA\Portaliq\Service\SubmissionReceiptService;
use OCP\AppFramework\Http;
use OCP\Http\Client\IClientService;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A declared file field is never written from a request body
 * (assignment-portal-file-upload). The body in these tests carries a typed
 * reference to somebody else's file; the writer must receive everything but
 * that field, on the authenticated create, the anonymous create and update.
 *
 * @spec openspec/changes/assignment-portal-file-upload/specs/portal-contribution-contract/spec.md#requirement-a-file-field-must-never-be-written-from-a-request-body
 */
class ContributionControllerFileFieldTest extends TestCase {
	/**
	 * The authenticated subject.
	 */
	private const SUBJECT = [
		'subjectRef' => 'learner-1',
		'audience' => 'student',
		'organisation' => '',
		'trust' => 'low',
		'roles' => [],
		'jti' => 'jti-1',
	];

	/**
	 * The hand-crafted body: a real field plus a typed file reference.
	 */
	private const BODY = [
		'assignmentId' => 'assignment-1',
		'attachmentRefs' => ['/admin/files/secret.pdf'],
	];

	/**
	 * A create action carrying a file field.
	 *
	 * @param string $type The action type.
	 * @param bool $anonymous Whether the action is anonymous.
	 *
	 * @return array<string, mixed>
	 */
	private function action(string $type, bool $anonymous = false): array {
		$action = [
			'id' => 'handIn',
			'type' => $type,
			'register' => 'learniq',
			'schema' => 'submission',
			'scopeField' => 'learnerRef',
			'fields' => ['assignmentId', 'attachmentRefs'],
			'fieldConfigs' => ['attachmentRefs' => ['type' => 'file', 'multiple' => true, 'size' => 'medium']],
		];
		if ($anonymous === true) {
			$action['anonymous'] = true;
		}

		return $action;
	}//end action()

	/**
	 * Create drops the typed reference before the writer sees the body.
	 *
	 * @return void
	 */
	public function testCreateDropsATypedFileFieldValue(): void {
		$saved = null;
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->once())->method('createObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data) use (&$saved) {
				$saved = $data;
				return ['id' => 'submission-1'];
			}
		);

		$response = $this->controller(actions: [$this->action(type: 'create')], writer: $writer)->create('learniq', 'submission');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['assignmentId' => 'assignment-1'], $saved);
	}//end testCreateDropsATypedFileFieldValue()

	/**
	 * The anonymous create path strips the same field.
	 *
	 * @return void
	 */
	public function testAnonymousCreateDropsATypedFileFieldValue(): void {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->once())->method('createAnonymousObject')
			->with('learniq', 'submission', ['assignmentId' => 'assignment-1'])
			->willReturn(['id' => 'submission-2']);

		$response = $this->controller(
			actions: [],
			writer: $writer,
			subject: null,
			anonymousActions: [$this->action(type: 'create', anonymous: true)]
		)->create('learniq', 'submission');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testAnonymousCreateDropsATypedFileFieldValue()

	/**
	 * Update drops the typed reference too.
	 *
	 * @return void
	 */
	public function testUpdateDropsATypedFileFieldValue(): void {
		$received = null;
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->once())->method('updateObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, array $data) use (&$received) {
				$received = $data;
				return ['id' => $id];
			}
		);

		$response = $this->controller(actions: [$this->action(type: 'update')], writer: $writer)->update('learniq', 'submission', 'submission-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['assignmentId' => 'assignment-1'], $received);
	}//end testUpdateDropsATypedFileFieldValue()

	/**
	 * A field that is not a file field still travels as before.
	 *
	 * @return void
	 */
	public function testANonFileFieldIsStillWritten(): void {
		$action = $this->action(type: 'create');
		$action['fieldConfigs'] = ['attachmentRefs' => ['label' => 'Reference', 'size' => 'medium']];

		$saved = null;
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('createObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data) use (&$saved) {
				$saved = $data;
				return ['id' => 'submission-3'];
			}
		);

		$this->controller(actions: [$action], writer: $writer)->create('learniq', 'submission');

		$this->assertSame(self::BODY, $saved);
	}//end testANonFileFieldIsStillWritten()

	/**
	 * Build the controller with the hand-crafted body and one contribution.
	 *
	 * @param array<int, array<string, mixed>> $actions The subject's actions.
	 * @param PortalObjectWriter $writer The writer under observation.
	 * @param array<string, mixed>|null $subject The subject, null for anonymous.
	 * @param array<int, array<string, mixed>> $anonymousActions The anonymous actions.
	 *
	 * @return ContributionController
	 */
	private function controller(
		array $actions,
		PortalObjectWriter $writer,
		?array $subject = self::SUBJECT,
		array $anonymousActions = [],
	): ContributionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer token');
		$request->method('getParam')->willReturnCallback(fn (string $key) => (self::BODY[$key] ?? null));

		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn(['contributions' => [['app' => 'learniq', 'collections' => [], 'actions' => $actions]]]);
		$registry->method('aggregateAnonymous')->willReturn(['contributions' => [['app' => 'learniq', 'collections' => [], 'actions' => $anonymousActions]]]);

		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn($subject);

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('resolveScopeValue')->willReturn('learner-1');

		return new ContributionController(
			$request,
			$registry,
			$session,
			$reader,
			$writer,
			$this->createMock(PortalFileWriter::class),
			$this->createMock(PortalFileReader::class),
			$this->createMock(PortalSchemaReader::class),
			$this->createMock(PortalInboxReader::class),
			$this->createMock(PortalAuditHook::class),
			new PortalActionForwarder($request, new InstanceLoopback($this->createMock(IClientService::class), $this->createMock(IURLGenerator::class), $this->createMock(InternalBaseUrl::class), $this->createMock(LoggerInterface::class)), $session),
			$this->createMock(AuditTrailService::class),
			$this->createMock(SubmissionReceiptService::class),
			$this->createMock(NotificationDispatchService::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()
}//end class
