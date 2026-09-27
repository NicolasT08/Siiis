import { Navigate, Route, Routes } from 'react-router-dom';
import AppLayout from '../layout/AppLayout';
import HomePage from '../../features/home/HomePage';
import ProjectsPage from '../../features/projects/ProjectsPage';
import ArticlesPage from '../../features/article/ArticlesPage';
import UsersPage from '../../features/users/UsersPage';
import AboutPage from '../../features/about/AboutPage';
import ContactPage from '../../features/contact/ContactPage';
import BlogPage from '../../features/blog/BlogPage';
import LoginPage from '../../features/auth/LoginPage';
import RegisterPage from '../../features/auth/RegisterPage';

export default function AppRoutes() {
  return (
    <Routes>
      <Route element={<AppLayout />}>
        <Route index element={<HomePage />} />
        <Route path="/" element={<HomePage />} />
        <Route path="/proyectos" element={<ProjectsPage />} />
        <Route path="/articulos" element={<ArticlesPage />} />
        <Route path="/usuarios" element={<UsersPage />} />
        <Route path="/acerca-de" element={<AboutPage />} />
        <Route path="/contacto" element={<ContactPage />} />
        <Route path="/blog" element={<BlogPage />} />
        <Route path="/login" element={<LoginPage />} />
        <Route path="/registro" element={<RegisterPage />} />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Route>
    </Routes>
  );
}
