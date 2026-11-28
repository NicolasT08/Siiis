import { useEffect, useState } from 'react';
import { Outlet, useLocation } from 'react-router-dom';
import Navbar from '../../components/Navbar';
import Footer from '../../components/Footer';
import PageLoader from '../../components/PageLoader';
import { aplicarMetadatos } from '../../lib/metadatos';

const DURACION_MS = 700;

export default function AppLayout() {
  const location = useLocation();
  // Animación de carga con el logo (RF-14) en cada cambio de página: se muestra hasta que la página actual
  // queda marcada como lista. Solo cambia de ruta (no de query string, p. ej. al filtrar o paginar).
  const [listaEn, setListaEn] = useState<string | null>(null);
  const isLoading = listaEn !== location.pathname;

  useEffect(() => {
    const timer = window.setTimeout(() => setListaEn(location.pathname), DURACION_MS);
    return () => window.clearTimeout(timer);
  }, [location.pathname]);

  // Al cambiar de página se vuelve arriba y se actualizan el título y la descripción (SEO).
  useEffect(() => {
    window.scrollTo({ top: 0 });
    aplicarMetadatos(location.pathname);
  }, [location.pathname]);

  return (
    <>
      <PageLoader visible={isLoading} />

      <div className="app-shell" aria-busy={isLoading}>
        <Navbar />
        <main className="page-shell">
          <Outlet />
        </main>
        <Footer />
      </div>
    </>
  );
}
