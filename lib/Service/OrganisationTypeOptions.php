<?php

/**
 * Portaliq Organisation Type Options
 *
 * The kinds of organisation a portal can say it is run by, read from the TOOI
 * organisation type scheme in OpenRegister's concept register, the one copy of
 * the TOOI value lists. Nothing is bundled here: without the scheme there is
 * nothing to choose. Also settles a portal on save: a type must be a concept
 * of the scheme, and its label is stored beside it.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Kernel\Contracts\OrganisationTypeOptionsInterface;
use OCP\IAppConfig;
use OCP\IL10N;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the organisation types from the concept register and settles a portal's choice.
 *
 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
 */
class OrganisationTypeOptions implements OrganisationTypeOptionsInterface {

	/**
	 * The app-config key holding the scheme's uri, for an instance whose
	 * concept register carries the list under another uri.
	 */
	public const SCHEME_KEY = 'organisationTypeScheme';

	/**
	 * The portal field holding the installed scheme's uuid, for the type picker's filter.
	 *
	 * @var string
	 */
	public const SCHEME_ID_FIELD = 'organisationTypeSchemeId';

	/**
	 * The uri the TOOI organisation type list is expected under.
	 */
	public const DEFAULT_SCHEME = 'https://identifier.overheid.nl/tooi/set/scw_organisatietypen';

	/**
	 * OpenRegister's concept register reader, looked up lazily so portaliq
	 * stays installable without OpenRegister.
	 */
	private const REPOSITORY = 'OCA\\OpenRegister\\Service\\Vocabulary\\ConceptRepository';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For the lazy OpenRegister lookup.
	 * @param IAppConfig         $appConfig Holds an instance's own scheme uri.
	 * @param IL10N              $l10n      For the sentences.
	 * @param LoggerInterface    $logger    Notes an unreadable concept register.
	 *
	 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The types to choose from, or none and a sentence when the list is not installed.
	 *
	 * Deprecated concepts are not offered; a portal that holds one keeps it.
	 *
	 * @return array{installed: bool, message: string|null, options: list<array{value: string, label: string}>}
	 *
	 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
	 */
	public function options(): array {
		$concepts = $this->concepts();
		if ($concepts === []) {
			return ['installed' => false, 'message' => $this->notInstalled(), 'options' => []];
		}

		$options = [];
		foreach ($concepts as $uri => $concept) {
			if (($concept['deprecated'] ?? false) !== true) {
				$options[] = ['value' => (string)$uri, 'label' => self::label(concept: $concept)];
			}
		}

		usort($options, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

		return ['installed' => true, 'message' => null, 'options' => $options];
	}//end options()

	/**
	 * Settle a portal's organisation type on save.
	 *
	 * An empty type removes the type and its label. A type of the scheme stores the
	 * concept's label beside it. Anything else is refused, so no free text is
	 * stored; when the list is not installed only the stored type is kept.
	 * Every save also stores the installed scheme's uuid as
	 * `organisationTypeSchemeId`, which the details form's type picker filters
	 * the concepts on (a concept names its scheme by uuid, and the uuid differs
	 * per instance); without the scheme the field is removed and the picker
	 * offers nothing.
	 *
	 * @param array<string, mixed> $portal The portal as it is saved.
	 * @param array<string, mixed> $old    The portal as it was stored, or [] when new.
	 *
	 * @return array{refusal: string|null, portal: array<string, mixed>}
	 *
	 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
	 */
	public function settle(array $portal, array $old): array {
		$portal = $this->withSchemeId(portal: $portal);
		$type = trim((string)($portal['organisationType'] ?? ''));
		if ($type === '') {
			unset($portal['organisationType'], $portal['organisationTypeLabel']);
			return ['refusal' => null, 'portal' => $portal];
		}

		$concept = ($this->concepts()[$type] ?? null);
		if ($concept !== null) {
			return ['refusal' => null, 'portal' => array_merge($portal, ['organisationTypeLabel' => self::label(concept: $concept)])];
		}

		if ($type === (string)($old['organisationType'] ?? '')) {
			return ['refusal' => null, 'portal' => array_merge($portal, ['organisationTypeLabel' => ($old['organisationTypeLabel'] ?? null)])];
		}

		return ['refusal' => $this->l10n->t('Choose the organisation type from the TOOI list.'), 'portal' => $portal];
	}//end settle()

	/**
	 * The scheme's uri: the instance's own, else the TOOI default.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
	 */
	public function schemeUri(): string {
		$uri = trim($this->appConfig->getValueString(app: AppIdentity::APP_ID, key: self::SCHEME_KEY, default: ''));
		if ($uri === '') {
			return self::DEFAULT_SCHEME;
		}

		return $uri;
	}//end schemeUri()

	/**
	 * The portal with the installed scheme's uuid, or without the field when the scheme is missing.
	 *
	 * @param array<string, mixed> $portal The portal as it is saved.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
	 */
	private function withSchemeId(array $portal): array {
		unset($portal[self::SCHEME_ID_FIELD]);
		try {
			$scheme = $this->container->get(self::REPOSITORY)->scheme(schemeUri: $this->schemeUri());
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: the organisation type scheme could not be read', ['reason' => $e->getMessage()]);
			return $portal;
		}

		$uuid = trim((string)($scheme['@uuid'] ?? ''));
		if ($uuid !== '') {
			$portal[self::SCHEME_ID_FIELD] = $uuid;
		}

		return $portal;
	}//end withSchemeId()

	/**
	 * The scheme's concepts keyed by uri, or [] when the scheme or OpenRegister is missing.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function concepts(): array {
		try {
			$repository = $this->container->get(self::REPOSITORY);
			if ($repository->scheme(schemeUri: $this->schemeUri()) === null) {
				return [];
			}

			return $repository->conceptsOf(schemeUri: $this->schemeUri());
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: the organisation types could not be read', ['reason' => $e->getMessage()]);
			return [];
		}
	}//end concepts()

	/**
	 * The sentence the picker shows when the list is not installed.
	 *
	 * @return string
	 */
	private function notInstalled(): string {
		return $this->l10n->t('The TOOI list of organisation types is not installed in the concept register, so there is nothing to choose yet.');
	}//end notInstalled()

	/**
	 * A concept's Dutch preferred label, else its uri.
	 *
	 * @param array<string, mixed> $concept The concept.
	 *
	 * @return string
	 */
	private static function label(array $concept): string {
		$label = ($concept['prefLabel']['nl'] ?? null);
		if (is_string($label) === true && trim($label) !== '') {
			return trim($label);
		}

		return (string)($concept['uri'] ?? '');
	}//end label()
}//end class
