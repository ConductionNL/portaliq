<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalEmbedController;
use OCA\Portaliq\Service\Identity\PortalChallengeService;
use OCA\Portaliq\Service\Intake\PortalEmailVerification;
use OCA\Portaliq\Service\Intake\PortalFormCalculator;
use OCA\Portaliq\Service\Intake\PortalFormDecision;
use OCA\Portaliq\Service\Intake\PortalSubmissionChecks;
use OCA\Portaliq\Service\Intake\PortalSubmissionGate;
use OCA\Portaliq\Service\Intake\PortalEmbedGuard;
use OCA\Portaliq\Service\Intake\PortalEmbedThrottle;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\Intake\PortalFormValidator;
use OCA\Portaliq\Service\Intake\DestinationIntake;
use OCA\Portaliq\Service\Intake\DestinationMapping;
use OCA\Portaliq\Service\Intake\DestinationSubmit;
use OCA\Portaliq\Service\PortalSchemaReader;
use OCA\Portaliq\Tests\Unit\Service\Intake\Fakes\FormSubmitFake;
use Psr\Log\LoggerInterface;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\ICacheFactory;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * embedded-intake-form: the frame refuses a disallowed origin before it reads
 * the form, sets no cookie, carries no session at all, and its submissions go
 * down the ordinary anonymous intake path with the origin recorded.
 *
 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
 */
class PortalEmbedControllerTest extends TestCase {

	/**
	 * The doubles the controller under test is built from.
	 *
	 * @var array<string, mixed>
	 */
	private array $doubles = [];

	/**
	 * OpenRegister's submit service, faked: the only writer a submit reaches.
	 *
	 * @var FormSubmitFake
	 */
	private FormSubmitFake $submit;

	/**
	 * Set by a test in which nothing may reach the destination.
	 *
	 * @var bool
	 */
	private bool $forbidSubmit = false;

	/**
	 * A refused submit stores nothing: OpenRegister's submit service was never asked.
	 *
	 * @return void
	 */
	protected function assertPostConditions(): void {
		if ($this->forbidSubmit === true) {
			$this->assertSame([], $this->submit->calls, 'nothing may reach the destination');
		}
	}//end assertPostConditions()

	public function testADisallowedOriginIsRefusedBeforeTheFormIsRead(): void {
		$controller = $this->controller(origin: 'https://www.elders.nl');
		// The whole point of the ordering: nothing resolves the form, so no
		// schema is read and no contribution provider is consulted.
		$this->doubles['bindings']->expects($this->never())->method('render');

		$response = $controller->frame(route: 'aanvragen/verhuizing');

		$this->assertSame('origin_not_allowed', $response->getParams()['embed']['refused']);
		$this->assertSame([], $response->getParams()['embed']['fields']);

	}//end testADisallowedOriginIsRefusedBeforeTheFormIsRead()

	public function testAnAllowedOriginGetsTheFormAndNoPrefill(): void {
		$controller = $this->controller(origin: 'https://www.gemeente.nl');

		$params = $controller->frame(route: 'aanvragen/verhuizing')->getParams()['embed'];

		$this->assertSame(['postcode'], array_column($params['fields'], 'name'));
		// A visitor signed in to the portal in another tab is anonymous here.
		$this->assertSame([], $params['prefill']);

	}//end testAnAllowedOriginGetsTheFormAndNoPrefill()

	public function testTheFrameSetsNoCookie(): void {
		$controller = $this->controller(origin: 'https://www.gemeente.nl');

		$response = $controller->frame(route: 'aanvragen/verhuizing');

		$this->assertSame([], $response->getCookies());

	}//end testTheFrameSetsNoCookie()

	public function testTheFrameAncestorsComeFromThisFormsOwnList(): void {
		$controller = $this->controller(origin: 'https://www.gemeente.nl');

		$csp = $controller->frame(route: 'aanvragen/verhuizing')->getContentSecurityPolicy()->buildPolicy();

		$this->assertStringContainsString('frame-ancestors', $csp);
		$this->assertStringContainsString('https://www.gemeente.nl', $csp);
		$this->assertStringNotContainsString("frame-ancestors *", $csp);

	}//end testTheFrameAncestorsComeFromThisFormsOwnList()

