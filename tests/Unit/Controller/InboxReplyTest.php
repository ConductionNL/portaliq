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
use OCA\Portaliq\Service\PortalCrossRefGuard;
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * inbox-reply-with-attachments REQ-IRA-002: the reply is built on the server
 * from the resident's own message, and carried fields are never the client's.
 *
 * @spec openspec/changes/inbox-reply-with-attachments/tasks.md#t03
 */
class InboxReplyTest extends TestCase {

	private const SUBJECT = ['subjectRef' => 'res-1', 'audience' => 'client', 'organisation' => 'org-1', 'trust' => 'low', 'roles' => [], 'jti' => 'j'];

	private MockObject $reader;

	private MockObject $writer;

	/**
	 * Build the controller over one inbox collection with a reply declaration.
	 *
	 * @param array<string, mixed> $collection Overrides for the inbox collection.
	 * @param array<string, mixed>|null $subject The session's subject.
	 * @param array<string, mixed> $params The request's parameters.
	 *
	 * @return ContributionController
	 */
	private function controller(array $collection=[], ?array $subject=self::SUBJECT, array $params=[]): ContributionController {
		$inbox = $collection + [
			'id' => 'berichten', 'kind' => 'inbox', 'register' => 'dossiq', 'schema' => 'portaalBericht', 'scopeField' => 'recipientRef',
			'fields' => ['subject', 'content', 'caseId'],
			'reply' => ['action' => 'replyToMessage', 'carry' => ['caseId' => 'caseId'], 'subjectFrom' => 'subject'],
		];
		$action = [
			'id' => 'replyToMessage', 'type' => 'create', 'register' => 'dossiq', 'schema' => 'portaalBericht', 'scopeField' => 'senderRef',
			'fields' => ['subject', 'content', 'caseId'], 'defaults' => ['direction' => 'citizen_to_handler'],
		];
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer t');
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default=null) => ($params[$key] ?? $default));
		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn(['contributions' => [['app' => 'dossiq', 'collections' => [$inbox], 'actions' => [$action]]]]);
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn($subject);
		$this->reader = $this->createMock(PortalObjectReader::class);
		$this->reader->method('resolveScopeValue')->willReturn('res-1');
		$this->writer = $this->createMock(PortalObjectWriter::class);
		$guard        = $this->createMock(PortalCrossRefGuard::class);
		$guard->method('refusal')->willReturn(null);
		$urls = $this->createMock(IURLGenerator::class);

		return new ContributionController(
			$request,
			$registry,
			$session,
			$this->reader,
			$this->writer,
			$this->createMock(PortalFileWriter::class),
			$this->createMock(PortalFileReader::class),
			$this->createMock(PortalSchemaReader::class),
			$this->createMock(PortalInboxReader::class),
			$this->createMock(PortalAuditHook::class),
			new PortalActionForwarder($request, new InstanceLoopback($this->createMock(IClientService::class), $urls, $this->createMock(InternalBaseUrl::class), $this->createMock(LoggerInterface::class)), $session),
			$this->createMock(AuditTrailService::class),
			$this->createMock(SubmissionReceiptService::class),
			$this->createMock(NotificationDispatchService::class),
			$this->createMock(LoggerInterface::class),
			crossRefs: $guard
		);
	}

	public function testReplyCarriesTheCaseFromTheMessage(): void {
		$controller = $this->controller(params: ['subject' => 'Re: Besluit', 'content' => 'Dank u wel.']);
		$this->reader->method('readObject')->willReturn(['id' => 'm1', 'subject' => 'Besluit', 'caseId' => 'Z/2026/09128']);
		$written = [];
		$this->writer->method('createObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data) use (&$written): array {
				$written = compact('register', 'schema', 'scopeField', 'subjectRef', 'organisation', 'data');
				return ['id' => 'r1'];
			}
		);

		$response = $controller->reply('dossiq', 'portaalBericht', 'm1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('Z/2026/09128', $written['data']['caseId']);
		$this->assertSame('Re: Besluit', $written['data']['subject']);
		$this->assertSame('citizen_to_handler', $written['data']['direction'], 'the action defaults still apply');
		$this->assertSame('senderRef', $written['scopeField']);
		$this->assertSame('res-1', $written['subjectRef']);
		$this->assertSame(['replyToMessage', 'dossiq', 'portaalBericht', 'r1'], [$response->getData()['action'], $response->getData()['register'], $response->getData()['schema'], $response->getData()['object']['id']]);

	}//end testReplyCarriesTheCaseFromTheMessage()

	public function testClientCaseIdIsOverwritten(): void {
		$controller = $this->controller(params: ['subject' => 'Re', 'content' => 'x', 'caseId' => 'Z/ANDERS/1']);
		$this->reader->method('readObject')->willReturn(['id' => 'm1', 'caseId' => 'Z/2026/09128']);
		$data = [];
		$this->writer->method('createObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $payload) use (&$data): array {
				$data = $payload;
				return ['id' => 'r1'];
			}
		);

		$controller->reply('dossiq', 'portaalBericht', 'm1');

		$this->assertSame('Z/2026/09128', $data['caseId'], 'the case of the message, not the client\'s');

	}//end testClientCaseIdIsOverwritten()

	public function testForeignMessageIs404(): void {
		$controller = $this->controller(params: ['content' => 'x']);
		$this->reader->method('readObject')->willReturn(null);
		$this->writer->expects($this->never())->method('createObject');

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->reply('dossiq', 'portaalBericht', 'someone-elses')->getStatus());

	}//end testForeignMessageIs404()

	public function testCollectionWithoutReplyIs404(): void {
		$controller = $this->controller(collection: ['reply' => null]);
		$this->reader->expects($this->never())->method('readObject');
		$this->writer->expects($this->never())->method('createObject');

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->reply('dossiq', 'portaalBericht', 'm1')->getStatus());

	}//end testCollectionWithoutReplyIs404()

	public function testLowTrustIs403(): void {
		$controller = $this->controller(collection: ['minTrust' => 'substantial']);
		$this->reader->expects($this->never())->method('readObject');
		$this->writer->expects($this->never())->method('createObject');

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->reply('dossiq', 'portaalBericht', 'm1')->getStatus());

	}//end testLowTrustIs403()

	public function testWithoutASessionAndOutsideTheInboxNothingHappens(): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(subject: null)->reply('dossiq', 'portaalBericht', 'm1')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->reply('dossiq', 'somethingElse', 'm1')->getStatus(), 'not an inbox collection of the resident');

	}//end testWithoutASessionAndOutsideTheInboxNothingHappens()

	public function testAReplyActionThatIsNotHeldIs404(): void {
		$controller = $this->controller(collection: ['reply' => ['action' => 'notMine', 'carry' => []]]);
		$this->reader->method('readObject')->willReturn(['id' => 'm1']);
		$this->writer->expects($this->never())->method('createObject');

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->reply('dossiq', 'portaalBericht', 'm1')->getStatus());

	}//end testAReplyActionThatIsNotHeldIs404()
}//end class
