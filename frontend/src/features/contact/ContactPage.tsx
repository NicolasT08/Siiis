import { useState } from 'react';
import type { FormEvent } from 'react';
import { Mail, Send } from 'lucide-react';
import ProjectsHero from '../projects/components/ProjectsHero';
import { api } from '../../lib/api';
import { erroresDeCampo, mensajeError } from '../../lib/errors';
import { Aviso, ErrorCampo } from '../../components/ui';
import '../projects/projects.css';
import './contact.css';

type Campo = 'nombre' | 'correo' | 'asunto' | 'mensaje';

export default function ContactPage() {
  const [enviando, setEnviando] = useState(false);
  const [ok, setOk] = useState('');
  const [error, setError] = useState('');
  const [campos, setCampos] = useState<Record<string, string>>({});

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const datos = new FormData(form);
    const valor = (c: Campo) => String(datos.get(c) ?? '').trim();
    setEnviando(true);
    setOk('');
    setError('');
    setCampos({});
    try {
      const respuesta = await api.contact({ nombre: valor('nombre'), correo: valor('correo'), asunto: valor('asunto'), mensaje: valor('mensaje') });
      form.reset();
      setOk(respuesta.mensaje);
    } catch (e) {
      setError(mensajeError(e));
      setCampos(erroresDeCampo(e));
    } finally {
      setEnviando(false);
    }
  }

  const props = (c: Campo) => ({
    id: `contact-${c}`,
    name: c,
    required: true,
    disabled: enviando,
    'aria-invalid': !!campos[c],
    'aria-describedby': `contact-${c}-e`,
  });

  return (
    <div className="projects-page contact-page">
      <ProjectsHero compact title="Contacto" subtitle="Escríbenos tus preguntas, propuestas o ideas para conectar con el Semillero SIIIS." />

      <main className="container contact-content">
        <section className="contact-card" aria-labelledby="contact-title">
          <div className="contact-card__heading">
            <span className="contact-card__icon" aria-hidden="true">
              <Mail size={25} strokeWidth={1.8} />
            </span>
            <div>
              <p className="contact-eyebrow">Hablemos</p>
              <h2 id="contact-title">Envía tu mensaje</h2>
            </div>
          </div>

          <form className="contact-form" onSubmit={(e) => void handleSubmit(e)} noValidate>
            <label htmlFor="contact-nombre">Nombre</label>
            <input {...props('nombre')} autoComplete="name" maxLength={80} />
            <ErrorCampo id="contact-nombre-e" mensaje={campos.nombre} />

            <label htmlFor="contact-correo">Correo electrónico</label>
            <input {...props('correo')} type="email" autoComplete="email" placeholder="correo@ejemplo.com" maxLength={254} />
            <ErrorCampo id="contact-correo-e" mensaje={campos.correo} />

            <label htmlFor="contact-asunto">Asunto</label>
            <input {...props('asunto')} maxLength={150} />
            <ErrorCampo id="contact-asunto-e" mensaje={campos.asunto} />

            <label htmlFor="contact-mensaje">Mensaje</label>
            <textarea {...props('mensaje')} placeholder="Escribe tu mensaje..." rows={7} maxLength={3000} />
            <ErrorCampo id="contact-mensaje-e" mensaje={campos.mensaje} />

            {ok && <Aviso tipo="ok">{ok}</Aviso>}
            {error && <Aviso tipo="error">{error}</Aviso>}

            <button className="btn btn--bronze contact-submit" type="submit" disabled={enviando}>
              <Send size={17} aria-hidden="true" />
              {enviando ? 'Enviando...' : 'Enviar'}
            </button>
          </form>
        </section>
      </main>
    </div>
  );
}
