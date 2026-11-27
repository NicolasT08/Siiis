import { Link } from 'react-router-dom';
import { MapPinOff } from 'lucide-react';

export default function NotFoundPage() {
  return (
    <section className="container app-page">
      <div className="app-empty" role="alert">
        <MapPinOff size={32} aria-hidden="true" />
        <h1>Esta página no existe</h1>
        <p>Puede que el enlace esté mal escrito o que la página se haya movido.</p>
        <Link className="btn btn--navy" to="/">
          Ir al inicio
        </Link>
      </div>
    </section>
  );
}
