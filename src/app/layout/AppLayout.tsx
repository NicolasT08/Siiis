import { useEffect, useRef, useState } from 'react';
import { Outlet, useLocation } from 'react-router-dom';
import Navbar from '../../components/Navbar';
import Footer from '../../components/Footer';
import PageLoader from '../../components/PageLoader';

export default function AppLayout() {
  const location = useLocation();
  const [isLoading, setIsLoading] = useState(false);
  const timeoutRef = useRef<number | null>(null);

  useEffect(() => {
    const isBlogRoute = location.pathname === '/blog';

    if (isBlogRoute) {
      setIsLoading(false);
      return;
    }

    setIsLoading(true);

    if (timeoutRef.current) {
      window.clearTimeout(timeoutRef.current);
    }

    timeoutRef.current = window.setTimeout(() => {
      setIsLoading(false);
    }, 700);

    return () => {
      if (timeoutRef.current) {
        window.clearTimeout(timeoutRef.current);
      }
    };
  }, [location.pathname]);

  useEffect(() => {
    const timer = window.setTimeout(() => setIsLoading(false), 700);

    return () => {
      window.clearTimeout(timer);
    };
  }, []);

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
