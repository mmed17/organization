<template>
	<NcAppNavigation>
		<NcAppNavigationItem
			:active="activeItem === 'organizations'"
			name="Organizations"
			@click="$emit('update:activeItem', 'organizations')">
			<template #icon>
				<AccountGroup :size="20" />
			</template>
		</NcAppNavigationItem>
		<NcAppNavigationItem
			:active="activeItem === 'plans'"
			name="Subscription Plans"
			@click="$emit('update:activeItem', 'plans')">
			<template #icon>
				<CardAccountDetails :size="20" />
			</template>
		</NcAppNavigationItem>
	</NcAppNavigation>
</template>

<script setup lang="ts">
import { NcAppNavigation, NcAppNavigationItem } from '@nextcloud/vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import CardAccountDetails from 'vue-material-design-icons/CardAccountDetails.vue'

defineProps<{
	activeItem: string
}>()

defineEmits(['update:activeItem'])
</script>
