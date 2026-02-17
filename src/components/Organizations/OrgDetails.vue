<template>
	<NcAppContentDetails>
		<div class="details-panel">
			<NcLoadingIcon v-if="loading" :size="48" class="details-loading-icon" />

			<!-- Header -->
			<div class="details-header">
				<div class="header-main">
					<NcAvatar
						:display-name="organization.displayname"
						:size="80"
						:disable-tooltip="true"
						class="header-avatar" />
					<div class="header-info">
						<h2>{{ organization.displayname }}</h2>
						<p class="header-subtitle">
							<span class="id-badge">ID: {{ organization.id }}</span>
						</p>
					</div>
				</div>
				<div class="header-actions">
					<div :class="['status-chip', organization.subscription.status]">
						{{ organization.subscription.status }}
					</div>
					<NcButton
						v-if="canManageMembers"
						type="tertiary"
						@click="$emit('edit-organization')">
						<template #icon>
							<Pencil :size="16" />
						</template>
						Edit
					</NcButton>
				</div>
			</div>

			<!-- KPI Section -->
			<div class="kpi-grid">
				<!-- Card 1: Plan Info -->
				<div class="kpi-card">
					<div class="stat-header">
						<div class="stat-icon plan">
							<CardAccountDetails :size="20" />
						</div>
						<span class="stat-title">Current Plan</span>
					</div>
					<div class="stat-value text-only">
						{{ organization.subscription.planName || 'Custom' }}
					</div>
					<div class="stat-meta">
						Expires: {{ organization.subscription.endedAt ? organization.subscription.endedAt.split(' ')[0] : 'Never' }}
					</div>
				</div>

				<!-- Card 2: Members -->
				<div class="kpi-card clickable" @click="$emit('manage-members')">
					<div class="stat-header">
						<div class="stat-icon members">
							<AccountGroup :size="20" />
						</div>
						<span class="stat-title">Members</span>
					</div>
					<div class="stat-value">
						{{ members.length }} <span class="stat-total">/ {{ maxMembers }}</span>
					</div>
					<div class="progress-bar-container">
						<div
							class="progress-bar members"
							:style="{ width: Math.min((members.length / maxMembers) * 100, 100) + '%' }">
						</div>
					</div>
				</div>

				<!-- Card 3: Projects -->
				<div class="kpi-card">
					<div class="stat-header">
						<div class="stat-icon projects">
							<Briefcase :size="20" />
						</div>
						<span class="stat-title">Projects Limit</span>
					</div>
					<div class="stat-value">
						{{ organization.subscription.maxProjects }}
					</div>
					<div class="progress-bar-container">
						<div class="progress-bar projects" style="width: 100%"></div>
					</div>
				</div>
			</div>

			<!-- Info Section -->
			<div class="info-grid">
				<!-- Block 1: Contact Person -->
				<div class="info-block">
					<div class="card-header">
						<h3>Contact Person</h3>
					</div>
					<div class="info-rows">
						<div class="info-row">
							<span class="label">Name</span>
							<span class="value">{{ contactFullName }}</span>
						</div>
						<div class="info-row">
							<span class="label">Email</span>
							<span class="value">
								<a v-if="organization.contactEmail" :href="'mailto:' + organization.contactEmail">
									{{ organization.contactEmail }}
								</a>
								<span v-else class="text-muted">Not set</span>
							</span>
						</div>
						<div class="info-row">
							<span class="label">Phone</span>
							<span class="value">
								<a v-if="organization.contactPhone" :href="'tel:' + organization.contactPhone">
									{{ organization.contactPhone }}
								</a>
								<span v-else class="text-muted">Not set</span>
							</span>
						</div>
					</div>
				</div>

				<!-- Block 2: Subscription Details -->
				<div class="info-block">
					<div class="card-header">
						<h3>Subscription Details</h3>
					</div>
					<div class="info-rows">
						<div class="info-row">
							<span class="label">Subscription ID</span>
							<span class="value monospace">{{ organization.subscription.id }}</span>
						</div>
						<div class="info-row">
							<span class="label">Status</span>
							<span class="value capitalize">{{ organization.subscription.status }}</span>
						</div>
						<div class="info-row">
							<span class="label">Start Date</span>
							<span class="value">{{ organization.subscription.startedAt }}</span>
						</div>
						<div class="info-row">
							<span class="label">End Date</span>
							<span class="value">{{ organization.subscription.endedAt || 'No end date' }}</span>
						</div>
					</div>
				</div>

				<!-- Block 3: Organization Settings -->
				<div class="info-block">
					<div class="card-header">
						<h3>Organization Settings</h3>
					</div>
					<div class="info-rows">
						<div class="info-row">
							<span class="label">Organization ID</span>
							<span class="value monospace">{{ organization.id }}</span>
						</div>
						<div class="info-row">
							<span class="label">Permissions</span>
							<div class="value tags">
								<span v-if="organization.canAdd" class="tag success">Add users</span>
								<span v-else class="tag error">Cannot add users</span>
								<span v-if="organization.canRemove" class="tag success">Remove users</span>
								<span v-else class="tag error">Cannot remove users</span>
							</div>
						</div>
					</div>
				</div>

				<!-- Block 4: Storage Quotas -->
				<div class="info-block">
					<div class="card-header">
						<h3>Storage Quotas</h3>
						<NcLoadingIcon v-if="loading" :size="16" />
					</div>
					<div class="info-rows">
						<div class="info-row">
							<span class="label">Shared per Project</span>
							<span class="value">
								{{ organization.plan ? formatFileSize(organization.plan.sharedStoragePerProject || 0) : 'Loading...' }}
							</span>
						</div>
						<div class="info-row">
							<span class="label">Private per User</span>
							<span class="value">
								{{ organization.plan ? formatFileSize(organization.plan.privateStoragePerUser || 0) : 'Loading...' }}
							</span>
						</div>
					</div>
				</div>
			</div>

			<!-- Members Section Preview -->
			<div class="members-section">
				<div class="section-header-bar">
					<div class="section-title">
						<AccountGroup :size="24" />
						<h3>Members</h3>
						<span class="member-badge">{{ members.length }}</span>
					</div>
					<NcButton
						v-if="canManageMembers"
						type="primary"
						@click="$emit('manage-members')">
						<template #icon>
							<AccountPlus :size="16" />
						</template>
						Manage Members
					</NcButton>
				</div>

				<div class="members-preview">
					<div v-if="members.length === 0" class="empty-members">
						<AccountGroup :size="32" />
						<p>No members yet</p>
						<NcButton
							v-if="canManageMembers"
							type="secondary"
							@click="$emit('manage-members')">
							Add Members
						</NcButton>
					</div>
					<div v-else class="members-avatars">
						<div
							v-for="member in displayedMembers"
							:key="member.uid"
							class="member-avatar-item"
							:title="member.displayName">
							<NcAvatar
								:display-name="member.displayName"
								:size="44"
								:disable-tooltip="true" />
							<span v-if="member.role === 'admin'" class="admin-indicator">Admin</span>
						</div>
						<div v-if="members.length > 5" class="more-members" @click="$emit('manage-members')">
							<span>+{{ members.length - 5 }}</span>
						</div>
					</div>
				</div>
			</div>
		</div>
	</NcAppContentDetails>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import {
	NcAppContentDetails,
	NcLoadingIcon,
	NcAvatar,
	NcButton,
} from '@nextcloud/vue'

