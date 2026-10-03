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
use OCA\Portaliq\Service\SubmissionReceiptService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The subjectField stamp on an endpoint forward (portal-take-assessment): the
 * learner the leaf app receives is the one portaliq resolved, never the one
 * the browser typed.
 *
 * @spec openspec/changes/portal-take-assessment/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-action-must-be-able-to-receive-the-subjects-scope-from-the-server
 */
class ContributionControllerSubjectFieldTest extends TestCase {
	/**
	 * The pupil.
	 */
	private const SUBJECT = ['subjectRef' => 'pupil-account-1', 'audience' => 'student', 'organisation' => '', 'trust' => 'low', 'jti' => 'jti-1'];

	/**
	 * The body the browser sends: a real field and a smuggled learner.
	 */
	private const BODY = ['attemptId' => 'attempt-1', 'learnerRef' => 'someone-else'];

	/**
	 * The start action learniq declares.
	 *
	 * @param array<string, mixed> $overrides Overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function action(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'submitTest',
				'endpoint' => '/apps/learniq/api/portal/assessments/submit',
				'method' => 'POST',
				'fields' => ['attemptId'],
				'subjectField' => 'learnerRef',
				'scopeClaim' => 'learnerRef',
			],
			$overrides
		);
	}//end action()

	/**
	 * The resolved learner replaces the smuggled one in the forwarded body.
	 *
	 * @return void
	 */
	public function testTheResolvedScopeOverridesAClientValue(): void {
		$sent = [];
		$controller = $this->controller(action: $this->action(), scopeValue: 'learner-profile-7', sent: $sent);

		$response = $controller->action('learniq', 'submitTest');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['attemptId' => 'attempt-1', 'learnerRef' => 'learner-profile-7'], json_decode($sent['body'], true));
	}//end testTheResolvedScopeOverridesAClientValue()

	/**
	 * No resolvable claim is 403, no request made and no forward audited.
	 *
	 * @return void
	 */
	public function testAnUnresolvableScopeIs403WithoutForwarding(): void {
		foreach ([null, ''] as $unresolved) {
			$sent = [];
			$auditor = $this->createMock(AuditTrailService::class);
			$auditor->expects($this->never())->method('record');
			$controller = $this->controller(action: $this->action(), scopeValue: $unresolved, sent: $sent, auditor: $auditor);

			$response = $controller->action('learniq', 'submitTest');

			$this->assertSame(403, $response->getStatus());
			$this->assertSame([], $sent, 'no outbound call');
		}
	}//end testAnUnresolvableScopeIs403WithoutForwarding()

	/**
	 * Without a `fields` whitelist the body is the stamp alone, never the raw
	 * request; without `subjectField` the forward is as before.
	 *
	 * @return void
	 */
	public function testStampWithoutFieldsAndNoStampWithoutSubjectField(): void {
		$action = $this->action();
		unset($action['fields']);
		$sent = [];
		$this->controller(action: $action, scopeValue: 'learner-profile-7', sent: $sent)->action('learniq', 'submitTest');
		$this->assertSame(['learnerRef' => 'learner-profile-7'], json_decode($sent['body'], true));

		$plain = $this->action();
		unset($plain['subjectField']);
		$sent = [];
		$this->controller(action: $plain, scopeValue: 'learner-profile-7', sent: $sent)->action('learniq', 'submitTest');
		$this->assertSame(['attemptId' => 'attempt-1'], json_decode($sent['body'], true));
	}//end testStampWithoutFieldsAndNoStampWithoutSubjectField()

	/**
	 * #804, case-actions-sign-a-document D3: an action that declares a
	 * `scopeClaim` has the resolved value signed INTO the assertion, where a
	 * receiver can trust it (filinq reads `signerEmail` there). A declared
	 * claim that does not resolve stops the forward, with or without a
	 * `subjectField`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-frozen-assertion-wire-format
	 */
	public function testADeclaredScopeClaimRidesInTheAssertion(): void {
		$session = $this->createMock(PortalSessionService::class);
		$session->expects($this->once())->method('issueAssertion')
			->with(self::SUBJECT, 'learnerRef', 'learner-profile-7')
			->willReturn('ASSERTION_JWT_HERE');
		$sent = [];
		$response = $this->controller(action: $this->action(), scopeValue: 'learner-profile-7', sent: $sent, session: $session)->action('learniq', 'submitTest');
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('ASSERTION_JWT_HERE', $sent['headers']['X-Portal-Subject']);

		$plain = $this->action();
		unset($plain['subjectField']);
		$sent = [];
		$response = $this->controller(action: $plain, scopeValue: null, sent: $sent)->action('learniq', 'submitTest');
		$this->assertSame(403, $response->getStatus());
		$this->assertSame([], $sent, 'no outbound call');
	}//end testADeclaredScopeClaimRidesInTheAssertion()

	/**
	 * Build the controller around a recording HTTP client.
	 *
	 * @param array<string, mixed> $action The one declared action.
	 * @param string|null $scopeValue What the claim resolves to.
	 * @param array<string, mixed> $sent Receives the outbound request options.
	 * @param AuditTrailService|null $auditor The auditor.
	 * @param PortalSessionService|null $session The session double, when a test pins the assertion.
	 *
	 * @return ContributionController
	 */
	private function controller(array $action, ?string $scopeValue, array &$sent, ?AuditTrailService $auditor = null, ?PortalSessionService $session = null): ContributionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer token');
		$request->method('getParam')->willReturnCallback(fn (string $key) => (self::BODY[$key] ?? null));

		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn(['contributions' => [['app' => 'learniq', 'collections' => [], 'actions' => [$action]]]]);

		if ($session === null) {
			$session = $this->createMock(PortalSessionService::class);
			$session->method('issueAssertion')->willReturn('ASSERTION_JWT_HERE');
		}

		$session->method('resolveFromBearer')->willReturn(self::SUBJECT);

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('resolveScopeValue')->willReturnCallback(
			function (string $scopeClaim, string $contributingApp, array $subject) use ($scopeValue): ?string {
				$this->assertSame('learnerRef', $scopeClaim);
				$this->assertSame('learniq', $contributingApp);
				return $scopeValue;
			}
		);

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('{"state":"submitted"}');
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

		return new ContributionController(
			$request,
			$registry,
			$session,
			$reader,
			$this->createMock(PortalObjectWriter::class),
			$this->createMock(PortalFileWriter::class),
			$this->createMock(PortalFileReader::class),
			$this->createMock(PortalSchemaReader::class),
			$this->createMock(PortalInboxReader::class),
			$this->createMock(PortalAuditHook::class),
			new PortalActionForwarder($request, $clientService, $urls, $session),
			($auditor ?? $this->createMock(AuditTrailService::class)),
			$this->createMock(SubmissionReceiptService::class),
			$this->createMock(NotificationDispatchService::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()
}//end class
