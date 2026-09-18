<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Tests\Unit\Service\Identity\PortalIdentityStoreTrait;
use PHPUnit\Framework\TestCase;

/**
 * portal-intake-form-as-an-object REQ-PIFO-001 and REQ-PIFO-002: a page binds
 * to a published form and keeps no field list of its own, one case type can
 * carry two forms and only the bound audience's is rendered, an edited form
 * reaches the portal with no portal change, and an external binding names its
 * destination without anything being fetched from it.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 */
class PortalFormBindingResolverTest extends TestCase {
	use PortalIdentityStoreTrait;

	protected function setUp(): void {
		$this->rows = [];

	}//end setUp()

	public function testOnlyTheBoundAudiencesFormIsRendered(): void {
		$this->seedForm(audience: 'client', fields: [['name' => 'postcode', 'order' => 1]]);
		$this->seedForm(audience: 'supplier', fields: [['name' => 'kvk', 'order' => 1]]);
		$resolver = $this->resolver();

		$render = $resolver->render(binding: $this->binding());

		$this->assertFalse($render['resolvesToNoForm']);
		$this->assertSame(['postcode'], array_column($render['fields'], 'name'));

	}//end testOnlyTheBoundAudiencesFormIsRendered()

	public function testTheFieldsComeInTheFormsOwnOrder(): void {
		$this->seedForm(audience: 'client', fields: [
			['name' => 'toelichting', 'order' => 3],
			['name' => 'postcode', 'order' => 1],
			['name' => 'huisnummer', 'order' => 2],
		]);
		$resolver = $this->resolver();

		$render = $resolver->render(binding: $this->binding());

		$this->assertSame(['postcode', 'huisnummer', 'toelichting'], array_column($render['fields'], 'name'));

	}//end testTheFieldsComeInTheFormsOwnOrder()

	public function testAnEditedFormReachesThePortalWithNoPortalChange(): void {
		$formId = $this->seedForm(audience: 'client', fields: [['name' => 'postcode', 'order' => 1]]);
		$resolver = $this->resolver();
		$this->assertCount(1, $resolver->render(binding: $this->binding())['fields']);

		// The form is edited where it lives. The binding is untouched.
		$this->rows[$formId]['fields'][] = ['name' => 'huisnummer', 'order' => 2];

		$this->assertSame(['postcode', 'huisnummer'], array_column($resolver->render(binding: $this->binding())['fields'], 'name'));

	}//end testAnEditedFormReachesThePortalWithNoPortalChange()

	public function testAPresetIsCarriedOntoItsField(): void {
		$this->seedForm(audience: 'client', fields: [['name' => 'gemeente', 'order' => 1]], extra: ['presets' => ['gemeente' => 'Gemeente X']]);
		$resolver = $this->resolver();

		$render = $resolver->render(binding: $this->binding());

		$this->assertSame('Gemeente X', $render['fields'][0]['preset']);

	}//end testAPresetIsCarriedOntoItsField()

	public function testTheConfirmationTextIsTheFormsOwn(): void {
		$this->seedForm(audience: 'client', fields: [['name' => 'postcode', 'order' => 1]], extra: ['confirmationText' => 'Bedankt, u hoort van ons.']);
		$resolver = $this->resolver();

		$render = $resolver->render(binding: $this->binding(['confirmationText' => 'De standaardtekst.']));

		$this->assertSame('Bedankt, u hoort van ons.', $render['settings']['confirmationText']);

	}//end testTheConfirmationTextIsTheFormsOwn()

	public function testABindingThatResolvesToNoFormSaysSoAndRendersNoFields(): void {
		$this->seedForm(audience: 'supplier', fields: [['name' => 'kvk', 'order' => 1]]);
		$resolver = $this->resolver();

		$render = $resolver->render(binding: $this->binding());

		$this->assertTrue($render['resolvesToNoForm']);
		$this->assertSame('no_published_form_for_audience', $render['reason']);
		$this->assertSame([], $render['fields']);

	}//end testABindingThatResolvesToNoFormSaysSoAndRendersNoFields()

