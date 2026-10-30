<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  PageLayoutEditor: the page's layout editor as the "Indeling" tab of the page
  itself (PtPagina). The page header, pills, breadcrumb and tabs stand above
  it, so the editor draws no title line of its own. It reads the page from the
  route's `id`, which the page detail route carries as the layout route does.

  @spec openspec/specs/portal-page-designer/spec.md#requirement-the-layout-editor-is-a-tab-of-the-page
-->
<template>
	<PageLayoutDesigner embedded data-testid="page-layout-editor" />
</template>

<script>
import PageLayoutDesigner from '../views/PageLayoutDesigner.vue'

export default {
	name: 'PageLayoutEditor',

	components: {
		PageLayoutDesigner,
	},
}
</script>
