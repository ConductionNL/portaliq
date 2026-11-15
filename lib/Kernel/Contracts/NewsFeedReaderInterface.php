<?php

/**
 * Contract of NewsFeedReader
 *
 * The public surface of NewsFeedReader that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Portaliq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Contracts;

/**
 * Contract of NewsFeedReader.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface NewsFeedReaderInterface {

	/**
	 * Every SENT (`sentAt` not null) `newsletter` in the guardian's own
	 * resolved audience, most recently sent first.
	 *
	 * Each newsletter carries `items`: the news items it references that are
	 * published and in the reader's audience, translated and photo-gated
	 * exactly as the feed serves them, so the archive shows the same
	 * translation and notice as the News page. A newsletter's own title is
	 * translated into its own `translations` entry and carried as
	 * `translation`, the shape a news item has. Titles and items share the
	 * one per-request bound of new translations, titles first.
	 *
	 * @param string $subjectRef The guardian's own subjectRef.
	 * @param string $language The reader's `messageLanguage`, '' for as written.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-newsletter-composes-existing-news-items-with-an-archive
	 * @spec openspec/specs/guardian-message-translation/spec.md#requirement-the-newsletter-archive-shows-its-items-as-the-news-page-does
	 * @spec openspec/specs/guardian-message-translation/spec.md#requirement-a-newsletter-keeps-its-title-translations-next-to-the-original
	 */
	public function archiveFor(string $subjectRef, string $language = ''): array;

	/**
	 * Every PUBLISHED `newsItem` in the calling guardian's own resolved
	 * audience. An empty/unresolvable audience yields an empty feed, never an
	 * error (fail-closed empty).
	 *
	 * With a `$language` the body of each item is shown in it: a stored AI
	 * translation is reused, at most three new ones are made per request and
	 * stored on the item, and the item carries `translation` (decision D24).
	 * Translation runs on the STORED rows, before the photo consent gate
	 * redacts the reader's copy, so a stored translation never drops a photo.
	 *
	 * @param string $subjectRef The guardian's own subjectRef.
	 * @param string $language The reader's `messageLanguage`, '' for as written.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-newsitem-is-authored-per-school-group-or-child-and-tracks-read-receipts
	 * @spec openspec/specs/guardian-message-translation/spec.md#requirement-a-news-item-keeps-its-ai-translations-next-to-the-original
	 * @spec openspec/specs/guardian-message-translation/spec.md#requirement-a-news-title-is-translated-with-its-body
	 */
	public function feedFor(string $subjectRef, string $language = ''): array;
}//end interface
