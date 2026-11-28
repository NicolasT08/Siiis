import { useMemo, useState } from 'react';
import type { ChangeEvent, FormEvent } from 'react';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Eye, FileText, ImagePlus, Pencil, Save, Trash2, Upload, X } from 'lucide-react';
import { api, ESTADOS_POR_MODULO } from '../../lib/api';
import type { CategoriaDocumento, Documento, DocumentoEntrada, EstadoDocumento, Modulo, UsuarioPublico } from '../../lib/api';
import { esRevisor, useAuth } from '../../lib/auth';
import { validarArchivo } from '../../lib/archivos';
import { pdfPrincipal, validarPdfDocumento } from '../../lib/documentoPdf';
import { erroresDeCampo, mensajeError } from '../../lib/errors';
import { ETIQUETA_CATEGORIA, ETIQUETA_ESTADO, nombrePersona, rutaDocumento } from '../../lib/format';
import { useApi } from '../../lib/useApi';
import MarkdownEditor from '../../components/MarkdownEditor';
import SelectorArchivos from '../../components/SelectorArchivos';
import { Aviso, Cargando, ErrorCampo, ErrorCarga } from '../../components/ui';
import type { Resultado } from './ProfilePage';

/** Límite de adjuntos del backend; incluye el documento PDF principal. */
const MAX_ADJUNTOS = 10;

/** DOCUMENTO_CONTENIDO_MAX_LENGTH del backend (DEC-B37). */
const MAX_CONTENIDO = 50000;

/** Mensaje de DEC-B70: el documento necesita texto, PDF o ambos. */
const SIN_TEXTO_NI_PDF = 'Escribe el contenido o sube un PDF: el documento necesita al menos uno de los dos.';

/** Estado que se pasa al pasar de "crear" a "editar". */
type EstadoCreacion = { creado?: boolean; errorPdf?: string; errorPublicar?: string } | null;

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

