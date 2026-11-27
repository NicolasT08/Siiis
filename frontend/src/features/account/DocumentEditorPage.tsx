import { useMemo, useState } from 'react';
import type { ChangeEvent, FormEvent } from 'react';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Eye, FileText, Paperclip, Pencil, Save, Trash2, Upload, X } from 'lucide-react';
import { api, ESTADOS_POR_MODULO } from '../../lib/api';
import type { CategoriaDocumento, Documento, DocumentoEntrada, EstadoDocumento, Modulo, UsuarioPublico } from '../../lib/api';
import { esRevisor, useAuth } from '../../lib/auth';
import { validarArchivo } from '../../lib/archivos';
import { erroresDeCampo, mensajeError } from '../../lib/errors';
import { ETIQUETA_CATEGORIA, ETIQUETA_ESTADO, nombrePersona, rutaDocumento } from '../../lib/format';
import { useApi } from '../../lib/useApi';
import Markdown from '../../components/Markdown';
import { Aviso, Cargando, ErrorCampo, ErrorCarga } from '../../components/ui';
import type { Resultado } from './ProfilePage';

const MAX_ADJUNTOS = 10;

/** Todos los integrantes activos (GET /users es paginado; máximo 50 por página). */
async function todosLosIntegrantes(signal: AbortSignal): Promise<UsuarioPublico[]> {
  const primera = await api.users({ per_page: 50 }, signal);
  const resto = await Promise.all(
    Array.from({ length: Math.min(primera.meta.total_pages, 20) - 1 }, (_, i) => api.users({ page: i + 2, per_page: 50 }, signal)),
  );
  return [primera, ...resto].flatMap((r) => r.data);
}

function SelectorIntegrantes({ seleccion, onChange, excluir }: { seleccion: string[]; onChange: (ids: string[]) => void; excluir?: string }) {
  const { estado } = useApi((signal) => todosLosIntegrantes(signal), []);
  const [filtro, setFiltro] = useState('');
  const todos = useMemo(() => (estado.status === 'ready' ? estado.data : []), [estado]);
  const porId = new Map(todos.map((u) => [u.id, u]));
  const q = filtro.trim().toLowerCase();
  const opciones = todos.filter((u) => u.id !== excluir && !seleccion.includes(u.id) && (!q || nombrePersona(u).toLowerCase().includes(q)));

  return (
    <fieldset className="app-field app-field--wide">
      <legend>Integrantes (opcional, hasta 20)</legend>
      {seleccion.length > 0 && (
        <ul className="app-people">
          {seleccion.map((id) => (
            <li key={id}>
              <span className="app-person">
                {porId.get(id) ? nombrePersona(porId.get(id)) : 'Integrante'}
                <button type="button" aria-label={`Quitar a ${porId.get(id) ? nombrePersona(porId.get(id)) : 'este integrante'}`} onClick={() => onChange(seleccion.filter((s) => s !== id))}>
                  <X size={14} aria-hidden="true" />
                </button>
              </span>
            </li>
          ))}
        </ul>
      )}
      {estado.status === 'loading' && <Cargando texto="Cargando integrantes…" />}
      {estado.status === 'ready' && seleccion.length < 20 && (
        <div className="app-filters">
          <div className="app-field">
            <label htmlFor="integrante-buscar">Buscar</label>
            <input id="integrante-buscar" type="search" value={filtro} onChange={(e) => setFiltro(e.target.value)} placeholder="Nombre o apellido" />
          </div>
          <div className="app-field">
            <label htmlFor="integrante-agregar">Agregar</label>
            <select
              id="integrante-agregar"
              value=""
              onChange={(e) => {
                if (e.target.value) onChange([...seleccion, e.target.value]);
              }}
            >
              <option value="">Elige un integrante…</option>
              {opciones.map((u) => (
                <option key={u.id} value={u.id}>
                  {nombrePersona(u)}
                </option>
              ))}
            </select>
          </div>
        </div>
      )}
    </fieldset>
  );
}

