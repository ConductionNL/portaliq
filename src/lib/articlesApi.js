// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * Thin client for the article endpoints the interface calls.
 *
 * Kept apart from the components the same way `mailingListApi.js` is: the
 * endpoint paths live in one place, and the two sections
 * (`ArticleContentSection`, `ArticleUsageSection`) plus the edit modal and
 * any later picker all share it rather than duplicating the fetch shape.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * One page of articles, newest first.
 *
 * @param {number} limit Page size.
 * @return {Promise<Array<object>>} The articles.
 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-portaliq
 */
export async function fetchArticles(limit = 100) {
	const { data } = await axios.get(
		generateUrl(
			`/apps/portaliq/api/articles?limit=${encodeURIComponent(limit)}`,
		),
	)
	return data?.data || []
}

/**
 * Where one article has been used.
 *
 * @param {string} articleId The article id.
 * @return {Promise<{data: Array<object>, counts: object}>} The envelope.
 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-reports-where-it-has-been-used
 */
export async function fetchArticleUsages(articleId) {
	const { data } = await axios.get(
		generateUrl(`/apps/portaliq/api/articles/${articleId}/usages`),
	)
	return data
}

/**
 * Create a new article as a draft.
 *
 * @param {object} payload The article fields.
 * @return {Promise<object>} The created article.
 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
 */
export async function createArticle(payload) {
	const { data } = await axios.post(
		generateUrl('/apps/portaliq/api/articles'),
		payload,
	)
	return data?.article || null
}

/**
 * Change an article's editable fields.
 *
 * @param {string} articleId The article id.
 * @param {object} payload The changed fields.
 * @return {Promise<object>} The updated article.
 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
 */
export async function updateArticle(articleId, payload) {
	const { data } = await axios.patch(
		generateUrl(`/apps/portaliq/api/articles/${articleId}`),
		payload,
	)
	return data?.article || null
}

/**
 * Publish an article, stamping the publication moment once.
 *
 * @param {string} articleId The article id.
 * @return {Promise<object>} The published article.
 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle
 */
export async function publishArticle(articleId) {
	const { data } = await axios.post(
		generateUrl(`/apps/portaliq/api/articles/${articleId}/publish`),
	)
	return data?.article || null
}

/**
 * Archive an article.
 *
 * @param {string} articleId The article id.
 * @return {Promise<object>} The archived article.
 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle
 */
export async function archiveArticle(articleId) {
	const { data } = await axios.post(
		generateUrl(`/apps/portaliq/api/articles/${articleId}/archive`),
	)
	return data?.article || null
}

/**
 * Apply one of the lifecycle's non-stamping transitions (submit for review,
 * return to draft, restore).
 *
 * @param {string} articleId The article id.
 * @param {string} transition The declared transition name.
 * @return {Promise<object>} The transitioned article.
 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle
 */
export async function transitionArticle(articleId, transition) {
	const { data } = await axios.post(
		generateUrl(`/apps/portaliq/api/articles/${articleId}/transition`),
		{ transition },
	)
	return data?.article || null
}
