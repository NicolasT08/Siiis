import { useState } from 'react';
import type { FormEvent } from 'react';
import { BookOpenCheck, CalendarDays, Lightbulb, Send } from 'lucide-react';
import ProjectsHero from '../projects/components/ProjectsHero';
import { api, errorText } from '../../lib/api';
import '../projects/projects.css';
import './registration.css';

const AREAS = [
  { value: 'desarrollo_software', label: 'Desarrollo de software' },
  { value: 'transformacion_digital', label: 'Transformación digital' },
  { value: 'tic', label: 'Tecnologías de información y comunicación (TIC)' },
  { value: 'innovacion', label: 'Innovación' },
  { value: 'inteligencia_artificial', label: 'Inteligencia artificial' },
  { value: 'ciencia_datos', label: 'Ciencia de datos' },
];

export default function RegisterPage() {
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [status, setStatus] = useState<'idle' | 'sent' | 'error'>('idle');
  const [feedback, setFeedback] = useState('');

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const formData = new FormData(form);

    setIsSubmitting(true);
    setStatus('idle');
    setFeedback('');

    try {
      const response = await api.inscripcion({
        nombre: String(formData.get('nombre') ?? '').trim(),
        correo: String(formData.get('correo') ?? '').trim(),
        codigo_estudiantil: String(formData.get('codigo_estudiantil') ?? '').trim(),
        programa_academico: String(formData.get('programa_academico') ?? '').trim(),
        semestre: Number(formData.get('semestre')),
        areas_interes: formData.getAll('areas_interes').map(String),
        motivacion: String(formData.get('motivacion') ?? '').trim(),
        disponibilidad: formData.get('disponibilidad') === 'on',
      });
      form.reset();
      setFeedback(response.mensaje);
      setStatus('sent');
    } catch (error) {
      setFeedback(errorText(error));
      setStatus('error');
    } finally {
      setIsSubmitting(false);
    }
  }

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

          <form className="registration-form" onSubmit={handleSubmit}>
            <div className="registration-fields">
              <label>
                Nombre completo
                <input name="nombre" type="text" autoComplete="name" required maxLength={120} disabled={isSubmitting} />
              </label>
              <label>
                Correo electrónico
                <input
                  name="correo"
                  type="email"
                  autoComplete="email"
                  placeholder="correo@ejemplo.com"
                  required
                  maxLength={254}
                  disabled={isSubmitting}
                />
              </label>
              <label>
                Código estudiantil
                <input name="codigo_estudiantil" type="text" required maxLength={32} disabled={isSubmitting} />
              </label>
              <label>
                Programa académico
                <input name="programa_academico" type="text" required maxLength={120} disabled={isSubmitting} />
              </label>
              <label>
                Semestre
                <select name="semestre" defaultValue="" required disabled={isSubmitting}>
                  <option value="" disabled>Selecciona tu semestre</option>
                  {Array.from({ length: 12 }, (_, index) => index + 1).map((semester) => (
                    <option key={semester} value={semester}>{semester}° semestre</option>
                  ))}
                </select>
              </label>
            </div>

            <fieldset className="registration-areas">
              <legend>Áreas de interés <span>(elige una o más)</span></legend>
              <div className="registration-areas__grid">
                {AREAS.map((area) => (
                  <label key={area.value}>
                    <input type="checkbox" name="areas_interes" value={area.value} disabled={isSubmitting} />
                    <span>{area.label}</span>
                  </label>
                ))}
              </div>
            </fieldset>

            <label className="registration-form__motivation">
              Carta de intención o motivación
              <textarea
                name="motivacion"
                rows={5}
                minLength={20}
                maxLength={2000}
                placeholder="Cuéntanos por qué quieres unirte y qué temas te gustaría explorar."
                required
                disabled={isSubmitting}
              />
            </label>

            <label className="registration-commitment">
              <input type="checkbox" name="disponibilidad" required disabled={isSubmitting} />
              <span>
                Cuento con disponibilidad para asistir a las reuniones semanales y participar en ciclos de formación
                técnica o proyectos colaborativos.
              </span>
            </label>

            <p className="registration-privacy">
              Usaremos tus datos únicamente para gestionar esta solicitud de ingreso al semillero.
            </p>

            {feedback && (
              <p className={`registration-feedback registration-feedback--${status}`} role={status === 'error' ? 'alert' : 'status'}>
                {feedback}
              </p>
            )}

            <button className="btn btn--bronze registration-submit" type="submit" disabled={isSubmitting}>
              <Send size={17} aria-hidden="true" />
              {isSubmitting ? 'Enviando...' : 'Enviar solicitud'}
            </button>
          </form>
        </section>
      </main>
    </div>
  );
}
