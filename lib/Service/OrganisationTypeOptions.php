<?php

/**
 * Portaliq organisation type options
 *
 * The kinds of organisation a portal can name itself, read from the TOOI
 * organisation type scheme in OpenRegister's concept register.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use DateTimeImmutable;
use OCA\Portaliq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the TOOI organisation types through OpenRegister's public vocabulary
 * classes: ConceptRepository for the scheme and its concepts, ConceptLifecycle
 * to leave out a deprecated or expired kind, ConceptHierarchy for the Dutch
 * label. Portaliq keeps no copy of the list (decision D3, TOOI half).
 *
 * The scheme uri is the app setting `organisation_type_scheme`, by default
 * TOOI-kern `overheidsorganisatie`. That scheme also holds office holders
 * (ambtsdrager, functionaris) and organisation parts; those branches are left
 * out by walking their narrower terms (skos:broader). When the concept
 * register does not hold the scheme, nothing is offered and the picker says
 * the list is not installed.
 *
 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
 */
class OrganisationTypeOptions {

	/**
	 * The app setting naming the TOOI organisation type scheme uri.
	 */
	public const SCHEME_KEY = 'organisation_type_scheme';

	/**
	 * TOOI-kern scheme of government organisation types (thesaurus 1.6.0).
	 */
	public const DEFAULT_SCHEME = 'https://identifier.overheid.nl/tooi/def/thes/kern/overheidsorganisatie';

	/**
	 * Roots of the branches in the default scheme that are not organisation
	 * types: ambtsdrager, functionaris and organisatieonderdeel.
	 */
	public const NOT_A_TYPE = [
		'https://identifier.overheid.nl/tooi/def/thes/kern/c_232d2da9',
		'https://identifier.overheid.nl/tooi/def/thes/kern/c_9023bfae',
		'https://identifier.overheid.nl/tooi/def/thes/kern/c_07d0ec18',
	];

	private const REPOSITORY = 'OCA\\OpenRegister\\Service\\Vocabulary\\ConceptRepository';

	private const HIERARCHY = 'OCA\\OpenRegister\\Service\\Vocabulary\\ConceptHierarchy';

	private const LIFECYCLE = 'OCA\\OpenRegister\\Service\\Vocabulary\\ConceptLifecycle';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's vocabulary classes.
	 * @param IAppConfig         $appConfig Holds the scheme uri.
	 * @param LoggerInterface    $logger    Logs an unreadable register.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The offerable kinds of organisation, sorted by label.
	 *
	 * @return array{installed: bool, options: list<array{uri: string, label: string}>}
	 *
	 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
	 */
	public function options(): array {
		$none   = ['installed' => false, 'options' => []];
		$scheme = trim($this->appConfig->getValueString(Application::APP_ID, self::SCHEME_KEY, self::DEFAULT_SCHEME));
		if ($scheme === '') {
			return $none;
		}

		try {
			$repository = $this->container->get(self::REPOSITORY);
			$hierarchy  = $this->container->get(self::HIERARCHY);
			$lifecycle  = $this->container->get(self::LIFECYCLE);
			if ($repository->scheme(schemeUri: $scheme) === null) {
				return $none;
			}

			$concepts = $repository->conceptsOf(schemeUri: $scheme);
		} catch (Throwable $e) {
			$this->logger->info('Portaliq: the organisation types could not be read', ['reason' => $e->getMessage()]);
			return $none;
		}

		$excluded = [];
		foreach (self::NOT_A_TYPE as $root) {
			if (isset($concepts[$root]) === true) {
				$excluded += array_fill_keys($hierarchy->branchUris(rootUri: $root, conceptsByUri: $concepts), true);
			}
		}

		$now     = new DateTimeImmutable();
		$options = [];
		foreach ($concepts as $uri => $concept) {
			if (isset($excluded[(string)$uri]) === true || is_array($concept) === false) {
				continue;
			}

			if ($lifecycle->isOfferable(concept: $concept, at: $now) === false) {
				continue;
			}

			$options[] = ['uri' => (string)$uri, 'label' => $hierarchy->labelOf(concept: $concept, language: 'nl')];
		}

		usort($options, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

		return ['installed' => true, 'options' => $options];
	}//end options()
}//end class
