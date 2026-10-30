<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalStaffFillController;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\Identity\PortalAccountLookup;
use OCA\Portaliq\Service\Identity\PortalRegisteredDetailsService;
use OCA\Portaliq\Service\Intake\DutchFormats;
use OCA\Portaliq\Service\Intake\DestinationIntake;
use OCA\Portaliq\Service\Intake\DestinationMapping;
use OCA\Portaliq\Service\Intake\DestinationSubmit;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\Intake\PortalFormValidator;
use OCA\Portaliq\Service\Intake\StaffFill;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSchemaReader;
use OCA\Portaliq\Tests\Unit\Service\Intake\Fakes\FormSubmitFake;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * resident-identity-in-forms REQ-RIF-005: staff mode is checked before
 * anything is read or sent, the applicant is checked, and the delivered
 * request names the applicant and the employee who filled it in.
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
 */
class PortalStaffFillControllerTest extends TestCase {

	private const EMPLOYEE = ['uid' => 'medewerker', 'displayName' => 'Mieke Balie'];

	private FormSubmitFake $submit;

	private StaffFill&MockObject $staff;

	private function controller(?array $employee = self::EMPLOYEE, ?array $binding = ['portal' => 'zuiddrecht', 'route' => 'woo', 'staffMayFill' => true, 'destination' => ['register' => 'dossiq', 'schema' => 'case', 'subjectField' => 'portalSubject']], array $render = []): PortalStaffFillController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('');

		$portals = $this->getMockBuilder(PortalResolver::class)->disableOriginalConstructor()->onlyMethods(['resolve'])->getMock();
		$portals->method('resolve')->willReturn(['slug' => 'zuiddrecht', 'organisation' => 'org-1']);

		$bindings = $this->getMockBuilder(PortalFormBindingResolver::class)->disableOriginalConstructor()->onlyMethods(['bindingFor', 'render'])->getMock();
		$bindings->method('bindingFor')->willReturn($binding);
		$bindings->method('render')->willReturn($render === [] ? $this->hostedForm() : $render);

		$this->staff = $this->getMockBuilder(StaffFill::class)
			->setConstructorArgs([
				$this->createMock(IUserSession::class),
				$this->createMock(IGroupManager::class),
				$this->createMock(PortalAccountLookup::class),
				$this->createMock(PortalRegisteredDetailsService::class),
				new DutchFormats(),
				$this->createMock(AuditTrailService::class),
			])
			->onlyMethods(['employee', 'prefill', 'subjectRefFor'])
			->getMock();
		$this->staff->method('employee')->willReturn($employee);
		$this->staff->method('subjectRefFor')->willReturn('sub-sanne');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$this->submit = new FormSubmitFake(outcome: ['reference' => '2026-0077', 'id' => 'u-7', 'receivedAt' => '2026-10-30T09:00:00+00:00', 'objects' => []]);
		$schemas = $this->createMock(PortalSchemaReader::class);
		$schemas->method('readSchema')->willReturn(['properties' => array_fill_keys(['naam', 'portalSubject', 'filledBy', 'applicant'], ['type' => 'string'])]);
		$intake = new DestinationIntake(
			submitter: new DestinationSubmit($this->submit->container(), $this->createMock(LoggerInterface::class)),
			mapping: new DestinationMapping(),
			schemas: $schemas
		);

