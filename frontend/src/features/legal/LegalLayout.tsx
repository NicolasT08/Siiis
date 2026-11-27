import type { ReactNode } from 'react';
import ProjectsHero from '../projects/components/ProjectsHero';
import '../projects/projects.css';

/** Página de texto legal con el estilo del sitio (cabecera compacta y tarjeta de lectura). */
export default function LegalLayout({ titulo, subtitulo, actualizado, children }: { titulo: string; subtitulo: string; actualizado: string; children: ReactNode }) {
  return (
    <div className="projects-page">
      <ProjectsHero compact title={titulo} subtitle={subtitulo} />
      <section className="container app-page">
        <article className="app-card legal">
          <p className="app-hint">Última actualización: {actualizado}</p>
          {children}
        </article>
      </section>
    </div>
  );
}
