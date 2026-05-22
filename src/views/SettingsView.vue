<template>
	<div class="settings-container">
		<div class="settings-header">
			<div class="header-icon-container">
				<Cog :size="32" class="header-icon" />
			</div>
			<div class="header-text">
				<h1>Trial Organization Settings</h1>
				<p>Configure the default limits, storage quotas, and trial durations automatically assigned to newly provisioned trial organizations.</p>
			</div>
		</div>

		<!-- Status banner -->
		<div v-if="statusMessage" :class="['status-banner', statusType]">
			<CheckCircle v-if="statusType === 'success'" :size="20" class="banner-icon" />
			<AlertCircle v-else :size="20" class="banner-icon" />
			<span>{{ statusMessage }}</span>
		</div>

		<div v-if="loading" class="loading-state">
			<NcLoadingIcon :size="48" />
			<p>Fetching latest settings...</p>
		</div>

		<form v-else @submit.prevent="handleSave" class="settings-form">
			<div class="cards-grid">
				<!-- General settings -->
				<div class="settings-card">
					<div class="card-header">
						<Briefcase :size="20" class="card-icon" />
						<h2>General Defaults</h2>
					</div>
					<div class="card-body">
						<div class="form-row">
							<label for="trial-plan-name" class="nc-label-text">Trial Plan Name</label>
							<input
								id="trial-plan-name"
								v-model="form.trial_plan_name"
								type="text"
								class="nc-input"
								required
								placeholder="e.g. Trial Plan" />
						</div>

						<div class="form-row">
							<label for="trial-duration" class="nc-label-text">Trial Duration</label>
							<input
								id="trial-duration"
								v-model="form.trial_duration"
								type="text"
								class="nc-input"
								required
								placeholder="e.g. 7 days" />
							<span class="field-desc">Duration parseable by PHP (e.g. "7 days", "14 days", "1 month").</span>
						</div>
					</div>
				</div>

				<!-- Member & Project Limits -->
				<div class="settings-card">
					<div class="card-header">
						<ScaleBalance :size="20" class="card-icon" />
						<h2>Resource Capacity</h2>
					</div>
					<div class="card-body">
						<div class="form-row">
							<label for="trial-max-members" class="nc-label-text">Max Members</label>
							<input
								id="trial-max-members"
								v-model.number="form.trial_max_members"
								type="number"
								min="1"
								class="nc-input"
								required />
						</div>

						<div class="form-row">
							<label for="trial-max-projects" class="nc-label-text">Max Projects</label>
							<input
								id="trial-max-projects"
								v-model.number="form.trial_max_projects"
								type="number"
								min="1"
								class="nc-input"
								required />
						</div>
					</div>
				</div>

				<!-- Storage Allocation -->
				<div class="settings-card">
					<div class="card-header">
						<Database :size="20" class="card-icon" />
						<h2>Storage Allocation</h2>
					</div>
					<div class="card-body">
						<div class="form-row">
							<label for="trial-shared-storage" class="nc-label-text">Shared Storage per Project (GB)</label>
							<input
								id="trial-shared-storage"
								v-model.number="form.trial_shared_storage_gb"
								type="number"
								min="0"
								step="0.01"
								class="nc-input"
								required />
						</div>

						<div class="form-row">
							<label for="trial-private-storage" class="nc-label-text">Private Storage per User (GB)</label>
							<input
								id="trial-private-storage"
								v-model.number="form.trial_private_storage_gb"
								type="number"
								min="0"
								step="0.01"
								class="nc-input"
								required />
						</div>
					</div>
				</div>
			</div>

			<div class="form-actions">
				<NcButton
					type="primary"
					:disabled="saving"
					submit>
					<template #icon>
						<NcLoadingIcon v-if="saving" :size="20" />
						<ContentSave v-else :size="20" />
					</template>
					{{ saving ? 'Saving Changes...' : 'Save Settings' }}
				</NcButton>
			</div>
		</form>
	</div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { confirmPassword } from '@nextcloud/password-confirmation'

import Cog from 'vue-material-design-icons/Cog.vue'
import Briefcase from 'vue-material-design-icons/Briefcase.vue'
import Database from 'vue-material-design-icons/Database.vue'
import ScaleBalance from 'vue-material-design-icons/ScaleBalance.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'

const loading = ref(true)
const saving = ref(false)

const form = ref({
	trial_plan_name: '',
	trial_duration: '',
	trial_max_members: 3,
	trial_max_projects: 1,
	trial_shared_storage_gb: 0.1,
	trial_private_storage_gb: 0,
})

const statusMessage = ref('')
const statusType = ref<'success' | 'error'>('success')

