<?php

/**
 * Portaliq Portal Form Binding Resolver
 *
 * What a portal form page renders. The page stores a binding, not a field
 * list: a case type tuple, an audience, an optional form name. The fields,
 * their order and their presets come from the published form at render time,
 * which is what lets an editor add a field in buildiq and see it on the portal
 * without a portal change.
 *
 * A binding that resolves to no form says so rather than rendering an empty
 * form: an administrator can then see why the page is blank, and the visitor
 * gets a sentence instead of a form that submits nothing.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use OCA\Portaliq\Contribution\FormStepsNormaliser;
use OCA\Portaliq\Service\CaseTypeVisibility;
use OCA\Portaliq\Service\PortalObjectReader;

/**
 * Resolves a form binding against the published form, at render time.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 */
class PortalFormBindingResolver {
	/**
	 * A binding whose form is rendered by the portal itself.
	 */
	public const KIND_HOSTED = 'hosted';

	/**
	 * A binding naming a start form on another host.
	 */
	public const KIND_EXTERNAL = 'external';

	/**
	 * The register the binding lives in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The schema recording a binding.
	 */
	private const SCHEMA = 'portalFormBinding';

	/**
	 * The register the published form leaf lives in, unless the binding names
	 * another one.
	 */
	private const DEFAULT_FORM_REGISTER = 'buildiq';

	/**
	 * The schema the published form leaf lives in, unless the binding names
	 * another one.
	 */
	private const DEFAULT_FORM_SCHEMA = 'registrationForm';

