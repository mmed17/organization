<template>
	<NcAppContentList title="Organizations">
		<template #actions>
			<NcActions>
				<NcActionButton @click="refresh">
					<template #icon>
						<Refresh :size="20" />
					</template>
					Refresh
				</NcActionButton>
				<NcActionButton @click="openCreateModal">
					<template #icon>
						<Plus :size="20" />
					</template>
					Create Organization
				</NcActionButton>
			</NcActions>
		</template>

		<div class="list-header">
			<NcTextField
				v-model="searchQuery"
				:show-trailing-button="false"
				label="Search organizations..."
				class="search-field"
				full-width>
				<template #leading-icon>
					<Magnify :size="20" />
				</template>
			</NcTextField>
		</div>

		<div v-if="loading" class="state-container">
			<NcLoadingIcon :size="64" />
			<p>Loading organizations...</p>
		</div>

		<div v-else-if="filteredOrganizations.length === 0" class="state-container">
			<div class="empty-icon-bg">
				<AccountGroup :size="48" />
			</div>
			<h3>No organizations found</h3>
			<p>Get started by creating a new organization.</p>
			<NcButton type="primary" @click="openCreateModal">
				<template #icon>
					<Plus :size="20" />
				</template>
				Create Organization
			</NcButton>
		</div>

		<template v-else>
			<NcListItem
				v-for="org in filteredOrganizations"
				:key="org.organizationId"
				:title="org.displayname"
				:force-display-actions="true">
				<template #icon>
					<NcAvatar
						:display-name="org.displayname"
						:size="44"
						:disable-tooltip="true" />
				</template>
				
				<template #subtitle>
					<div class="org-details">
						<span class="detail-item" title="Members">
							<AccountGroup :size="14" /> {{ org.usercount }} / {{ org.subscription.maxMembers }}
						</span>
						<span class="detail-item" title="Projects">
							<Briefcase :size="14" /> {{ org.subscription.maxProjects }}
						</span>
						<span class="detail-item plan-badge">
							{{ org.subscription.planName || 'Custom Plan' }}
						</span>
						<span :class="['status-badge', org.subscription.status]">
							{{ org.subscription.status }}
						</span>
					</div>
				</template>

				<template #actions>
					<NcActions>
						<NcActionButton @click="editOrg(org)">
							<template #icon>
								<Pencil :size="20" />
							</template>
							Edit Organization
						</NcActionButton>
						<NcActionButton @click="manageSubscription(org)">
							<template #icon>
								<CardAccountDetails :size="20" />
							</template>
							Manage Subscription
						</NcActionButton>
					</NcActions>
				</template>
			</NcListItem>
		</template>

		<NcModal
			v-if="showCreateModal"
			title="Create New Organization"
			size="large"
			@close="closeCreateModal">
			<div class="modal-content">
				<div class="form-grid">
					<!-- Basic Info -->
					<div class="form-section">
						<h3>Basic Info</h3>
						<NcTextField
							v-model="newOrg.displayname"
							label="Organization Name"
							:error="!!errors.displayname"
							:helper-text="errors.displayname"
							required />
						<NcTextField
							v-model="newOrg.groupid"
							label="Group ID (Slug)"
							helper-text="Unique identifier (e.g., 'acme-corp')"
							:error="!!errors.groupid"
							required />
					</div>

					<!-- Subscription Plan -->
					<div class="form-section">
						<h3>Subscription Plan</h3>
						<div class="form-row">
							<label class="nc-label-text">Plan</label>
							<select v-model="newOrg.planId" class="nc-select-native" @change="onPlanChange">
								<option :value="null">Custom Plan</option>
								<option v-for="plan in plans" :key="plan.id" :value="plan.id">
									{{ plan.name }}
								</option>
							</select>
						</div>
						<div class="form-row">
							<label class="nc-label-text">Validity</label>
							<select v-model="newOrg.validity" class="nc-select-native">
								<option value="1 month">1 Month</option>
								<option value="1 year">1 Year</option>
							</select>
						</div>
					</div>

					<!-- Limits -->
					<div class="form-section">
						<h3>Limits & Quotas</h3>
						<div class="grid-2">
							<NcTextField
								v-model.number="newOrg.memberLimit"
								label="Max Members"
								type="number" />
							<NcTextField
								v-model.number="newOrg.projectsLimit"
								label="Max Projects"
								type="number" />
							<NcTextField
								v-model.number="newOrg.sharedStoragePerProject"
								label="Shared Storage (bytes)"
								type="number" />
							<NcTextField
								v-model.number="newOrg.privateStorage"
								label="Private Storage (bytes)"
								type="number" />
						</div>
					</div>
				</div>

				<div class="modal-actions">
					<NcButton @click="closeCreateModal">Cancel</NcButton>
					<NcButton type="primary" @click="createOrganization" :disabled="submitting">
						<template #icon v-if="submitting">
							<NcLoadingIcon :size="20" />
						</template>
						{{ submitting ? 'Creating...' : 'Create Organization' }}
					</NcButton>
				</div>
			</div>
		</NcModal>
	</NcAppContentList>
</template>

<script setup lang="ts">
import { ref, onMounted, computed, reactive } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import {
	NcAppContentList,
	NcListItem,
	NcActions,
	NcActionButton,
	NcTextField,
	NcLoadingIcon,
	NcAvatar,
	NcModal,
	NcButton,
} from '@nextcloud/vue'

