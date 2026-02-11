import { createApp } from 'vue'
import App from './App.vue'
import { generateUrl } from '@nextcloud/router'

// Standard Nextcloud globals setup
// eslint-disable-next-line camelcase
// @ts-ignore
__webpack_public_path__ = generateUrl('/apps/organization/js/')

const app = createApp(App)
app.mount('#content')