import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import Briefcase from 'vue-material-design-icons/Briefcase.vue'
import CardAccountDetails from 'vue-material-design-icons/CardAccountDetails.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'

const props = defineProps<{
	organization: any
	loading: boolean
	canManageMembers: boolean
	members: any[]
}>()

const emit = defineEmits(['edit-organization', 'manage-members', 'members-updated', 'organization-updated'])

const contactFullName = computed(() => {
	const first = props.organization.contactFirstName || ''
	const last = props.organization.contactLastName || ''
	const full = `${first} ${last}`.trim()
	return full || 'Not set'
})

const maxMembers = computed(() => Number(props.organization.subscription?.maxMembers || 0))

const displayedMembers = computed(() => {
	return props.members.slice(0, 5)
})

const formatFileSize = (bytes: number) => {
	if (bytes === 0) return '0 B'
	const k = 1024
	const sizes = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']
	const i = Math.floor(Math.log(bytes) / Math.log(k))
	return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
}
</script>

<style scoped>
.details-loading-icon {
	position: absolute;
	top: 50%;
	left: 50%;
	transform: translate(-50%, -50%);
	z-index: 100;
}

.details-panel {
	position: relative;
	padding: 24px;
	height: 100%;
	overflow-y: auto;
	box-sizing: border-box;
}

.details-header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin-bottom: 32px;
	padding-bottom: 24px;
	border-bottom: 1px solid var(--color-border);
}

.header-main {
	display: flex;
	align-items: center;
	gap: 20px;
}

.header-info h2 {
	margin: 0 0 4px;
	font-size: 1.8rem;
	font-weight: bold;
}

.header-subtitle {
	margin: 0;
	color: var(--color-text-light);
}

.id-badge {
	font-family: monospace;
	background-color: var(--color-background-dark);
	padding: 2px 6px;
	border-radius: 4px;
	font-size: 0.85em;
}

.header-actions {
	display: flex;
	align-items: center;
	gap: 12px;
}

.status-chip {
	padding: 6px 12px;
	border-radius: 16px;
	font-weight: bold;
	text-transform: capitalize;
	font-size: 0.9rem;
}

.status-chip.active {
	background-color: var(--color-success);
	color: var(--color-main-background);
}

.status-chip.expired {
	background-color: var(--color-error);
	color: var(--color-main-background);
}

