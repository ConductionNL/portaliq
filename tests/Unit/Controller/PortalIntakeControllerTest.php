<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalIntakeController;
use OCA\Portaliq\Service\Identity\PortalChallengeService;
use OCA\Portaliq\Service\Intake\PortalApplicantPrefill;
use OCA\Portaliq\Service\Intake\PortalCatalogueReader;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\Intake\PortalFormValidator;
use OCA\Portaliq\Service\Intake\PortalIntakePayment;
use OCA\Portaliq\Service\Intake\PortalIntakeQueue;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * portal-intake-form-as-an-object, the order of the intake: an invalid
 * submission is refused before anything is recorded and long before the case
 * app is called, an external binding accepts nothing, and the reference page
 * answers the submission's real state.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 */
class PortalIntakeControllerTest extends TestCase {

	/**
	 * The doubles the controller under test is built from.
	 *
	 * @var array<string, mixed>
	 */
	private array $doubles = [];

	public function testAnInvalidSubmissionIsRefusedBeforeAnythingIsRecorded(): void {
		$controller = $this->controller(render: $this->hostedForm());
		$this->doubles['queue']->expects($this->never())->method('accept');

		$response = $controller->submit(route: 'aanvragen/verhuizing', answers: ['toelichting' => 'Ik verhuis.']);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertArrayHasKey('postcode', $response->getData()['errors']);

	}//end testAnInvalidSubmissionIsRefusedBeforeAnythingIsRecorded()

	public function testAValidSubmissionIsAcknowledgedWithTheFormsConfirmation(): void {
		$controller = $this->controller(render: $this->hostedForm());
		$this->doubles['queue']->method('accept')->willReturn(['reference' => 'AANVRAAG-ABC123', 'state' => 'queued']);

		$data = $controller->submit(route: 'aanvragen/verhuizing', answers: ['postcode' => '1234 AB'])->getData();

		$this->assertSame('AANVRAAG-ABC123', $data['reference']);
		$this->assertSame('queued', $data['state']);
		$this->assertSame('Bedankt, u hoort van ons.', $data['confirmationText']);

	}//end testAValidSubmissionIsAcknowledgedWithTheFormsConfirmation()

	public function testAnExternalBindingAcceptsNoSubmission(): void {
		$controller = $this->controller(render: ['kind' => 'external', 'resolvesToNoForm' => false, 'destination' => 'formulieren.example.org', 'settings' => []]);
		$this->doubles['queue']->expects($this->never())->method('accept');

		$response = $controller->submit(route: 'aanvragen/verhuizing', answers: []);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testAnExternalBindingAcceptsNoSubmission()

	public function testABindingThatResolvesToNoFormAcceptsNothing(): void {
		$controller = $this->controller(render: ['kind' => 'hosted', 'resolvesToNoForm' => true, 'fields' => [], 'settings' => []]);
		$this->doubles['queue']->expects($this->never())->method('accept');

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->submit(route: 'aanvragen/verhuizing', answers: [])->getStatus());

	}//end testABindingThatResolvesToNoFormAcceptsNothing()

	public function testAnUnsolvedChallengeStopsTheSubmissionBeforeValidation(): void {
		$render = $this->hostedForm();
		$render['settings']['challenge'] = true;
		$controller = $this->controller(render: $render);
		$this->doubles['challenge']->method('accepts')->willReturn(false);
		$this->doubles['queue']->expects($this->never())->method('accept');

		$response = $controller->submit(route: 'aanvragen/verhuizing', answers: ['postcode' => '1234 AB'], nonce: 'nonce-1', solution: 'nonsense');

		$this->assertSame(['error' => 'challenge_failed'], $response->getData());

	}//end testAnUnsolvedChallengeStopsTheSubmissionBeforeValidation()

