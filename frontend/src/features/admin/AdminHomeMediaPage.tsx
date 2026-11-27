import { useState } from 'react';
import type { FormEvent } from 'react';
import { ArrowDown, ArrowUp, Film, Image as ImageIcon, Save, Trash2, Upload } from 'lucide-react';
import { api } from '../../lib/api';
import type { MultimediaAdmin, SeccionInicio } from '../../lib/api';
import { validarFotoInicio, validarVideo } from '../../lib/archivos';
import { erroresDeCampo, mensajeError } from '../../lib/errors';
import { fecha } from '../../lib/format';
import { useApi } from '../../lib/useApi';
import { Aviso, Cargando, ErrorCampo, ErrorCarga, Vacio } from '../../components/ui';
import SelectorArchivos from '../../components/SelectorArchivos';

type Resultado = { tipo: 'ok' | 'error'; texto: string } | null;

const SECCIONES: { seccion: SeccionInicio; titulo: string; esVideo: boolean; ayuda: string }[] = [
  {
    seccion: 'slider_principal',
    titulo: 'Carrusel de fotos',
    esVideo: false,
    ayuda: 'JPG, PNG o WebP de hasta 5 MB. Se reducen a 1600 px de ancho.',
  },
  {
    seccion: 'video_presentacion',
    titulo: 'Videos de presentación',
    esVideo: true,
    ayuda: 'MP4, WebM o MOV de hasta 50 MB (mejor en 720p). La subida puede tardar un par de minutos.',
  },
];

function Elemento({
  item,
  esVideo,
  primero,
  ultimo,
  ocupado,
  onMover,
  onCambio,
  onBorrar,
}: {
  item: MultimediaAdmin;
  esVideo: boolean;
  primero: boolean;
  ultimo: boolean;
  ocupado: boolean;
  onMover: (direccion: -1 | 1) => void;
  onCambio: (item: MultimediaAdmin) => void;
  onBorrar: () => void;
}) {
  const [descripcion, setDescripcion] = useState(item.descripcion ?? '');
  const [guardando, setGuardando] = useState(false);
  const [resultado, setResultado] = useState<Resultado>(null);
  const cambio = descripcion.trim() !== (item.descripcion ?? '');

  const guardar = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setGuardando(true);
    setResultado(null);
    try {
      const { multimedia } = await api.updateMultimedia(item.id, { descripcion: descripcion.trim() });
      onCambio(multimedia);
      setResultado({ tipo: 'ok', texto: 'Descripción guardada.' });
    } catch (e) {
      setResultado({ tipo: 'error', texto: erroresDeCampo(e).descripcion ?? mensajeError(e) });
    } finally {
      setGuardando(false);
    }
  };

  return (
    <li className="media-admin__item">
      <div className="media-admin__preview">
        {esVideo ? (
          <video src={item.url} controls preload="metadata" aria-label={item.descripcion ?? 'Video'} />
        ) : (
          <img src={item.url} alt={item.descripcion ?? ''} loading="lazy" />
        )}
        <span className="app-tag">#{item.orden}</span>
      </div>
      <form className="media-admin__body" onSubmit={(e) => void guardar(e)} noValidate>
        <label className="app-field">
          Descripción (texto alternativo)
          <input value={descripcion} maxLength={200} required onChange={(e) => setDescripcion(e.target.value)} disabled={guardando || ocupado} />
        </label>
        {item.fecha_subida && <span className="app-hint">Subido el {fecha(item.fecha_subida)}</span>}
        {resultado && <Aviso tipo={resultado.tipo}>{resultado.texto}</Aviso>}
        <div className="app-actions">
          <button type="submit" className="btn btn--navy btn--sm" disabled={!cambio || !descripcion.trim() || guardando || ocupado}>
            <Save size={14} aria-hidden="true" /> Guardar
          </button>
          <button type="button" className="btn btn--ghost btn--sm" disabled={primero || ocupado} onClick={() => onMover(-1)} aria-label="Subir en el orden">
            <ArrowUp size={14} aria-hidden="true" /> Antes
          </button>
          <button type="button" className="btn btn--ghost btn--sm" disabled={ultimo || ocupado} onClick={() => onMover(1)} aria-label="Bajar en el orden">
            <ArrowDown size={14} aria-hidden="true" /> Después
          </button>
          <button type="button" className="btn btn--danger btn--sm" disabled={ocupado} onClick={onBorrar}>
            <Trash2 size={14} aria-hidden="true" /> Borrar
          </button>
        </div>
      </form>
    </li>
  );
}

