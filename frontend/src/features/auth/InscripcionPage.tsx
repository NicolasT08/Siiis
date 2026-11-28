import { useState } from 'react';
import type { FormEvent } from 'react';
import { BookOpenCheck, CalendarDays, Lightbulb, Send } from 'lucide-react';
import ProjectsHero from '../projects/components/ProjectsHero';
import { AREAS_INTERES as AREAS, SITIO } from '../../config/sitio';
import { api } from '../../lib/api';
import { erroresDeCampo, mensajeError } from '../../lib/errors';
import { Aviso, ErrorCampo } from '../../components/ui';
import SelectorArchivos from '../../components/SelectorArchivos';
import { validarHojaDeVida } from '../../lib/archivos';
import '../projects/projects.css';
import './registration.css';

const CARTA_MAX = 3000;

export default function InscripcionPage() {
  const [enviando, setEnviando] = useState(false);
  const [ok, setOk] = useState('');
  const [error, setError] = useState('');
  const [campos, setCampos] = useState<Record<string, string>>({});
  // RF-11: hoja de vida opcional, un PDF; viaja en documentos[] (DEC-B49, DEC-B63).
  const [hojaDeVida, setHojaDeVida] = useState<File[]>([]);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const datos = new FormData(form);
    const texto = (c: string) => String(datos.get(c) ?? '').trim();
    const errores: Record<string, string> = {};

    // Validación en el navegador (el backend vuelve a validar todo).
    const correo = texto('correo').toLowerCase();
    if (!correo) errores.correo = 'Este campo es obligatorio.';
    else if (!correo.endsWith(`@${SITIO.dominioInscripcion}`)) errores.correo = `Usa tu correo institucional @${SITIO.dominioInscripcion}.`;
    for (const c of ['nombre_completo', 'programa_academico']) if (!texto(c)) errores[c] = 'Este campo es obligatorio.';
    const codigo = texto('codigo_estudiantil');
    if (codigo && !/^[A-Za-z0-9]{4,20}$/.test(codigo)) errores.codigo_estudiantil = 'Debe tener de 4 a 20 letras o números, sin espacios ni símbolos.';
    const semestre = Number(texto('semestre'));
    if (!Number.isInteger(semestre) || semestre < 1 || semestre > 10) errores.semestre = 'Elige un semestre entre 1 y 10.';
    const cartaTexto = texto('motivacion');
    if (!cartaTexto) errores.carta_motivacion = 'Este campo es obligatorio.';
    const problemaHoja = hojaDeVida[0] ? validarHojaDeVida(hojaDeVida[0]) : null;
    if (problemaHoja) errores.documentos = problemaHoja;
    if (datos.get('disponibilidad') !== 'on') errores.disponibilidad = 'Confirma tu disponibilidad para las reuniones semanales.';

    setOk('');
    setError('');
    if (Object.keys(errores).length > 0) {
      setCampos(errores);
      setError('Revisa los datos marcados en el formulario.');
      return;
    }

    setCampos({});
    setEnviando(true);
    try {
      const respuesta = await api.createInscription({
        nombre_completo: texto('nombre_completo'),
        correo,
        codigo_estudiantil: codigo,
        programa_academico: texto('programa_academico'),
        semestre,
        areas_interes: datos.getAll('areas_interes').map(String),
        carta_motivacion: cartaTexto,
        disponibilidad: true,
        documentos: hojaDeVida,
      });
      form.reset();
      setHojaDeVida([]);
      setOk(respuesta.mensaje);
    } catch (e) {
      setCampos(erroresDeCampo(e));
      setError(mensajeError(e));
    } finally {
      setEnviando(false);
    }
  }

  const invalido = (c: string) => ({ 'aria-invalid': !!campos[c], 'aria-describedby': `ins-${c}-e` });

  return (
    <div className="projects-page registration-page">
      <ProjectsHero
        compact
        title="Registro"
        subtitle="¿Quieres ser parte del Semillero de Investigación SIIIS? Cuéntanos sobre ti y tus intereses."
      />

      <main className="container registration-content">
        <section className="registration-intro" aria-labelledby="registration-title">
          <p className="registration-eyebrow">Convocatoria abierta</p>
          <h2 id="registration-title">Crezcamos investigando juntos</h2>
          <p>
            Buscamos estudiantes con curiosidad, ganas de aprender y motivación para aportar a proyectos de
            investigación, tecnología e innovación.
          </p>
        </section>

        <div className="registration-points">
          <article className="registration-point">
            <Lightbulb size={22} aria-hidden="true" />
            <div>
              <h3>Cuéntanos qué te interesa</h3>
              <p>Comparte tus áreas de interés y tu motivación para unirte al semillero.</p>
            </div>
          </article>
          <article className="registration-point">
            <CalendarDays size={22} aria-hidden="true" />
            <div>
              <h3>Participa activamente</h3>
              <p>Se requiere disponibilidad para reuniones semanales y proyectos colaborativos.</p>
            </div>
          </article>
        </div>

        <section className="registration-card" aria-labelledby="registration-form-title">
          <div className="registration-card__heading">
            <span className="registration-card__icon" aria-hidden="true">
              <BookOpenCheck size={25} strokeWidth={1.8} />
            </span>
            <div>
              <p className="registration-eyebrow">Solicitud de ingreso</p>
              <h2 id="registration-form-title">Formulario de inscripción</h2>
            </div>
          </div>

          <form className="registration-form" onSubmit={(e) => void handleSubmit(e)} noValidate>
            <div className="registration-fields">
              <label>
                Nombre completo
                <input name="nombre_completo" type="text" autoComplete="name" required maxLength={120} disabled={enviando} {...invalido('nombre_completo')} />
                <ErrorCampo id="ins-nombre_completo-e" mensaje={campos.nombre_completo} />
              </label>
              <label>
                Correo electrónico
                <input
                  name="correo"
                  type="email"
                  autoComplete="email"
                  placeholder={`usuario@${SITIO.dominioInscripcion}`}
                  required
                  maxLength={254}
                  disabled={enviando}
                  {...invalido('correo')}
                />
                <ErrorCampo id="ins-correo-e" mensaje={campos.correo} />
              </label>
              <label>
                Código estudiantil
                <input name="codigo_estudiantil" type="text" maxLength={20} disabled={enviando} {...invalido('codigo_estudiantil')} />
                <ErrorCampo id="ins-codigo_estudiantil-e" mensaje={campos.codigo_estudiantil} />
              </label>
              <label>
                Programa académico
                <input name="programa_academico" type="text" required maxLength={120} disabled={enviando} {...invalido('programa_academico')} />
                <ErrorCampo id="ins-programa_academico-e" mensaje={campos.programa_academico} />
              </label>
              <label>
                Semestre
                <select name="semestre" defaultValue="" required disabled={enviando} {...invalido('semestre')}>
                  <option value="" disabled>
                    Selecciona tu semestre
                  </option>
                  {Array.from({ length: 10 }, (_, i) => i + 1).map((s) => (
                    <option key={s} value={s}>
                      {s}° semestre
                    </option>
                  ))}
                </select>
                <ErrorCampo id="ins-semestre-e" mensaje={campos.semestre} />
              </label>
            </div>

            <fieldset className="registration-areas">
              <legend>
                Áreas de interés <span>(elige una o más)</span>
              </legend>
              <div className="registration-areas__grid">
                {AREAS.map((area) => (
                  <label key={area.value}>
                    <input type="checkbox" name="areas_interes" value={area.value} disabled={enviando} />
                    <span>{area.label}</span>
                  </label>
                ))}
              </div>
              <ErrorCampo id="ins-areas_interes-e" mensaje={campos.areas_interes} />
            </fieldset>

            <label className="registration-form__motivation">
              Carta de intención o motivación
              <textarea
                name="motivacion"
                rows={5}
                maxLength={CARTA_MAX}
                placeholder="Cuéntanos por qué quieres unirte y qué temas te gustaría explorar."
                required
                disabled={enviando}
                aria-invalid={!!campos.carta_motivacion}
                aria-describedby="ins-carta_motivacion-e"
              />
              <ErrorCampo id="ins-carta_motivacion-e" mensaje={campos.carta_motivacion} />
            </label>

            <fieldset className="registration-areas registration-cv">
              <legend>Hoja de vida (PDF)</legend>
              <SelectorArchivos
                archivos={hojaDeVida}
                onChange={(archivos) => {
                  setHojaDeVida(archivos);
                  setCampos((c) => ({ ...c, documentos: '' }));
                }}
                accept="application/pdf,.pdf"
                ayuda="Opcional. PDF de hasta 3 MB"
                disabled={enviando}
                invalido={!!campos.documentos}
                errorId="ins-documentos-e"
              />
              <ErrorCampo id="ins-documentos-e" mensaje={campos.documentos} />
            </fieldset>

            <label className="registration-commitment">
              <input type="checkbox" name="disponibilidad" required disabled={enviando} {...invalido('disponibilidad')} />
              <span>
                Cuento con disponibilidad para asistir a las reuniones semanales y participar en ciclos de formación
                técnica o proyectos colaborativos.
              </span>
            </label>
            <ErrorCampo id="ins-disponibilidad-e" mensaje={campos.disponibilidad} />

            <p className="registration-privacy">
              Usaremos tus datos únicamente para gestionar esta solicitud de ingreso al semillero.
            </p>

            {ok && <Aviso tipo="ok">{ok}</Aviso>}
            {error && <Aviso tipo="error">{error}</Aviso>}

            <button className="btn btn--bronze registration-submit" type="submit" disabled={enviando}>
              <Send size={17} aria-hidden="true" />
              {enviando ? 'Enviando...' : 'Enviar solicitud'}
            </button>
          </form>
        </section>
      </main>
    </div>
  );
}
