// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Icon registry for portaliq (ADR-077 semantic icon vocabulary).
//
// CnAppNav, CnIcon, CnIndexPage / CnDetailPage headers and empty states resolve
// an `icon` by PascalCase name through the registry that `registerIcons()`
// populates. A name that is not registered renders NO icon in the navigation —
// not a fallback glyph — so this file must cover every `icon` the manifests and
// register files name. Keep it in sync when you add a menu entry.
//
// Generated from the app's own manifests; every name is verified to exist in
// vue-material-design-icons.

import Account from 'vue-material-design-icons/Account.vue'
import AccountBoxOutline from 'vue-material-design-icons/AccountBoxOutline.vue'
import AccountKey from 'vue-material-design-icons/AccountKey.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import BellOutline from 'vue-material-design-icons/BellOutline.vue'
import BookAlphabet from 'vue-material-design-icons/BookAlphabet.vue'
import BookOpenVariant from 'vue-material-design-icons/BookOpenVariant.vue'
import BookOpenVariantOutline from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import ChartBoxOutline from 'vue-material-design-icons/ChartBoxOutline.vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import Email from 'vue-material-design-icons/Email.vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import FileCheckOutline from 'vue-material-design-icons/FileCheckOutline.vue'
import FileDocument from 'vue-material-design-icons/FileDocument.vue'
import FileDocumentMultipleOutline from 'vue-material-design-icons/FileDocumentMultipleOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import FormSelect from 'vue-material-design-icons/FormSelect.vue'
import History from 'vue-material-design-icons/History.vue'
import MapMarkerPath from 'vue-material-design-icons/MapMarkerPath.vue'
import Menu from 'vue-material-design-icons/Menu.vue'
import MessageTextOutline from 'vue-material-design-icons/MessageTextOutline.vue'
import Palette from 'vue-material-design-icons/Palette.vue'
import ShieldCheckOutline from 'vue-material-design-icons/ShieldCheckOutline.vue'
import ShieldKeyOutline from 'vue-material-design-icons/ShieldKeyOutline.vue'
import ShieldLock from 'vue-material-design-icons/ShieldLock.vue'
import Sitemap from 'vue-material-design-icons/Sitemap.vue'
import StoreOutline from 'vue-material-design-icons/StoreOutline.vue'
import Ticket from 'vue-material-design-icons/Ticket.vue'
import ViewDashboardOutline from 'vue-material-design-icons/ViewDashboardOutline.vue'
import Web from 'vue-material-design-icons/Web.vue'
import WebBox from 'vue-material-design-icons/WebBox.vue'

export default {
	Account,
	AccountBoxOutline,
	AccountKey,
	AlertCircleOutline,
	BellOutline,
	BookAlphabet,
	BookOpenVariant,
	BookOpenVariantOutline,
	ChartBoxOutline,
	ChartLine,
	Email,
	EmailOutline,
	FileCheckOutline,
	FileDocument,
	FileDocumentMultipleOutline,
	FileDocumentOutline,
	FolderOutline,
	FormSelect,
	History,
	MapMarkerPath,
	Menu,
	MessageTextOutline,
	Palette,
	ShieldCheckOutline,
	ShieldKeyOutline,
	ShieldLock,
	Sitemap,
	StoreOutline,
	Ticket,
	ViewDashboardOutline,
	Web,
	WebBox,
}