function Seccion({ seccion, titulo, esVideo, ayuda }: (typeof SECCIONES)[number]) {
  const { estado, recargar } = useApi((signal) => api.multimedia(seccion, signal), [seccion]);
  const [local, setLocal] = useState<MultimediaAdmin[] | null>(null);
  const [ocupado, setOcupado] = useState(false);
  const [resultado, setResultado] = useState<Resultado>(null);
  const [campos, setCampos] = useState<Record<string, string>>({});
  const [archivos, setArchivos] = useState<File[]>([]);
  const items = local ?? (estado.status === 'ready' ? estado.data.data : []);
  const ids = { archivo: `${seccion}-archivo`, descripcion: `${seccion}-descripcion`, orden: `${seccion}-orden` };

  const ejecutar = async (tarea: () => Promise<MultimediaAdmin[] | void>, ok?: string) => {
    setOcupado(true);
    setResultado(null);
    try {
      const nuevos = await tarea();
      if (nuevos) setLocal(nuevos);
      if (ok) setResultado({ tipo: 'ok', texto: ok });
      return true;
    } catch (e) {
      setResultado({ tipo: 'error', texto: mensajeError(e) });
      setCampos(erroresDeCampo(e));
      return false;
    } finally {
      setOcupado(false);
    }
  };

  const mover = (indice: number, direccion: -1 | 1) => {
    const orden = items.map((i) => i.id);
    const destino = indice + direccion;
    [orden[indice], orden[destino]] = [orden[destino], orden[indice]];
    void ejecutar(async () => (await api.reorderMultimedia(seccion, orden)).data);
  };

  const borrar = (item: MultimediaAdmin) => {
    if (!window.confirm(`¿Borrar "${item.descripcion ?? 'este elemento'}"? Se elimina también el archivo y no se puede deshacer.`)) return;
    void ejecutar(async () => {
      await api.deleteMultimedia(item.id);
      return items.filter((i) => i.id !== item.id);
    }, 'Elemento borrado.');
  };

  const subir = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = event.currentTarget;
    const datos = new FormData(form);
    const archivo = archivos[0];
    const descripcion = String(datos.get('descripcion') ?? '').trim();
    const orden = Number(datos.get('orden')) || null;
    const errores: Record<string, string> = {};
    if (!archivo || archivo.size === 0) errores.archivo = esVideo ? 'Elige un video.' : 'Elige una foto.';
    else {
      const problema = esVideo ? validarVideo(archivo) : validarFotoInicio(archivo);
      if (problema) errores.archivo = problema;
    }
    if (!descripcion) errores.descripcion = 'Escribe una descripción: es el texto alternativo para quien no ve la imagen.';
    setCampos(errores);
    if (Object.keys(errores).length || !archivo) return;

    const ok = await ejecutar(async () => {
      await api.uploadMultimedia({ seccion, descripcion, orden, archivo });
      return (await api.multimedia(seccion)).data;
    }, esVideo ? 'Video subido.' : 'Foto subida.');
    if (ok) {
      form.reset();
      setArchivos([]);
    }
  };

  return (
    <section className="app-card app-section" aria-labelledby={`${seccion}-titulo`}>
      <h2 id={`${seccion}-titulo`}>
        {esVideo ? <Film size={20} aria-hidden="true" /> : <ImageIcon size={20} aria-hidden="true" />} {titulo}
        <span className="app-tag">{items.length}</span>
      </h2>

      {estado.status === 'loading' && !local && <Cargando />}
      {estado.status === 'error' && <ErrorCarga error={estado.error} onRetry={recargar} />}
      {estado.status === 'ready' && items.length === 0 && (
        <Vacio titulo={esVideo ? 'Aún no hay videos' : 'Aún no hay fotos'}>
          {esVideo ? 'Sin videos, la página de inicio no muestra esa sección.' : 'Sin fotos, el inicio muestra un aviso en lugar del carrusel.'}
        </Vacio>
      )}
      {items.length > 0 && (
        <ol className="media-admin">
          {items.map((item, i) => (
            <Elemento
              key={item.id}
              item={item}
              esVideo={esVideo}
              primero={i === 0}
              ultimo={i === items.length - 1}
              ocupado={ocupado}
              onMover={(d) => mover(i, d)}
              onCambio={(nuevo) => setLocal(items.map((x) => (x.id === nuevo.id ? nuevo : x)))}
              onBorrar={() => borrar(item)}
            />
          ))}
        </ol>
      )}

      {resultado && <Aviso tipo={resultado.tipo}>{resultado.texto}</Aviso>}

      <form className="app-form media-admin__upload" onSubmit={(e) => void subir(e)} noValidate>
        <h3>
          <Upload size={18} aria-hidden="true" /> {esVideo ? 'Subir video' : 'Subir foto'}
        </h3>
        <p className="app-hint">{ayuda}</p>
        <div className="app-form-grid">
          <div className="app-field app-field--wide">
            <span>{esVideo ? 'Video' : 'Foto'}</span>
            <SelectorArchivos
              archivos={archivos}
              onChange={setArchivos}
              accept={esVideo ? 'video/mp4,video/webm,video/quicktime' : 'image/jpeg,image/png,image/webp'}
              etiqueta={esVideo ? (archivos.length ? 'Cambiar video' : 'Elegir video') : archivos.length ? 'Cambiar foto' : 'Elegir foto'}
              disabled={ocupado}
              invalido={!!campos.archivo}
              errorId={`${ids.archivo}-e`}
            />
            <ErrorCampo id={`${ids.archivo}-e`} mensaje={campos.archivo} />
          </div>
          <div className="app-field">
            <label htmlFor={ids.descripcion}>Descripción</label>
            <input id={ids.descripcion} name="descripcion" maxLength={200} required disabled={ocupado} aria-invalid={!!campos.descripcion} aria-describedby={`${ids.descripcion}-e`} />
            <ErrorCampo id={`${ids.descripcion}-e`} mensaje={campos.descripcion} />
          </div>
          <div className="app-field">
            <label htmlFor={ids.orden}>Posición (opcional)</label>
            <input id={ids.orden} name="orden" type="number" min={1} max={1000} placeholder="Al final" disabled={ocupado} aria-invalid={!!campos.orden} aria-describedby={`${ids.orden}-e`} />
            <ErrorCampo id={`${ids.orden}-e`} mensaje={campos.orden} />
          </div>
        </div>
        <button type="submit" className="btn btn--bronze" disabled={ocupado}>
          <Upload size={16} aria-hidden="true" /> {ocupado ? 'Procesando…' : esVideo ? 'Subir video' : 'Subir foto'}
        </button>
      </form>
    </section>
  );
}

/** Administración → Inicio (RF-07, RF-11, DEC-B62): solo superadministrador y administrador (lo exige la ruta). */
export default function AdminHomeMediaPage() {
  return (
    <>
      <div className="app-panel-head">
        <div>
          <h1>Inicio</h1>
          <p>Fotos del carrusel y videos de presentación de la página de inicio. Los cambios se ven allí en unos minutos.</p>
        </div>
      </div>
      <div className="app-stack">
        {SECCIONES.map((s) => (
          <Seccion key={s.seccion} {...s} />
        ))}
      </div>
    </>
  );
}
