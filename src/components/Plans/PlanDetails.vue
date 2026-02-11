<template>
	<NcAppContentDetails>
		<div class="details-panel">
			<NcLoadingIcon v-if="loading" :size="48" class="details-loading-icon" />

			<!-- Header -->
			<div class="details-header">
				<div class="header-main">
					<NcAvatar
						:display-name="plan.name"
						:size="80"
						:disable-tooltip="true"
						class="header-avatar" />
					<div class="header-info">
						<h2>{{ plan.name }}</h2>
						<p class="header-subtitle">
							<span class="id-badge">ID: {{ plan.id }}</span>
						</p>
					</div>
				</div>
				<div :class="['status-chip', plan.isPublic ? 'public' : 'private']">
					{{ plan.isPublic ? 'Public' : 'Private' }}
				</div>
			</div>

			<!-- KPI Section -->
			<div class="kpi-grid">
				<!-- Card 1: Members Limit -->
				<div class="kpi-card">
					<div class="stat-header">
						<div class="stat-icon members">
							<AccountGroup :size="20" />
						</div>
						<span class="stat-title">Members Limit</span>
					</div>
					<div class="stat-value">
						{{ plan.maxMembers }}
					</div>
					<div class="progress-bar-container">
						<div class="progress-bar members" style="width: 100%"></div>
					</div>
				</div>

				<!-- Card 2: Projects Limit -->
				<div class="kpi-card">
					<div class="stat-header">
						<div class="stat-icon projects">
							<Briefcase :size="20" />
						</div>
						<span class="stat-title">Projects Limit</span>
					</div>
					<div class="stat-value">
						{{ plan.maxProjects }}
					</div>
					<div class="progress-bar-container">
						<div class="progress-bar projects" style="width: 100%"></div>
					</div>
				</div>

				<!-- Card 3: Pricing -->
				<div class="kpi-card">
					<div class="stat-header">
						<div class="stat-icon pricing">
							<CurrencyUsd :size="20" />
						</div>
						<span class="stat-title">Pricing</span>
					</div>
					<div class="stat-value text-only">
						{{ plan.price ? `${plan.price} ${plan.currency}` : 'Free' }}
					</div>
					<div class="stat-meta">
						{{ plan.price ? 'Per month' : 'No cost' }}
					</div>
				</div>
			</div>

			<!-- Info Section -->
			<div class="info-grid">
				<!-- Block 1: Storage Quotas -->
				<div class="info-block">
					<div class="card-header">
						<h3>Storage Quotas</h3>
					</div>
					<div class="info-rows">
						<div class="info-row">
							<span class="label">Shared per Project</span>
							<span class="value">{{ formatFileSize(plan.sharedStoragePerProject) }}</span>
						</div>
						<div class="info-row">
							<span class="label">Private per User</span>
							<span class="value">{{ formatFileSize(plan.privateStoragePerUser) }}</span>
						</div>
					</div>
				</div>

				<!-- Block 2: Plan Settings -->
				<div class="info-block">
					<div class="card-header">
						<h3>Plan Settings</h3>
					</div>
					<div class="info-rows">
						<div class="info-row">
							<span class="label">Plan ID</span>
							<span class="value monospace">{{ plan.id }}</span>
						</div>
						<div class="info-row">
							<span class="label">Visibility</span>
							<span class="value">{{ plan.isPublic ? 'Public' : 'Private' }}</span>
						</div>
						<div class="info-row">
							<span class="label">Subscriptions Using</span>
							<span class="value">{{ plan.subscriptionCount ?? 0 }}</span>
						</div>
					</div>
				</div>
			</div>

			<!-- Actions -->
			<div class="details-actions">
				<NcButton type="primary" @click="$emit('edit')">
					<template #icon><Pencil :size="20" /></template>
					Edit Plan
				</NcButton>
				<NcButton type="error" @click="handleDelete" :disabled="deleting">
					<template #icon>
						<NcLoadingIcon v-if="deleting" :size="20" />
						<Delete v-else :size="20" />
					</template>
					Delete Plan
				</NcButton>
			</div>
		</div>
	</NcAppContentDetails>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import {
	NcAppContentDetails,
	NcLoadingIcon,
	NcAvatar,
	NcButton,
} from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { confirmPassword } from '@nextcloud/password-confirmation'

import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import Briefcase from 'vue-material-design-icons/Briefcase.vue'
import CurrencyUsd from 'vue-material-design-icons/CurrencyUsd.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/Delete.vue'

const props = defineProps<{
	plan: any
	loading: boolean
}>()

const emit = defineEmits(['edit', 'deleted'])

const deleting = ref(false)

const formatFileSize = (bytes: number) => {
	if (bytes === 0) return '0 B'
	const k = 1024
	const sizes = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']
	const i = Math.floor(Math.log(bytes) / Math.log(k))
	return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
}

const handleDelete = async () => {
	try {
		await confirmPassword()
		deleting.value = true
		await axios.delete(generateOcsUrl('apps/organization/plans/' + props.plan.id))
		emit('deleted')
	} catch (error) {
		if (error !== 'cancelled') {
			console.error('Failed to delete plan', error)
		}
	} finally {
		deleting.value = false
	}
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

.status-chip {
	padding: 6px 12px;
	border-radius: 16px;
	font-weight: bold;
	text-transform: capitalize;
	font-size: 0.9rem;
}

.status-chip.public {
	background-color: var(--color-success);
	color: var(--color-main-background);
}

.status-chip.private {
	background-color: var(--color-text-lighter);
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
.stat-icon.pricing { background-color: #27ae60; }

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
	margin-bottom: 32px;
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

.details-actions {
	display: flex;
	gap: 12px;
	margin-top: 32px;
	padding-top: 24px;
	border-top: 1px solid var(--color-border);
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
}
</style>
