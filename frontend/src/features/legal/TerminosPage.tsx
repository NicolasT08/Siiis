import { Link } from 'react-router-dom';
import { SITIO } from '../../config/sitio';
import LegalLayout from './LegalLayout';

export default function TerminosPage() {
  return (
    <LegalLayout
      titulo="Condiciones de uso"
      subtitulo="Reglas básicas para usar el sitio del Semillero SIIIS."
      actualizado="26 de noviembre de 2026"
    >
      <h2>1. Uso académico</h2>
      <p>
        Este sitio pertenece al Semillero de Investigación SIIIS de la {SITIO.universidad} y tiene fines académicos:
        difundir sus proyectos, publicaciones y actividades, y recibir solicitudes de ingreso. No debe usarse con fines
        comerciales ni para actividades que afecten su funcionamiento.
      </p>

      <h2>2. Contenido publicado por los integrantes</h2>
      <p>
        Cada integrante es responsable de lo que publica (proyectos, artículos, noticias, reseñas y archivos adjuntos):
        debe ser propio o contar con permiso para compartirlo, citar sus fuentes y respetar los derechos de autor y la
        privacidad de otras personas.
      </p>

      <h2>3. Moderación</h2>
      <p>
        Los administradores y revisores del semillero revisan el contenido antes de publicarlo y pueden ocultar o borrar
        publicaciones, reseñas o archivos que incumplan estas condiciones, así como desactivar cuentas que hagan un uso
        indebido del sitio.
      </p>

      <h2>4. Cuentas</h2>
      <p>
        Las cuentas son personales. Cuida tu contraseña y cierra sesión en equipos compartidos; avísanos si crees que
        alguien entró a tu cuenta.
      </p>

      <h2>5. Cambios del sitio</h2>
      <p>
        El sitio, sus funciones y estas condiciones pueden cambiar o interrumpirse temporalmente, por ejemplo por
        mantenimiento. La fecha de la última versión aparece al inicio.
      </p>

      <p>
        El tratamiento de datos personales se explica en la <Link to="/privacidad">política de privacidad</Link>. Dudas:{' '}
        <a href={`mailto:${SITIO.correoContacto}`}>{SITIO.correoContacto}</a>.
      </p>
    </LegalLayout>
  );
}