	public function testAnUnknownRouteIsNotAForm(): void {
		$controller = $this->controller(render: $this->hostedForm(), binding: null);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->form(route: 'aanvragen/bestaat-niet')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->submit(route: 'aanvragen/bestaat-niet')->getStatus());

	}//end testAnUnknownRouteIsNotAForm()

	public function testTheRenderCarriesThePrefillAndTheFormsFields(): void {
		$controller = $this->controller(render: $this->hostedForm());
		$this->doubles['prefill']->method('forSubject')->willReturn(['applicantName' => 'Ans de Vries']);

		$data = $controller->form(route: 'aanvragen/verhuizing')->getData();

		$this->assertSame(['postcode'], array_column($data['fields'], 'name'));
		$this->assertSame(['applicantName' => 'Ans de Vries'], $data['prefill']);

	}//end testTheRenderCarriesThePrefillAndTheFormsFields()

	public function testTheReferencePageAnswersTheRealState(): void {
		$controller = $this->controller(render: $this->hostedForm());
		$this->doubles['queue']->method('status')->willReturn(['reference' => 'AANVRAAG-ABC123', 'state' => 'failed', 'caseId' => '', 'failureReason' => 'The case app refused the create.', 'submittedAt' => '']);

		$data = $controller->status(reference: 'AANVRAAG-ABC123')->getData();

		$this->assertSame('failed', $data['state']);
		$this->assertSame('', $data['caseId']);

	}//end testTheReferencePageAnswersTheRealState()

	public function testAnUnknownReferenceIsNotFound(): void {
		$controller = $this->controller(render: $this->hostedForm());
		$this->doubles['queue']->method('status')->willReturn(null);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->status(reference: 'AANVRAAG-NOPE')->getStatus());

	}//end testAnUnknownReferenceIsNotFound()

	/**
	 * intake-pay-on-submit REQ-IPS-003: paying needs a session, and nothing
	 * else is asked before it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-pay-on-submit/specs/portal-intake-payment/spec.md#requirement-only-the-submitter-can-pay-once-req-ips-003
	 */
	public function testPayNeedsASession(): void {
		$controller = $this->controller(render: $this->hostedForm());
		$this->doubles['payments']->expects($this->never())->method('pay');

		$response = $controller->pay(reference: 'AANVRAAG-ABC123');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'sign_in_required'], $response->getData());

	}//end testPayNeedsASession()

	/**
	 * The route answers what the payment service decided, for the portal and
	 * the resident the server resolved.
	 *
	 * @return void
	 */
	public function testPayAnswersWhatThePaymentDecided(): void {
		$subject = ['subjectRef' => 'bsn-1', 'trust' => 'substantial'];
		$controller = $this->controller(render: $this->hostedForm(), subject: $subject);
		$this->doubles['payments']->expects($this->once())->method('pay')
			->with(['slug' => 'gemeente-x', 'organisation' => 'gemeente-x'], $subject, 'AANVRAAG-ABC123')
			->willReturn(['status' => Http::STATUS_BAD_GATEWAY, 'body' => ['error' => 'payment_unavailable']]);

		$response = $controller->pay(reference: 'AANVRAAG-ABC123');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame(['error' => 'payment_unavailable'], $response->getData());

	}//end testPayAnswersWhatThePaymentDecided()

	/**
	 * REQ-IPS-005: the reference page reads the payment state from integriq's
	 * record behind the submission.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-pay-on-submit/specs/portal-intake-payment/spec.md#requirement-the-result-is-read-from-the-payment-record-req-ips-005
	 */
	public function testStatusReadsPaymentFromTheIntent(): void {
		$controller = $this->controller(render: $this->hostedForm());
		$this->doubles['queue']->method('status')->willReturn(['reference' => 'AANVRAAG-ABC123', 'state' => 'registered', 'caseId' => 'zaak-1', 'failureReason' => '', 'submittedAt' => '']);
		$this->doubles['queue']->method('paymentIntentOf')->willReturn('intent-1');
		$this->doubles['payments']->expects($this->once())->method('stateOf')->with('intent-1')->willReturn(PortalIntakePayment::STATE_PAID);

		$data = $controller->status(reference: 'AANVRAAG-ABC123')->getData();

		$this->assertSame(['state' => 'paid'], $data['payment']);
		$this->assertSame('registered', $data['state']);

	}//end testStatusReadsPaymentFromTheIntent()

	/**
	 * REQ-IPS-005: a return address with `?status=paid` shows what the record
	 * says, and a request nobody started paying carries no payment at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-pay-on-submit/specs/portal-intake-payment/spec.md#requirement-the-result-is-read-from-the-payment-record-req-ips-005
	 */
	public function testQueryStringDoesNotSetPaymentState(): void {
		$controller = $this->controller(render: $this->hostedForm());
		$this->doubles['queue']->method('status')->willReturn(['reference' => 'AANVRAAG-ABC123', 'state' => 'queued', 'caseId' => '', 'failureReason' => '', 'submittedAt' => '']);
		$this->doubles['queue']->method('paymentIntentOf')->willReturnOnConsecutiveCalls('intent-1', '');
		$this->doubles['payments']->method('stateOf')->willReturn(PortalIntakePayment::STATE_FAILED);

		$this->assertSame(['state' => 'failed'], $controller->status(reference: 'AANVRAAG-ABC123')->getData()['payment']);
		$this->assertArrayNotHasKey('payment', $controller->status(reference: 'AANVRAAG-ABC123')->getData());
		$this->assertNotContains('status', array_map(static fn (\ReflectionParameter $param): string => $param->getName(), (new \ReflectionMethod(PortalIntakeController::class, 'status'))->getParameters()));

	}//end testQueryStringDoesNotSetPaymentState()

	/**
	 * REQ-IPS-002: an anonymous visitor on a fee-bearing form is asked to
	 * sign in, and told the fee, before the first question.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-pay-on-submit/specs/portal-intake-payment/spec.md#requirement-a-fee-bearing-form-asks-the-visitor-to-sign-in-first-req-ips-002
	 */
	public function testAFeeBearingFormAsksAnAnonymousVisitorToSignIn(): void {
		$fee = ['amount' => '45.00', 'currency' => 'EUR', 'description' => 'Parkeervergunning'];
		$controller = $this->controller(render: $this->hostedForm() + ['fee' => $fee]);

		$response = $controller->form(route: 'aanvragen/parkeren');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'sign_in_required', 'minTrust' => 'substantial', 'fee' => $fee], $response->getData());

	}//end testAFeeBearingFormAsksAnAnonymousVisitorToSignIn()

	public function testTheEntryPointListsWhatTheCatalogueSaysToday(): void {
		$controller = $this->controller(render: $this->hostedForm());
		$this->doubles['catalogue']->method('topicsFor')->willReturn([['topic' => 'Wonen', 'entries' => [['title' => 'Verhuizing doorgeven', 'route' => 'aanvragen/verhuizing', 'summary' => '']]]]);

		$data = $controller->catalogue()->getData();

		$this->assertSame('Wonen', $data['topics'][0]['topic']);

	}//end testTheEntryPointListsWhatTheCatalogueSaysToday()

	/**
	 * The public site inside Nextcloud names its portal by slug, as the
	 * content API does; every intake endpoint resolves that portal rather
	 * than falling back to the host alone.
	 *
	 * @return void
	 */
	public function testTheSiteNamesItsPortalBySlugOnEveryIntakeEndpoint(): void {
		$controller = $this->controller(render: $this->hostedForm());
		$this->doubles['portals']->expects($this->exactly(4))
			->method('resolve')
			->with($this->anything(), 'gemeente-y');
		$this->doubles['catalogue']->method('topicsFor')->willReturn([]);
		$this->doubles['queue']->method('status')->willReturn(['reference' => 'AANVRAAG-1', 'state' => 'queued', 'caseId' => '', 'failureReason' => '', 'submittedAt' => '']);
		$this->doubles['queue']->method('accept')->willReturn(['reference' => 'AANVRAAG-1', 'state' => 'queued']);

		$controller->catalogue(portal: 'gemeente-y');
		$controller->form(route: 'aanvragen/verhuizing', portal: 'gemeente-y');
		$controller->submit(route: 'aanvragen/verhuizing', answers: ['postcode' => '1234 AB'], portal: 'gemeente-y');
		$controller->status(reference: 'AANVRAAG-1', portal: 'gemeente-y');

	}//end testTheSiteNamesItsPortalBySlugOnEveryIntakeEndpoint()

	/**
	 * Without a slug the portal is resolved from the host, as before.
	 *
	 * @return void
	 */
	public function testWithoutASlugThePortalComesFromTheHost(): void {
		$controller = $this->controller(render: $this->hostedForm());
		$this->doubles['portals']->expects($this->once())
			->method('resolve')
			->with($this->anything(), null);
		$this->doubles['catalogue']->method('topicsFor')->willReturn([]);

		$controller->catalogue();

	}//end testWithoutASlugThePortalComesFromTheHost()

	public function testAFormRequiringDigidIsNotRenderedToAnAnonymousVisitor(): void {
		// portaliq#725: on the portal's own page a form above `low` needs a
		// session at or above that level before it is rendered.
		$controller = $this->controller(render: $this->hostedForm() + ['minTrust' => 'substantial']);
		$this->doubles['prefill']->expects($this->never())->method('forSubject');

		$response = $controller->form(route: 'aanvragen/verhuizing');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('sign_in_required', $response->getData()['error']);
		$this->assertSame('substantial', $response->getData()['minTrust']);

	}//end testAFormRequiringDigidIsNotRenderedToAnAnonymousVisitor()

	public function testAFormRequiringDigidAcceptsNothingFromAnAnonymousVisitor(): void {
		$controller = $this->controller(render: $this->hostedForm() + ['minTrust' => 'substantial']);
		$this->doubles['queue']->expects($this->never())->method('accept');

		$response = $controller->submit(route: 'aanvragen/verhuizing', answers: ['postcode' => '1234 AB']);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAFormRequiringDigidAcceptsNothingFromAnAnonymousVisitor()

	public function testASessionBelowTheFormsLevelIsRefused(): void {
		$controller = $this->controller(
			render: $this->hostedForm() + ['minTrust' => 'high'],
			subject: ['subjectRef' => 'bsn:999993653', 'trust' => 'substantial']
		);
		$this->doubles['queue']->expects($this->never())->method('accept');

		$response = $controller->submit(route: 'aanvragen/verhuizing', answers: ['postcode' => '1234 AB']);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('sign_in_required', $response->getData()['error']);

	}//end testASessionBelowTheFormsLevelIsRefused()

	public function testASessionAtTheFormsLevelIsAccepted(): void {
		$controller = $this->controller(
			render: $this->hostedForm() + ['minTrust' => 'substantial'],
			subject: ['subjectRef' => 'bsn:999993653', 'trust' => 'substantial']
		);
		$this->doubles['queue']->expects($this->once())->method('accept')->willReturn(['reference' => 'AANVRAAG-ABC123', 'state' => 'queued']);

		$data = $controller->submit(route: 'aanvragen/verhuizing', answers: ['postcode' => '1234 AB'])->getData();

		$this->assertSame('AANVRAAG-ABC123', $data['reference']);

	}//end testASessionAtTheFormsLevelIsAccepted()

	public function testAPortalRequiringIdentifiedIntakeAcceptsNothingAnonymous(): void {
		$controller = $this->controller(
			render: $this->hostedForm(),
			site: ['slug' => 'gemeente-x', 'authentication' => ['requiresIdentifiedIntake' => true]]
		);
		$this->doubles['queue']->expects($this->never())->method('accept');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->submit(route: 'aanvragen/verhuizing', answers: ['postcode' => '1234 AB'])->getStatus());

	}//end testAPortalRequiringIdentifiedIntakeAcceptsNothingAnonymous()

	/**
	 * A hosted form requiring a postcode.
	 *
	 * @return array<string, mixed>
	 */
	private function hostedForm(): array {
		return [
			'kind' => 'hosted',
			'resolvesToNoForm' => false,
			'fields' => [['name' => 'postcode', 'required' => true]],
			'settings' => ['challenge' => false, 'confirmationText' => 'Bedankt, u hoort van ons.'],
		];
	}//end hostedForm()

	/**
	 * The controller over doubles, with a real validator so the ordering test
	 * exercises the validation the controller really runs.
	 *
	 * @param array<string, mixed> $render What the binding renders to.
	 * @param array<string, mixed>|null $binding The binding found, or null.
	 * @param array<string, mixed>|null $subject The signed-in subject, or null.
	 * @param array<string, mixed> $site The portal resolved.
	 *
	 * @return PortalIntakeController
	 */
	private function controller(
		array $render,
		?array $binding = ['portal' => 'gemeente-x'],
		?array $subject = null,
		array $site = ['slug' => 'gemeente-x', 'organisation' => 'gemeente-x'],
	): PortalIntakeController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('');

		$portals = $this->double(PortalResolver::class, ['resolve']);
		$portals->method('resolve')->willReturn($site);

		$session = $this->double(PortalSessionService::class, ['resolveFromBearer']);
		$session->method('resolveFromBearer')->willReturn($subject);

		$bindings = $this->double(PortalFormBindingResolver::class, ['bindingFor', 'render']);
		$bindings->method('bindingFor')->willReturn($binding);
		$bindings->method('render')->willReturn($render);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$this->doubles = [
			'portals' => $portals,
			'queue' => $this->double(PortalIntakeQueue::class, ['accept', 'status', 'paymentIntentOf']),
			'payments' => $this->double(PortalIntakePayment::class, ['pay', 'stateOf']),
			'prefill' => $this->double(PortalApplicantPrefill::class, ['forSubject']),
			'challenge' => $this->double(PortalChallengeService::class, ['issue', 'accepts']),
			'catalogue' => $this->double(PortalCatalogueReader::class, ['topicsFor']),
		];

		return new PortalIntakeController(
			$request,
			$portals,
			$session,
			$bindings,
			$this->doubles['prefill'],
			new PortalFormValidator($l10n),
			$this->doubles['queue'],
			$this->doubles['challenge'],
			$this->doubles['catalogue'],
			$this->doubles['payments']
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