.status-chip.cancelled {
	background-color: var(--color-warning);
	color: var(--color-main-background);
}

/* KPI Grid */
.kpi-grid {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 20px;
	margin-bottom: 32px;
}

.kpi-card {
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 20px;
	display: flex;
	flex-direction: column;
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
	transition: all 0.2s ease;
}

.kpi-card.clickable {
	cursor: pointer;
}

.kpi-card.clickable:hover {
	border-color: var(--color-primary);
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
	transform: translateY(-2px);
}

.stat-header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 16px;
}

.stat-icon {
	width: 36px;
	height: 36px;
	border-radius: 8px;
	display: flex;
	align-items: center;
	justify-content: center;
	color: var(--color-main-background);
}

.stat-icon.members { background-color: var(--color-primary); }
.stat-icon.projects { background-color: #8e44ad; }
.stat-icon.plan { background-color: #2c3e50; }

.stat-title {
	font-weight: 600;
	color: var(--color-text-light);
	font-size: 0.9rem;
}

.stat-value {
	font-size: 1.8rem;
	font-weight: bold;
	margin-bottom: 12px;
	display: flex;
	align-items: baseline;
	gap: 4px;
}

.stat-value.text-only {
	font-size: 1.4rem;
}

.stat-total {
	font-size: 1rem;
	color: var(--color-text-lighter);
	font-weight: normal;
}

.stat-meta {
	font-size: 0.85rem;
	color: var(--color-text-light);
}

/* Progress Bar */
.progress-bar-container {
	height: 6px;
	background-color: var(--color-background-dark);
	border-radius: 3px;
	overflow: hidden;
}

.progress-bar {
	height: 100%;
	border-radius: 3px;
	transition: width 0.3s ease;
}

.progress-bar.members { background-color: var(--color-primary); }
.progress-bar.projects { background-color: #8e44ad; }

/* Info Grid */
.info-grid {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 24px;
}

.info-block {
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	overflow: hidden;
}

.card-header {
	padding: 16px 20px;
	background-color: var(--color-background-hover);
	border-bottom: 1px solid var(--color-border);
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.card-header h3 {
	margin: 0;
	font-size: 1.1rem;
	font-weight: 600;
}

.info-rows {
	padding: 8px 0;
}

.info-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px 20px;
	border-bottom: 1px solid var(--color-border-light);
}

.info-row:last-child {
	border-bottom: none;
}

.info-row .label {
	color: var(--color-text-light);
	font-size: 0.95rem;
}

.info-row .value {
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.value.monospace {
	font-family: monospace;
	font-size: 0.9em;
}

.value.capitalize {
	text-transform: capitalize;
}

.tags {
	display: flex;
	gap: 8px;
}

.tag {
	padding: 2px 8px;
	border-radius: 4px;
	font-size: 0.8rem;
	font-weight: 600;
}

.tag.success {
	background-color: var(--color-success-light);
	color: var(--color-success-hover);
}

.tag.error {
	background-color: var(--color-error-light);
	color: var(--color-error-hover);
}

.text-muted {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

/* Members Section */
.members-section {
	margin-top: 24px;
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	overflow: hidden;
}

.section-header-bar {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 16px 20px;
	background-color: var(--color-background-hover);
	border-bottom: 1px solid var(--color-border);
}

.section-title {
	display: flex;
	align-items: center;
	gap: 12px;
}

.section-title h3 {
	margin: 0;
	font-size: 1.1rem;
	font-weight: 600;
}

.member-badge {
	padding: 2px 10px;
	background-color: var(--color-primary);
	color: white;
	font-size: 0.85rem;
	font-weight: 600;
	border-radius: 999px;
}

.members-preview {
	padding: 20px;
}

.empty-members {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12px;
	padding: 40px;
	color: var(--color-text-maxcontrast);
}

.empty-members p {
	margin: 0;
	font-size: 1rem;
}

.members-avatars {
	display: flex;
	align-items: center;
	gap: 12px;
}

.member-avatar-item {
	position: relative;
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 4px;
}

.admin-indicator {
	font-size: 0.65rem;
	padding: 2px 6px;
	background-color: var(--color-success-light);
	color: var(--color-success);
	border-radius: 4px;
	font-weight: 600;
	text-transform: uppercase;
}

.more-members {
	width: 44px;
	height: 44px;
	display: flex;
	align-items: center;
	justify-content: center;
	background-color: var(--color-background-dark);
	border-radius: 50%;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	transition: all 0.2s ease;
}

.more-members:hover {
	background-color: var(--color-primary);
	color: white;
}

@media (max-width: 768px) {
	.kpi-grid,
	.info-grid {
		grid-template-columns: 1fr;
	}
	
	.details-header {
		flex-direction: column;
		gap: 16px;
		align-items: flex-start;
	}

	.header-actions {
		width: 100%;
		justify-content: space-between;
	}

	.members-avatars {
		flex-wrap: wrap;
	}
}
</style>
