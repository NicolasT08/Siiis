import { Link } from 'react-router-dom';
import { SITIO } from '../../config/sitio';
import LegalLayout from './LegalLayout';

export default function PrivacidadPage() {
  const correo = SITIO.correoContacto;
  return (
    <LegalLayout
      titulo="Política de privacidad"
      subtitulo="Cómo trata el Semillero SIIIS los datos personales que recibe a través de este sitio."
      actualizado="26 de noviembre de 2026"
    >
      <h2>1. Responsable</h2>
      <p>
        El responsable del tratamiento es el Semillero de Investigación SIIIS de la {SITIO.universidad}. Esta política
        se rige por la Ley 1581 de 2012 y sus decretos reglamentarios sobre protección de datos personales en Colombia.
      </p>

      <h2>2. Qué datos recogemos</h2>
      <ul>
        <li>
          <strong>Cuentas de integrantes:</strong> nombre, apellido, correo institucional, rol, programa académico,
          semestre, teléfono (opcional), biografía y foto de perfil. El correo y el teléfono no se publican.
        </li>
        <li>
          <strong>Solicitudes de inscripción:</strong> nombre completo, correo, código estudiantil, programa académico,
          semestre, áreas de interés, carta de motivación y, si se adjuntan, documentos de apoyo.
        </li>
        <li>
          <strong>Mensajes de contacto:</strong> nombre, correo, asunto y mensaje.
        </li>
        <li>
          <strong>Inicio de sesión con Google:</strong> solo se leen el nombre y el correo institucional que Google
          confirma, para identificar a integrantes que ya tienen cuenta. No se crean cuentas ni se accede a otros datos
          de Google.
        </li>
        <li>
          <strong>Datos técnicos de sesión:</strong> fecha y dirección IP de los inicios de sesión, para la seguridad de
          las cuentas.
        </li>
      </ul>

      <h2>3. Para qué los usamos</h2>
      <ul>
        <li>Gestionar las cuentas de los integrantes y mostrar su perfil público en el sitio.</li>
        <li>Evaluar las solicitudes de ingreso al semillero y comunicar su resultado.</li>
        <li>Responder los mensajes de contacto.</li>
        <li>Publicar los proyectos, artículos, noticias y reseñas del semillero.</li>
        <li>Proteger el sitio frente a usos indebidos (por ejemplo, intentos repetidos de inicio de sesión).</li>
      </ul>

      <h2>4. Con quién los compartimos</h2>
      <p>
        No vendemos ni compartimos datos personales con terceros. Solo los procesan los servicios técnicos que hacen
        funcionar el sitio: <strong>MongoDB Atlas</strong> (base de datos), <strong>Cloudinary</strong> (fotos y
        documentos) y <strong>Google</strong> (inicio de sesión con la cuenta institucional y envío de correos). Los
        documentos de las solicitudes se guardan de forma privada y solo los ven los administradores del semillero.
      </p>

      <h2>5. Cuánto tiempo los conservamos</h2>
      <ul>
        <li>Las cuentas de integrantes, mientras pertenezcan al semillero; al retirarse, la cuenta se desactiva.</li>
        <li>
          Las solicitudes de inscripción <strong>rechazadas se conservan 6 meses</strong> y luego se borran, con sus
          documentos.
        </li>
        <li>Los mensajes de contacto, el tiempo necesario para atenderlos.</li>
      </ul>

      <h2>6. Tus derechos</h2>
      <p>
        Puedes conocer, actualizar, corregir o pedir la eliminación de tus datos, y revocar la autorización para su
        tratamiento, escribiendo a <a href={`mailto:${correo}`}>{correo}</a>. Responderemos en los plazos que fija la
        Ley 1581 de 2012. Los integrantes también pueden editar sus datos en "Mi perfil".
      </p>

      <h2>7. Cambios</h2>
      <p>
        Podemos actualizar esta política; la fecha de la última versión aparece al inicio. Consulta también las{' '}
        <Link to="/terminos">condiciones de uso</Link>.
      </p>
    </LegalLayout>
  );
}