const fetchSettings = async () => {
	loading.value = true
	try {
		const response = await axios.get(generateOcsUrl('apps/organization/admin/settings/trial'))
		const data = response.data.ocs.data
		form.value = {
			trial_plan_name: data.planName || 'Trial Plan',
			trial_duration: data.duration || '7 days',
			trial_max_members: data.maxMembers ?? 3,
			trial_max_projects: data.maxProjects ?? 1,
			trial_shared_storage_gb: parseFloat(((data.sharedStoragePerProject || 0) / 1073741824).toFixed(3)),
			trial_private_storage_gb: parseFloat(((data.privateStoragePerUser || 0) / 1073741824).toFixed(3)),
		}
	} catch (error: any) {
		console.error('Failed to fetch settings', error)
		statusType.value = 'error'
		statusMessage.value = 'Could not load trial settings.'
	} finally {
		loading.value = false
	}
}

const handleSave = async () => {
	saving.value = true
	statusMessage.value = ''
	try {
		await confirmPassword()
		const response = await axios.put(generateOcsUrl('apps/organization/admin/settings/trial'), form.value)
		if (response.data?.ocs?.meta?.status === 'ok') {
			statusType.value = 'success'
			statusMessage.value = 'Trial settings saved successfully.'
		} else {
			statusType.value = 'error'
			statusMessage.value = response.data?.ocs?.meta?.message || 'Failed to save settings.'
		}
	} catch (error: any) {
		console.error('Failed to save settings', error)
		statusType.value = 'error'
		statusMessage.value = error.response?.data?.ocs?.meta?.message || 'Failed to save settings. Password confirmation may have failed.'
	} finally {
		saving.value = false
	}
}

onMounted(() => {
	fetchSettings()
})
</script>

<style scoped>
.settings-container {
	padding: 32px 40px;
	max-width: 1200px;
	margin: 0 auto;
	box-sizing: border-box;
	min-height: 100%;
}

.settings-header {
	display: flex;
	align-items: center;
	gap: 20px;
	margin-bottom: 32px;
}

.header-icon-container {
	background: color-mix(in srgb, var(--color-primary) 12%, transparent);
	color: var(--color-primary);
	border: 1px solid color-mix(in srgb, var(--color-primary) 30%, transparent);
	border-radius: var(--border-radius-large);
	padding: 12px;
	display: flex;
	align-items: center;
	justify-content: center;
}

.header-text h1 {
	margin: 0;
	font-size: 1.6rem;
	font-weight: 700;
	color: var(--color-main-text);
}

.header-text p {
	margin: 6px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.95rem;
	line-height: 1.5;
}

.loading-state {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 80px 0;
	color: var(--color-text-maxcontrast);
	gap: 16px;
}

.status-banner {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 16px 20px;
	border-radius: var(--border-radius-large);
	margin-bottom: 28px;
	font-weight: 500;
}

.status-banner.success {
	background-color: var(--color-success-light);
	color: var(--color-success-hover);
	border: 1px solid color-mix(in srgb, var(--color-success) 20%, transparent);
}

.status-banner.error {
	background-color: var(--color-error-light);
	color: var(--color-error-hover);
	border: 1px solid color-mix(in srgb, var(--color-error) 20%, transparent);
}

.banner-icon {
	flex-shrink: 0;
}

.cards-grid {
	display: grid;
	grid-template-columns: 1fr;
	gap: 28px;
	margin-bottom: 32px;
}

@media (min-width: 900px) {
	.cards-grid {
		grid-template-columns: 1fr 1fr;
	}
}

.settings-card {
	background-color: var(--color-background-translucent);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 24px;
	box-shadow: 0 2px 6px rgba(0, 0, 0, 0.02);
	transition: box-shadow 0.2s ease, border-color 0.2s ease;
}

.settings-card:hover {
	box-shadow: 0 6px 16px rgba(0, 0, 0, 0.04);
	border-color: color-mix(in srgb, var(--color-primary) 25%, var(--color-border));
}

.card-header {
	display: flex;
	align-items: center;
	gap: 10px;
	margin-bottom: 20px;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 12px;
}

.card-icon {
	color: var(--color-primary);
	display: flex;
	align-items: center;
}

.card-header h2 {
	margin: 0;
	font-size: 1.15rem;
	font-weight: 700;
	color: var(--color-main-text);
}

.card-body {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.form-row {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.nc-label-text {
	font-weight: 600;
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
}

.nc-input {
	width: 100%;
	padding: 10px 12px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.95rem;
	transition: border-color 0.2s, box-shadow 0.2s;
	box-sizing: border-box;
}

.nc-input:focus {
	border-color: var(--color-primary);
	outline: none;
	box-shadow: 0 0 0 2px color-mix(in srgb, var(--color-primary) 20%, transparent);
}

.field-desc {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	margin-top: 2px;
}

.form-actions {
	display: flex;
	justify-content: flex-start;
	border-top: 1px solid var(--color-border);
	padding-top: 24px;
}
</style>
