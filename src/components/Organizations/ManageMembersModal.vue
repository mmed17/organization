<template>
	<NcModal
		v-if="show"
		title="Manage Members"
		size="large"
		class="manage-members-modal"
		@close="closeModal">
		<div class="modal-content">
			<!-- Header Info -->
			<div class="modal-header">
				<div class="header-info">
					<h2>Members</h2>
					<div class="member-stats">
						<span class="stat">
							<span class="stat-value">{{ members.length }}</span>
							<span class="stat-label">of {{ maxMembers }} members</span>
						</span>
						<div class="seats-indicator" :class="{ 'low': seatsLeft <= 3 }">
							<SeatIcon :size="16" />
							<span>{{ seatsLeft }} seats available</span>
						</div>
					</div>
				</div>
			</div>

			<!-- Tab Navigation -->
			<div class="tab-navigation">
				<button
					class="tab-btn"
					:class="{ active: activeTab === 'current' }"
					@click="activeTab = 'current'">
					<AccountGroup :size="18" />
					<span>Current Members</span>
					<span class="tab-badge">{{ members.length }}</span>
				</button>
				<button
					v-if="canManageMembers && seatsLeft > 0"
					class="tab-btn"
					:class="{ active: activeTab === 'add' }"
					@click="activeTab = 'add'">
					<AccountPlus :size="18" />
					<span>Add Existing</span>
				</button>
				<button
					v-if="canManageMembers && seatsLeft > 0"
					class="tab-btn"
					:class="{ active: activeTab === 'create' }"
					@click="activeTab = 'create'">
					<AccountAdd :size="18" />
					<span>Create Account</span>
				</button>
			</div>

			<!-- Tab Content: Current Members -->
			<div v-if="activeTab === 'current'" class="tab-content">
				<div v-if="members.length === 0" class="empty-state">
					<AccountGroup :size="48" />
					<h3>No members yet</h3>
					<p v-if="canManageMembers && seatsLeft > 0">
						Add members from the "Add Existing" tab or create new accounts.
					</p>
				</div>

				<div v-else class="members-list">
					<div
						v-for="member in members"
						:key="member.uid"
						class="member-card">
						<div class="member-avatar">
							<NcAvatar
								:display-name="member.displayName"
								:size="40"
								:disable-tooltip="true" />
						</div>
						<div class="member-info">
							<div class="member-name">{{ member.displayName }}</div>
							<div class="member-details">
								<span class="member-uid">{{ member.uid }}</span>
								<span v-if="member.email" class="member-email">• {{ member.email }}</span>
							</div>
						</div>
						<div class="member-role">
							<span :class="['role-badge', member.role]">
								{{ member.role }}
							</span>
						</div>
						<button
							v-if="canManageMembers && member.role !== 'admin'"
							class="remove-btn"
							type="button"
							@click="removeMember(member.uid)"
							:disabled="loading">
							<Close :size="16" />
						</button>
					</div>
				</div>
			</div>

			<!-- Tab Content: Add Existing User -->
			<div v-if="activeTab === 'add'" class="tab-content">
				<div class="add-section">
					<div class="search-box">
						<Magnify :size="20" class="search-icon" />
						<input
							v-model="searchQuery"
							type="text"
							class="search-input"
							placeholder="Search users by name or email..."
							@input="debouncedSearch"
							@focus="showSearchResults = true"
							ref="searchInput" />
						<NcLoadingIcon v-if="searching" :size="20" class="search-loading" />
					</div>

					<!-- Search Results -->
					<div v-if="showSearchResults && searchResults.length > 0" class="search-results" v-click-outside="closeSearchResults">
						<div
							v-for="user in searchResults"
							:key="user.uid"
							class="search-result-item"
							@click="selectUser(user)">
							<NcAvatar
								:display-name="user.displayName"
								:size="32"
								:disable-tooltip="true" />
							<div class="result-info">
								<div class="result-name">{{ user.displayName }}</div>
								<div class="result-details">
									{{ user.uid }}
									<span v-if="user.email">• {{ user.email }}</span>
								</div>
							</div>
							<Plus :size="16" class="result-add-icon" />
						</div>
					</div>

					<div v-if="showSearchResults && !searching && searchQuery && searchResults.length === 0" class="search-no-results">
						<AlertCircle :size="20" />
						<span>No users found matching "{{ searchQuery }}"</span>
					</div>

					<!-- Selected User Preview -->
					<div v-if="selectedUser" class="selected-user-preview">
						<div class="preview-header">
							<span class="preview-label">Selected User</span>
							<button class="clear-btn" @click="clearSelectedUser">
								<Close :size="14" />
							</button>
						</div>
						<div class="preview-content">
							<NcAvatar
								:display-name="selectedUser.displayName"
								:size="48"
								:disable-tooltip="true" />
							<div class="preview-info">
								<div class="preview-name">{{ selectedUser.displayName }}</div>
								<div class="preview-details">{{ selectedUser.uid }}</div>
								<div v-if="selectedUser.email" class="preview-email">{{ selectedUser.email }}</div>
							</div>
						</div>
						<NcButton
							type="primary"
							class="add-btn"
							@click="addSelectedUser"
							:disabled="loading">
							<template #icon>
								<NcLoadingIcon v-if="loading" :size="20" />
								<Plus v-else :size="20" />
							</template>
							{{ loading ? 'Adding...' : 'Add to Organization' }}
						</NcButton>
					</div>
				</div>
			</div>

			<!-- Tab Content: Create Account -->
			<div v-if="activeTab === 'create'" class="tab-content">
				<div class="create-account-section">
					<div class="form-intro">
						<AccountAdd :size="32" class="intro-icon" />
						<h3>Create New Account</h3>
						<p>Create a new Nextcloud user account and automatically add them to this organization.</p>
					</div>

					<div class="create-form">
						<div class="form-row">
							<NcTextField
								v-model="newAccount.userId"
								label="User ID *"
								placeholder="username"
								:helper-text="newAccountErrors.userId"
								:error="!!newAccountErrors.userId"
								required>
								<template #leading-icon>
									<Identifier :size="16" />
								</template>
							</NcTextField>
						</div>

						<div class="form-row">
							<NcTextField
								v-model="newAccount.password"
								label="Password *"
								type="password"
								placeholder="Temporary password"
								:helper-text="newAccountErrors.password"
								:error="!!newAccountErrors.password"
								required>
								<template #leading-icon>
									<Lock :size="16" />
								</template>
							</NcTextField>
						</div>

						<div class="form-row grid-2">
							<NcTextField
								v-model="newAccount.displayName"
								label="Display Name"
								placeholder="Full name (optional)">
								<template #leading-icon>
									<Account :size="16" />
								</template>
							</NcTextField>

							<NcTextField
								v-model="newAccount.email"
								label="Email"
								type="email"
								placeholder="email@example.com (optional)">
								<template #leading-icon>
									<Email :size="16" />
								</template>
							</NcTextField>
						</div>

						<NcButton
							type="primary"
							class="create-btn"
							@click="createAccount"
							:disabled="loading || !newAccount.userId.trim() || !newAccount.password.trim()">
							<template #icon>
								<NcLoadingIcon v-if="loading" :size="20" />
								<AccountPlus v-else :size="20" />
							</template>
							{{ loading ? 'Creating...' : 'Create Account & Add' }}
						</NcButton>
					</div>
				</div>
			</div>

			<!-- Member Limit Warning -->
			<div v-if="seatsLeft === 0" class="limit-warning">
				<AlertCircle :size="20" />
				<span>Member limit reached. Upgrade subscription to add more members.</span>
			</div>
		</div>
	</NcModal>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { NcModal, NcTextField, NcButton, NcLoadingIcon, NcAvatar } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { vOnClickOutside as vClickOutside } from '@vueuse/components'

