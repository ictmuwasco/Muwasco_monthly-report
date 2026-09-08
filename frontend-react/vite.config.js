import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// The backend Laravel API runs at VITE_API_URL (default http://localhost:8000/api/v1).
// A dedicated port keeps the SPA and API servers independent during development.
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    proxy: {
      // Dev convenience: hit the API through the same origin (no CORS needed)
      // If you prefer direct access, set VITE_USE_PROXY=false and the axios
      // client will point straight at VITE_API_URL. CORS is already permissive
      // (allowed_origins=* in the Laravel HandleCors default).
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
});