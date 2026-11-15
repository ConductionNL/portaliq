<?php

/**
 * News Row Source
 *
 * The OpenRegister read behind NewsFeedReader: every row of a news schema in
 * this app's register, normalised to arrays, and a row's id. Taken out of
 * NewsFeedReader so the audience, translation and archive logic stays under
 * phpmd's class complexity threshold (news-title-and-newsletter-translation).
 * An unavailable ObjectService or a failed read yields no rows, never an error.
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
 * @spec openspec/changes/archive/2026-10-09-news-and-newsletter-authoring/design.md#architecture-overview
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads news rows from OpenRegister.
 *
 * @spec openspec/changes/archive/2026-10-09-news-and-newsletter-authoring/design.md#architecture-overview
 */
class NewsRowSource {
	use ObjectRowNormaliser;
	use ObjectServiceLookup;
	use PagedObjectReads;
	use NormalisedRowReads {
		findAll as public;
		rowId as public;
	}

	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	private const REGISTER = 'portaliq';

	/**
	 * The warning logged when a read fails.
	 */
	private const READ_FAILED = 'Portaliq: news feed read failed';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container, for OpenRegister's ObjectService.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
