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
import AccountLock from 'vue-material-design-icons/AccountLock.vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import AccountMultipleOutline from 'vue-material-design-icons/AccountMultipleOutline.vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import BellOutline from 'vue-material-design-icons/BellOutline.vue'
import BookAlphabet from 'vue-material-design-icons/BookAlphabet.vue'
import BookOpenVariant from 'vue-material-design-icons/BookOpenVariant.vue'
import BookOpenVariantOutline from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import BullhornOutline from 'vue-material-design-icons/BullhornOutline.vue'
// The three integration-leaf widget icons the manifest names (leaf-integrations):
// Calendar on PortalAccountDetail, ChatOutline on PortalMessageDetail,
// ClipboardText on PortalSubmissionDetail. A leaf widget whose icon is not
// registered draws no glyph at all, which reads as a half-rendered card rather
// than as a missing registration.
import Calendar from 'vue-material-design-icons/Calendar.vue'
import ChartBoxOutline from 'vue-material-design-icons/ChartBoxOutline.vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import ChatOutline from 'vue-material-design-icons/ChatOutline.vue'
import Check from 'vue-material-design-icons/Check.vue'
import CheckboxMarkedOutline from 'vue-material-design-icons/CheckboxMarkedOutline.vue'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import ClipboardListOutline from 'vue-material-design-icons/ClipboardListOutline.vue'
import ClipboardText from 'vue-material-design-icons/ClipboardText.vue'
import Close from 'vue-material-design-icons/Close.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import CursorDefaultClickOutline from 'vue-material-design-icons/CursorDefaultClickOutline.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Email from 'vue-material-design-icons/Email.vue'
import EmailEditOutline from 'vue-material-design-icons/EmailEditOutline.vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import EmailPlusOutline from 'vue-material-design-icons/EmailPlusOutline.vue'
import EmailSearchOutline from 'vue-material-design-icons/EmailSearchOutline.vue'
import EyeLock from 'vue-material-design-icons/EyeLock.vue'
import FileCheckOutline from 'vue-material-design-icons/FileCheckOutline.vue'
import FileDocument from 'vue-material-design-icons/FileDocument.vue'
import FileDocumentEdit from 'vue-material-design-icons/FileDocumentEdit.vue'
import FileDocumentMultipleOutline from 'vue-material-design-icons/FileDocumentMultipleOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import FileFindOutline from 'vue-material-design-icons/FileFindOutline.vue'
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import FormSelect from 'vue-material-design-icons/FormSelect.vue'
import HeartOutline from 'vue-material-design-icons/HeartOutline.vue'
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue'
import History from 'vue-material-design-icons/History.vue'
import Home from 'vue-material-design-icons/Home.vue'
import HomeOutline from 'vue-material-design-icons/HomeOutline.vue'
import Login from 'vue-material-design-icons/Login.vue'
import MapMarkerPath from 'vue-material-design-icons/MapMarkerPath.vue'
import Menu from 'vue-material-design-icons/Menu.vue'
import MessageText from 'vue-material-design-icons/MessageText.vue'
import MessageTextOutline from 'vue-material-design-icons/MessageTextOutline.vue'
import MotionPlayOutline from 'vue-material-design-icons/MotionPlayOutline.vue'
import NewspaperVariantOutline from 'vue-material-design-icons/NewspaperVariantOutline.vue'
import OfficeBuildingOutline from 'vue-material-design-icons/OfficeBuildingOutline.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import PackageVariantClosed from 'vue-material-design-icons/PackageVariantClosed.vue'
import Palette from 'vue-material-design-icons/Palette.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import PowerPlugOutline from 'vue-material-design-icons/PowerPlugOutline.vue'
import Pulse from 'vue-material-design-icons/Pulse.vue'
import ShieldAccount from 'vue-material-design-icons/ShieldAccount.vue'
import ShieldCheckOutline from 'vue-material-design-icons/ShieldCheckOutline.vue'
import ShieldKeyOutline from 'vue-material-design-icons/ShieldKeyOutline.vue'
import ShieldLock from 'vue-material-design-icons/ShieldLock.vue'
import Sitemap from 'vue-material-design-icons/Sitemap.vue'
import StoreOutline from 'vue-material-design-icons/StoreOutline.vue'
import Tablet from 'vue-material-design-icons/Tablet.vue'
import Ticket from 'vue-material-design-icons/Ticket.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import ViewDashboardOutline from 'vue-material-design-icons/ViewDashboardOutline.vue'
import Web from 'vue-material-design-icons/Web.vue'
import WebBox from 'vue-material-design-icons/WebBox.vue'

export default {
	Account,
	AccountBoxOutline,
	AccountKey,
	AccountLock,
	AccountMultiple,
	AccountPlus,
	AlertCircleOutline,
	BellOutline,
	BookAlphabet,
	BookOpenVariant,
	BookOpenVariantOutline,
	Calendar,
	ChartBoxOutline,
	ChartLine,
	ClipboardCheckOutline,
	ClipboardListOutline,
	ChatOutline,
	// The Grant and Refuse row actions of the Access requests page (#797).
	Check,
	ClipboardText,
	Close,
	CursorDefaultClickOutline,
	Email,
	EmailOutline,
	EmailPlusOutline,
	EyeLock,
	FileCheckOutline,
	FileDocument,
	FileDocumentEdit,
	FileDocumentMultipleOutline,
	FileDocumentOutline,
	// The `portalCaseType` schema in lib/Settings/portaliq_register.json. The
	// register named it without registering it here, so the schema's index and
	// detail headers drew no icon at all, not a fallback (rule 3 above).
	FileTreeOutline,
	FolderOutline,
	FormSelect,
	EmailEditOutline,
	EmailSearchOutline,
	HelpCircleOutline,
	FileFindOutline,
	History,
	// The Home page entry of a portal's configuration (portal-home-page, #1183).
	Home,
	// The Sign-in widget on a portal's page (signin-integriq-broker-login).
	Login,
	MapMarkerPath,
	Menu,
	MotionPlayOutline,
	MessageText,
	MessageTextOutline,
	NewspaperVariantOutline,
	OpenInNew,
	Palette,
	// The News page's New news item and Change actions (staff-news-screen).
	Pencil,
	Plus,
	PowerPlugOutline,
	// ADR-077 Tier A: the concept "activity" (the `activityOffer` schema,
	// extracurricular-activity-offer) is drawn with Pulse.
	Pulse,
	ShieldAccount,
	ShieldCheckOutline,
	ShieldKeyOutline,
	ShieldLock,
	Sitemap,
	StoreOutline,
	Ticket,
	HomeOutline,
	OfficeBuildingOutline,
	PackageVariantClosed,
	BullhornOutline,
	HeartOutline,
	AccountMultipleOutline,
	CheckboxMarkedOutline,
	Tablet,
	Download,
	Upload,
	ContentCopy,
	ViewDashboardOutline,
	Web,
	WebBox,
}
