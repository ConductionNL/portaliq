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
	 *
	 * @return PortalIntakeController
	 */
	private function controller(array $render, ?array $binding = ['portal' => 'gemeente-x']): PortalIntakeController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('');

		$portals = $this->double(PortalResolver::class, ['resolve']);
		$portals->method('resolve')->willReturn(['slug' => 'gemeente-x', 'organisation' => 'gemeente-x']);

		$session = $this->double(PortalSessionService::class, ['resolveFromBearer']);
		$session->method('resolveFromBearer')->willReturn(null);

		$bindings = $this->double(PortalFormBindingResolver::class, ['bindingFor', 'render']);
		$bindings->method('bindingFor')->willReturn($binding);
		$bindings->method('render')->willReturn($render);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$this->doubles = [
			'queue' => $this->double(PortalIntakeQueue::class, ['accept', 'status']),
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
			$this->doubles['catalogue']
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
