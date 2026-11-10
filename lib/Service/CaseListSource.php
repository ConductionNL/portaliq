<?php

/**
 * Portaliq Case List Source
 *
 * One declared case collection and the app that contributes it, the source
 * every listed case row names.
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
 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * A case collection with its app.
 *
 * @spec openspec/specs/portal-identity-space/spec.md#requirement-my-cases-lists-what-the-claim-scopes-req-pis-004
 */
final class CaseListSource {

	/**
	 * The contributing app.
	 *
	 * @var string
	 */
	public readonly string $appId;

	/**
	 * The contributing app's label, its id when it has none.
	 *
	 * @var string
	 */
	public readonly string $label;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $contribution The app's contribution.
	 * @param array<string, mixed> $collection   The declared case collection.
	 */
	public function __construct(
		array $contribution,
		public readonly array $collection,
	) {
		$this->appId = (string)($contribution['app'] ?? '');
		$this->label = (string)($contribution['label'] ?? $this->appId);
	}//end __construct()

	/**
	 * Where a listed row came from.
	 *
	 * @return array{appId: string, label: string, register: string, schema: string, collection: string}
	 *
	 * @spec openspec/specs/portal-identity-space/spec.md#requirement-my-cases-lists-what-the-claim-scopes-req-pis-004
	 */
	public function stamp(): array {
		return [
			'appId' => $this->appId,
			'label' => $this->label,
			'register' => (string)($this->collection['register'] ?? ''),
			'schema' => (string)($this->collection['schema'] ?? ''),
			'collection' => (string)($this->collection['id'] ?? ''),
		];
	}//end stamp()
}//end class
