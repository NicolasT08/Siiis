import { useState } from 'react';
import type { Miembro } from '../memberUtils';
import { inicialesMiembro } from '../memberUtils';
import { imagenCloudinary } from '../../../lib/cloudinary';

/** Foto del integrante o, si no hay (o falla), sus iniciales. */
/** `ancho`: píxeles que se piden a Cloudinary (unas 2x del tamaño en que se muestra). */
export default function MemberAvatar({ miembro, className, ancho = 640 }: { miembro: Miembro; className: string; ancho?: number }) {
  const [fallo, setFallo] = useState(false);
  const url = miembro.foto_perfil?.url;

  if (url && !fallo) {
    return (
      <img
        className={className}
        src={imagenCloudinary(url, ancho)}
        alt=""
        loading="lazy"
        decoding="async"
        draggable={false}
        onError={() => setFallo(true)}
      />
    );
  }
  return (
    <span className={`${className} member-avatar--initials`} aria-hidden="true">
      {inicialesMiembro(miembro)}
    </span>
  );
}
