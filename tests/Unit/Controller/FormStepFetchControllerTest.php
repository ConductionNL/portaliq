<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\FormStepFetchController;
use OCA\Portaliq\Service\Intake\FormSignInGate;
use OCA\Portaliq\Service\Intake\FormStepFetches;
use OCA\Portaliq\Service\Intake\PortalApplicantPrefill;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\Intake\PortalFormFetch;
use OCA\Portaliq\Service\Intake\SubmissionDecisions;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Tests\Unit\Service\Intake\Fakes\ServiceFetchEventFake;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * data-lookups-and-checks-in-forms REQ-DIF-005: the fetch route of a form
 * step asks the same portal, binding and sign-in level as the form, and runs
 * the step's fetch through integriq on the server.
 *
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t05
 */
#[CoversClass(FormStepFetchController::class)]
#[UsesClass(FormSignInGate::class)]
#[UsesClass(FormStepFetches::class)]
#[UsesClass(PortalFormFetch::class)]
#[UsesClass(SubmissionDecisions::class)]
class FormStepFetchControllerTest extends TestCase {

	/**
	 * A form whose second step asks the Kadaster who owns the building.
	 *
	 * @var array<string, mixed>
	 */
	private const RENDER = [
		'kind' => 'form',
		'fields' => [['name' => 'postcode', 'type' => 'text'], ['name' => 'eigenaar', 'type' => 'text']],
		'settings' => [],
		'steps' => [
			['id' => 'adres', 'title' => 'Adres', 'fields' => ['postcode']],
			[
				'id' => 'pand',
				'title' => 'Pand',
				'fields' => ['eigenaar'],
				'fetch' => [
					['id' => 'eigenaar', 'source' => 'kadaster-eigendom', 'inputs' => ['postcode' => 'postcode'], 'outputs' => ['eigenaarNaam' => 'eigenaar']],
				],
			],
		],
	];

	/**
	 * The controller with a portal, a binding, a sign-in level and a fetch
	 * service that answers.
	 *
	 * @param array<string, mixed>|null $site     The portal, or null.
	 * @param array<string, mixed>|null $binding  The binding, or null.
	 * @param string|null               $minTrust The sign-in level the form asks, or null.
	 *
	 * @return FormStepFetchController
	 */
	private function controller(
		?array $site = ['slug' => 'gemeente-x'],
		?array $binding = ['portal' => 'gemeente-x', 'route' => 'pand'],
		?string $minTrust = null,
	): FormStepFetchController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getHeader')->willReturn('');

		$portals = $this->double(class: PortalResolver::class, methods: ['resolve']);
		$portals->method('resolve')->willReturn($site);

		$session = $this->double(class: PortalSessionService::class, methods: ['resolveFromBearer']);
		$session->method('resolveFromBearer')->willReturn(null);

		$bindings = $this->double(class: PortalFormBindingResolver::class, methods: ['bindingFor', 'render', 'requiredTrust']);
		$bindings->method('bindingFor')->willReturn($binding);
		$bindings->method('render')->willReturn(self::RENDER);
		$bindings->method('requiredTrust')->willReturn($minTrust);

		$prefill = $this->double(class: PortalApplicantPrefill::class, methods: ['yiviRequest']);
		$prefill->method('yiviRequest')->willReturn([]);

		$events = $this->createMock(originalClassName: IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			static function (object $event): void {
				$event->setOutputs(
					outputs: ['eigenaarNaam' => 'S. de Vries', 'isEigenaar' => true],
					fetchedAt: '2026-11-17T12:00:00Z'
				);
			}
		);
		$fetches = new PortalFormFetch(
			events: $events,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			fetchEvent: ServiceFetchEventFake::class
		);

		return new FormStepFetchController(
			request: $request,
			portals: $portals,
			session: $session,
			bindings: $bindings,
			signInGate: new FormSignInGate(bindings: $bindings, prefill: $prefill),
			stepFetches: new FormStepFetches(fetches: $fetches)
		);
	}//end controller()

	/**
	 * A mock of $class with only $methods replaced.
	 *
	 * @param string             $class   The class to double.
	 * @param array<int, string> $methods The methods to replace.
	 *
	 * @return mixed
	 */
	private function double(string $class, array $methods): mixed {
		return $this->getMockBuilder(className: $class)
			->disableOriginalConstructor()
			->onlyMethods($methods)
			->getMock();
	}//end double()

	/**
	 * The route runs the step's fetch and answers the fields it fills; a
	 * step without that fetch is a 404.
	 *
	 * @return void
	 */
	public function testTheFetchRouteAnswersTheFilledFields(): void {
		$controller = $this->controller();

		$fetched = $controller->fetch(route: 'pand', step: 'pand', fetch: 'eigenaar', answers: ['postcode' => '3311AB']);
		$this->assertSame(expected: Http::STATUS_OK, actual: $fetched->getStatus());
		$this->assertSame(
			expected: ['fetch' => 'eigenaar', 'values' => ['eigenaar' => 'S. de Vries'], 'check' => null, 'fetchedAt' => '2026-11-17T12:00:00Z'],
			actual: $fetched->getData()
		);

		$unknown = $controller->fetch(route: 'pand', step: 'adres', fetch: 'eigenaar');
		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $unknown->getStatus());
	}//end testTheFetchRouteAnswersTheFilledFields()

	/**
	 * No portal or no form is a 404; a form that asks a sign-in refuses a
	 * visitor without one, before anything is fetched.
	 *
	 * @return void
	 */
	public function testTheFetchRouteRefusesWhatTheFormRefuses(): void {
		$noPortal = $this->controller(site: null)->fetch(route: 'pand', step: 'pand', fetch: 'eigenaar', portal: 'elders');
		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $noPortal->getStatus());

		$noForm = $this->controller(binding: null)->fetch(route: 'x', step: 'pand', fetch: 'eigenaar');
		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $noForm->getStatus());

		$refused = $this->controller(minTrust: 'substantial')->fetch(route: 'pand', step: 'pand', fetch: 'eigenaar');
		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $refused->getStatus());
		$this->assertSame(expected: 'sign_in_required', actual: $refused->getData()['error']);
	}//end testTheFetchRouteRefusesWhatTheFormRefuses()
}//end class