function Adjuntos({ modulo, documento, onCambio }: { modulo: Modulo; documento: Documento; onCambio: (d: Documento) => void }) {
  const [ocupado, setOcupado] = useState(false);
  const [resultado, setResultado] = useState<Resultado>(null);

  const subir = async (event: ChangeEvent<HTMLInputElement>) => {
    const archivo = event.target.files?.[0];
    event.target.value = '';
    if (!archivo) return;
    const problema = validarArchivo(archivo);
    if (problema) {
      setResultado({ tipo: 'error', texto: problema });
      return;
    }
    setOcupado(true);
    setResultado(null);
    try {
      const { documento: nuevo } = await api.uploadAttachment(modulo, documento.id, archivo);
      onCambio(nuevo);
      setResultado({ tipo: 'ok', texto: `Se subió "${archivo.name}".` });
    } catch (e) {
      setResultado({ tipo: 'error', texto: mensajeError(e) });
    } finally {
      setOcupado(false);
    }
  };

  const quitar = async (archivo: string, nombre: string) => {
    if (!window.confirm(`¿Quitar "${nombre}"?`)) return;
    setOcupado(true);
    setResultado(null);
    try {
      const { documento: nuevo } = await api.deleteAttachment(modulo, documento.id, archivo);
      onCambio(nuevo);
    } catch (e) {
      setResultado({ tipo: 'error', texto: mensajeError(e) });
    } finally {
      setOcupado(false);
    }
  };

  const lleno = documento.archivos_adjuntos.length >= MAX_ADJUNTOS;
  return (
    <section className="app-card app-form" aria-labelledby="adjuntos-editor">
      <h2 id="adjuntos-editor">
        <Paperclip size={20} aria-hidden="true" /> Archivos adjuntos
      </h2>
      <p className="app-hint">PDF de hasta 3 MB, o JPG, PNG o WebP de hasta 5 MB. Máximo {MAX_ADJUNTOS} archivos; se suben de a uno.</p>
      {documento.archivos_adjuntos.length > 0 && (
        <ul className="app-files">
          {documento.archivos_adjuntos.map((a) => (
            <li key={a.archivo}>
              <a href={a.url} target="_blank" rel="noopener noreferrer">
                <span>
                  <FileText size={16} aria-hidden="true" /> {a.nombre_archivo}
                </span>
              </a>
              <button type="button" className="btn btn--danger btn--sm" disabled={ocupado} onClick={() => void quitar(a.archivo, a.nombre_archivo)}>
                <Trash2 size={14} aria-hidden="true" /> Quitar
              </button>
            </li>
          ))}
        </ul>
      )}
      <label className={`btn btn--navy${ocupado || lleno ? ' is-disabled' : ''}`} aria-disabled={ocupado || lleno}>
        <Upload size={16} aria-hidden="true" /> {ocupado ? 'Procesando…' : lleno ? 'Límite de adjuntos alcanzado' : 'Subir archivo'}
        <input type="file" className="visually-hidden" accept="application/pdf,image/jpeg,image/png,image/webp" disabled={ocupado || lleno} onChange={(e) => void subir(e)} />
      </label>
      {resultado && <Aviso tipo={resultado.tipo}>{resultado.texto}</Aviso>}
    </section>
  );
}