/** Imágenes adjuntas extra (opcionales). El documento PDF principal se maneja en el formulario. */
function Imagenes({ modulo, documento, onCambio }: { modulo: Modulo; documento: Documento; onCambio: (d: Documento) => void }) {
  const [ocupado, setOcupado] = useState(false);
  const [resultado, setResultado] = useState<Resultado>(null);
  const principal = pdfPrincipal(documento.archivos_adjuntos);
  const extras = documento.archivos_adjuntos.filter((a) => a.archivo !== principal?.archivo);

  const subir = async (event: ChangeEvent<HTMLInputElement>) => {
    const archivo = event.target.files?.[0];
    event.target.value = '';
    if (!archivo) return;
    const problema = validarPdfDocumento(archivo) === null ? 'El documento PDF se sube en el formulario de arriba.' : validarArchivo(archivo);
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
        <ImagePlus size={20} aria-hidden="true" /> Imágenes adjuntas (opcional)
      </h2>
      <p className="app-hint">JPG, PNG o WebP de hasta 5 MB; se suben de a una y se muestran debajo del PDF. Máximo {MAX_ADJUNTOS} archivos contando el PDF.</p>
      {extras.length > 0 && (
        <ul className="app-files">
          {extras.map((a) => (
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
        <Upload size={16} aria-hidden="true" /> {ocupado ? 'Procesando…' : lleno ? 'Límite de adjuntos alcanzado' : 'Subir imagen'}
        <input type="file" className="visually-hidden" accept="image/jpeg,image/png,image/webp" disabled={ocupado || lleno} onChange={(e) => void subir(e)} />
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
  const [pdf, setPdf] = useState<File[]>([]);
  const [contenido, setContenido] = useState(documento?.contenido ?? '');
  const [integrantes, setIntegrantes] = useState<string[]>(documento?.integrantes.map((i) => i.id) ?? []);
  const [guardando, setGuardando] = useState(false);
  const llegada = useLocation().state as EstadoCreacion;
  const [resultado, setResultado] = useState<Resultado>(() => {
    if (llegada?.errorPdf) return { tipo: 'error', texto: `Se creó el documento, pero no se pudo subir el PDF: ${llegada.errorPdf} Elige el PDF de nuevo y guarda para reintentar.` };
    if (llegada?.errorPublicar) return { tipo: 'error', texto: `Se creó el documento con su PDF, pero quedó en revisión y oculto: ${llegada.errorPublicar}` };
    if (llegada?.creado) return { tipo: 'ok', texto: 'Guardado. Si quieres, agrega imágenes adjuntas.' };
    return null;
  });
  const [campos, setCampos] = useState<Record<string, string>>({});
  const principal = actual ? pdfPrincipal(actual.archivos_adjuntos) : null;

  /** Sube el PDF elegido y, si reemplaza a otro, borra el anterior. Devuelve el error o null. */
  const subirPdf = async (doc: Documento, archivo: File): Promise<string | null> => {
    const anterior = pdfPrincipal(doc.archivos_adjuntos);
    try {
      let { documento: nuevo } = await api.uploadAttachment(modulo, doc.id, archivo);
      setActual(nuevo);
      if (anterior) {
        try {
          ({ documento: nuevo } = await api.deleteAttachment(modulo, doc.id, anterior.archivo));
          setActual(nuevo);
        } catch (e) {
          return `Se subió el PDF nuevo, pero no se pudo quitar el anterior ("${anterior.nombre_archivo}"): ${mensajeError(e)}`;
        }
      }
      setPdf([]);
      return null;
    } catch (e) {
      return mensajeError(e);
    }
  };

  /** Quita el PDF principal. El backend lo rechaza si el documento guardado se quedaría sin texto (DEC-B70). */
  const quitarPdf = async () => {
    if (!actual || !principal) return;
    if (!actual.contenido?.trim()) {
      setCampos((c) => ({ ...c, pdf: 'Para quitar el PDF, primero escribe el contenido y guarda los cambios.' }));
      return;
    }
    if (!window.confirm(`¿Quitar "${principal.nombre_archivo}"?`)) return;
    setGuardando(true);
    setResultado(null);
    try {
      const { documento: nuevo } = await api.deleteAttachment(modulo, actual.id, principal.archivo);
      setActual(nuevo);
      setResultado({ tipo: 'ok', texto: 'Se quitó el PDF.' });
    } catch (e) {
      setResultado({ tipo: 'error', texto: mensajeError(e) });
    } finally {
      setGuardando(false);
    }
  };

  const guardar = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const datos = new FormData(event.currentTarget);
    const texto = (c: string) => String(datos.get(c) ?? '').trim();
    const entrada: DocumentoEntrada = {
      titulo: texto('titulo'),
      descripcion: texto('descripcion'),
      // Texto y PDF son opcionales, pero hace falta al menos uno (DEC-B70).
      contenido: contenido.trim() || null,
      integrantes,
    };
    if (modulo === 'articles') entrada.categoria = texto('categoria') as CategoriaDocumento;
    // Solo los revisores cambian estado y visibilidad (DEC-B33); un no revisor recibiría 403.
    const publicacion: Pick<DocumentoEntrada, 'estado' | 'visible'> = {};
    if (revisor) {
      publicacion.estado = texto('estado') as EstadoDocumento;
      publicacion.visible = datos.get('visible') === 'on';
    }
    const errores: Record<string, string> = {};
    if (!entrada.titulo) errores.titulo = 'Este campo es obligatorio.';
    if (!entrada.descripcion) errores.descripcion = 'Este campo es obligatorio.';
    if (contenido.length > MAX_CONTENIDO) errores.contenido = `Máximo ${MAX_CONTENIDO.toLocaleString('es-CO')} caracteres.`;
    const archivo = pdf[0];
    if (archivo) {
      const problema = validarPdfDocumento(archivo);
      if (problema) errores.pdf = problema;
    }
    if (!entrada.contenido && !archivo && !principal) errores.contenido = SIN_TEXTO_NI_PDF;
    setCampos(errores);
    setResultado(null);
    if (Object.keys(errores).length) return;

    setGuardando(true);
    try {
      if (actual) {
        // Primero el PDF: así se puede cambiar de "solo texto" a "solo PDF" sin que el documento quede vacío.
        const error = archivo ? await subirPdf(actual, archivo) : null;
        if (error) {
          setResultado({ tipo: 'error', texto: `No se guardaron los cambios porque falló el PDF: ${error}` });
          return;
        }
        const { documento: nuevo } = await api.updateDocument(modulo, actual.id, { ...entrada, ...publicacion });
        setActual(nuevo);
        setResultado({ tipo: 'ok', texto: 'Cambios guardados.' });
      } else {
        // Con PDF se crea como borrador, se sube el PDF y después se publica: el backend no deja publicar sin
        // texto ni PDF, y el PDF solo se puede subir a un documento que ya existe.
        const publicaDeEntrada = !archivo || !!entrada.contenido;
        let { documento: nuevo } = await api.createDocument(modulo, publicaDeEntrada ? { ...entrada, ...publicacion } : entrada);
        const error = archivo ? await subirPdf(nuevo, archivo) : null;
        let errorPublicar: string | null = null;
        const quierePublicar = publicacion.estado !== undefined && (publicacion.estado !== 'en_revision' || publicacion.visible);
        if (!publicaDeEntrada && !error && quierePublicar) {
          try {
            ({ documento: nuevo } = await api.updateDocument(modulo, nuevo.id, publicacion));
          } catch (e) {
            errorPublicar = mensajeError(e);
          }
        }
        // Se pasa a la edición: ahí se reintenta el PDF si falló y se agregan imágenes.
        const estado: EstadoCreacion = error ? { errorPdf: error } : errorPublicar ? { errorPublicar } : { creado: true };
        navigate(`/mis-contenidos/${modulo}/${encodeURIComponent(nuevo.id)}/editar`, { replace: true, state: estado });
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
          <Pencil size={20} aria-hidden="true" /> {actual ? `Editar ${nombreTipo}` : `${nombreTipo === 'noticia' ? 'Nueva' : 'Nuevo'} ${nombreTipo}`}
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

          <div className="app-field--wide">
            <Aviso tipo="info">El documento necesita al menos uno de los dos: el contenido escrito o un PDF. Puedes usar ambos.</Aviso>
          </div>

          <div className="app-field app-field--wide">
            <label htmlFor="doc-contenido">Contenido (opcional)</label>
            <MarkdownEditor
              id="doc-contenido"
              valor={contenido}
              onChange={(valor) => {
                setContenido(valor);
                if (campos.contenido) setCampos((c) => ({ ...c, contenido: '' }));
              }}
              maxLength={MAX_CONTENIDO}
              disabled={guardando}
              invalido={!!campos.contenido}
              describedBy="doc-contenido-e"
            />
            <ErrorCampo id="doc-contenido-e" mensaje={campos.contenido} />
          </div>

          <div className="app-field app-field--wide">
            <span>Documento PDF (opcional)</span>
            {principal && (
              <p className="app-hint">
                Actual:{' '}
                <a href={principal.url} target="_blank" rel="noopener noreferrer">
                  {principal.nombre_archivo}
                </a>
                . Elige otro solo si quieres reemplazarlo.{' '}
                <button type="button" className="btn btn--danger btn--sm" disabled={guardando} onClick={() => void quitarPdf()}>
                  <Trash2 size={14} aria-hidden="true" /> Quitar PDF
                </button>
              </p>
            )}
            <SelectorArchivos
              archivos={pdf}
              onChange={(lista) => {
                setPdf(lista);
                // Se valida al elegir para avisar antes de guardar.
                const problema = lista[0] ? validarPdfDocumento(lista[0]) : null;
                setCampos((c) => ({ ...c, pdf: problema ?? '', ...(lista[0] && c.contenido === SIN_TEXTO_NI_PDF ? { contenido: '' } : {}) }));
              }}
              accept="application/pdf,.pdf"
              etiqueta={pdf.length ? 'Cambiar PDF' : principal ? 'Reemplazar PDF' : 'Elegir PDF'}
              ayuda="Solo PDF, máximo 3 MB."
              disabled={guardando}
              invalido={!!campos.pdf}
              errorId="doc-pdf-e"
            />
            <ErrorCampo id="doc-pdf-e" mensaje={campos.pdf} />
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
        <Imagenes modulo={modulo} documento={actual} onCambio={setActual} />
      ) : (
        <Aviso tipo="info">Podrás agregar imágenes adjuntas después de crear el documento.</Aviso>
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
