<template>
	<NcAppContent
		:show-details="!!selectedOrganization"
		@update:showDetails="onShowDetailsUpdate">
		<template #list>
			<OrgList
				:organizations="organizations"
				:selected-id="selectedOrganization?.id"
				:loading="loading"
				:can-create="permissions.isGlobalAdmin"
				@select="selectOrganization"
				@create="openCreateModal" />
		</template>

		<template #default>
			<OrgDetails
				v-if="selectedOrganization"
				:organization="selectedOrganization"
				:loading="loadingDetails"
				:can-manage-members="permissions.isGlobalAdmin || permissions.isOrganizationAdmin"
				:members="selectedOrganization.members || []"
				@edit-organization="showEditModal = true"
				@manage-members="showMembersModal = true"
				@members-updated="onMembersUpdated"
				@organization-updated="onOrganizationUpdated" />

			<div v-else class="empty-content">
				<div class="icon-bg">
					<AccountGroup :size="64" />
				</div>
				<h2>Select an organization</h2>
				<p>Select an organization from the list to view details.</p>
			</div>
		</template>
	</NcAppContent>

	<CreateOrgModal
		:show="showCreateModal"
		:plans="plans"
		@close="showCreateModal = false"
		@success="onOrgCreated" />

	<EditOrganizationModal
		:show="showEditModal"
		:organization="selectedOrganization"
		@close="showEditModal = false"
		@saved="onOrganizationUpdated" />

	<ManageMembersModal
		:show="showMembersModal"
		:organization="selectedOrganization"
		:members="selectedOrganization?.members || []"
		:can-manage-members="permissions.isGlobalAdmin || permissions.isOrganizationAdmin"
		@close="showMembersModal = false"
		@members-updated="onMembersUpdated" />
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { NcAppContent } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'

import OrgList from '../components/Organizations/OrgList.vue'
import OrgDetails from '../components/Organizations/OrgDetails.vue'
import CreateOrgModal from '../components/Organizations/CreateOrgModal.vue'
import EditOrganizationModal from '../components/Organizations/EditOrganizationModal.vue'
import ManageMembersModal from '../components/Organizations/ManageMembersModal.vue'

const organizations = ref([])
const plans = ref([])
const loading = ref(true)
const showCreateModal = ref(false)
const showEditModal = ref(false)
const showMembersModal = ref(false)
const selectedOrganization = ref(null)
const loadingDetails = ref(false)

const props = defineProps<{
	permissions: {
		isGlobalAdmin: boolean
		isOrganizationAdmin: boolean
		organizationId: number | null
	}
}>()

const fetchOrganizations = async () => {
	loading.value = true
	try {
		const response = await axios.get(generateOcsUrl('apps/organization/organizations'))
		organizations.value = response.data.ocs.data.organizations
		if (!props.permissions.isGlobalAdmin && organizations.value.length === 1) {
			selectOrganization(organizations.value[0])
		}
	} catch (error) {
		console.error('Failed to fetch organizations', error)
	} finally {
		loading.value = false
	}
}

const fetchPlans = async () => {
	if (!props.permissions.isGlobalAdmin) {
		plans.value = []
		return
	}

	try {
		const response = await axios.get(generateOcsUrl('apps/organization/plans'))
		plans.value = response.data.ocs.data.plans
	} catch (error) {
		console.error('Failed to fetch plans', error)
	}
}

const openCreateModal = () => {
	if (!props.permissions.isGlobalAdmin) {
		return
	}
	showCreateModal.value = true
}

const onOrgCreated = () => {
	fetchOrganizations()
}

const onShowDetailsUpdate = (show: boolean) => {
	if (!show) {
		selectedOrganization.value = null
	}
}

const selectOrganization = async (org: any) => {
	selectedOrganization.value = { ...org }
	loadingDetails.value = true

	try {
		const response = await axios.get(generateOcsUrl('apps/organization/organizations/' + org.id))
		const data = response.data.ocs.data

		selectedOrganization.value = {
			...org,
			...data.organization,
			usercount: org.usercount,
			subscription: {
				...org.subscription,
				...data.subscription,
			},
			plan: data.plan,
			members: data.members || [],
		}
	} catch (error) {
		console.error('Failed to fetch organization details', error)
	} finally {
		loadingDetails.value = false
	}
}

const onMembersUpdated = (members: any[]) => {
	if (!selectedOrganization.value) {
		return
	}

	selectedOrganization.value = {
		...selectedOrganization.value,
		members,
		usercount: members.length,
	}

	organizations.value = organizations.value.map((org: any) => org.id === selectedOrganization.value.id
		? { ...org, usercount: members.length }
		: org)
}

const onOrganizationUpdated = (updatedOrg: any) => {
	if (!selectedOrganization.value) {
		return
	}

	selectedOrganization.value = {
		...selectedOrganization.value,
		...updatedOrg,
		displayname: updatedOrg.name || updatedOrg.displayname || selectedOrganization.value.displayname,
	}

	organizations.value = organizations.value.map((org: any) => org.id === selectedOrganization.value.id
		? {
			...org,
			displayname: selectedOrganization.value.displayname,
		}
		: org)
}

onMounted(() => {
	fetchOrganizations()
	if (props.permissions.isGlobalAdmin) {
		fetchPlans()
	}
})
</script>

<style scoped>
.empty-content {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	height: 100%;
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding: 20px;
}

.icon-bg {
	background-color: var(--color-background-dark);
	border-radius: 50%;
	padding: 30px;
	margin-bottom: 24px;
	display: flex;
	align-items: center;
	justify-content: center;
}

h2 {
	font-size: 1.5rem;
	margin-bottom: 12px;
	font-weight: bold;
}

p {
	max-width: 400px;
	line-height: 1.6;
	color: var(--color-text-light);
}
</style>