function Formulario({ modulo, categoriaInicial, documento }: { modulo: Modulo; categoriaInicial: CategoriaDocumento; documento: Documento | null }) {
  const { usuario } = useAuth();
  const navigate = useNavigate();
  const revisor = esRevisor(usuario?.rol);
  const [actual, setActual] = useState<Documento | null>(documento);
  const [contenido, setContenido] = useState(documento?.contenido ?? '');
  const [vista, setVista] = useState<'editar' | 'previa'>('editar');
  const [integrantes, setIntegrantes] = useState<string[]>(documento?.integrantes.map((i) => i.id) ?? []);
  const [guardando, setGuardando] = useState(false);
  const creado = (useLocation().state as { creado?: boolean } | null)?.creado === true;
  const [resultado, setResultado] = useState<Resultado>(creado ? { tipo: 'ok', texto: 'Guardado. Ya puedes subir archivos adjuntos.' } : null);
  const [campos, setCampos] = useState<Record<string, string>>({});

  const guardar = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const datos = new FormData(event.currentTarget);
    const texto = (c: string) => String(datos.get(c) ?? '').trim();
    const entrada: DocumentoEntrada = {
      titulo: texto('titulo'),
      descripcion: texto('descripcion'),
      contenido: contenido.trim() || null,
      integrantes,
    };
    if (modulo === 'articles') entrada.categoria = texto('categoria') as CategoriaDocumento;
    // Solo los revisores cambian estado y visibilidad (DEC-B33); un no revisor recibiría 403.
    if (revisor) {
      entrada.estado = texto('estado') as EstadoDocumento;
      entrada.visible = datos.get('visible') === 'on';
    }
    const errores: Record<string, string> = {};
    if (!entrada.titulo) errores.titulo = 'Este campo es obligatorio.';
    if (!entrada.descripcion) errores.descripcion = 'Este campo es obligatorio.';
    setCampos(errores);
    setResultado(null);
    if (Object.keys(errores).length) return;

    setGuardando(true);
    try {
      if (actual) {
        const { documento: nuevo } = await api.updateDocument(modulo, actual.id, entrada);
        setActual(nuevo);
        setResultado({ tipo: 'ok', texto: 'Cambios guardados.' });
      } else {
        const { documento: nuevo } = await api.createDocument(modulo, entrada);
        // Al crear se pasa a la edición, donde ya se pueden subir adjuntos.
        navigate(`/mis-contenidos/${modulo}/${encodeURIComponent(nuevo.id)}/editar`, { replace: true, state: { creado: true } });
      }
    } catch (e) {
      setCampos(erroresDeCampo(e));
      setResultado({ tipo: 'error', texto: mensajeError(e) });
    } finally {
      setGuardando(false);
    }
  };

  const nombreTipo = ETIQUETA_CATEGORIA[actual?.categoria ?? categoriaInicial].toLowerCase();
  const f = (c: string) => ({ id: `doc-${c}`, name: c, disabled: guardando, 'aria-invalid': !!campos[c], 'aria-describedby': `doc-${c}-e` });

  return (
    <div className="app-stack">
      <form className="app-card app-form" onSubmit={(e) => void guardar(e)} noValidate>
        <h2>
          <Pencil size={20} aria-hidden="true" /> {actual ? `Editar ${nombreTipo}` : `Nuevo ${nombreTipo === 'noticia' ? 'noticia' : nombreTipo}`}
        </h2>
        {!revisor && (
          <p className="app-hint">Queda en revisión y oculto hasta que un revisor (docente director o administrador) lo publique. Puedes editarlo mientras siga en revisión.</p>
        )}
        <div className="app-form-grid">
          <div className="app-field app-field--wide">
            <label htmlFor="doc-titulo">Título</label>
            <input {...f('titulo')} defaultValue={actual?.titulo ?? ''} maxLength={200} required />
            <ErrorCampo id="doc-titulo-e" mensaje={campos.titulo} />
          </div>
          <div className="app-field app-field--wide">
            <label htmlFor="doc-descripcion">Resumen (aparece en los listados)</label>
            <textarea {...f('descripcion')} defaultValue={actual?.descripcion ?? ''} rows={3} maxLength={1000} required />
            <ErrorCampo id="doc-descripcion-e" mensaje={campos.descripcion} />
          </div>
          {modulo === 'articles' && (
            <div className="app-field">
              <label htmlFor="doc-categoria">Tipo</label>
              <select {...f('categoria')} defaultValue={actual?.categoria ?? categoriaInicial}>
                <option value="articulo">Artículo</option>
                <option value="noticia">Noticia</option>
              </select>
              <ErrorCampo id="doc-categoria-e" mensaje={campos.categoria} />
            </div>
          )}
          {revisor && (
            <>
              <div className="app-field">
                <label htmlFor="doc-estado">Estado</label>
                <select {...f('estado')} defaultValue={actual?.estado ?? 'en_revision'}>
                  {ESTADOS_POR_MODULO[modulo].map((e) => (
                    <option key={e} value={e}>
                      {ETIQUETA_ESTADO[e]}
                    </option>
                  ))}
                </select>
                <ErrorCampo id="doc-estado-e" mensaje={campos.estado} />
              </div>
              <label className="app-check">
                <input type="checkbox" name="visible" defaultChecked={actual?.visible ?? false} disabled={guardando} /> Visible en el sitio público
              </label>
            </>
          )}

          <div className="app-field app-field--wide">
            <div className="app-tabs" role="tablist" aria-label="Contenido">
              <button type="button" role="tab" aria-selected={vista === 'editar'} className={vista === 'editar' ? 'is-active' : ''} onClick={() => setVista('editar')}>
                <Pencil size={14} aria-hidden="true" /> Escribir
              </button>
              <button type="button" role="tab" aria-selected={vista === 'previa'} className={vista === 'previa' ? 'is-active' : ''} onClick={() => setVista('previa')}>
                <Eye size={14} aria-hidden="true" /> Vista previa
              </button>
            </div>
            {vista === 'editar' ? (
              <>
                <label htmlFor="doc-contenido">Contenido en Markdown (opcional)</label>
                <textarea
                  id="doc-contenido"
                  rows={14}
                  maxLength={50000}
                  value={contenido}
                  disabled={guardando}
                  onChange={(e) => setContenido(e.target.value)}
                  aria-describedby="doc-contenido-ayuda doc-contenido-e"
                  placeholder={'## Objetivo\n\nTexto con **negrita**, *cursiva*, listas:\n\n- Punto 1\n- Punto 2\n\n[Enlace](https://ejemplo.com)'}
                />
                <span id="doc-contenido-ayuda" className="app-hint">
                  Usa ## para títulos, **negrita**, *cursiva*, - para listas y [texto](url) para enlaces. El HTML no se muestra. {contenido.length} / 50 000
                </span>
              </>
            ) : contenido.trim() ? (
              <div className="app-card">
                <Markdown texto={contenido} />
              </div>
            ) : (
              <p className="app-hint">Aún no hay contenido para previsualizar.</p>
            )}
            <ErrorCampo id="doc-contenido-e" mensaje={campos.contenido} />
          </div>

          <SelectorIntegrantes seleccion={integrantes} onChange={setIntegrantes} excluir={actual?.autor?.id ?? usuario?.id} />
          <ErrorCampo id="doc-integrantes-e" mensaje={campos.integrantes} />
        </div>

        {resultado && <Aviso tipo={resultado.tipo}>{resultado.texto}</Aviso>}
        <div className="app-actions">
          <button type="submit" className="btn btn--bronze" disabled={guardando}>
            <Save size={16} aria-hidden="true" /> {guardando ? 'Guardando…' : actual ? 'Guardar cambios' : 'Crear'}
          </button>
          {actual && (
            <Link className="btn btn--ghost" to={rutaDocumento(modulo, actual.id)}>
              <Eye size={16} aria-hidden="true" /> Ver publicación
            </Link>
          )}
        </div>
      </form>

      {actual ? (
        <Adjuntos modulo={modulo} documento={actual} onCambio={setActual} />
      ) : (
        <Aviso tipo="info">Podrás subir archivos adjuntos después de crear el documento.</Aviso>
      )}
    </div>
  );
}