	public function testADraftFormIsNotRendered(): void {
		$this->seedForm(audience: 'client', fields: [['name' => 'postcode', 'order' => 1]], extra: ['status' => 'draft']);
		$resolver = $this->resolver();

		$this->assertTrue($resolver->render(binding: $this->binding())['resolvesToNoForm']);

	}//end testADraftFormIsNotRendered()

	public function testAnExternalBindingNamesItsDestinationAndRendersNoFields(): void {
		$resolver = $this->resolver();

		$render = $resolver->render(binding: $this->binding([
			'intakeKind' => 'external',
			'externalUrl' => 'https://formulieren.example.org/verhuizing',
		]));

		$this->assertSame('external', $render['kind']);
		$this->assertSame('formulieren.example.org', $render['destination']);
		$this->assertArrayNotHasKey('fields', $render);

	}//end testAnExternalBindingNamesItsDestinationAndRendersNoFields()

	public function testADraftBindingIsNotServed(): void {
		$this->seedRow('portalFormBinding', [
			'portal' => 'gemeente-x',
			'route' => 'aanvragen/verhuizing',
			'status' => 'draft',
			'audience' => 'client',
			'typeId' => 'verhuizing',
		]);
		$resolver = $this->resolver();

		$this->assertNull($resolver->bindingFor(portal: 'gemeente-x', route: 'aanvragen/verhuizing'));

	}//end testADraftBindingIsNotServed()

	public function testABindingIsFoundByItsOwnPortalAndRoute(): void {
		$this->seedRow('portalFormBinding', [
			'portal' => 'gemeente-x',
			'route' => 'aanvragen/verhuizing',
			'status' => 'published',
			'audience' => 'client',
			'typeId' => 'verhuizing',
		]);
		$resolver = $this->resolver();

		$this->assertNotNull($resolver->bindingFor(portal: 'gemeente-x', route: 'aanvragen/verhuizing'));
		$this->assertNull($resolver->bindingFor(portal: 'gemeente-y', route: 'aanvragen/verhuizing'));
		$this->assertNull($resolver->bindingFor(portal: 'gemeente-x', route: 'aanvragen/iets-anders'));

	}//end testABindingIsFoundByItsOwnPortalAndRoute()

	/**
	 * A binding for the client form of one case type.
	 *
	 * @param array<string, mixed> $extra Anything to override.
	 *
	 * @return array<string, mixed>
	 */
	private function binding(array $extra = []): array {
		return array_merge([
			'portal' => 'gemeente-x',
			'route' => 'aanvragen/verhuizing',
			'typeRegister' => 'dossiq',
			'typeSchema' => 'zaaktype',
			'typeId' => 'verhuizing',
			'audience' => 'client',
			'formRegister' => 'buildiq',
			'formSchema' => 'registrationForm',
			'intakeKind' => 'hosted',
			'status' => 'published',
		], $extra);
	}//end binding()

	/**
	 * Put one published form in the fake store.
	 *
	 * @param string $audience The audience it is published for.
	 * @param array<int, array<string, mixed>> $fields Its fields.
	 * @param array<string, mixed> $extra Anything to override.
	 *
	 * @return string The form's uuid.
	 */
	private function seedForm(string $audience, array $fields, array $extra = []): string {
		return $this->seedRow('registrationForm', array_merge([
			'caseType' => 'verhuizing',
			'audience' => $audience,
			'status' => 'published',
			'name' => 'verhuizing-' . $audience,
			'fields' => $fields,
		], $extra));
	}//end seedForm()

	/**
	 * The resolver over the fake store.
	 *
	 * @return PortalFormBindingResolver
	 */
	private function resolver(): PortalFormBindingResolver {
		return new PortalFormBindingResolver($this->fakeReader());
	}//end resolver()

}//end class