		return new PortalStaffFillController(
			request: $request,
			portals: $portals,
			bindings: $bindings,
			staff: $this->staff,
			validator: new PortalFormValidator($l10n),
			intake: $intake
		);
	}//end controller()

	private function hostedForm(): array {
		return [
			'kind' => 'hosted',
			'resolvesToNoForm' => false,
			'fields' => [['name' => 'naam', 'required' => true]],
			'settings' => ['challenge' => false, 'confirmationText' => 'Bedankt.', 'staffMayFill' => true],
		];
	}//end hostedForm()

	public function testEveryRouteRefusesAUserOutsideTheDeskGroupBeforeAnythingHappens(): void {
		$controller = $this->controller(employee: null);
		$this->staff->expects($this->never())->method('prefill');

		$this->assertSame(['error' => 'staff_not_allowed'], $controller->employee(route: 'woo')->getData());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->employee(route: 'woo')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->start(route: 'woo', applicant: ['kind' => 'bsn', 'value' => '111222333'])->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->submit(route: 'woo', applicant: ['kind' => 'bsn', 'value' => '111222333'], answers: ['naam' => 'Sanne'])->getStatus());
		$this->assertSame([], $this->submit->calls);
	}//end testEveryRouteRefusesAUserOutsideTheDeskGroupBeforeAnythingHappens()

	public function testAnUnknownFormIsNotFound(): void {
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(binding: null)->employee(route: 'nope')->getStatus());
	}//end testAnUnknownFormIsNotFound()

	public function testAnExternalFormHasNoStaffMode(): void {
		$controller = $this->controller(render: ['kind' => 'external', 'resolvesToNoForm' => false, 'settings' => []]);

		$this->assertSame(['error' => 'form_not_submittable'], $controller->employee(route: 'woo')->getData());
	}//end testAnExternalFormHasNoStaffMode()

	public function testTheEmployeeIsNamed(): void {
		$this->assertSame(['filledBy' => self::EMPLOYEE], $this->controller()->employee(route: 'woo')->getData());
	}//end testTheEmployeeIsNamed()

	public function testStartingForAResidentAnswersTheFormFilledInFromTheBrp(): void {
		$controller = $this->controller();
		$this->staff->expects($this->once())->method('prefill')
			->with(self::EMPLOYEE, ['kind' => 'bsn', 'value' => '111222333'], $this->anything())
			->willReturn(['applicantName' => 'Sanne de Vries']);

		$data = $controller->start(route: 'woo', applicant: ['kind' => 'bsn', 'value' => '111222333'])->getData();

		$this->assertSame(['applicantName' => 'Sanne de Vries'], $data['prefill']);
		$this->assertSame(['kind' => 'bsn', 'value' => '111222333'], $data['applicant']);
		$this->assertSame(self::EMPLOYEE, $data['filledBy']);
		$this->assertSame('naam', $data['fields'][0]['name']);
	}//end testStartingForAResidentAnswersTheFormFilledInFromTheBrp()

	public function testAnApplicantThatIsNotABsnIsRefused(): void {
		$controller = $this->controller();
		$this->staff->expects($this->never())->method('prefill');

		$this->assertSame(['error' => 'applicant_invalid'], $controller->start(route: 'woo', applicant: ['kind' => 'bsn', 'value' => '123'])->getData());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->submit(route: 'woo', applicant: [], answers: ['naam' => 'x'])->getStatus());
		$this->assertSame([], $this->submit->calls);
	}//end testAnApplicantThatIsNotABsnIsRefused()

	public function testTheDeliveredRequestNamesTheApplicantAndTheEmployee(): void {
		$controller = $this->controller();

		$data = $controller->submit(route: 'woo', applicant: ['kind' => 'bsn', 'value' => '111222333'], answers: ['naam' => 'Sanne de Vries'])->getData();

		$this->assertSame('2026-0077', $data['reference']);
		$fixed = $this->submit->calls[0]['mapping']['fixed'];
		$this->assertSame(self::EMPLOYEE, $fixed['filledBy']);
		$this->assertSame(['kind' => 'bsn', 'value' => '111222333'], $fixed['applicant']);
		$this->assertSame('sub-sanne', $fixed['portalSubject'], 'the resident finds it in their own Mijn zaken');
	}//end testTheDeliveredRequestNamesTheApplicantAndTheEmployee()

	public function testAnInvalidSubmissionIsRefusedBeforeDelivery(): void {
		$response = $this->controller()->submit(route: 'woo', applicant: ['kind' => 'named', 'value' => 'Henk'], answers: []);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertArrayHasKey('naam', $response->getData()['errors']);
		$this->assertSame([], $this->submit->calls);
	}//end testAnInvalidSubmissionIsRefusedBeforeDelivery()

	public function testTheRoutesNeedASignedInUserAndOnlyTheReadSkipsTheCsrfCheck(): void {
		foreach (['employee', 'start', 'submit'] as $method) {
			$reflection = new ReflectionMethod(PortalStaffFillController::class, $method);
			$this->assertNotEmpty($reflection->getAttributes(NoAdminRequired::class), $method);
			$this->assertEmpty($reflection->getAttributes(PublicPage::class), $method);
			$this->assertSame($method === 'employee', $reflection->getAttributes(NoCSRFRequired::class) !== [], $method);
		}
	}//end testTheRoutesNeedASignedInUserAndOnlyTheReadSkipsTheCsrfCheck()
}//end class
