<?php

/**
 * Contract of GuardianMessageTranslator
 *
 * The public surface of GuardianMessageTranslator that other components call, so they depend
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
 * Contract of GuardianMessageTranslator.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GuardianMessageTranslatorInterface {

	/**
	 * The messages as the reader should see them.
	 *
	 * @param array<int, array<string, mixed>> $messages The thread's messages, already authorised.
	 * @param string $readerRef The reader's own subjectRef.
	 * @param string $language The reader's `messageLanguage`, '' for as written.
	 * @param string $schema The schema the rows live in and are stored back to:
	 *                       `guardianMessage`, or `newsItem` for the news feed.
	 * @param string $titleField A field translated with the body into the same
	 *                           entry as `title` (`title` for news), '' for none.
	 * @param string $textField The field translated into the entry's `text`:
	 *                          `body`, or `title` for a newsletter, whose entry
	 *                          then carries the same text as its `title`.
	 * @param int|null $budget The new translations this request may still ask
	 *                         for, spent in place so several calls share one
	 *                         bound; null for a fresh bound.
	 *
	 * @return array<int, array<string, mixed>> The same messages, each carrying
	 *                                          `translation` when one applies.
	 *
	 * @spec openspec/specs/guardian-message-translation/spec.md#requirement-translation-work-per-request-is-bounded-and-skips-what-needs-none
	 * @spec openspec/specs/guardian-message-translation/spec.md#requirement-the-stored-message-keeps-both-texts-and-the-provenance
	 * @spec openspec/specs/guardian-message-translation/spec.md#requirement-a-news-item-keeps-its-ai-translations-next-to-the-original
	 * @spec openspec/specs/guardian-message-translation/spec.md#requirement-a-news-title-is-translated-with-its-body
	 * @spec openspec/specs/guardian-message-translation/spec.md#requirement-a-newsletter-keeps-its-title-translations-next-to-the-original
	 */
	public function forReader(
		array $messages,
		string $readerRef,
		string $language,
		string $schema = 'guardianMessage',
		string $titleField = '',
		string $textField = 'body',
		?int &$budget = null
	): array;
}//end interface
