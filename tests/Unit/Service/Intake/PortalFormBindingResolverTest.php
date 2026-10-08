<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\CaseTypeVisibility;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\Intake\PortalFormTrustLevel;
use OCA\Portaliq\Service\PortalResolver;
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

	/**
	 * The form's steps travel with the render, with a review step last
	 * (site-multi-step-forms REQ-SMF-010).
	 *
	 * @return void
	 */
	public function testStepsTravelWithTheForm(): void {
		$this->seedForm(
			audience: 'client',
			fields: [['name' => 'onderwerp', 'order' => 1], ['name' => 'periodeVan', 'order' => 2]],
			extra: [
				'steps' => [
					['id' => 'vraag', 'title' => 'Uw vraag', 'fields' => ['onderwerp']],
					['id' => 'periode', 'title' => 'Periode en documenten', 'description' => 'Welke periode?', 'fields' => ['periodeVan']],
					['id' => 'controle', 'title' => 'Controleren en versturen', 'review' => true],
				],
			]
		);

		$render = $this->resolver()->render(binding: $this->binding());

		$this->assertSame(
			[
				['id' => 'vraag', 'title' => 'Uw vraag', 'fields' => ['onderwerp']],
				['id' => 'periode', 'title' => 'Periode en documenten', 'fields' => ['periodeVan'], 'description' => 'Welke periode?'],
				['id' => 'controle', 'title' => 'Controleren en versturen', 'fields' => [], 'review' => true],
			],
			$render['steps']
		);
	}//end testStepsTravelWithTheForm()

	/**
	 * A step naming a field the form does not have is dropped; its known
	 * fields go to the last step.
	 *
	 * @return void
	 */
	public function testAStepNamingAnUnknownFieldIsDropped(): void {
		$this->seedForm(
			audience: 'client',
			fields: [['name' => 'onderwerp', 'order' => 1], ['name' => 'naam', 'order' => 2]],
			extra: [
				'steps' => [
					['id' => 'vraag', 'title' => 'Uw vraag', 'fields' => ['onderwerp']],
					['id' => 'betalen', 'title' => 'Betalen', 'fields' => ['naam', 'iban']],
				],
			]
		);

		$steps = $this->resolver()->render(binding: $this->binding())['steps'];

		$this->assertSame(['vraag', 'more'], array_column($steps, 'id'));
		$this->assertSame(['naam'], $steps[1]['fields']);
	}//end testAStepNamingAnUnknownFieldIsDropped()

	/**
	 * A field in no step goes in a last step of its own, before the review;
	 * a form without steps renders as one page.
	 *
	 * @return void
	 */
	public function testLooseFieldsGetALastStep(): void {
		$formId = $this->seedForm(
			audience: 'client',
			fields: [['name' => 'onderwerp', 'order' => 1], ['name' => 'toelichting', 'order' => 2]],
			extra: [
				'steps' => [
					['id' => 'vraag', 'title' => 'Uw vraag', 'fields' => ['onderwerp']],
					['id' => 'controle', 'title' => 'Controleren', 'review' => true],
				],
			]
		);

		$steps = $this->resolver()->render(binding: $this->binding())['steps'];
		$this->assertSame(['vraag', 'more', 'controle'], array_column($steps, 'id'));
		$this->assertSame(['toelichting'], $steps[1]['fields']);

		unset($this->rows[$formId]['steps']);
		$this->assertSame([], $this->resolver()->render(binding: $this->binding())['steps']);
	}//end testLooseFieldsGetALastStep()

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

	public function testTheFormsSignInLevelIsCarriedToTheRender(): void {
		// portaliq#725: buildiq writes the per-form sign-in level on the
		// registration form (buildiq#935); the render must carry it or no
		// caller can enforce it.
		$this->seedForm(audience: 'client', fields: [['name' => 'postcode', 'order' => 1]], extra: ['minTrust' => 'substantial']);
		$resolver = $this->resolver();

		$render = $resolver->render(binding: $this->binding());

		$this->assertSame('substantial', $render['minTrust']);
		$this->assertSame('substantial', $resolver->requiredTrust(site: ['slug' => 'gemeente-x'], binding: $this->binding(), render: $render));

	}//end testTheFormsSignInLevelIsCarriedToTheRender()

	public function testTheStrictestOfPortalBindingAndFormWins(): void {
		$resolver = $this->resolver();
		$identified = ['authentication' => ['requiresIdentifiedIntake' => true]];

		$this->assertNull($resolver->requiredTrust(site: [], binding: [], render: []));
		$this->assertSame('low', $resolver->requiredTrust(site: [], binding: ['minTrust' => 'low'], render: ['minTrust' => 0]));
		$this->assertSame('low', $resolver->requiredTrust(site: $identified, binding: [], render: []));
		$this->assertSame('high', $resolver->requiredTrust(site: $identified, binding: ['minTrust' => 'substantial'], render: ['minTrust' => 'high']));
		$this->assertSame('substantial', $resolver->requiredTrust(site: [], binding: ['minTrust' => 'substantial'], render: []));
		// A typo never widens access: it wins over every known level.
		$this->assertSame(PortalFormTrustLevel::UNRECOGNISED, $resolver->requiredTrust(site: [], binding: ['minTrust' => 'high'], render: ['minTrust' => 'digid']));

	}//end testTheStrictestOfPortalBindingAndFormWins()

	public function testADeclaredLowNeedsASignedInSession(): void {
		// portaliq#731: `low` is DigiD basis, the lowest signed-in level, the
		// same `low` a portalPage entry and requiresIdentifiedIntake mean. It
		// never means anonymous, on the form or on the binding.
		$resolver = $this->resolver();

		$this->assertSame('low', $resolver->requiredTrust(site: [], binding: [], render: ['minTrust' => 'low']));
		$this->assertSame('low', $resolver->requiredTrust(site: [], binding: ['minTrust' => 'low'], render: []));

	}//end testADeclaredLowNeedsASignedInSession()

	public function testOnlyAbsenceZeroAndAnonymousMeanAnonymous(): void {
		$resolver = $this->resolver();

		$this->assertNull($resolver->requiredTrust(site: [], binding: [], render: []));
		$this->assertNull($resolver->requiredTrust(site: [], binding: [], render: ['minTrust' => 0]));
		$this->assertNull($resolver->requiredTrust(site: [], binding: [], render: ['minTrust' => '0']));
		$this->assertNull($resolver->requiredTrust(site: [], binding: ['minTrust' => 'anonymous'], render: ['minTrust' => '']));

	}//end testOnlyAbsenceZeroAndAnonymousMeanAnonymous()

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
	 * gate-7, the scope the reference-link route is confined to. The portal
	 * declares its case types by publishing bindings for them, and nothing
	 * else in the app records that set.
	 *
	 * @return void
	 */
	public function testAPortalDeclaresOnlyTheCaseTypesItPublishedABindingFor(): void {
		$this->seedRow('portalFormBinding', $this->binding());
		$this->seedRow('portalFormBinding', $this->binding([
			'portal' => 'gemeente-y',
			'route' => 'aanvragen/kap',
			'typeRegister' => 'andere-gemeente',
			'typeId' => 'kapvergunning',
		]));
		$this->seedRow('portalFormBinding', $this->binding([
			'route' => 'aanvragen/concept',
			'typeId' => 'concept',
			'status' => 'draft',
		]));
		$resolver = $this->resolver();

		$this->assertSame([['dossiq', 'zaaktype', 'verhuizing']], $resolver->declaredCaseTypes(portal: 'gemeente-x'));

	}//end testAPortalDeclaresOnlyTheCaseTypesItPublishedABindingFor()

	/**
	 * gate-7. The predicate the anonymous route refuses on: another portal's
	 * case type, a draft one, and a partly-right triple are all outside.
	 *
	 * @return void
	 */
	public function testOnlyTheExactDeclaredTripleIsInScope(): void {
		$this->seedRow('portalFormBinding', $this->binding());
		$this->seedRow('portalFormBinding', $this->binding([
			'portal' => 'gemeente-y',
			'route' => 'aanvragen/kap',
			'typeRegister' => 'andere-gemeente',
			'typeId' => 'kapvergunning',
		]));
		$resolver = $this->resolver();

		$this->assertTrue($resolver->caseTypeIsInPortalScope(portal: 'gemeente-x', register: 'dossiq', schema: 'zaaktype', typeId: 'verhuizing'));
		// Another portal's declaration is not this portal's scope.
		$this->assertFalse($resolver->caseTypeIsInPortalScope(portal: 'gemeente-x', register: 'andere-gemeente', schema: 'zaaktype', typeId: 'kapvergunning'));
		// The register is part of the triple, not decoration.
		$this->assertFalse($resolver->caseTypeIsInPortalScope(portal: 'gemeente-x', register: 'andere-gemeente', schema: 'zaaktype', typeId: 'verhuizing'));
		// So is the schema.
		$this->assertFalse($resolver->caseTypeIsInPortalScope(portal: 'gemeente-x', register: 'dossiq', schema: 'portalCaseType', typeId: 'verhuizing'));
		// An empty value names nothing and is never in scope.
		$this->assertFalse($resolver->caseTypeIsInPortalScope(portal: 'gemeente-x', register: '', schema: 'zaaktype', typeId: 'verhuizing'));
		// A portal that resolved to nothing declares nothing.
		$this->assertFalse($resolver->caseTypeIsInPortalScope(portal: '', register: 'dossiq', schema: 'zaaktype', typeId: 'verhuizing'));

	}//end testOnlyTheExactDeclaredTripleIsInScope()

	/**
	 * operate-show-per-case-type REQ-OSC-002: a binding for a case type its
	 * portal hides resolves to no form, and says why; the same binding on a
	 * portal that shows the type still renders.
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-hidden-case-type-does-not-reach-residents-req-osc-002
	 */
	public function testHiddenCaseTypeResolvesToNoForm(): void {
		$this->seedForm(audience: 'client', fields: [['name' => 'postcode', 'order' => 1]]);
		$this->seedRow('portalFormBinding', $this->binding());
		$this->seedRow('portalFormBinding', $this->binding(['route' => 'aanvragen/kap', 'typeId' => 'kapvergunning']));
		$resolver = $this->hidingResolver();

		$render = $resolver->render(binding: $this->binding());
		$this->assertTrue($render['resolvesToNoForm']);
		$this->assertSame('hiddenCaseType', $render['reason']);
		$this->assertSame([], $render['fields']);

		$shown = $resolver->render(binding: $this->binding(['portal' => 'gemeente-y']));
		$this->assertFalse($shown['resolvesToNoForm']);

		$this->assertSame(['aanvragen/verhuizing', 'aanvragen/kap'], array_column($resolver->publishedBindings(portal: 'gemeente-x'), 'route'));
	}//end testHiddenCaseTypeResolvesToNoForm()

	/**
	 * intake-conditional-questions-and-drafts REQ-ICQ-003: a form with a
	 * condition the portal cannot replay on submit (an endpoint, a source, the
	 * clock) opens no form, with the reason the preview names; a condition on
	 * another answer is fine.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md#requirement-a-condition-the-portal-cannot-check-refuses-the-form-req-icq-003
	 */
	public function testNonLocalConditionResolvesToNoForm(): void {
		$local = ['name' => 'partnerName', 'order' => 2, 'visibleWhen' => ['field' => 'together', 'op' => 'eq', 'value' => 'Yes']];
		foreach ([
			['endpoint' => '/apps/dossiq/api/eligibility', 'field' => 'ok', 'value' => true],
			['source' => ['register' => 'dossiq', 'schema' => 'case'], 'field' => '@total', 'op' => 'gt', 'value' => 0],
			['all' => [['field' => 'together', 'value' => 'Yes'], ['field' => 'movedOn', 'op' => 'lt', 'value' => '@today']]],
		] as $condition) {
			$this->setUp();
			$this->seedForm(audience: 'client', fields: [
				['name' => 'together', 'order' => 1],
				$local,
				['name' => 'extra', 'order' => 3, 'visibleWhen' => $condition],
			]);

			$render = $this->resolver()->render(binding: $this->binding());

			$this->assertTrue($render['resolvesToNoForm'], json_encode($condition));
			$this->assertSame('unsupportedCondition', $render['reason']);
			$this->assertSame([], $render['fields']);
		}

		$this->setUp();
		$this->seedForm(audience: 'client', fields: [['name' => 'together', 'order' => 1], $local]);
		$render = $this->resolver()->render(binding: $this->binding());
		$this->assertFalse($render['resolvesToNoForm']);
		$this->assertSame($local['visibleWhen'], $render['fields'][1]['visibleWhen']);
	}//end testNonLocalConditionResolvesToNoForm()

	/**
	 * A resolver whose portal gemeente-x hides the case type verhuizing.
	 *
	 * @return PortalFormBindingResolver
	 */
	private function hidingResolver(): PortalFormBindingResolver {
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('allPublishedPortals')->willReturn([
			['slug' => 'gemeente-x', 'hiddenCaseTypes' => [['typeId' => 'verhuizing']]],
			['slug' => 'gemeente-y'],
		]);

		return new PortalFormBindingResolver($this->fakeReader(), new CaseTypeVisibility($portals));
	}//end hidingResolver()

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
