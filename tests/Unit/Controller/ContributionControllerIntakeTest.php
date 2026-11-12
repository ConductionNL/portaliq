<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Controller\ContributionController;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\Intake\DestinationIntake;
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
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * What an anonymous create hands the destination intake: the action's
 * string fields, its scope split into portal and route, its server values
 * and the idempotency key, and how a refusal is answered.
 */
class ContributionControllerIntakeTest extends TestCase {
	/**
	 * Submit through the controller's destination intake.
	 *
	 * @param DestinationIntake|null $intake  The intake, or null when it is not wired.
	 * @param array<string, mixed>   $action  The matched action.
	 * @param array<string, mixed>   $answers The whitelisted answers.
	 *
	 * @return \OCP\AppFramework\Http\JSONResponse
	 */
	private function submit(?DestinationIntake $intake, array $action, array $answers): \OCP\AppFramework\Http\JSONResponse {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => ($name === 'Idempotency-Key' ? 'key-9' : ''));
		$controller = new ContributionController(
			request: $request,
			registry: $this->createMock(PortalContributionRegistry::class),
			session: $this->createMock(PortalSessionService::class),
			reader: $this->createMock(PortalObjectReader::class),
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
			intake: $intake
		);

		return (new ReflectionMethod($controller, 'submitIntoDestination'))->invoke($controller, $action, $answers);
	}//end submit()

	/**
	 * The intake gets the string fields only, the scope split once, the
	 * server values and the key; its answer is the object.
	 *
	 * @return void
	 */
	public function testTheIntakeGetsTheActionAsItsBinding(): void {
		$calls  = [];
		$intake = $this->createMock(DestinationIntake::class);
		$intake->method('submit')->willReturnCallback(
			function (array $binding, array $render, array $answers, string $subjectRef = '', array $server = [], string $idempotencyKey = '') use (&$calls): array {
				$calls[] = compact('binding', 'render', 'answers', 'subjectRef', 'server', 'idempotencyKey');
				return ['ok' => true, 'answer' => ['reference' => 'R-1']];
			}
		);
		$action = ['fields' => ['email', '', 5, 'name'], 'destination' => ['register' => 'r', 'schema' => 's'], 'intakeScope' => 'portal-a/a/b', 'server' => ['formId' => 'f']];

		$response = $this->submit($intake, $action, ['email' => 'e']);
		$this->assertSame(['object' => ['reference' => 'R-1']], $response->getData());
		$this->assertSame(
			[
				'binding' => ['destination' => ['register' => 'r', 'schema' => 's'], 'portal' => 'portal-a', 'route' => 'a/b'],
				'render' => ['fields' => [['name' => 'email'], ['name' => 'name']]],
				'answers' => ['email' => 'e'],
				'subjectRef' => '',
				'server' => ['formId' => 'f'],
				'idempotencyKey' => 'key-9',
			],
			$calls[0]
		);

		$this->submit($intake, ['destination' => [], 'intakeScope' => 'portal-only'], []);
		$this->assertSame(['destination' => [], 'portal' => 'portal-only', 'route' => ''], $calls[1]['binding']);
		$this->assertSame(['fields' => []], $calls[1]['render']);
		$this->assertSame([], $calls[1]['server']);
	}//end testTheIntakeGetsTheActionAsItsBinding()

	/**
	 * A refused submission and a missing intake answer as the intake words them.
	 *
	 * @return void
	 */
	public function testRefusalsAreTheIntakesOwn(): void {
		$intake = $this->createMock(DestinationIntake::class);
		$intake->method('submit')->willReturn(['ok' => false, 'status' => 404]);

		$refused = $this->submit($intake, ['destination' => []], []);
		$this->assertSame(404, $refused->getStatus());
		$this->assertSame(['error' => 'form_not_submittable'], $refused->getData());

		$missing = $this->submit(null, ['destination' => []], []);
		$this->assertSame(503, $missing->getStatus());
		$this->assertSame(['error' => 'try_again_later'], $missing->getData());
	}//end testRefusalsAreTheIntakesOwn()
}//end class