import Refresh from 'vue-material-design-icons/Refresh.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import Briefcase from 'vue-material-design-icons/Briefcase.vue'
import CardAccountDetails from 'vue-material-design-icons/CardAccountDetails.vue'

const organizations = ref([])
const plans = ref([])
const loading = ref(true)
const submitting = ref(false)
const searchQuery = ref('')
const showCreateModal = ref(false)

const errors = reactive({
	displayname: '',
	groupid: ''
})

const defaultNewOrg = {
	displayname: '',
	groupid: '',
	validity: '1 year',
	planId: null,
	memberLimit: 10,
	projectsLimit: 5,
	sharedStoragePerProject: 1073741824, // 1GB
	privateStorage: 5368709120, // 5GB
	price: 0,
	currency: 'EUR'
}

const newOrg = reactive({ ...defaultNewOrg })

const filteredOrganizations = computed(() => {
	if (!searchQuery.value) return organizations.value
	const query = searchQuery.value.toLowerCase()
	return organizations.value.filter(org => 
		org.displayname.toLowerCase().includes(query) || 
		org.id.toLowerCase().includes(query)
	)
})

const fetchOrganizations = async () => {
	loading.value = true
	try {
		const response = await axios.get(generateOcsUrl('apps/organization/organizations'))
		organizations.value = response.data.ocs.data.organizations
	} catch (error) {
		console.error('Failed to fetch organizations', error)
	} finally {
		loading.value = false
	}
}

const fetchPlans = async () => {
	try {
		const response = await axios.get(generateOcsUrl('apps/organization/plans'))
		plans.value = response.data.ocs.data.plans
	} catch (error) {
		console.error('Failed to fetch plans', error)
	}
}

const refresh = () => {
	fetchOrganizations()
	fetchPlans()
}

const openCreateModal = () => {
	Object.assign(newOrg, defaultNewOrg)
	errors.displayname = ''
	errors.groupid = ''
	showCreateModal.value = true
}

const closeCreateModal = () => {
	showCreateModal.value = false
}

const onPlanChange = () => {
	if (newOrg.planId) {
		const plan = plans.value.find(p => p.id === newOrg.planId)
		if (plan) {
			// Populate limits from plan if available
			// Note: The plan object structure depends on the API. 
			// Assuming plan has these fields based on common patterns, 
			// but if not, this is just a helper.
            // Based on PlanMapper, it might have limits.
		}
	}
}

const createOrganization = async () => {
	errors.displayname = !newOrg.displayname ? 'Name is required' : ''
	errors.groupid = !newOrg.groupid ? 'Group ID is required' : ''
	
	if (errors.displayname || errors.groupid) return

	submitting.value = true
	try {
		await axios.post(generateOcsUrl('apps/organization/organizations'), newOrg)
		showCreateModal.value = false
		fetchOrganizations()
	} catch (error) {
		console.error('Failed to create organization', error)
		// Handle specific API errors here if needed
	} finally {
		submitting.value = false
	}
}

const editOrg = (org) => {
	console.log('Edit organization', org)
	// TODO: Implement edit
}

const manageSubscription = (org) => {
	console.log('Manage subscription', org)
	// TODO: Implement subscription management
}

onMounted(() => {
	fetchOrganizations()
	fetchPlans()
})
</script>

<style scoped>
.list-header {
	padding: 10px 20px;
	border-bottom: 1px solid var(--color-border);
}

.search-field {
	max-width: 400px;
}

.state-container {
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: 60px 20px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.empty-icon-bg {
	background-color: var(--color-background-dark);
	border-radius: 50%;
	padding: 20px;
	margin-bottom: 20px;
	display: flex;
	align-items: center;
	justify-content: center;
}

.org-details {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-top: 4px;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
	flex-wrap: wrap;
}

.detail-item {
	display: flex;
	align-items: center;
	gap: 4px;
}

.plan-badge {
	background-color: var(--color-background-dark);
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 0.85em;
	font-weight: 500;
}

.status-badge {
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 0.85em;
	text-transform: capitalize;
	font-weight: 600;
}

.status-badge.active {
	background-color: var(--color-success);
	color: var(--color-main-text);
}

.status-badge.expired {
	background-color: var(--color-error);
	color: var(--color-text-light);
}

.status-badge.cancelled {
	background-color: var(--color-warning);
	color: var(--color-main-text);
}

.modal-content {
	padding: 0 10px;
	display: flex;
	flex-direction: column;
	gap: 24px;
}

.form-grid {
	display: flex;
	flex-direction: column;
	gap: 24px;
}

.form-section h3 {
	margin: 0 0 16px 0;
	font-size: 1.1em;
	font-weight: 600;
	color: var(--color-main-text);
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 8px;
}

.form-row {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 16px;
}

.nc-label-text {
	font-weight: 600;
	color: var(--color-main-text);
}

.nc-select-native {
	width: 100%;
	padding: 10px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}

.grid-2 {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}

.modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: 12px;
	margin-top: 10px;
	padding-top: 20px;
	border-top: 1px solid var(--color-border);
}

@media (max-width: 600px) {
	.grid-2 {
		grid-template-columns: 1fr;
	}
}
</style>
