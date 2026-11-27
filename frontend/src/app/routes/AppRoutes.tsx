import { lazy, Suspense } from 'react';
import type { ReactNode } from 'react';
import { Navigate, Route, Routes } from 'react-router-dom';
import AppLayout from '../layout/AppLayout';
import HomePage from '../../features/home/HomePage';
import AboutPage from '../../features/about/AboutPage';
import UsersPage from '../../features/users/UsersPage';
import MemberDetailPage from '../../features/users/MemberDetailPage';
import DocumentListPage from '../../features/content/DocumentListPage';
import ReviewsPage from '../../features/reviews/ReviewsPage';
import ContactPage from '../../features/contact/ContactPage';
import InscripcionPage from '../../features/auth/InscripcionPage';
import LoginPage from '../../features/auth/LoginPage';
import RestablecerPage from '../../features/auth/RestablecerPage';
import NotFoundPage from '../../features/NotFoundPage';
import RequireAuth from '../../components/RequireAuth';
import { Cargando } from '../../components/ui';
import { ROLES_ADMINISTRATIVOS, ROLES_REVISORES } from '../../lib/auth';

// Carga diferida (RNF-06): el detalle con Markdown, el área del usuario y el panel se descargan solo al usarlos.
const DocumentDetailPage = lazy(() => import('../../features/content/DocumentDetailPage'));
const ProfilePage = lazy(() => import('../../features/account/ProfilePage'));
const MyContentPage = lazy(() => import('../../features/account/MyContentPage'));
const DocumentEditorPage = lazy(() => import('../../features/account/DocumentEditorPage'));
const AdminLayout = lazy(() => import('../../features/admin/AdminLayout'));
const AdminHomePage = lazy(() => import('../../features/admin/AdminHomePage'));
const AdminUsersPage = lazy(() => import('../../features/admin/AdminUsersPage'));
const AdminUserFormPage = lazy(() => import('../../features/admin/AdminUserFormPage'));
const AdminContentPage = lazy(() => import('../../features/admin/AdminContentPage'));
const AdminReviewsPage = lazy(() => import('../../features/admin/AdminReviewsPage'));
const AdminHomeMediaPage = lazy(() => import('../../features/admin/AdminHomeMediaPage'));
const AdminMessagesPage = lazy(() => import('../../features/admin/AdminMessagesPage'));
const AdminInscriptionsPage = lazy(() => import('../../features/admin/AdminInscriptionsPage').then((m) => ({ default: m.AdminInscriptionsPage })));
const AdminInscriptionDetailPage = lazy(() =>
  import('../../features/admin/AdminInscriptionsPage').then((m) => ({ default: m.AdminInscriptionDetailPage })),
);

const diferida = (pagina: ReactNode) => <Suspense fallback={<Cargando />}>{pagina}</Suspense>;
const conSesion = (pagina: ReactNode, roles?: readonly (typeof ROLES_REVISORES)[number][]) => (
  <RequireAuth roles={roles}>{diferida(pagina)}</RequireAuth>
);

export default function AppRoutes() {
  return (
    <Routes>
      <Route element={<AppLayout />}>
        {/* Sitio público */}
        <Route index element={<HomePage />} />
        <Route path="/acerca-de" element={<AboutPage />} />
        <Route path="/usuarios" element={<UsersPage />} />
        <Route path="/usuarios/:id" element={<MemberDetailPage />} />
        <Route
          path="/proyectos"
          element={
            <DocumentListPage
              modulo="projects"
              titulo="Proyectos"
              subtitulo="Investigación, desarrollo e innovación aplicada en sistemas de información, inteligencia artificial y análisis social."
            />
          }
        />
        <Route path="/proyectos/:id" element={diferida(<DocumentDetailPage modulo="projects" />)} />
        <Route
          path="/articulos"
          element={<DocumentListPage modulo="articles" titulo="Artículos" subtitulo="Investigaciones, publicaciones y noticias del Semillero SIIIS." />}
        />
        <Route path="/articulos/:id" element={diferida(<DocumentDetailPage modulo="articles" />)} />
        <Route
          path="/noticias"
          element={<DocumentListPage modulo="articles" categoriaFija="noticia" titulo="Noticias" subtitulo="Lo que está pasando en el Semillero SIIIS." />}
        />
        <Route path="/blog" element={<Navigate to="/noticias" replace />} />
        <Route path="/resenas" element={<ReviewsPage />} />
        <Route path="/contacto" element={<ContactPage />} />
        <Route path="/inscripcion" element={<InscripcionPage />} />
        <Route path="/registro" element={<Navigate to="/inscripcion" replace />} />
        <Route path="/login" element={<LoginPage />} />
        <Route path="/restablecer" element={<RestablecerPage />} />

        {/* Área del usuario: cualquier rol con sesión */}
        <Route path="/mi-perfil" element={conSesion(<ProfilePage />)} />
        <Route path="/mis-contenidos" element={conSesion(<MyContentPage />)} />
        <Route path="/mis-contenidos/nuevo/:tipo" element={conSesion(<DocumentEditorPage />)} />
        <Route path="/mis-contenidos/:modulo/:id/editar" element={conSesion(<DocumentEditorPage />)} />

        {/* Administración: revisores (contenido, reseñas) y administrativos (usuarios, inscripciones, mensajes) */}
        <Route path="/admin" element={conSesion(<AdminLayout />, ROLES_REVISORES)}>
          <Route index element={diferida(<AdminHomePage />)} />
          <Route path="contenido" element={diferida(<AdminContentPage />)} />
          <Route path="resenas" element={diferida(<AdminReviewsPage />)} />
          <Route path="usuarios" element={conSesion(<AdminUsersPage />, ROLES_ADMINISTRATIVOS)} />
          <Route path="usuarios/nuevo" element={conSesion(<AdminUserFormPage />, ROLES_ADMINISTRATIVOS)} />
          <Route path="usuarios/:id" element={conSesion(<AdminUserFormPage />, ROLES_ADMINISTRATIVOS)} />
          <Route path="inscripciones" element={conSesion(<AdminInscriptionsPage />, ROLES_ADMINISTRATIVOS)} />
          <Route path="inscripciones/:id" element={conSesion(<AdminInscriptionDetailPage />, ROLES_ADMINISTRATIVOS)} />
          <Route path="mensajes" element={conSesion(<AdminMessagesPage />, ROLES_ADMINISTRATIVOS)} />
          <Route path="inicio" element={conSesion(<AdminHomeMediaPage />, ROLES_ADMINISTRATIVOS)} />
        </Route>

        <Route path="*" element={<NotFoundPage />} />
      </Route>
    </Routes>
  );
}
