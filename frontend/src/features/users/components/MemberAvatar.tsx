import { useState } from 'react';
import type { Miembro } from '../memberUtils';
import { inicialesMiembro } from '../memberUtils';

/** Foto del integrante o, si no hay (o falla), sus iniciales. */
export default function MemberAvatar({ miembro, className }: { miembro: Miembro; className: string }) {
  const [fallo, setFallo] = useState(false);
  const url = miembro.foto_perfil?.url;

  if (url && !fallo) {
    return (
      <img
        className={className}
        src={url}
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