import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import AccountAdd from 'vue-material-design-icons/AccountPlus.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Close from 'vue-material-design-icons/Close.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import SeatIcon from 'vue-material-design-icons/Seat.vue'
import Account from 'vue-material-design-icons/Account.vue'
import Email from 'vue-material-design-icons/Email.vue'
import Lock from 'vue-material-design-icons/Lock.vue'
import Identifier from 'vue-material-design-icons/Identifier.vue'

const props = defineProps<{
	show: boolean
	organization: any | null
	members: any[]
	canManageMembers: boolean
}>()

const emit = defineEmits(['close', 'members-updated'])

const loading = ref(false)
const activeTab = ref('current')
const searchInput = ref<HTMLInputElement | null>(null)

// Search functionality
const searchQuery = ref('')
const searchResults = ref<any[]>([])
const searching = ref(false)
const showSearchResults = ref(false)
const selectedUser = ref<any>(null)
let searchTimeout: ReturnType<typeof setTimeout> | null = null

// New account form
const newAccount = ref({
	userId: '',
	password: '',
	displayName: '',
	email: '',
})

const newAccountErrors = ref({
	userId: '',
	password: '',
})

const maxMembers = computed(() => Number(props.organization?.subscription?.maxMembers || 0))
const seatsLeft = computed(() => Math.max(maxMembers.value - props.members.length, 0))

