<?php

/**
 * The plain page's widgets that read data: the publication search and detail, and the public records.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Cms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

/**
 * Renders a widget that reads public data for the plain page, or answers
 * null when the widget is not one of them or has nothing to show here, so
 * the page says the widget needs JavaScript.
 *
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-the-server-renders-a-plain-version-of-every-public-page-req-shj-002
 */
class PlainReadWidgets {

	/**
	 * The widgets rendered here.
	 *
	 * @var array<int, string>
	 */
	public const WIDGETS = ['federatedSearch', 'publicationDetail', 'publicRecords'];

	/**
	 * Constructor.
	 *
	 * @param PlainPublicationSearch $search  The publication search.
	 * @param PlainPublicationDetail $detail  The publication detail.
	 * @param PlainPublicRecords     $records The public records.
	 */
	public function __construct(
		private readonly PlainPublicationSearch $search,
		private readonly PlainPublicationDetail $detail,
		private readonly PlainPublicRecords $records,
	) {
	}//end __construct()

	/**
	 * One widget as a block, or null.
	 *
	 * @param string               $key     The widget key.
	 * @param array<string, mixed> $props   The props.
	 * @param PlainContext         $context The request.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
	 */
	public function block(string $key, array $props, PlainContext $context): ?array {
		return match ($key) {
			'federatedSearch' => $this->search->build(
				props: $props,
				route: $context->route,
				query: $context->query,
				page: $context->page,
				address: $context->address,
				locale: $context->locale
			),
			'publicationDetail' => $this->detailBlock(props: $props, context: $context),
			'publicRecords' => $this->records->build(props: $props, context: $context),
			default => null,
		};
	}//end block()

	/**
	 * The publication detail widget, for the route's trailing segment. On the
	 * detail page itself, with no id, there is nothing to read and the widget
	 * says it needs JavaScript.
	 *
	 * @param array<string, mixed> $props   The props.
	 * @param PlainContext         $context The request.
	 *
	 * @return array<string, mixed>|null
	 */
	private function detailBlock(array $props, PlainContext $context): ?array {
		if ($context->routeParam === '') {
			return null;
		}

		return $this->detail->build(props: $props, id: $context->routeParam, fullUrl: $context->fullUrl(), locale: $context->locale);
	}//end detailBlock()
}//end class
