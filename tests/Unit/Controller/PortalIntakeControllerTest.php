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
	 * resident-identity-in-forms REQ-RIF-002: a form that asks for a verified
	 * address refuses a submit whose address has no proof, and a proof of
	 * another address, and records the address that has one.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
	 */
	public function testASubmitWithoutTheProofOfAVerifiedAddressIsRefused(): void {
		$render = $this->hostedForm();
		$render['fields'][] = ['name' => 'mail', 'type' => 'email', 'verify' => true, 'required' => true];
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$crypto = $this->createMock(\OCP\Security\ICrypto::class);
		$crypto->method('calculateHMAC')->willReturnCallback(static fn (string $text): string => hash('sha256', 'k'.$text));
		$verification = new \OCA\Portaliq\Service\Intake\PortalEmailVerification(
			$this->createMock(\OCP\ICacheFactory::class),
			$this->createMock(\OCP\Security\ISecureRandom::class),
			$crypto,
			$this->createMock(\OCA\Portaliq\Service\Intake\FormEmailCodeMailer::class),
			$l10n
		);
		$controller = $this->controller(render: $render, pay: ['verification' => $verification]);
		$this->doubles['queue']->expects($this->once())->method('accept')
			->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->callback(
				static fn (array $record): bool => $record[0]['address'] === 'sanne@example.nl'
			))
			->willReturn(['reference' => 'AANVRAAG-ABC123', 'state' => 'queued']);

		$answers = ['postcode' => '1234 AB', 'mail' => 'Sanne@Example.nl'];
		$none = $controller->submit(route: 'aanvragen/verhuizing', answers: $answers);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $none->getStatus());
		$this->assertArrayHasKey('mail', $none->getData()['errors']);

		$foreign = $controller->submit(route: 'aanvragen/verhuizing', answers: $answers, verifiedEmails: ['sanne@example.nl' => '9999999999.bogus']);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $foreign->getStatus());

		$proof = $this->proofFor(verification: $verification, email: 'sanne@example.nl');
		$ok = $controller->submit(route: 'aanvragen/verhuizing', answers: $answers, verifiedEmails: ['sanne@example.nl' => $proof]);
		$this->assertSame(Http::STATUS_OK, $ok->getStatus());
	}//end testASubmitWithoutTheProofOfAVerifiedAddressIsRefused()

	/**
	 * A proof, minted the way the check route mints one.
	 *
	 * @param \OCA\Portaliq\Service\Intake\PortalEmailVerification $verification The service.
	 * @param string $email The address.
	 *
	 * @return string
	 */
	private function proofFor(\OCA\Portaliq\Service\Intake\PortalEmailVerification $verification, string $email): string {
		$method = new \ReflectionMethod($verification, 'proofFor');
		return $method->invoke($verification, 'gemeente-x', 'aanvragen/verhuizing', $email, time());
	}//end proofFor()

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
		?\OCA\Portaliq\Service\Intake\PortalFormCalculator $calculator = null,
		?\OCA\Portaliq\Service\Intake\PortalFormDecision $decision = null,
		array $pay = [],
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
			'queue' => $this->double(PortalIntakeQueue::class, ['accept', 'status', 'markConfirmationMail', 'find', 'markPaymentIntent']),
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
			$mailer,
			$calculator,
			$decision,
			$pay['fees'] ?? null,
			$pay['intents'] ?? null,
			$pay['registry'] ?? null,
			$pay['forwarder'] ?? null,
			null,
			$pay['verification'] ?? null
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
	 * A form with a start date, a calculated end date, a decided kind of permit
	 * and a step that decides.
	 *
	 * @return array<string, mixed>
	 */
	private function flowForm(): array {
		return [
			'kind' => 'hosted',
			'resolvesToNoForm' => false,
			'fields' => [
				['name' => 'startdatum', 'type' => 'date', 'required' => true],
				['name' => 'einddatum', 'type' => 'date', 'required' => true, 'calculate' => ['op' => 'addDays', 'args' => ['startdatum', 365]]],
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
	 * @return \OCA\Portaliq\Service\Intake\PortalFormDecision
	 */
	private function decisionAnswering(?string $outcome): \OCA\Portaliq\Service\Intake\PortalFormDecision {
		$decision = $this->createMock(\OCA\Portaliq\Service\Intake\PortalFormDecision::class);
		$decision->method('decide')->willReturn(
			$outcome === null
				? ['status' => 'unavailable', 'outcome' => '', 'output' => 'soortVergunning', 'nextStep' => '']
				: ['status' => 'decided', 'outcome' => $outcome, 'output' => 'soortVergunning', 'nextStep' => 'start']
		);

		return $decision;
	}//end decisionAnswering()

	/**
	 * REQ-FFL-002: a value the browser sent for a calculated field is replaced
	 * by the server's own result before anything is recorded.
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t03
	 */
	public function testATamperedCalculatedValueIsReplacedBeforeItIsStored(): void {
		$controller = $this->controller(
			render: $this->flowForm(),
			calculator: new \OCA\Portaliq\Service\Intake\PortalFormCalculator(),
			decision: $this->decisionAnswering('bedrijf')
		);
		$stored = [];
		$this->doubles['queue']->method('accept')->willReturnCallback(
			function (string $portal, string $route, array $answers, string $subjectRef = '', string $origin = '', array $statements = [], array $computed = [], array $decisions = []) use (&$stored): array {
				$stored = compact('answers', 'computed', 'decisions');
				return ['reference' => 'AANVRAAG-ABC123', 'state' => 'queued'];
			}
		);

		$controller->submit(route: 'parkeren', answers: ['startdatum' => '2026-11-01', 'einddatum' => '2030-01-01', 'soortVergunning' => 'gehackt']);

		$this->assertSame('2027-11-01', $stored['answers']['einddatum']);
		$this->assertSame('bedrijf', $stored['answers']['soortVergunning']);
		$this->assertSame(['einddatum', 'soortVergunning'], $stored['computed']);
		$this->assertSame(['route' => 'bedrijf'], $stored['decisions']);

	}//end testATamperedCalculatedValueIsReplacedBeforeItIsStored()

	/**
	 * REQ-FFL-003: a rule engine that does not answer on submit records nothing.
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t05
	 */
	public function testASubmissionWhoseDecisionCannotBeAskedIsNotRecorded(): void {
		$controller = $this->controller(
			render: $this->flowForm(),
			calculator: new \OCA\Portaliq\Service\Intake\PortalFormCalculator(),
			decision: $this->decisionAnswering(null)
		);
		$this->doubles['queue']->expects($this->never())->method('accept');

		$response = $controller->submit(route: 'parkeren', answers: ['startdatum' => '2026-11-01']);

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame(['error' => 'decision_unavailable'], $response->getData());

	}//end testASubmissionWhoseDecisionCannotBeAskedIsNotRecorded()

	/**
	 * REQ-FFL-003: the decide route answers outcome, field and next step, and
	 * the form the browser gets carries no rule.
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t05
	 */
	public function testTheDecideRouteAnswersTheOutcomeAndTheRuleStaysOnTheServer(): void {
		$controller = $this->controller(
			render: $this->flowForm(),
			calculator: new \OCA\Portaliq\Service\Intake\PortalFormCalculator(),
			decision: $this->decisionAnswering('bedrijf')
		);

		$decided = $controller->decide(route: 'parkeren', step: 'route', answers: ['adres' => ['plaats' => 'Zuiddrecht']]);
		$this->assertSame(['outcome' => 'bedrijf', 'output' => 'soortVergunning', 'nextStep' => 'start'], $decided->getData());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->decide(route: 'parkeren', step: 'start', answers: [])->getStatus(), 'a step without a decision decides nothing');
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->decide(route: 'parkeren', step: 'nope', answers: [])->getStatus());

		$this->doubles['prefill']->method('forSubject')->willReturn([]);
		$form = json_encode($controller->form(route: 'parkeren')->getData());
		$this->assertStringNotContainsString('parkeren-soort-vergunning', $form);
		$this->assertStringNotContainsString('woonplaats', $form);
		$this->assertStringContainsString('"decides":true', $form);

	}//end testTheDecideRouteAnswersTheOutcomeAndTheRuleStaysOnTheServer()

	/**
	 * REQ-FFL-003: an engine that is down is a 503 the form can retry, with no answer lost.
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t05
	 */
	public function testTheDecideRouteIsUnavailableWhenTheEngineIsDown(): void {
		$controller = $this->controller(render: $this->flowForm(), decision: $this->decisionAnswering(null));

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $controller->decide(route: 'parkeren', step: 'route', answers: [])->getStatus());

	}//end testTheDecideRouteIsUnavailableWhenTheEngineIsDown()
	/**
	 * The collaborators of the pay route over doubles.
	 *
	 * @param array<string, mixed>|null $submission The stored submission, or null.
	 * @param array<string, mixed>|null $fee        The fee the binding's case type declares.
	 * @param int                       $status     The case app's answer status.
	 * @param array<string, mixed>      $answer     The case app's answer body.
	 * @param string|null               $intent     The payment intent status, or null.
	 * @param bool                      $hasAction  Whether the pay action is in the subject's manifest.
	 *
	 * @return array<string, mixed>
	 */
	private function payParts(?array $submission, ?array $fee, int $status=200, array $answer=[], ?string $intent=null, bool $hasAction=true): array {
		$fees = $this->getMockBuilder(\OCA\Portaliq\Service\Intake\PortalFee::class)->disableOriginalConstructor()->onlyMethods(['forBinding'])->getMock();
		$fees->method('forBinding')->willReturn($fee);

		$intents = $this->double(\OCA\Portaliq\Service\Intake\PortalPaymentIntents::class, ['status']);
		$intents->method('status')->willReturn($intent);

		$registry = $this->double(\OCA\Portaliq\Contribution\PortalContributionRegistry::class, ['aggregateFor']);
		$registry->method('aggregateFor')->willReturn(['contributions' => $hasAction ? [['app' => 'dossiq', 'actions' => [['id' => 'create-payment', 'endpoint' => '/apps/dossiq/api/pay']]]] : []]);

		$response = $this->createMock(\OCP\Http\Client\IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$forwarder = $this->double(\OCA\Portaliq\Service\PortalActionForwarder::class, ['forward', 'decodeBody', 'isForwardable']);
		$forwarder->method('isForwardable')->willReturn(true);
		$forwarder->method('forward')->willReturn($response);
		$forwarder->method('decodeBody')->willReturn($answer);

		return ['fees' => $fees, 'intents' => $intents, 'registry' => $registry, 'forwarder' => $forwarder, 'submission' => $submission];
	}//end payParts()

	/**
	 * @return array<string, mixed>
	 */
	private function ownSubmission(): array {
		return ['reference' => 'AANVRAAG-1', 'route' => 'parkeren', 'subjectRef' => 'sub-1', 'portal' => 'gemeente-x'];
	}//end ownSubmission()

	/**
	 * @return array<string, mixed>
	 */
	private function declaredFee(): array {
		return ['amount' => '45.00', 'currency' => 'EUR', 'description' => 'Parkeervergunning', 'payAction' => 'create-payment'];
	}//end declaredFee()

	/**
	 * The pay route over a signed-in subject and the given parts.
	 *
	 * @param array<string, mixed> $parts The parts from payParts().
	 * @param array<string, mixed> $site  The portal.
	 *
	 * @return PortalIntakeController
	 */
	private function payController(array $parts, ?array $subject = ['subjectRef' => 'sub-1'], array $site = ['slug' => 'gemeente-x', 'paymentHosts' => ['www.mollie.com']]): PortalIntakeController {
		$controller = $this->controller(render: [], subject: $subject, site: $site, pay: $parts);
		$this->doubles['queue']->method('find')->willReturn($parts['submission']);

		return $controller;
	}//end payController()

	/**
	 * intake-pay-on-submit T04: the case app is asked for exactly the declared
	 * amount; the browser has no say in it.
	 *
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t04
	 */
	public function testPayForwardsTheDeclaredAmount(): void {
		$parts = $this->payParts($this->ownSubmission(), $this->declaredFee(), answer: ['checkoutUrl' => 'https://www.mollie.com/checkout/abc', 'paymentIntentId' => 'pi-1']);
		$sent = null;
		$parts['forwarder']->expects($this->once())->method('forward')->willReturnCallback(
			function (array $action, array $subject, ?array $whitelisted = null, string $scopeValue = '') use (&$sent) {
				$sent = $whitelisted;
				$response = $this->createMock(\OCP\Http\Client\IResponse::class);
				$response->method('getStatusCode')->willReturn(200);
				return $response;
			}
		);
		$controller = $this->payController($parts);
		$this->doubles['queue']->expects($this->once())->method('markPaymentIntent')->with($this->ownSubmission(), 'pi-1');

		$response = $controller->pay('AANVRAAG-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['checkoutUrl' => 'https://www.mollie.com/checkout/abc'], $response->getData());
		$this->assertSame('45.00', $sent['amount']);
		$this->assertSame('EUR', $sent['currency']);
		$this->assertSame('AANVRAAG-1', $sent['reference']);
		$this->assertStringContainsString('reference=AANVRAAG-1', $sent['returnUrl']);
	}//end testPayForwardsTheDeclaredAmount()

	/**
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t04
	 */
	public function testPayNeedsASession(): void {
		$parts = $this->payParts($this->ownSubmission(), $this->declaredFee());
		$parts['forwarder']->expects($this->never())->method('forward');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->payController($parts, subject: null)->pay('AANVRAAG-1')->getStatus());
	}//end testPayNeedsASession()

	/**
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t04
	 */
	public function testForeignReferenceIs404(): void {
		$parts = $this->payParts(['reference' => 'AANVRAAG-1', 'route' => 'parkeren', 'subjectRef' => 'someone-else'], $this->declaredFee());
		$parts['forwarder']->expects($this->never())->method('forward');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->payController($parts)->pay('AANVRAAG-1')->getStatus());

		$none = $this->payParts(null, $this->declaredFee());
		$none['forwarder']->expects($this->never())->method('forward');
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->payController($none)->pay('AANVRAAG-9')->getStatus());
	}//end testForeignReferenceIs404()

	/**
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t04
	 */
	public function testPaidSubmissionIs409(): void {
		$submission = $this->ownSubmission() + ['paymentIntentId' => 'pi-1'];
		$parts = $this->payParts($submission, $this->declaredFee(), intent: 'paid');
		$parts['forwarder']->expects($this->never())->method('forward');

		$this->assertSame(Http::STATUS_CONFLICT, $this->payController($parts)->pay('AANVRAAG-1')->getStatus());
	}//end testPaidSubmissionIs409()

	/**
	 * A case type without a fee has nothing to pay.
	 *
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t04
	 */
	public function testNoFeeIs409(): void {
		$parts = $this->payParts($this->ownSubmission(), null);
		$parts['forwarder']->expects($this->never())->method('forward');

		$this->assertSame(Http::STATUS_CONFLICT, $this->payController($parts)->pay('AANVRAAG-1')->getStatus());
	}//end testNoFeeIs409()

	/**
	 * A pay action missing from the subject's own manifest is not forwarded.
	 *
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t04
	 */
	public function testAPayActionOutsideTheManifestIs403(): void {
		$parts = $this->payParts($this->ownSubmission(), $this->declaredFee(), hasAction: false);
		$parts['forwarder']->expects($this->never())->method('forward');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->payController($parts)->pay('AANVRAAG-1')->getStatus());
	}//end testAPayActionOutsideTheManifestIs403()

	/**
	 * A checkout on a host the portal does not name is never handed on.
	 *
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t04
	 */
	public function testUndeclaredCheckoutHostIsRefused(): void {
		foreach (['https://pay.example.org/checkout', 'http://www.mollie.com/checkout', 'https://www.mollie.com.evil.example/x', 'javascript:alert(1)'] as $checkout) {
			$parts = $this->payParts($this->ownSubmission(), $this->declaredFee(), answer: ['checkoutUrl' => $checkout, 'paymentIntentId' => 'pi-1']);
			$this->doubles = [];
			$controller = $this->payController($parts);
			$this->doubles['queue']->expects($this->never())->method('markPaymentIntent');

			$response = $controller->pay('AANVRAAG-1');

			$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus(), $checkout);
			$this->assertSame('payment_unavailable', $response->getData()['error']);
		}
	}//end testUndeclaredCheckoutHostIsRefused()

	/**
	 * A case app that answers with an error gives the resident the same refusal.
	 *
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t04
	 */
	public function testACaseAppFailureIs502(): void {
		$parts = $this->payParts($this->ownSubmission(), $this->declaredFee(), status: 500);

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $this->payController($parts)->pay('AANVRAAG-1')->getStatus());
	}//end testACaseAppFailureIs502()

	/**
	 * intake-pay-on-submit T05: the status carries the state the payment
	 * record says, and an address that claims otherwise changes nothing.
	 *
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t05
	 */
	public function testStatusReadsPaymentFromTheIntent(): void {
		$expected = ['paid' => 'paid', 'authorized' => 'paid', 'pending' => 'unpaid', 'open' => 'unpaid', 'failed' => 'failed', 'canceled' => 'failed', 'expired' => 'failed', 'refunded' => 'unknown'];
		foreach ($expected as $status => $state) {
			$this->doubles = [];
			$submission = $this->ownSubmission() + ['paymentIntentId' => 'pi-1'];
			$fees = new \OCA\Portaliq\Service\Intake\PortalFee($this->createMock(\OCA\Portaliq\Service\CaseTypeReader::class));
			$parts = $this->payParts($submission, null, intent: $status);
			$parts['fees'] = $fees;
			$controller = $this->payController($parts);
			$this->doubles['queue']->method('status')->willReturn(['reference' => 'AANVRAAG-1', 'state' => 'registered']);

			$this->assertSame(['state' => $state], $controller->status('AANVRAAG-1')->getData()['payment'], $status);
		}
	}//end testStatusReadsPaymentFromTheIntent()

	/**
	 * T05: a submission with no payment started says nothing about one, and a
	 * query-string status is not read at all.
	 *
	 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t05
	 */
	public function testQueryStringDoesNotSetPaymentState(): void {
		$fees = new \OCA\Portaliq\Service\Intake\PortalFee($this->createMock(\OCA\Portaliq\Service\CaseTypeReader::class));
		$parts = $this->payParts($this->ownSubmission(), null, intent: 'paid');
		$parts['fees'] = $fees;
		$controller = $this->payController($parts);
		$this->doubles['queue']->method('status')->willReturn(['reference' => 'AANVRAAG-1', 'state' => 'queued']);

		$this->assertArrayNotHasKey('payment', $controller->status('AANVRAAG-1')->getData(), 'no intent stored, no payment state');
		$source = (string)file_get_contents(__DIR__ . '/../../../lib/Controller/PortalIntakeController.php');
		$this->assertDoesNotMatchRegularExpression('/getParam\(\s*.status/', $source, 'the controller never reads a status from the address');
	}//end testQueryStringDoesNotSetPaymentState()

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
