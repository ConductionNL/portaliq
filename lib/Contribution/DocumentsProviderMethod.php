<?php

/**
 * Portaliq Documents Provider Method
 *
 * A case collection may declare `documents: {label, provider}`: the method on
 * its app's own portal provider that returns the documents a resident may see
 * on one case (cases-documents-on-the-case, REQ-CDC-001). The app decides what
 * is published; portaliq hands it on. The name is held to the timeline method
 * rule: a plain identifier, never one of the contract's own methods. Anything
 * else drops the key.
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
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
 * @spec openspec/specs/citizen-case-documents/spec.md#requirement-the-case-app-declares-which-documents-a-resident-may-see-req-cdc-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Keeps a well-formed `documents` declaration on a collection.
 *
 * @spec openspec/specs/citizen-case-documents/spec.md#requirement-the-case-app-declares-which-documents-a-resident-may-see-req-cdc-001
 */
class DocumentsProviderMethod {
	/**
	 * Keep `documents: {label, provider}` when portaliq may call the method;
	 * drop the key otherwise. A label that is not text becomes empty.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/citizen-case-documents/spec.md#requirement-the-case-app-declares-which-documents-a-resident-may-see-req-cdc-001
	 */
	public function normalise(array $collection): array {
		if (array_key_exists('documents', $collection) === false) {
			return $collection;
		}

		$declared = $collection['documents'];
		unset($collection['documents']);
		if (is_array($declared) === false || (new TimelineProviderMethod())->accepts(name: ($declared['provider'] ?? null)) === false) {
			return $collection;
		}

		$label = ($declared['label'] ?? '');
		if (is_string($label) === false) {
			$label = '';
		}

		$collection['documents'] = ['label' => $label, 'provider' => $declared['provider']];
		return $collection;
	}//end normalise()
}//end class