	public function testTheControllerHasNoSessionToRead(): void {
		// REQ-EIF-005 is a property of the wiring, not of a branch: there is
		// no session service in the constructor, so there is nothing in this
		// class that could read a bearer even by mistake.
		$constructor = (new ReflectionClass(PortalEmbedController::class))->getConstructor();
		$types = [];
		foreach ($constructor->getParameters() as $parameter) {
			$types[] = (string)$parameter->getType();
		}

		$this->assertNotContains(PortalSessionService::class, $types);

	}//end testTheControllerHasNoSessionToRead()

	public function testAnIdentifiedIntakeLinksToThePortalRatherThanLoggingIn(): void {
		$controller = $this->controller(origin: 'https://www.gemeente.nl', site: [
			'slug' => 'gemeente-x',
			'authentication' => ['requiresIdentifiedIntake' => true],
		]);

		$params = $controller->frame(route: 'aanvragen/verhuizing')->getParams()['embed'];

		$this->assertSame('identified_intake', $params['refused']);
		$this->assertSame([], $params['fields']);
		$this->assertStringContainsString('https://portaal.example.org/site', $params['portalUrl']);

	}//end testAnIdentifiedIntakeLinksToThePortalRatherThanLoggingIn()

	public function testASubmissionRecordsTheOriginItCameFrom(): void {
		$controller = $this->controller(origin: 'https://www.gemeente.nl');
		$data = $controller->submit(route: 'aanvragen/verhuizing', answers: ['postcode' => '1234 AB'])->getData();

		$this->assertSame('2026-0042', $data['reference']);
		$this->assertSame('2026-11-24', $data['deadline']);
		$this->assertStringContainsString('2026-0042', $data['followUrl']);
		$call = $this->submit->calls[0];
		$this->assertSame(['postcode' => '1234 AB'], $call['payload']);
		$this->assertSame('https://www.gemeente.nl', $call['mapping']['fixed']['origin']);
		// The frame reads no session: nobody is stamped on the case.
		$this->assertArrayNotHasKey('portalSubject', $call['mapping']['fixed']);

	}//end testASubmissionRecordsTheOriginItCameFrom()

