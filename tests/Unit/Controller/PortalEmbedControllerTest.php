<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalEmbedController;
use OCA\Portaliq\Service\Intake\PortalEmbedGuard;
use OCA\Portaliq\Service\Intake\PortalEmbedThrottle;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\Intake\PortalFormValidator;
use OCA\Portaliq\Service\Intake\PortalIntakeQueue;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
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
		$this->doubles['queue']->expects($this->once())
			->method('accept')
			->with(
				$this->equalTo('gemeente-x'),
				$this->equalTo('aanvragen/verhuizing'),
				$this->equalTo(['postcode' => '1234 AB']),
				$this->equalTo(''),
				$this->equalTo('https://www.gemeente.nl')
			)
			->willReturn(['reference' => 'AANVRAAG-ABC123', 'state' => 'queued']);

		$data = $controller->submit(route: 'aanvragen/verhuizing', answers: ['postcode' => '1234 AB'])->getData();

		$this->assertSame('AANVRAAG-ABC123', $data['reference']);
		$this->assertStringContainsString('AANVRAAG-ABC123', $data['followUrl']);

	}//end testASubmissionRecordsTheOriginItCameFrom()

	public function testASubmissionFromADisallowedOriginCreatesNothing(): void {
		$controller = $this->controller(origin: 'https://www.elders.nl');
		$this->doubles['queue']->expects($this->never())->method('accept');

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->submit(route: 'aanvragen/verhuizing', answers: ['postcode' => '1234 AB'])->getStatus());

	}//end testASubmissionFromADisallowedOriginCreatesNothing()

	public function testABurstFromOneOriginIsThrottledAndCreatesNothing(): void {
		$controller = $this->controller(origin: 'https://www.gemeente.nl', throttleOpen: false);
		$this->doubles['queue']->expects($this->never())->method('accept');

		$this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $controller->submit(route: 'aanvragen/verhuizing', answers: ['postcode' => '1234 AB'])->getStatus());

	}//end testABurstFromOneOriginIsThrottledAndCreatesNothing()

	public function testValidationIsTheSchemasNotTheFrames(): void {
		$controller = $this->controller(origin: 'https://www.gemeente.nl');
		$this->doubles['queue']->expects($this->never())->method('accept');

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
		$this->doubles['queue']->expects($this->never())->method('accept');

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
		$this->doubles['queue']->expects($this->never())->method('accept');

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

	public function testAnUnrecognisedSignInLevelFailsClosed(): void {
		$controller = $this->controller(origin: 'https://www.gemeente.nl', formMinTrust: 'digid');
		$this->doubles['queue']->expects($this->never())->method('accept');

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->submit(route: 'aanvragen/verhuizing', answers: ['postcode' => '1234 AB'])->getStatus());

	}//end testAnUnrecognisedSignInLevelFailsClosed()

	/**
	 * The controller over doubles, with the real guard and validator so the
	 * origin and schema decisions are the ones the controller really makes.
	 *
	 * @param string $origin The framing origin of the request.
	 * @param array<string, mixed>|null $site The portal resolved.
	 * @param bool $throttleOpen Whether the throttle lets the request through.
	 * @param mixed $formMinTrust The published form's sign-in level, or null.
	 * @param mixed $bindingMinTrust The binding's sign-in level, or null.
	 *
	 * @return PortalEmbedController
	 */
	private function controller(
		string $origin,
		?array $site = ['slug' => 'gemeente-x'],
		bool $throttleOpen = true,
		mixed $formMinTrust = null,
		mixed $bindingMinTrust = null,
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
		];
		if ($bindingMinTrust !== null) {
			$binding['minTrust'] = $bindingMinTrust;
		}

		$render = [
			'kind' => 'hosted',
			'resolvesToNoForm' => false,
			'fields' => [['name' => 'postcode', 'required' => true]],
			'settings' => ['confirmationText' => 'Bedankt.'],
		];
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

		$this->doubles = [
			'bindings' => $bindings,
			'queue' => $this->double(PortalIntakeQueue::class, ['accept']),
		];

		return new PortalEmbedController(
			$request,
			$portals,
			$bindings,
			new PortalEmbedGuard(),
			$throttle,
			new PortalFormValidator($l10n),
			$this->doubles['queue'],
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
