import { useId } from 'react';
import { Download, FileText } from 'lucide-react';
import type { Documento } from '../../lib/api';
import { pdfPrincipal, urlDescarga } from '../../lib/documentoPdf';
import Markdown from '../../components/Markdown';

/**
 * Contenido de un proyecto, artículo o noticia (DEC-B70): el texto Markdown y después el PDF principal, cada uno
 * solo si existe. Lo comparten la página de detalle y la vista previa del perfil (RF-05).
 */
export default function DocumentoContenido({ documento }: { documento: Documento }) {
  const pdfTituloId = useId();
  const texto = documento.contenido?.trim() ? documento.contenido : null;
  const pdf = pdfPrincipal(documento.archivos_adjuntos);

  return (
    <>
      {texto && (
        <section className="app-section" aria-label="Contenido">
          <Markdown texto={texto} />
        </section>
      )}

      {pdf && (
        <section className="app-section doc-pdf" aria-labelledby={pdfTituloId}>
          <div className="doc-pdf__header">
            <h3 id={pdfTituloId}>
              <FileText size={18} aria-hidden="true" /> {pdf.nombre_archivo}
            </h3>
            <a className="btn btn--bronze btn--sm" href={urlDescarga(pdf.url)} download={pdf.nombre_archivo} target="_blank" rel="noopener noreferrer">
              <Download size={16} aria-hidden="true" /> Descargar PDF
            </a>
          </div>
          <object className="doc-pdf__viewer" data={pdf.url} type="application/pdf" aria-label={`Documento: ${pdf.nombre_archivo}`}>
            <p className="app-hint">
              Tu navegador no puede mostrar el PDF aquí.{' '}
              <a href={pdf.url} target="_blank" rel="noopener noreferrer">
                Ábrelo en otra pestaña
              </a>
              .
            </p>
          </object>
        </section>
      )}
    </>
  );
}