watch(() => props.show, (val) => {
	if (val) {
		activeTab.value = 'current'
		resetForms()
	}
})

const resetForms = () => {
	searchQuery.value = ''
	searchResults.value = []
	selectedUser.value = null
	showSearchResults.value = false
	newAccount.value = {
		userId: '',
		password: '',
		displayName: '',
		email: '',
	}
	newAccountErrors.value = {
		userId: '',
		password: '',
	}
}

const closeModal = () => {
	emit('close')
}

const closeSearchResults = () => {
	showSearchResults.value = false
}

const debouncedSearch = () => {
	if (searchTimeout) {
		clearTimeout(searchTimeout)
	}

	if (!searchQuery.value.trim()) {
		searchResults.value = []
		return
	}

	searchTimeout = setTimeout(() => {
		performSearch()
	}, 300)
}

const performSearch = async () => {
	const query = searchQuery.value.trim()
	if (!query || !props.organization) return

	searching.value = true
	try {
		const response = await axios.get(
			generateOcsUrl(`apps/organization/organizations/${props.organization.id}/available-users`),
			{ params: { search: query } }
		)
		// Filter out users already in organization
		const existingUids = new Set(props.members.map(m => m.uid))
		searchResults.value = (response.data.ocs.data.users || [])
			.filter((user: any) => !existingUids.has(user.uid))
		showSearchResults.value = true
	} catch (error) {
		console.error('Failed to search users', error)
		searchResults.value = []
	} finally {
		searching.value = false
	}
}

const selectUser = (user: any) => {
	selectedUser.value = user
	showSearchResults.value = false
	searchQuery.value = ''
}

const clearSelectedUser = () => {
	selectedUser.value = null
}

const addSelectedUser = async () => {
	if (!selectedUser.value || !props.organization) return

	loading.value = true
	try {
		const response = await axios.post(
			generateOcsUrl(`apps/organization/organizations/${props.organization.id}/members`),
			{ userId: selectedUser.value.uid }
		)
		emit('members-updated', response.data.ocs.data.members || [])
		selectedUser.value = null
		activeTab.value = 'current'
	} catch (error) {
		console.error('Failed to add member', error)
	} finally {
		loading.value = false
	}
}

const createAccount = async () => {
	// Validate
	newAccountErrors.value.userId = !newAccount.value.userId.trim() ? 'User ID is required' : ''
	newAccountErrors.value.password = !newAccount.value.password.trim() ? 'Password is required' : ''

	if (newAccountErrors.value.userId || newAccountErrors.value.password) return
	if (!props.organization) return

	loading.value = true
	try {
		const response = await axios.post(
			generateOcsUrl(`apps/organization/organizations/${props.organization.id}/users`),
			{
				userId: newAccount.value.userId.trim(),
				password: newAccount.value.password,
				displayName: newAccount.value.displayName.trim() || null,
				email: newAccount.value.email.trim() || null,
			}
		)
		emit('members-updated', response.data.ocs.data.members || [])
		resetForms()
		activeTab.value = 'current'
	} catch (error) {
		console.error('Failed to create account', error)
	} finally {
		loading.value = false
	}
}