	public function testASubmissionFromADisallowedOriginCreatesNothing(): void {
		$controller = $this->controller(origin: 'https://www.elders.nl');
		$this->forbidSubmit = true;

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->submit(route: 'aanvragen/verhuizing', answers: ['postcode' => '1234 AB'])->getStatus());

	}//end testASubmissionFromADisallowedOriginCreatesNothing()

	public function testABurstFromOneOriginIsThrottledAndCreatesNothing(): void {
		$controller = $this->controller(origin: 'https://www.gemeente.nl', throttleOpen: false);
		$this->forbidSubmit = true;

		$this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $controller->submit(route: 'aanvragen/verhuizing', answers: ['postcode' => '1234 AB'])->getStatus());

	}//end testABurstFromOneOriginIsThrottledAndCreatesNothing()

	public function testValidationIsTheSchemasNotTheFrames(): void {
		$controller = $this->controller(origin: 'https://www.gemeente.nl');
		$this->forbidSubmit = true;

		$response = $controller->submit(route: 'aanvragen/verhuizing', answers: ['toelichting' => 'Ik verhuis.']);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertArrayHasKey('postcode', $response->getData()['errors']);

	}//end testValidationIsTheSchemasNotTheFrames()

	public function testADirectSubmissionToAPortalRequiringIdentifiedIntakeIsRefused(): void {
		// portaliq#725: the frame refuses on such a portal, so the submit must
		// too; otherwise a direct POST with an allowed Origin is accepted
		// anonymously and the portal-wide rule is bypassed.
		$controller = $this->controller(origin: 'https://www.gemeente.nl', site: [
			'slug' => 'gemeente-x',
			'authentication' => ['requiresIdentifiedIntake' => true],
		]);
		$this->forbidSubmit = true;

		$response = $controller->submit(route: 'aanvragen/verhuizing', answers: ['postcode' => '1234 AB']);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('identified_intake', $response->getData()['error']);

	}//end testADirectSubmissionToAPortalRequiringIdentifiedIntakeIsRefused()

	public function testAFormRequiringDigidIsNotFramedOnAnAnonymousPortal(): void {
		$controller = $this->controller(origin: 'https://www.gemeente.nl', formMinTrust: 'substantial');

		$params = $controller->frame(route: 'aanvragen/verhuizing')->getParams()['embed'];

		$this->assertSame('identified_intake', $params['refused']);
		$this->assertSame([], $params['fields']);
		$this->assertStringContainsString('https://portaal.example.org/site', $params['portalUrl']);

	}//end testAFormRequiringDigidIsNotFramedOnAnAnonymousPortal()

	public function testAFormRequiringDigidAcceptsNoSubmissionThroughTheFrame(): void {
		$controller = $this->controller(origin: 'https://www.gemeente.nl', formMinTrust: 'high');
		$this->forbidSubmit = true;

		$response = $controller->submit(route: 'aanvragen/verhuizing', answers: ['postcode' => '1234 AB']);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testAFormRequiringDigidAcceptsNoSubmissionThroughTheFrame()

	public function testABindingRequiringEherkenningIsNotFramed(): void {
		$controller = $this->controller(origin: 'https://www.gemeente.nl', bindingMinTrust: 'substantial');

		$params = $controller->frame(route: 'aanvragen/verhuizing')->getParams()['embed'];

		$this->assertSame('identified_intake', $params['refused']);

	}//end testABindingRequiringEherkenningIsNotFramed()

	public function testAnAnonymousFormNextToASignedInOneIsStillFramed(): void {
		// buildiq writes 0 as its anonymous floor (buildiq#921): not a
		// requirement, and the form stays anonymous.
		$controller = $this->controller(origin: 'https://www.gemeente.nl', formMinTrust: 0);

		$params = $controller->frame(route: 'aanvragen/verhuizing')->getParams()['embed'];

		$this->assertArrayNotHasKey('refused', $params);
		$this->assertSame(['postcode'], array_column($params['fields'], 'name'));

	}//end testAnAnonymousFormNextToASignedInOneIsStillFramed()

	public function testAFormRequiringLevelLowIsNotFramed(): void {
		// portaliq#731: a maker who set "DigiD or eHerkenning, level low" in
		// buildiq asked for a signed-in session, so the anonymous frame
		// refuses and points at the portal instead.
		$controller = $this->controller(origin: 'https://www.gemeente.nl', formMinTrust: 'low');

		$params = $controller->frame(route: 'aanvragen/verhuizing')->getParams()['embed'];

		$this->assertSame('identified_intake', $params['refused']);
		$this->assertSame([], $params['fields']);

	}//end testAFormRequiringLevelLowIsNotFramed()

	public function testAFormRequiringLevelLowAcceptsNoSubmissionThroughTheFrame(): void {
		$controller = $this->controller(origin: 'https://www.gemeente.nl', formMinTrust: 'low');
		$this->forbidSubmit = true;

		$response = $controller->submit(route: 'aanvragen/verhuizing', answers: ['postcode' => '1234 AB']);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testAFormRequiringLevelLowAcceptsNoSubmissionThroughTheFrame()

	public function testABindingRequiringLevelLowIsNotFramed(): void {
		$controller = $this->controller(origin: 'https://www.gemeente.nl', bindingMinTrust: 'low');

		$params = $controller->frame(route: 'aanvragen/verhuizing')->getParams()['embed'];

		$this->assertSame('identified_intake', $params['refused']);

	}//end testABindingRequiringLevelLowIsNotFramed()

	public function testAnUnrecognisedSignInLevelFailsClosed(): void {
		$controller = $this->controller(origin: 'https://www.gemeente.nl', formMinTrust: 'digid');
		$this->forbidSubmit = true;

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->submit(route: 'aanvragen/verhuizing', answers: ['postcode' => '1234 AB'])->getStatus());

	}//end testAnUnrecognisedSignInLevelFailsClosed()

	/**
	 * submit-creates-the-case-directly 2.2: a filled honeypot gets a quiet 202
	 * and nothing reaches the destination; it is checked before the throttle.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#2.2
	 */
	public function testAFilledHoneypotIsAcceptedQuietlyAndSendsNothing(): void {
		$controller = $this->controller(origin: 'https://www.gemeente.nl', throttleOpen: false);
		$this->forbidSubmit = true;

		$response = $controller->submit(route: 'aanvragen/verhuizing', answers: ['postcode' => '1234 AB'], _hp: 'https://spam.example');

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$this->assertArrayNotHasKey('reference', $response->getData());
	}//end testAFilledHoneypotIsAcceptedQuietlyAndSendsNothing()

	/**
	 * REQ-PIFO-004: the destination's refusal comes back on its field; an
	 * outage says try again later.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-submission-is-validated-against-its-destinations-schema-before-anything-is-stored-req-pifo-004
	 */
	public function testTheDestinationsRefusalComesBackThroughTheFrame(): void {
		$controller = $this->controller(origin: 'https://www.gemeente.nl');
		$this->submit->outcome = FormSubmitFake::refusal(status: 422, findings: [['property' => 'postcode', 'code' => 'pattern', 'message' => 'Dit is geen postcode.']]);

		$refused = $controller->submit(route: 'aanvragen/verhuizing', answers: ['postcode' => '1234 AB']);
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $refused->getStatus());
		$this->assertSame(['postcode' => 'Dit is geen postcode.'], $refused->getData()['errors']);

		$this->submit->outcome = new \RuntimeException('down');
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $controller->submit(route: 'aanvragen/verhuizing', answers: ['postcode' => '1234 AB'])->getStatus());
	}//end testTheDestinationsRefusalComesBackThroughTheFrame()

	/**
	 * pq-f1: a form that asks for the anti-bot challenge cannot be filed
	 * through the frame's submit without solving it. The Origin header is a
	 * browser control a script sets at will, so it proves nothing here.
	 *
	 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
	 */
	public function testAChallengeTheFormAsksForCannotBeSkippedThroughTheFrame(): void {
		$render = $this->plainForm();
		$render['settings']['challenge'] = true;
		$controller = $this->controller(
			origin: 'https://www.gemeente.nl',
			site: ['slug' => 'gemeente-x', 'authentication' => ['challenge' => ['proofOfWork' => ['enabled' => true]]]],
			render: $render
		);
		$this->forbidSubmit = true;

		$response = $controller->submit(route: 'aanvragen/verhuizing', answers: ['postcode' => '1234 AB']);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'challenge_failed'], $response->getData());
	}//end testAChallengeTheFormAsksForCannotBeSkippedThroughTheFrame()

	/**
	 * pq-f1: a calculated value and a rule-engine outcome the browser sent are
	 * replaced by the server's own before anything reaches the destination,
	 * exactly as on the portal's own submit (REQ-FFL-002/003).
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t03
	 */
	public function testATamperedCalculationAndDecisionAreTheServersOwnThroughTheFrame(): void {
		$controller = $this->controller(
			origin: 'https://www.gemeente.nl',
			render: $this->flowForm(),
			calculator: new PortalFormCalculator(),
			decision: $this->decisionAnswering(outcome: 'bedrijf')
		);

		$controller->submit(route: 'parkeren', answers: ['startdatum' => '2026-11-01', 'einddatum' => '2030-01-01', 'soortVergunning' => 'gehackt']);
		$sent = $this->submit->calls[0];

		$this->assertSame('2027-11-01', $sent['payload']['einddatum']);
		$this->assertSame('bedrijf', $sent['payload']['soortVergunning']);
		$this->assertSame(['route' => 'bedrijf'], $sent['mapping']['fixed']['decisions']);
	}//end testATamperedCalculationAndDecisionAreTheServersOwnThroughTheFrame()

	/**
	 * pq-f1: a rule engine that does not answer records nothing, through the
	 * frame as on the portal.
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t05
	 */
	public function testADecisionThatCannotBeAskedRecordsNothingThroughTheFrame(): void {
		$controller = $this->controller(
			origin: 'https://www.gemeente.nl',
			render: $this->flowForm(),
			calculator: new PortalFormCalculator(),
			decision: $this->decisionAnswering(outcome: null)
		);
		$this->forbidSubmit = true;

		$response = $controller->submit(route: 'parkeren', answers: ['startdatum' => '2026-11-01']);

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
	}//end testADecisionThatCannotBeAskedRecordsNothingThroughTheFrame()

	/**
	 * pq-f1: an address the form asks to verify is refused without its proof,
	 * through the frame as on the portal (REQ-RIF-002).
	 *
	 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
	 */
	public function testAnAddressToVerifyIsRefusedWithoutItsProofThroughTheFrame(): void {
		$render = $this->plainForm();
		$render['fields'][] = ['name' => 'email', 'type' => 'email', 'verify' => true];
		$verification = $this->createMock(PortalEmailVerification::class);
		$verification->expects($this->once())->method('unverified')->willReturn(['email' => 'Verify this address first.']);
		$controller = $this->controller(origin: 'https://www.gemeente.nl', render: $render, emailVerification: $verification);
		$this->forbidSubmit = true;

		$response = $controller->submit(route: 'aanvragen/verhuizing', answers: ['postcode' => '1234 AB', 'email' => 'iemand@example.org']);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertArrayHasKey('email', $response->getData()['errors']);
	}//end testAnAddressToVerifyIsRefusedWithoutItsProofThroughTheFrame()

	/**
	 * pq-f1: an external intake takes no submission here, framed or not.
	 *
	 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
	 */
	public function testAnExternalIntakeTakesNoSubmissionThroughTheFrame(): void {
		$render = $this->plainForm();
		$render['kind'] = PortalFormBindingResolver::KIND_EXTERNAL;
		$controller = $this->controller(origin: 'https://www.gemeente.nl', render: $render);
		$this->forbidSubmit = true;

		$response = $controller->submit(route: 'aanvragen/verhuizing', answers: ['postcode' => '1234 AB']);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'form_not_submittable'], $response->getData());
	}//end testAnExternalIntakeTakesNoSubmissionThroughTheFrame()

	/**
	 * The plain one-field form most tests frame.
	 *
	 * @return array<string, mixed>
	 */
	private function plainForm(): array {
		return [
			'kind' => 'hosted',
			'resolvesToNoForm' => false,
			'fields' => [['name' => 'postcode', 'required' => true]],
			'settings' => ['confirmationText' => 'Bedankt.'],
		];
	}//end plainForm()

	/**
	 * A form with a calculated field and a step that asks the rule engine.
	 *
	 * @return array<string, mixed>
	 */
	private function flowForm(): array {
		return [
			'kind' => 'hosted',
			'resolvesToNoForm' => false,
			'fields' => [
				['name' => 'startdatum', 'type' => 'date'],
				['name' => 'einddatum', 'type' => 'date', 'calculate' => ['op' => 'addDays', 'args' => ['startdatum', 365]]],
				['name' => 'soortVergunning', 'type' => 'string', 'computed' => true],
			],
			'steps' => [
				['id' => 'start', 'title' => 'Start', 'fields' => ['startdatum', 'einddatum']],
				['id' => 'route', 'title' => 'Route', 'fields' => ['soortVergunning'], 'decision' => [
					'rule' => 'parkeren-soort-vergunning', 'inputs' => ['woonplaats' => 'adres.plaats'], 'output' => 'soortVergunning',
					'nextStep' => ['bedrijf' => 'start'],
				]],
			],
			'settings' => ['challenge' => false, 'confirmationText' => ''],
		];
	}//end flowForm()

	/**
	 * A decision double: answers the outcome the test sets, or is down.
	 *
	 * @param string|null $outcome The outcome, or null for an engine that does not answer.
	 *
	 * @return PortalFormDecision
	 */
	private function decisionAnswering(?string $outcome): PortalFormDecision {
		$decision = $this->createMock(PortalFormDecision::class);
		$decision->method('decide')->willReturn(
			$outcome === null
				? ['status' => PortalFormDecision::UNAVAILABLE, 'outcome' => '', 'output' => 'soortVergunning', 'nextStep' => '']
				: ['status' => PortalFormDecision::DECIDED, 'outcome' => $outcome, 'output' => 'soortVergunning', 'nextStep' => 'start']
		);

		return $decision;
	}//end decisionAnswering()

	/**
	 * The controller over doubles, with the real guard and validator so the
	 * origin and schema decisions are the ones the controller really makes.
	 *
	 * @param string $origin The framing origin of the request.
	 * @param array<string, mixed>|null $site The portal resolved.
	 * @param bool $throttleOpen Whether the throttle lets the request through.
	 * @param mixed $formMinTrust The published form's sign-in level, or null.
	 * @param mixed $bindingMinTrust The binding's sign-in level, or null.
	 * @param array<string, mixed>|null $render The form the binding renders to; the plain form when null.
	 * @param PortalFormCalculator|null $calculator The calculator, or none.
	 * @param PortalFormDecision|null $decision The rule engine, or none.
	 * @param PortalEmailVerification|null $emailVerification The e-mail proof check, or none.
	 *
	 * @return PortalEmbedController
	 */
	private function controller(
		string $origin,
		?array $site = ['slug' => 'gemeente-x'],
		bool $throttleOpen = true,
		mixed $formMinTrust = null,
		mixed $bindingMinTrust = null,
		?array $render = null,
		?PortalFormCalculator $calculator = null,
		?PortalFormDecision $decision = null,
		?PortalEmailVerification $emailVerification = null,
	): PortalEmbedController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static function (string $name) use ($origin): string {
				return ($name === 'Origin' ? $origin : '');
			}
		);
		$request->method('getRemoteAddress')->willReturn('203.0.113.10');

		$portals = $this->double(PortalResolver::class, ['resolve']);
		$portals->method('resolve')->willReturn($site);

		$bindings = $this->double(PortalFormBindingResolver::class, ['bindingFor', 'render']);
		$binding = [
			'portal' => 'gemeente-x',
			'route' => 'aanvragen/verhuizing',
			'allowedOrigins' => ['https://www.gemeente.nl'],
			'destination' => ['register' => 'dossiq', 'schema' => 'case', 'subjectField' => 'portalSubject'],
		];
		if ($bindingMinTrust !== null) {
			$binding['minTrust'] = $bindingMinTrust;
		}

		$render = ($render ?? $this->plainForm());
		if ($formMinTrust !== null) {
			$render['minTrust'] = $formMinTrust;
		}

		$bindings->method('bindingFor')->willReturn($binding);
		$bindings->method('render')->willReturn($render);

		$throttle = $this->double(PortalEmbedThrottle::class, ['allow']);
		$throttle->method('allow')->willReturn($throttleOpen);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://portaal.example.org/site');

		$this->doubles = ['bindings' => $bindings];
		$this->submit  = new FormSubmitFake(outcome: ['reference' => '2026-0042', 'id' => 'u-1', 'receivedAt' => '2026-10-11T09:00:00+00:00', 'confirmation' => ['deadline' => '2026-11-24']]);
		$schemas       = $this->createMock(PortalSchemaReader::class);
		$schemas->method('readSchema')->willReturn(['properties' => [
			'postcode' => ['type' => 'string'], 'origin' => ['type' => 'string'], 'portalSubject' => ['type' => 'string'],
			'startdatum' => ['type' => 'string'], 'einddatum' => ['type' => 'string'], 'soortVergunning' => ['type' => 'string'],
			'email' => ['type' => 'string'], 'decisions' => ['type' => 'object'], 'fieldMeta' => ['type' => 'object'],
		]]);
		$intake = new DestinationIntake(
			submitter: new DestinationSubmit($this->submit->container(), $this->createMock(LoggerInterface::class)),
			mapping: new DestinationMapping(),
			schemas: $schemas
		);

		return new PortalEmbedController(
			$request,
			$portals,
			$bindings,
			new PortalEmbedGuard(),
			$throttle,
			new PortalSubmissionGate(
				challenge: new PortalChallengeService($this->createMock(ISecureRandom::class), $this->createMock(ICrypto::class), $this->createMock(ICacheFactory::class)),
				checks: new PortalSubmissionChecks(
					validator: new PortalFormValidator($l10n),
					calculator: $calculator,
					decision: $decision,
					emailVerification: $emailVerification
				)
			),
			$intake,
			$urls
		);
	}//end controller()

	/**
	 * A double of one class, limited to the methods it really has.
	 *
	 * @param string $class The class to double.
	 * @param array<int, string> $methods The methods to stub.
	 *
	 * @return mixed
	 */
	private function double(string $class, array $methods): mixed {
		return $this->getMockBuilder($class)
			->disableOriginalConstructor()
			->onlyMethods($methods)
			->getMock();
	}//end double()

}//end class
