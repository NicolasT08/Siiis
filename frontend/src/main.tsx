import React from 'react';
import ReactDOM from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import AppRoutes from './app/routes/AppRoutes';
import AuthProvider from './components/AuthProvider';
import ErrorBoundary from './components/ErrorBoundary';
import { recargarUnaVez } from './lib/recargaPorChunk';
import './assets/fuentes/fuentes.css';
import './styles/global.css';
import './styles/siiis.css';
import './styles/app.css';

// Vite avisa con este evento cuando no puede precargar un archivo de una página diferida (p. ej. tras un
// despliegue). Si se va a recargar, se evita el error; si ya se recargó una vez, el error llega al ErrorBoundary.
window.addEventListener('vite:preloadError', (event) => {
  if (recargarUnaVez()) event.preventDefault();
});

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <ErrorBoundary>
      <BrowserRouter>
        <AuthProvider>
          <AppRoutes />
        </AuthProvider>
      </BrowserRouter>
    </ErrorBoundary>
  </React.StrictMode>,
);
