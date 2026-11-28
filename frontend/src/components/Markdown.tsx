import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { imagenCloudinary } from '../lib/cloudinary';

/**
 * Contenido Markdown de proyectos y artículos (DEC-B37) mostrado de forma segura:
 * - `skipHtml`: el HTML incrustado no se interpreta ni se muestra (el backend lo guarda tal cual).
 * - react-markdown no usa dangerouslySetInnerHTML y su `urlTransform` por defecto descarta `javascript:` y
 *   otros protocolos peligrosos en enlaces e imágenes.
 * - Los enlaces externos se abren en otra pestaña sin dar acceso a esta (`noopener noreferrer`).
 */
export default function Markdown({ texto }: { texto: string }) {
  return (
    <div className="app-markdown">
      <ReactMarkdown
        skipHtml
        remarkPlugins={[remarkGfm]}
        components={{
          a: ({ href, children }) => {
            const externo = !!href && /^https?:\/\//i.test(href);
            return (
              <a href={href} {...(externo ? { target: '_blank', rel: 'noopener noreferrer' } : {})}>
                {children}
              </a>
            );
          },
          img: ({ src, alt }) => <img src={typeof src === 'string' ? imagenCloudinary(src, 1600) : undefined} alt={alt ?? ''} loading="lazy" decoding="async" />,
        }}
      >
        {texto}
      </ReactMarkdown>
    </div>
  );
}
