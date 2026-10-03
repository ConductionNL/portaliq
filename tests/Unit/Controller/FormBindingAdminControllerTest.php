<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\FormBindingAdminController;
use OCA\Portaliq\Service\Intake\PortalBindingPreview;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\PortalObjectReader;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The admin surface names the form a binding resolves to today (T03).
 *
 * The controller runs the REAL preview over the REAL resolver, so what the
 * administrator reads is what the citizen-facing render decides. Only the
 * OpenRegister read under the resolver is stubbed.
 *
 * @spec openspec/specs/portal-intake-form/spec.md#requirement-a-portal-page-binds-to-a-published-form-not-to-a-field-list-req-pifo-001
 */
class FormBindingAdminControllerTest extends TestCase {

	/**
	 * A controller whose form register answers the given rows.
	 *
	 * @param array<int, array<string, mixed>> $forms The published forms.
	 *
	 * @return FormBindingAdminController The controller.
	 */
	private function controllerWithForms(array $forms): FormBindingAdminController {
		$reader = $this->getMockBuilder(PortalObjectReader::class)
			->disableOriginalConstructor()
			->onlyMethods(['readCollection'])
			->getMock();
		$reader->method('readCollection')->willReturn($forms);

		$resolver = new PortalFormBindingResolver(reader: $reader);

		return new FormBindingAdminController(
			request: $this->createMock(IRequest::class),
			preview: new PortalBindingPreview(resolver: $resolver),
		);
	}//end controllerWithForms()

	/**
	 * The binding the admin opens.
	 *
	 * @param array<string, mixed> $extra Fields to add.
	 *
	 * @return array<string, mixed>
	 */
	private function binding(array $extra = []): array {
		return array_merge(
			['portal' => 'gemeente', 'route' => '/aanvragen/formulier', 'typeId' => 'type-1', 'audience' => 'citizen'],
			$extra
		);
	}//end binding()

	/**
	 * A binding with a published form names that form.
	 *
	 * @return void
	 */
	public function testABindingWithAPublishedFormNamesIt(): void {
		$controller = $this->controllerWithForms(
			[['name' => 'Melding openbare ruimte', 'caseType' => 'type-1', 'audience' => 'citizen', 'status' => 'published']]
		);

		$body = $controller->preview(binding: $this->binding())->getData();

		$this->assertSame(PortalBindingPreview::RESOLVED, $body['state']);
		$this->assertSame('Melding openbare ruimte', $body['formName']);
		$this->assertNull($body['reason']);
	}//end testABindingWithAPublishedFormNamesIt()

	/**
	 * 🔴 A binding naming an audience with no published form says it
	 * resolves to no form, and names no form.
	 *
	 * @return void
	 */
	public function testABindingThatResolvesToNoFormSaysSoInTheAdmin(): void {
		$controller = $this->controllerWithForms(
			[['name' => 'Melding openbare ruimte', 'caseType' => 'type-1', 'audience' => 'supplier', 'status' => 'published']]
		);

		$body = $controller->preview(binding: $this->binding())->getData();

		$this->assertSame(PortalBindingPreview::RESOLVES_TO_NONE, $body['state']);
		$this->assertNull($body['formName']);
		$this->assertSame(PortalBindingPreview::REASON_NO_FORM, $body['reason']);
	}//end testABindingThatResolvesToNoFormSaysSoInTheAdmin()

	/**
	 * A binding that asks for a form by name that is only a draft says the
	 * named form is not published, and still names no form it opens.
	 *
	 * @return void
	 */
	public function testANamedFormThatIsNotPublishedIsNamedAsTheReason(): void {
		$controller = $this->controllerWithForms(
			[['name' => 'Kapvergunning', 'caseType' => 'type-1', 'audience' => 'citizen', 'status' => 'draft']]
		);

		$body = $controller->preview(binding: $this->binding(['formName' => 'Kapvergunning']))->getData();

		$this->assertSame(PortalBindingPreview::RESOLVES_TO_NONE, $body['state']);
		$this->assertNull($body['formName']);
		$this->assertSame(PortalBindingPreview::REASON_NAMED_FORM_NOT_PUBLISHED, $body['reason']);
		$this->assertSame('Kapvergunning', $body['askedFor']);
	}//end testANamedFormThatIsNotPublishedIsNamedAsTheReason()

	/**
	 * An external binding names where it sends people; one without an
	 * address resolves to nothing.
	 *
	 * @return void
	 */
	public function testAnExternalBindingNamesItsDestinationOrItsMissingAddress(): void {
		$controller = $this->controllerWithForms([]);

		$sent = $controller->preview(
			binding: $this->binding(['intakeKind' => 'external', 'externalUrl' => 'https://formulieren.example.nl/start'])
		)->getData();
		$this->assertSame(PortalBindingPreview::EXTERNAL, $sent['state']);
		$this->assertSame('formulieren.example.nl', $sent['destination']);

		$empty = $controller->preview(binding: $this->binding(['intakeKind' => 'external']))->getData();
		$this->assertSame(PortalBindingPreview::RESOLVES_TO_NONE, $empty['state']);
		$this->assertSame(PortalBindingPreview::REASON_EXTERNAL_WITHOUT_ADDRESS, $empty['reason']);
	}//end testAnExternalBindingNamesItsDestinationOrItsMissingAddress()

	/**
	 * A body that is no binding at all is a 400, not a preview of nothing.
	 *
	 * @return void
	 */
	public function testAnEmptyBodyIsRefused(): void {
		$controller = $this->controllerWithForms([]);

		$this->assertSame(400, $controller->preview(binding: [])->getStatus());
	}//end testAnEmptyBodyIsRefused()
}//end class