const removeMember = async (userId: string) => {
	if (!props.organization) return

	loading.value = true
	try {
		const response = await axios.delete(
			generateOcsUrl(`apps/organization/organizations/${props.organization.id}/members/${encodeURIComponent(userId)}`)
		)
		emit('members-updated', response.data.ocs.data.members || [])
	} catch (error) {
		console.error('Failed to remove member', error)
	} finally {
		loading.value = false
	}
}
</script>

<style scoped>
.modal-content {
	display: flex;
	flex-direction: column;
	gap: 20px;
	padding: 8px 4px;
}

.modal-header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	padding-bottom: 16px;
	border-bottom: 1px solid var(--color-border);
}

.header-info h2 {
	margin: 0 0 8px 0;
	font-size: 1.3rem;
	font-weight: 600;
}

.member-stats {
	display: flex;
	align-items: center;
	gap: 16px;
}

.stat {
	display: flex;
	align-items: baseline;
	gap: 4px;
}

.stat-value {
	font-size: 1.2rem;
	font-weight: 600;
	color: var(--color-primary);
}

.stat-label {
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
}

.seats-indicator {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 4px 12px;
	background-color: var(--color-success-light);
	color: var(--color-success);
	border-radius: 999px;
	font-size: 0.85rem;
	font-weight: 500;
}

.seats-indicator.low {
	background-color: var(--color-warning-light);
	color: var(--color-warning);
}

/* Tab Navigation */
.tab-navigation {
	display: flex;
	gap: 8px;
	padding: 4px;
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius-large);
}

.tab-btn {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 16px;
	border: none;
	background: transparent;
	color: var(--color-text-maxcontrast);
	font-size: 0.95rem;
	font-weight: 500;
	border-radius: var(--border-radius);
	cursor: pointer;
	transition: all 0.2s ease;
}

.tab-btn:hover {
	background-color: var(--color-background-hover);
	color: var(--color-main-text);
}

