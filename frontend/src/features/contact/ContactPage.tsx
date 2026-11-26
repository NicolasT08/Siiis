import { useState } from 'react';
import type { FormEvent } from 'react';
import { Mail, Send } from 'lucide-react';
import ProjectsHero from '../projects/components/ProjectsHero';
import { api, ApiError } from '../../lib/api';
import '../projects/projects.css';
import './contact.css';

export default function ContactPage() {
  const [status, setStatus] = useState<'idle' | 'sending' | 'sent' | 'error'>('idle');
  const [errorMessage, setErrorMessage] = useState('');

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const formData = new FormData(form);
    const email = String(formData.get('email') ?? '').trim();
    const message = String(formData.get('message') ?? '').trim();

    setStatus('sending');
    setErrorMessage('');

    try {
      const response = await api.contact(email, message);
      setStatus('sent');
      form.reset();
      if (response.mensaje) setErrorMessage('');
    } catch (error) {
      setStatus('error');
      setErrorMessage(error instanceof ApiError ? error.message : 'No se pudo enviar el mensaje.');
    }
  }

  return (
    <div className="projects-page contact-page">
      <ProjectsHero
        compact
        title="Contacto"
        subtitle="Escríbenos tus preguntas, propuestas o ideas para conectar con el Semillero SIIIS."
      />

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

          <form className="contact-form" onSubmit={handleSubmit}>
            <label htmlFor="contact-email">Email</label>
            <input
              id="contact-email"
              name="email"
              type="email"
              autoComplete="email"
              placeholder="correo@ejemplo.com"
              required
              maxLength={320}
              disabled={status === 'sending'}
            />

            <label htmlFor="contact-message">Mensaje</label>
            <textarea
              id="contact-message"
              name="message"
              placeholder="Escribe tu mensaje..."
              rows={7}
              required
              maxLength={2000}
              disabled={status === 'sending'}
            />

            {status === 'sent' && (
              <p className="contact-status contact-status--success" role="status">
                Tu mensaje fue enviado correctamente.
              </p>
            )}
            {status === 'error' && (
              <p className="contact-status contact-status--error" role="alert">
                {errorMessage}
              </p>
            )}

            <button className="btn btn--bronze contact-submit" type="submit" disabled={status === 'sending'}>
              <Send size={17} aria-hidden="true" />
              {status === 'sending' ? 'Enviando...' : 'Enviar'}
            </button>
          </form>
        </section>
      </main>
    </div>
  );
}
