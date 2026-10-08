<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalIntakeController;
use OCA\Portaliq\Service\Identity\PortalChallengeService;
use OCA\Portaliq\Service\Intake\PortalApplicantPrefill;
use OCA\Portaliq\Service\Intake\PortalCatalogueReader;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\Intake\PortalFormValidator;
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
		?\OCA\Portaliq\Service\Intake\PortalAddressLookup $addresses = null,
		?\OCA\Portaliq\Service\Intake\PortalFamilyMembers $family = null,
		?\OCA\Portaliq\Service\Intake\FormStatements $statements = null,
		?\OCA\Portaliq\Service\Intake\FormConfirmationMailer $mailer = null,
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
			'queue' => $this->double(PortalIntakeQueue::class, ['accept', 'status', 'markConfirmationMail']),
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
			$addresses,
			$family,
			$statements,
			$mailer
		);
	}//end controller()

	/**
	 * data-lookups-and-checks-in-forms T01: the address route answers street
	 * and town, or the same 404 for a miss and for a lookup that is absent.
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t01
	 */
	public function testTheAddressRouteAnswersStreetAndTownOrNotFound(): void {
		$lookup = $this->double(\OCA\Portaliq\Service\Intake\PortalAddressLookup::class, ['find']);
		$lookup->method('find')->willReturnCallback(
			static fn (string $postcode, string $number): ?array => $number === '12' ? ['street' => 'Lindelaan', 'town' => 'Zuiddrecht'] : null
		);
		$controller = $this->controller(render: [], addresses: $lookup);

		$found = $controller->address('1234AB', '12');
		$this->assertSame(Http::STATUS_OK, $found->getStatus());
		$this->assertSame(['street' => 'Lindelaan', 'town' => 'Zuiddrecht'], $found->getData());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->address('1234AB', '99')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(render: [])->address('1234AB', '12')->getStatus());
	}//end testTheAddressRouteAnswersStreetAndTownOrNotFound()

	/**
	 * data-lookups-and-checks-in-forms T04: the family route needs a session,
	 * and answers 404 when nothing can be offered.
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t04
	 */
	public function testTheFamilyRouteNeedsASessionAndOffersOnlyWhatTheBrpBacks(): void {
		$family = $this->double(\OCA\Portaliq\Service\Intake\PortalFamilyMembers::class, ['forSubject']);
		$family->method('forSubject')->willReturn([['ref' => 'partner-aaaaaaaaaaaaaaaaaaaa', 'name' => 'Henk', 'relation' => 'partner', 'birthYear' => '1983']]);
		$subject = ['subjectRef' => 'sub-1'];

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(render: [], family: $family)->family()->getStatus());
		$ok = $this->controller(render: [], subject: $subject, family: $family)->family();
		$this->assertSame(Http::STATUS_OK, $ok->getStatus());
		$this->assertSame('Henk', $ok->getData()['members'][0]['name']);
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(render: [], subject: $subject)->family()->getStatus());
	}//end testTheFamilyRouteNeedsASessionAndOffersOnlyWhatTheBrpBacks()

	/**
	 * A forged family reference stops the submission before anything is recorded.
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t04
	 */
	public function testAForgedFamilyReferenceStopsTheSubmit(): void {
		$family = $this->double(\OCA\Portaliq\Service\Intake\PortalFamilyMembers::class, ['forged']);
		$family->method('forged')->willReturn(['partner-aaaaaaaaaaaaaaaaaaaa']);
		$render = ['kind' => 'hosted', 'resolvesToNoForm' => false, 'settings' => [], 'fields' => [['name' => 'mee', 'type' => 'familyMembers']]];
		$controller = $this->controller(render: $render, subject: ['subjectRef' => 'sub-1'], family: $family);
		$this->doubles['queue']->expects($this->never())->method('accept');

		$response = $controller->submit('aanvragen/verhuizing', ['mee' => ['partner-aaaaaaaaaaaaaaaaaaaa']]);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertArrayHasKey('mee', $response->getData()['errors']);
	}//end testAForgedFamilyReferenceStopsTheSubmit()

	/**
	 * A form that asks both statements, on a portal that words them.
	 *
	 * @return array<string, mixed>
	 */
	private function formWithStatements(): array {
		$form = $this->hostedForm();
		$form['settings']['statementsDeclared'] = ['truth' => ['required' => true], 'privacy' => ['required' => true]];

		return $form;
	}//end formWithStatements()

	/**
	 * @return array<string, mixed>
	 */
	private function wordedSite(): array {
		return [
			'slug' => 'gemeente-x',
			'organisation' => 'gemeente-x',
			'statementTexts' => ['truth' => ['text' => 'Mijn antwoorden kloppen.', 'version' => '2'], 'privacy' => ['text' => 'Ik ga akkoord.', 'version' => '5']],
		];
	}//end wordedSite()

	/**
	 * form-statements-intro-and-confirmation-mail T03: a required statement
	 * that is not ticked stops the submission and names the statement.
	 *
	 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t03
	 */
	public function testAMissingRequiredStatementStopsTheSubmission(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$controller = $this->controller(render: $this->formWithStatements(), site: $this->wordedSite(), statements: new \OCA\Portaliq\Service\Intake\FormStatements($l10n));
		$this->doubles['queue']->expects($this->never())->method('accept');

		$response = $controller->submit(route: 'aanvragen/verhuizing', answers: ['postcode' => '1234 AB'], statements: ['truth']);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['statement-privacy'], array_keys($response->getData()['errors']));
	}//end testAMissingRequiredStatementStopsTheSubmission()

	/**
	 * Both statements ticked: the submission records each with its text version.
	 *
	 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t03
	 */
	public function testAcceptedStatementsAreRecordedWithTheirTextVersion(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$controller = $this->controller(render: $this->formWithStatements(), site: $this->wordedSite(), statements: new \OCA\Portaliq\Service\Intake\FormStatements($l10n));
		$recorded = null;
		$this->doubles['queue']->method('accept')->willReturnCallback(
			function (string $portal, string $route, array $answers, string $subjectRef = '', string $origin = '', array $statements = []) use (&$recorded): array {
				$recorded = $statements;
				return ['reference' => 'AANVRAAG-ABC123', 'state' => 'queued'];
			}
		);

		$controller->submit(route: 'aanvragen/verhuizing', answers: ['postcode' => '1234 AB'], statements: ['truth', 'privacy', 'invented']);

		$this->assertSame(['truth', 'privacy'], array_column($recorded, 'key'));
		$this->assertSame(['2', '5'], array_column($recorded, 'textVersion'));
		$this->assertNotEmpty($recorded[0]['acceptedAt']);
	}//end testAcceptedStatementsAreRecordedWithTheirTextVersion()

	/**
	 * A required statement the portal has no wording for cannot be accepted.
	 *
	 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t03
	 */
	public function testARequiredStatementWithoutTextBlocksTheForm(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$controller = $this->controller(render: $this->formWithStatements(), statements: new \OCA\Portaliq\Service\Intake\FormStatements($l10n));
		$this->doubles['queue']->expects($this->never())->method('accept');

		$response = $controller->submit(route: 'aanvragen/verhuizing', answers: ['postcode' => '1234 AB'], statements: ['truth', 'privacy']);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertCount(2, $response->getData()['errors']);
	}//end testARequiredStatementWithoutTextBlocksTheForm()

	/**
	 * With the confirmation mail on, the mail goes to the form's e-mail answer,
	 * the page learns where, and a refusal is recorded as failed.
	 *
	 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t05
	 */
	public function testTheConfirmationMailIsSentRecordedAndNamedOnThePage(): void {
		foreach ([true, false] as $sent) {
			$render = $this->hostedForm();
			$render['fields'][] = ['name' => 'mail', 'type' => 'email'];
			$render['settings']['confirmationMail'] = true;
			$mailer = $this->double(\OCA\Portaliq\Service\Intake\FormConfirmationMailer::class, ['addressIn', 'send']);
			$mailer->method('addressIn')->willReturn('sanne@example.nl');
			$mailer->method('send')->willReturn($sent);
			$controller = $this->controller(render: $render, mailer: $mailer);
			$this->doubles['queue']->method('accept')->willReturn(['reference' => 'AANVRAAG-ABC123', 'state' => 'queued']);
			$this->doubles['queue']->expects($this->once())->method('markConfirmationMail')->with('AANVRAAG-ABC123', 'gemeente-x', $sent ? 'sent' : 'failed');

			$data = $controller->submit(route: 'aanvragen/verhuizing', answers: ['postcode' => '1234 AB', 'mail' => 'sanne@example.nl'])->getData();

			$this->assertSame($sent ? 'sanne@example.nl' : '', $data['mailedTo']);
		}
	}//end testTheConfirmationMailIsSentRecordedAndNamedOnThePage()

	/**
	 * No mail is sent when the form does not ask for one.
	 *
	 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t05
	 */
	public function testNoMailGoesWhenTheFormDoesNotAskForOne(): void {
		$mailer = $this->double(\OCA\Portaliq\Service\Intake\FormConfirmationMailer::class, ['addressIn', 'send']);
		$mailer->expects($this->never())->method('send');
		$controller = $this->controller(render: $this->hostedForm(), mailer: $mailer);
		$this->doubles['queue']->method('accept')->willReturn(['reference' => 'AANVRAAG-ABC123', 'state' => 'queued']);
		$this->doubles['queue']->expects($this->never())->method('markConfirmationMail');

		$data = $controller->submit(route: 'aanvragen/verhuizing', answers: ['postcode' => '1234 AB'])->getData();

		$this->assertSame('', $data['mailedTo']);
	}//end testNoMailGoesWhenTheFormDoesNotAskForOne()

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