.tab-btn.active {
	background-color: var(--color-main-background);
	color: var(--color-primary);
	box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.tab-badge {
	padding: 2px 8px;
	background-color: var(--color-primary);
	color: white;
	font-size: 0.75rem;
	border-radius: 999px;
}

/* Tab Content */
.tab-content {
	min-height: 300px;
}

/* Members List */
.empty-state {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 60px 20px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.empty-state h3 {
	margin: 16px 0 8px 0;
	font-size: 1.1rem;
	font-weight: 600;
}

.empty-state p {
	max-width: 300px;
	line-height: 1.5;
}

.members-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.member-card {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px 16px;
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	transition: all 0.2s ease;
}

.member-card:hover {
	border-color: var(--color-primary);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.member-avatar {
	flex-shrink: 0;
}

.member-info {
	flex: 1;
	min-width: 0;
}

.member-name {
	font-weight: 600;
	font-size: 0.95rem;
}

.member-details {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.member-uid {
	font-family: monospace;
	font-size: 0.85em;
}

.role-badge {
	padding: 4px 10px;
	border-radius: 999px;
	font-size: 0.75rem;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.03em;
	background-color: var(--color-background-dark);
}

.role-badge.admin {
	background-color: var(--color-success-light);
	color: var(--color-success);
}

.remove-btn {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 32px;
	height: 32px;
	border: none;
	background-color: transparent;
	color: var(--color-text-maxcontrast);
	border-radius: 50%;
	cursor: pointer;
	transition: all 0.2s ease;
}

.remove-btn:hover {
	background-color: var(--color-error-light);
	color: var(--color-error);
}

.remove-btn:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

/* Add Section */
.add-section {
	padding: 8px 0;
}

.search-box {
	position: relative;
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px 16px;
	background-color: var(--color-main-background);
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius-large);
	transition: border-color 0.2s ease;
}

.search-box:focus-within {
	border-color: var(--color-primary);
}

.search-icon {
	color: var(--color-text-maxcontrast);
}

.search-input {
	flex: 1;
	border: none;
	background: transparent;
	font-size: 1rem;
	color: var(--color-main-text);
	outline: none;
}

.search-input::placeholder {
	color: var(--color-text-maxcontrast);
}

.search-loading {
	color: var(--color-primary);
}

/* Search Results */
.search-results {
	margin-top: 8px;
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	max-height: 280px;
	overflow-y: auto;
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.search-result-item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px 16px;
	cursor: pointer;
	transition: background-color 0.15s ease;
	border-bottom: 1px solid var(--color-border-light);
}

.search-result-item:last-child {
	border-bottom: none;
}

.search-result-item:hover {
	background-color: var(--color-background-hover);
}

.result-info {
	flex: 1;
	min-width: 0;
}

.result-name {
	font-weight: 600;
	font-size: 0.95rem;
}

.result-details {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.result-add-icon {
	color: var(--color-primary);
}

.search-no-results {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 16px;
	color: var(--color-text-maxcontrast);
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius);
	margin-top: 8px;
}

/* Selected User Preview */
.selected-user-preview {
	margin-top: 24px;
	padding: 20px;
	background-color: var(--color-background-translucent);
	border: 2px solid var(--color-primary);
	border-radius: var(--border-radius-large);
}

.preview-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.preview-label {
	font-size: 0.85rem;
	font-weight: 600;
	color: var(--color-primary);
	text-transform: uppercase;
	letter-spacing: 0.05em;
}

.clear-btn {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	border: none;
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	border-radius: 50%;
	cursor: pointer;
	transition: all 0.2s ease;
}

.clear-btn:hover {
	background-color: var(--color-error-light);
	color: var(--color-error);
}

.preview-content {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-bottom: 20px;
}

.preview-info {
	flex: 1;
}

.preview-name {
	font-size: 1.1rem;
	font-weight: 600;
	margin-bottom: 4px;
}

.preview-details {
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
	font-family: monospace;
}

.preview-email {
	font-size: 0.85rem;
	color: var(--color-text-light);
	margin-top: 4px;
}

.add-btn {
	width: 100%;
	justify-content: center;
}

/* Create Account Section */
.create-account-section {
	padding: 8px 0;
}

.form-intro {
	text-align: center;
	padding: 24px 0;
	margin-bottom: 24px;
	border-bottom: 1px solid var(--color-border);
}

.intro-icon {
	color: var(--color-primary);
	margin-bottom: 12px;
}

.form-intro h3 {
	margin: 0 0 8px 0;
	font-size: 1.2rem;
}

.form-intro p {
	color: var(--color-text-maxcontrast);
	max-width: 400px;
	margin: 0 auto;
	line-height: 1.5;
}

.create-form {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.form-row {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.form-row.grid-2 {
	display: grid;
	grid-template-columns: 1fr;
	gap: 16px;
}

@media (min-width: 600px) {
	.form-row.grid-2 {
		grid-template-columns: 1fr 1fr;
	}
}

.create-btn {
	margin-top: 8px;
	width: 100%;
	justify-content: center;
}

/* Limit Warning */
.limit-warning {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 12px 16px;
	background-color: var(--color-warning-light);
	color: var(--color-warning);
	border-radius: var(--border-radius);
	font-size: 0.9rem;
	font-weight: 500;
}

/* Mobile optimizations */
@media (max-width: 600px) {
	.member-stats {
		flex-direction: column;
		align-items: flex-start;
		gap: 8px;
	}

	.tab-btn {
		padding: 8px 12px;
		font-size: 0.85rem;
	}

	.tab-btn span:not(.tab-badge) {
		display: none;
	}
}
</style>
