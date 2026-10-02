<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Controller\ContributionController;
use OCA\Portaliq\Service\AuditTrailService;
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
use OCA\Portaliq\Service\PortalUserDisplayNames;
use OCA\Portaliq\Service\SubmissionReceiptService;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A `render: "user"` column reaches the resident as the user's display name,
 * on the list and on the single object, and the user id never leaves the
 * server (contribution-user-display-name).
 *
 * @spec openspec/changes/contribution-user-display-name/specs/portal-contribution-contract/spec.md#requirement-a-column-may-show-a-nextcloud-user-by-name
 */
class ContributionControllerUserNamesTest extends TestCase {
	/**
	 * The guardian.
	 */
	private const SUBJECT = ['subjectRef' => 'guardian-1', 'audience' => 'parent', 'organisation' => '', 'trust' => 'substantial', 'jti' => 'jti-1'];

	/**
	 * The absence reports collection learniq declares, with its teacher column.
	 */
	private const COLLECTION = [
		'id' => 'absences',
		'register' => 'learniq',
		'schema' => 'absence-report',
		'scopeField' => 'guardianRef',
		'columns' => [
			['field' => 'reason', 'render' => 'text'],
			['field' => 'handledBy', 'label' => 'Leerkracht', 'render' => 'user'],
		],
	];

	/**
	 * The stored row: a Nextcloud user id in `handledBy`.
	 */
	private const ROW = ['id' => 'r1', 'reason' => 'Ziek', 'handledBy' => 'po-leerkracht-09', 'guardianRef' => 'guardian-1'];

	/**
	 * The list answers the teacher's name in place of the user id.
	 *
	 * @return void
	 */
	public function testTheListAnswersTheTeachersName(): void {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturn([self::ROW]);

		$data = $this->controller(reader: $reader)->collection('learniq', 'absence-report')->getData();

		$this->assertSame('Meester Jansen', $data['objects'][0]['handledBy']);
		$this->assertSame('Ziek', $data['objects'][0]['reason']);
		$this->assertStringNotContainsString('po-leerkracht-09', (string)json_encode($data));
	}//end testTheListAnswersTheTeachersName()

	/**
	 * The single object answers the name too.
	 *
	 * @return void
	 */
	public function testTheObjectAnswersTheTeachersName(): void {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readObject')->willReturn(self::ROW);

		$data = $this->controller(reader: $reader)->object('learniq', 'absence-report', 'r1')->getData();

		$this->assertSame('Meester Jansen', $data['object']['handledBy']);
		$this->assertStringNotContainsString('po-leerkracht-09', (string)json_encode($data));
	}//end testTheObjectAnswersTheTeachersName()

	/**
	 * The controller under test, with one teacher on the instance.
	 *
	 * @param PortalObjectReader $reader The reader.
	 *
	 * @return ContributionController
	 */
	private function controller(PortalObjectReader $reader): ContributionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnMap([['Authorization', 'Bearer guardian-token']]);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $default);

		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn(['contributions' => [['app' => 'learniq', 'collections' => [self::COLLECTION]]]]);

		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn(self::SUBJECT);

		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturnMap([['po-leerkracht-09', 'Meester Jansen']]);

		return new ContributionController(
			request: $request,
			registry: $registry,
			session: $session,
			reader: $reader,
			writer: $this->createMock(PortalObjectWriter::class),
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
			userNames: new PortalUserDisplayNames(users: $users)
		);
	}//end controller()
}//end class
