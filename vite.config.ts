import { createAppConfig } from '@nextcloud/vite-config'
import { join } from 'path'

export default createAppConfig({
	main: join(import.meta.dirname, 'src', 'main.ts'),
}, {
	config: {
		server: {
			hmr: {
				protocol: 'ws',
			},
		},
	},
})
