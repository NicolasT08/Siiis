import { useId, useState } from 'react';
import type { FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { MessageSquareQuote, Send, Star } from 'lucide-react';
import { api } from '../../lib/api';
import { nombreCompleto, useAuth } from '../../lib/auth';
import { erroresDeCampo, mensajeError } from '../../lib/errors';
import { fecha } from '../../lib/format';
import { useApi } from '../../lib/useApi';
import { Aviso, Cargando, ErrorCampo, ErrorCarga, Paginacion, Vacio } from '../../components/ui';
import './reviews.css';

export function Estrellas({ valor, etiqueta }: { valor: number; etiqueta?: string }) {
  const redondeado = Math.round(valor);
  return (
    <span className="reviews-stars" aria-label={etiqueta ?? `${valor} de 5 estrellas`} role="img">
      {[1, 2, 3, 4, 5].map((n) => (
        <Star key={n} size={16} aria-hidden="true" fill={n <= redondeado ? 'currentColor' : 'none'} />
      ))}
    </span>
  );
}

function FormularioResena({ onEnviada }: { onEnviada: () => void }) {
  const { usuario } = useAuth();
  const ids = { nombre: useId(), comentario: useId(), calificacion: useId() };
  const [enviando, setEnviando] = useState(false);
  const [ok, setOk] = useState('');
  const [error, setError] = useState('');
  const [campos, setCampos] = useState<Record<string, string>>({});

  const enviar = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = event.currentTarget;
    const datos = new FormData(form);
    const calificacion = Number(datos.get('calificacion'));
    setEnviando(true);
    setOk('');
    setError('');
    setCampos({});
    try {
      const { resena } = await api.createReview({
        ...(usuario ? {} : { nombre_visible: String(datos.get('nombre_visible') ?? '').trim() }),
        comentario: String(datos.get('comentario') ?? '').trim(),
        ...(calificacion >= 1 && calificacion <= 5 ? { calificacion } : {}),
      });
      form.reset();
      setOk(
        resena.estado === 'oculta'
          ? '¡Gracias! Tu reseña se publicará cuando el equipo del semillero la revise.'
          : '¡Gracias! Tu reseña ya está publicada.',
      );
      onEnviada();
    } catch (e) {
      setError(mensajeError(e));
      setCampos(erroresDeCampo(e));
    } finally {
      setEnviando(false);
    }
  };

  return (
    <form className="app-form reviews-form" onSubmit={(e) => void enviar(e)} noValidate>
      <h3>Deja tu reseña</h3>
      {usuario ? (
        <p className="app-hint">Publicarás como {nombreCompleto(usuario)}. Tu reseña aparece de inmediato.</p>
      ) : (
        <p className="app-hint">
          Como visitante, tu reseña <strong>se publicará cuando la revisemos</strong>. Si eres integrante,{' '}
          <Link to="/login" state={{ from: '/resenas' }}>
            inicia sesión
          </Link>{' '}
          para publicarla con tu nombre.
        </p>
      )}
      {!usuario && (
        <div className="app-field">
          <label htmlFor={ids.nombre}>Tu nombre</label>
          <input id={ids.nombre} name="nombre_visible" maxLength={80} required aria-describedby={`${ids.nombre}-e`} aria-invalid={!!campos.nombre_visible} />
          <ErrorCampo id={`${ids.nombre}-e`} mensaje={campos.nombre_visible} />
        </div>
      )}
      <div className="app-field">
        <label htmlFor={ids.comentario}>Comentario</label>
        <textarea id={ids.comentario} name="comentario" rows={4} maxLength={1000} required aria-describedby={`${ids.comentario}-e`} aria-invalid={!!campos.comentario} />
        <ErrorCampo id={`${ids.comentario}-e`} mensaje={campos.comentario} />
      </div>
      <div className="app-field">
        <label htmlFor={ids.calificacion}>Calificación (opcional)</label>
        <select id={ids.calificacion} name="calificacion" defaultValue="">
          <option value="">Sin calificación</option>
          {[5, 4, 3, 2, 1].map((n) => (
            <option key={n} value={n}>
              {'★'.repeat(n)} ({n} de 5)
            </option>
          ))}
        </select>
        <ErrorCampo id={`${ids.calificacion}-e`} mensaje={campos.calificacion} />
      </div>
      {ok && <Aviso tipo="ok">{ok}</Aviso>}
      {error && <Aviso tipo="error">{error}</Aviso>}
      <button type="submit" className="btn btn--bronze" disabled={enviando}>
        <Send size={16} aria-hidden="true" /> {enviando ? 'Enviando…' : 'Enviar reseña'}
      </button>
    </form>
  );
}

/** Reseñas sobre el semillero (RF-10): promedio, listado y formulario. */
export default function ReviewsSection({ porPagina = 6, paginado = false }: { porPagina?: number; paginado?: boolean }) {
  const [page, setPage] = useState(1);
  const { usuario } = useAuth();
  const { estado, datos, recargar } = useApi((signal) => api.reviews({ page, per_page: porPagina }, signal), [page, porPagina, usuario?.id]);

  return (
    <div className="reviews">
      <div className="reviews__list">
        {datos && (
          <p className="reviews__summary">
            {datos.resumen.promedio !== null ? (
              <>
                <strong>{datos.resumen.promedio.toLocaleString('es-CO', { minimumFractionDigits: 1 })}</strong>
                <Estrellas valor={datos.resumen.promedio} etiqueta={`Promedio ${datos.resumen.promedio} de 5`} />
                <span>
                  {datos.resumen.calificaciones} {datos.resumen.calificaciones === 1 ? 'calificación' : 'calificaciones'}
                </span>
              </>
            ) : (
              <span>Aún no hay calificaciones.</span>
            )}
          </p>
        )}
        {estado.status === 'loading' && !datos && <Cargando texto="Cargando reseñas…" />}
        {estado.status === 'error' && <ErrorCarga error={estado.error} onRetry={recargar} />}
        {datos && datos.data.length === 0 && (
          <Vacio icono={<MessageSquareQuote size={28} aria-hidden="true" />} titulo="Sé la primera persona en opinar" />
        )}
        {datos && datos.data.length > 0 && (
          <ul className="reviews__items">
            {datos.data.map((r) => (
              <li key={r.id} className="reviews__item">
                <MessageSquareQuote className="reviews__quote" size={26} aria-hidden="true" />
                <p>{r.comentario}</p>
                <footer>
                  <strong>{r.nombre_visible}</strong>
                  {r.calificacion !== null && <Estrellas valor={r.calificacion} />}
                  <time dateTime={r.fecha}>{fecha(r.fecha)}</time>
                </footer>
              </li>
            ))}
          </ul>
        )}
        {paginado ? (
          <Paginacion meta={datos?.meta ?? null} onPage={setPage} />
        ) : (
          datos &&
          datos.meta.total > datos.data.length && (
            <Link className="btn btn--navy" to="/resenas">
              Ver todas las reseñas
            </Link>
          )
        )}
      </div>
      <FormularioResena onEnviada={recargar} />
    </div>
  );
}