/** /mis-contenidos/nuevo/:tipo y /mis-contenidos/:modulo/:id/editar */
export default function DocumentEditorPage() {
  const { tipo, modulo: moduloParam, id } = useParams();
  const editando = Boolean(id);
  const modulo: Modulo = editando ? (moduloParam === 'articles' ? 'articles' : 'projects') : tipo === 'proyecto' ? 'projects' : 'articles';
  const categoria: CategoriaDocumento = tipo === 'noticia' ? 'noticia' : tipo === 'proyecto' ? 'proyecto' : 'articulo';
  const { estado, recargar } = useApi(
    (signal) => (id ? api.document(modulo, id, signal).then((r) => r.documento) : Promise.resolve(null)),
    [modulo, id],
  );

  return (
    <section className="container app-page">
      <Link to="/mis-contenidos" className="project-detail__back">
        <ArrowLeft size={16} aria-hidden="true" /> Mis contenidos
      </Link>
      {estado.status === 'loading' && <Cargando />}
      {estado.status === 'error' && <ErrorCarga error={estado.error} onRetry={recargar} />}
      {estado.status === 'ready' && estado.data && !estado.data.permisos.editar ? (
        <Aviso tipo="info">
          Ya no puedes editar "{estado.data.titulo}": solo se edita mientras está en revisión. Pide el cambio a un revisor.{' '}
          <Link to={rutaDocumento(modulo, estado.data.id)}>Ver publicación</Link>
        </Aviso>
      ) : (
        estado.status === 'ready' && <Formulario key={estado.data?.id ?? 'nuevo'} modulo={modulo} categoriaInicial={categoria} documento={estado.data} />
      )}
    </section>
  );
}
