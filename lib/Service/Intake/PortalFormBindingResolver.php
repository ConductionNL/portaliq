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
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader Reads the binding and the form.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
	) {
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
	 */
	public function render(array $binding): array {
		$settings = [
			'addressLookup' => (($binding['addressLookup'] ?? false) === true),
			'prefillFromEarlierCases' => (($binding['prefillFromEarlierCases'] ?? false) === true),
			'challenge' => (($binding['challenge'] ?? false) === true),
			'confirmationText' => (string)($binding['confirmationText'] ?? ''),
		];

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
			return [
				'kind' => self::KIND_HOSTED,
				'resolvesToNoForm' => true,
				'reason' => 'no_published_form_for_audience',
				'fields' => [],
				'settings' => $settings,
			];
		}

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
			'fields' => $this->fieldsOf(form: $form),
			'settings' => $settings,
		];
	}//end render()

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

		$name = (string)($binding['formName'] ?? '');
		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			// The reader's filter narrows; the answer is re-checked here,
			// because rendering the WRONG audience's form would hand a citizen
			// a supplier's questions.
			if (($row['caseType'] ?? '') !== $typeId || ($row['audience'] ?? '') !== $audience) {
				continue;
			}

			if ((string)($row['status'] ?? 'published') !== 'published') {
				continue;
			}

			if ($name !== '' && (string)($row['name'] ?? '') !== $name) {
				continue;
			}

			return $row;
		}

		return null;
	}//end publishedForm()

	/**
	 * The form's fields, in the order the form declares, with its presets.
	 *
	 * @param array<string, mixed> $form The published form.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fieldsOf(array $form): array {
		$fields = ($form['fields'] ?? []);
		if (is_array($fields) === false) {
			return [];
		}

		$presets = (array)($form['presets'] ?? []);
		$out = [];
		foreach ($fields as $field) {
			if (is_array($field) === false) {
				continue;
			}

			$name = (string)($field['name'] ?? '');
			if ($name === '') {
				continue;
			}

			if (array_key_exists($name, $presets) === true) {
				$field['preset'] = $presets[$name];
			}

			$out[] = $field;
		}

		usort(
			$out,
			static function (array $first, array $second): int {
				return ((int)($first['order'] ?? 0) <=> (int)($second['order'] ?? 0));
			}
		);

		return $out;
	}//end fieldsOf()

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