	/**
	 * Reads the fields of a published form.
	 *
	 * @var PortalFormFields
	 */
	private readonly PortalFormFields $fields;

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader Reads the binding and the form.
	 * @param CaseTypeVisibility|null $caseTypes The case types a portal hides
	 *                                           (operate-show-per-case-type).
	 *                                           Absent hides nothing.
	 * @param VisibleWhenLocal $visibleWhen Which field conditions the portal
	 *                                      can check on submit.
	 * @param PortalReferenceLists|null $lists Fills a field's `options.referenceList`. Absent
	 *                                         leaves such a field with no options, which
	 *                                         closes it.
	 * @param PortalFormCalculator $calculator Knows which `calculate` operations the server can repeat.
	 * @param PortalFee|null $fees Reads the fee a case type declares. Absent means no form carries one.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly ?CaseTypeVisibility $caseTypes = null,
		private readonly VisibleWhenLocal $visibleWhen = new VisibleWhenLocal(),
		?PortalReferenceLists $lists = null,
		private readonly PortalFormCalculator $calculator = new PortalFormCalculator(),
		private readonly ?PortalFee $fees = null,
	) {
		$this->fields = new PortalFormFields(lists: $lists);
	}//end __construct()

	/**
	 * The binding a portal route carries, or null.
	 *
	 * @param string $portal The portal slug.
	 * @param string $route The in-portal route.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
	 */
	public function bindingFor(string $portal, string $route): ?array {
		if ($portal === '' || $route === '') {
			return null;
		}

		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'route',
			subjectRef: $route,
			organisation: '',
			limit: 20,
			filter: ['portal' => $portal]
		);

		foreach ($rows as $row) {
			if (is_array($row) === false || ($row['route'] ?? '') !== $route || ($row['portal'] ?? '') !== $portal) {
				continue;
			}

			if ((string)($row['status'] ?? 'draft') !== 'published') {
				// A draft binding is not a page anybody may fill in.
				continue;
			}

			return $row;
		}

		return null;
	}//end bindingFor()

	/**
	 * The case types a portal has published a binding for.
	 *
	 * A binding is where a portal writes down which case type it serves, so
	 * the published bindings are the portal's own declaration of the case
	 * types it speaks for. Nothing else in the app records that, which is why
	 * this reads bindings rather than a list of its own.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<int, array{0: string, 1: string, 2: string}> One
	 *         `[register, schema, typeId]` triple per published binding.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function declaredCaseTypes(string $portal): array {
		$declared = [];
		foreach ($this->publishedBindings(portal: $portal) as $row) {
			$triple = [
				(string)($row['typeRegister'] ?? ''),
				(string)($row['typeSchema'] ?? ''),
				(string)($row['typeId'] ?? ''),
			];
			if (in_array('', $triple, true) === true) {
				continue;
			}

			$declared[] = $triple;
		}

		return $declared;
	}//end declaredCaseTypes()

	/**
	 * A portal's published bindings.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
	 */
	public function publishedBindings(string $portal): array {
		if ($portal === '') {
			return [];
		}

		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'portal',
			subjectRef: $portal,
			organisation: '',
			limit: 200,
			filter: ['portal' => $portal]
		);

		$published = [];
		foreach ($rows as $row) {
			if (is_array($row) === false || ($row['portal'] ?? '') !== $portal) {
				continue;
			}

			if ((string)($row['status'] ?? 'draft') !== 'published') {
				// A draft binding declares nothing yet.
				continue;
			}

			$published[] = $row;
		}

		return $published;
	}//end publishedBindings()

	/**
	 * Whether a case type is inside the scope a portal declared.
	 *
	 * The reference-link route takes its register, schema and case type from
	 * an anonymous request. Without this, naming any three values would reach
	 * any object on the instance, because the read behind it runs with RBAC
	 * and multitenancy off. The answer is false unless a published binding of
	 * THIS portal names exactly that triple.
	 *
	 * @param string $portal The portal slug.
	 * @param string $register The register named in the request.
	 * @param string $schema The schema named in the request.
	 * @param string $typeId The case type named in the request.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function caseTypeIsInPortalScope(string $portal, string $register, string $schema, string $typeId): bool {
		if ($register === '' || $schema === '' || $typeId === '') {
			return false;
		}

		return in_array([$register, $schema, $typeId], $this->declaredCaseTypes(portal: $portal), true);
	}//end caseTypeIsInPortalScope()

	/**
	 * What the page should render for a binding.
	 *
	 * @param array<string, mixed> $binding The binding.
	 *
	 * @return array<string, mixed> The render payload: `kind`, and either the
	 *         resolved form (`fields`, `order`, `confirmationText`, the intake
	 *         settings) or `resolvesToNoForm` true with the reason the admin
	 *         surface prints.
	 *
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-hidden-case-type-does-not-reach-residents-req-osc-002
	 * @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md#requirement-a-condition-the-portal-cannot-check-refuses-the-form-req-icq-003
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-a-published-form-with-steps-must-be-filled-in-one-step-at-a-time-with-visible-progress-req-smf-010
	 */
	public function render(array $binding): array {
		$settings = [
			'addressLookup' => (($binding['addressLookup'] ?? false) === true),
			'prefillFromEarlierCases' => (($binding['prefillFromEarlierCases'] ?? false) === true),
			'challenge' => (($binding['challenge'] ?? false) === true),
			'confirmationText' => (string)($binding['confirmationText'] ?? ''),
			// The introduction, the statements asked, the confirmation page and
			// mail (form-statements-intro-and-confirmation-mail). Carried as the
			// binding declares them; the controller adds the portal's wording.
			'intro' => $this->arrayOrNull(value: ($binding['intro'] ?? null)),
			'statementsDeclared' => $this->arrayOrNull(value: ($binding['statements'] ?? null)),
			'confirmation' => $this->arrayOrNull(value: ($binding['confirmation'] ?? null)),
			'confirmationMail' => ((($binding['confirmationMail']['enabled'] ?? false)) === true),
		];

		if ($this->caseTypes?->hidesBinding(binding: $binding) === true) {
			// The portal does not show this case type, so its form does not
			// open (operate-show-per-case-type REQ-OSC-002).
			return $this->noForm(kind: (string)($binding['intakeKind'] ?? self::KIND_HOSTED), reason: 'hiddenCaseType', settings: $settings);
		}

		if ((string)($binding['intakeKind'] ?? self::KIND_HOSTED) === self::KIND_EXTERNAL) {
			$url = (string)($binding['externalUrl'] ?? '');

			// The destination is named before the visitor leaves, and nothing
			// is fetched from it: an external form is linked to, never proxied.
			return [
				'kind' => self::KIND_EXTERNAL,
				'resolvesToNoForm' => ($url === ''),
				'externalUrl' => $url,
				'destination' => $this->hostOf(url: $url),
				'settings' => $settings,
			];
		}

		$form = $this->publishedForm(binding: $binding);
		if ($form === null) {
			return $this->noForm(kind: self::KIND_HOSTED, reason: 'no_published_form_for_audience', settings: $settings);
		}

		$fields = $this->fields->fieldsOf(form: $form);
		if ($this->visibleWhen->decidesEveryField(fields: $fields) === false) {
			// The server could not repeat on submit what the screen decided,
			// so the form is refused rather than half checked (REQ-ICQ-003).
			return $this->noForm(kind: self::KIND_HOSTED, reason: 'unsupportedCondition', settings: $settings);
		}

		if ($this->calculator->knowsEveryOperation(fields: $fields) === false) {
			// The server could not work the value out again on submit, so the
			// form does not open (form-flow-repeating-groups-calculations-and-decisions REQ-FFL-002).
			return $this->noForm(kind: self::KIND_HOSTED, reason: 'unsupportedCalculation', settings: $settings);
		}
		$fee = $this->fees?->forBinding(binding: $binding);

		$confirmation = (string)($form['confirmationText'] ?? '');
		if ($confirmation !== '') {
			// The confirmation the citizen reads is the form's own words when
			// the form carries them; the binding's text is the fallback.
			$settings['confirmationText'] = $confirmation;
		}

		return [
			'kind' => self::KIND_HOSTED,
			'resolvesToNoForm' => false,
			'formId' => (string)($form['uuid'] ?? $form['id'] ?? ''),
			'formName' => (string)($form['name'] ?? ''),
			'fields' => $fields,
			// The form's own steps (site-multi-step-forms REQ-SMF-010), kept
			// only where they name fields the form has; [] renders one page.
			'steps' => (new FormStepsNormaliser())->steps(
				steps: ($form['steps'] ?? null),
				known: array_map(static fn (array $field): string => (string)$field['name'], $fields)
			),
			'settings' => $settings,
			// What the request costs, from the case type and nowhere else
			// (intake-pay-on-submit REQ-IPS-001). Null for a free request.
			'fee' => $fee,
			// The sign-in level the maker chose for this form (buildiq#935).
			// Carried as declared; requiredTrust() decides what it means.
			'minTrust' => ($form['minTrust'] ?? null),
		];
	}//end render()

	/**
	 * The render of a binding that resolves to no form.
	 *
	 * @param string               $kind     The binding's intake kind.
	 * @param string               $reason   Why, as the admin surface prints it.
	 * @param array<string, mixed> $settings The intake settings.
	 *
	 * @return array<string, mixed>
	 */
	private function noForm(string $kind, string $reason, array $settings): array {
		return [
			'kind' => $kind,
			'resolvesToNoForm' => true,
			'reason' => $reason,
			'fields' => [],
			'settings' => $settings,
		];
	}//end noForm()

	/**
	 * The sign-in level a submission of this form needs, or null for none.
	 *
	 * The strictest of the portal's, the binding's and the form's own level;
	 * see PortalFormTrustLevel for what each declared value means.
	 *
	 * @param array<string, mixed> $site The portal.
	 * @param array<string, mixed> $binding The binding.
	 * @param array<string, mixed> $render What render() returned for it.
	 *
	 * @return string|null `low`, `substantial`, `high`,
	 *                     PortalFormTrustLevel::UNRECOGNISED, or null when an
	 *                     anonymous visitor may fill the form in.
	 *
	 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
	 */
	public function requiredTrust(array $site, array $binding, array $render): ?string {
		return (new PortalFormTrustLevel())->required(site: $site, binding: $binding, render: $render);
	}//end requiredTrust()

	/**
	 * The published form a binding resolves to today, or null.
	 *
	 * @param array<string, mixed> $binding The binding.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
	 */
	public function publishedForm(array $binding): ?array {
		$typeId = (string)($binding['typeId'] ?? '');
		$audience = (string)($binding['audience'] ?? '');
		if ($typeId === '' || $audience === '') {
			return null;
		}

		$rows = $this->reader->readCollection(
			register: (string)($binding['formRegister'] ?? self::DEFAULT_FORM_REGISTER),
			schema: (string)($binding['formSchema'] ?? self::DEFAULT_FORM_SCHEMA),
			scopeField: 'caseType',
			subjectRef: $typeId,
			organisation: '',
			limit: 20,
			filter: ['audience' => $audience]
		);

		foreach ($rows as $row) {
			if (is_array($row) === true && $this->formAnswersTheBinding(row: $row, binding: $binding, typeId: $typeId, audience: $audience) === true) {
				return $row;
			}
		}

		return null;
	}//end publishedForm()

	/**
	 * Whether one form row is the published form this binding asked for.
	 *
	 * The reader's filter narrows; this re-checks the answer, because
	 * rendering the WRONG audience's form would hand a citizen a supplier's
	 * questions.
	 *
	 * @param array<string, mixed> $row One row the reader returned.
	 * @param array<string, mixed> $binding The binding being resolved.
	 * @param string $typeId The case type the binding names.
	 * @param string $audience The audience the binding names.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
	 */
	private function formAnswersTheBinding(array $row, array $binding, string $typeId, string $audience): bool {
		if (($row['caseType'] ?? '') !== $typeId || ($row['audience'] ?? '') !== $audience) {
			return false;
		}

		if ((string)($row['status'] ?? 'published') !== 'published') {
			return false;
		}

		$name = (string)($binding['formName'] ?? '');

		return ($name === '' || (string)($row['name'] ?? '') === $name);
	}//end formAnswersTheBinding()

	/**
	 * A value when it is an array, otherwise null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<string, mixed>|null
	 */
	private function arrayOrNull(mixed $value): ?array {
		if (is_array($value) === true) {
			return $value;
		}

		return null;
	}//end arrayOrNull()

	/**
	 * The host a URL names, for the card the visitor reads before leaving.
	 *
	 * @param string $url The external form's address.
	 *
	 * @return string
	 */
	private function hostOf(string $url): string {
		$host = parse_url($url, PHP_URL_HOST);
		if (is_string($host) === false) {
			return '';
		}

		return $host;
	}//end hostOf()
}//end class
