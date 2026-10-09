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
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
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
 * The scheme uri is the app setting `organisation_type_scheme`. Without it, or
 * when the concept register does not hold that scheme, nothing is offered and
 * the picker says the list is not installed.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
 */
class OrganisationTypeOptions {

	/**
	 * The app setting naming the TOOI organisation type scheme uri.
	 */
	public const SCHEME_KEY = 'organisation_type_scheme';

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
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-portal-names-its-organisation-type-from-tooi-req-pia-003
	 */
	public function options(): array {
		$none   = ['installed' => false, 'options' => []];
		$scheme = trim($this->appConfig->getValueString(Application::APP_ID, self::SCHEME_KEY, ''));
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

		$now     = new DateTimeImmutable();
		$options = [];
		foreach ($concepts as $uri => $concept) {
			if (is_array($concept) === false || $lifecycle->isOfferable(concept: $concept, at: $now) === false) {
				continue;
			}

			$options[] = ['uri' => (string)$uri, 'label' => $hierarchy->labelOf(concept: $concept, language: 'nl')];
		}

		usort($options, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

		return ['installed' => true, 'options' => $options];
	}//end options()
}//end class
