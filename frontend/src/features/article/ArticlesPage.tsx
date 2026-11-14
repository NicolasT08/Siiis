import { useEffect, useId, useRef, useState } from 'react';
import { CalendarDays, ChevronDown, CircleCheck, Clock, Layers, Search, X } from 'lucide-react';
import ProjectsHero from '../projects/components/ProjectsHero';
import './articles.css';
import '../projects/projects.css';

type ArticleStatus = 'aprobado' | 'pendiente';
type ArticleFilters = { status: ArticleStatus | ''; year: string; query: string };
type FilterOption = { value: string; label: string };

type Article = {
  id: string;
  title: string;
  summary: string;
  status: ArticleStatus;
  author: string;
  date: string;
  image: string;
};

const ARTICLES: Article[] = [
  {
    id: 'snowballing-literature-searches',
    title:
      'Semi-Automatic Mapping Technique Using Snowballing to Support Massive Literature Searches in Software Engineering',
    summary:
      'Systematic literature reviews represent an important methodology in Evidence-Based Software Engineering.',
    status: 'aprobado',
    author: 'Jimmy Alejandro Zea Gutiérrez',
    date: '2022-05-21',
    image: '/assets/proyectos/proyecto-academia-historia.png',
  },
  {
    id: 'patrimonio-boyaca-vision-artificial',
    title: 'Reconocimiento e Interacción con el Patrimonio Histórico de Boyacá: un Enfoque Basado en Visión Artificial.',
    summary:
      'La visión por computador o visión computarizada hace referencia a un grupo de tecnologías o herramientas que permiten a los equipos captar imágenes del mundo real, procesarlas y generar información a través de ellas (análisis).',
    status: 'pendiente',
    author: 'Admin SIIIS',
    date: '2025-04-05',
    image: '/assets/proyectos/proyecto-patrimonio-boyaca.png',
  },
];

const STATUS_LABELS: Record<ArticleStatus, string> = {
  aprobado: 'Aprobado',
  pendiente: 'En espera',
};

const STATUS_OPTIONS: FilterOption[] = [
  { value: 'aprobado', label: 'Aprobados' },
  { value: 'pendiente', label: 'En espera' },
];

function normalizeText(value: string): string {
  return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
}

function FilterSelect({
  label,
  value,
  options,
  onChange,
}: {
  label: string;
  value: string;
  options: FilterOption[];
  onChange: (value: string) => void;
}) {
  const id = useId();

  return (
    <div className="filter-select">
      <label htmlFor={id} className="visually-hidden">
        {label}
      </label>
      <select id={id} value={value} onChange={(event) => onChange(event.target.value)}>
        <option value="">{label}</option>
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
      <ChevronDown className="filter-select__chevron" size={16} aria-hidden="true" />
    </div>
  );
}

function ArticleStats({ articles }: { articles: Article[] }) {
  const stats = [
    { Icon: Layers, value: articles.length, label: 'Artículos registrados' },
    { Icon: CircleCheck, value: articles.filter((article) => article.status === 'aprobado').length, label: 'Aprobados' },
    {
      Icon: Clock,
      value: articles.filter((article) => article.status === 'pendiente').length,
      label: 'En espera de aprobación',
    },
  ];

  return (
    <div className="container">
      <ul className="project-stats" aria-label="Resumen de artículos">
        {stats.map(({ Icon, value, label }) => (
          <li key={label} className="project-stats__item">
            <Icon className="project-stats__icon" size={30} strokeWidth={1.6} aria-hidden="true" />
            <div>
              <strong className="project-stats__value">{value}</strong>
              <span className="project-stats__label">{label}</span>
            </div>
          </li>
        ))}
      </ul>
    </div>
  );
}

function ArticleStatusBadge({ status }: { status: ArticleStatus }) {
  return (
    <span className="article-status">
      <span className="visually-hidden">Estado: </span>
      {STATUS_LABELS[status]}
    </span>
  );
}

function ArticleCard({ article, onOpen }: { article: Article; onOpen: (article: Article) => void }) {
  const initials = article.author
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part[0])
    .join('')
    .toUpperCase();

  return (
    <article className="project-card article-card">
      <div className="article-card__media">
        <img className="article-card__image" src={article.image} alt="" loading="lazy" decoding="async" />
      </div>
      <div className="article-card__body">
        <ArticleStatusBadge status={article.status} />
        <h2 className="article-card__title">{article.title}</h2>
        <p className="article-card__summary">{article.summary}</p>
        <button className="article-card__open" type="button" onClick={() => onOpen(article)}>
          Abrir
        </button>
        <footer className="article-card__footer">
          <span className="article-card__avatar" aria-hidden="true">
            {initials}
          </span>
          <span className="article-card__meta">
            <span className="article-card__author">{article.author}</span>
            <time className="article-card__date" dateTime={article.date}>
              <CalendarDays size={14} aria-hidden="true" />
              {article.date}
            </time>
          </span>
        </footer>
      </div>
    </article>
  );
}

function ArticleDialog({ article, onClose }: { article: Article; onClose: () => void }) {
  const dialogRef = useRef<HTMLDialogElement>(null);

  useEffect(() => {
    dialogRef.current?.showModal();
  }, []);

  return (
    <dialog ref={dialogRef} className="article-dialog" onClose={onClose} aria-labelledby="article-dialog-title">
      <div className="article-dialog__body">
        <button
          className="article-dialog__close"
          type="button"
          onClick={() => dialogRef.current?.close()}
          aria-label="Cerrar detalles del artículo"
        >
          <X size={20} aria-hidden="true" />
        </button>
        <ArticleStatusBadge status={article.status} />
        <h2 id="article-dialog-title" className="article-dialog__title">
          {article.title}
        </h2>
        <p className="article-dialog__summary">{article.summary}</p>
        <p className="article-dialog__meta">
          {article.author} · <time dateTime={article.date}>{article.date}</time>
        </p>
      </div>
    </dialog>
  );
}

export default function ArticlesPage() {
  const [filters, setFilters] = useState<ArticleFilters>({ status: '', year: '', query: '' });
  const [selectedArticle, setSelectedArticle] = useState<Article | null>(null);
  const searchId = useId();
  const years = [...new Set(ARTICLES.map((article) => article.date.slice(0, 4)))]
    .sort((a, b) => b.localeCompare(a))
    .map((year) => ({ value: year, label: year }));
  const query = normalizeText(filters.query.trim());
  const articles = ARTICLES.filter((article) => {
    if (filters.status && article.status !== filters.status) return false;
    if (filters.year && article.date.slice(0, 4) !== filters.year) return false;
    if (query && !normalizeText(`${article.title} ${article.summary} ${article.author}`).includes(query)) return false;
    return true;
  });
  const hasActiveFilters = Boolean(filters.status || filters.year || filters.query);
  const clearFilters = () => setFilters({ status: '', year: '', query: '' });

  return (
    <div className="projects-page articles-page">
      <ProjectsHero
        title="Artículos"
        subtitle="Investigaciones y publicaciones del Semillero de Investigación SIIIS."
      />
      <ArticleStats articles={ARTICLES} />

      <section className="container articles-explorer" aria-labelledby="articles-title">
        <div className="articles-explorer__head">
          <h2 id="articles-title" className="projects-explorer__title">
            Explora <span>los artículos</span>
          </h2>
          <form
            className="project-filters"
            role="search"
            aria-label="Buscar y filtrar artículos"
            onSubmit={(event) => event.preventDefault()}
          >
            <FilterSelect
              label="Estado"
              value={filters.status}
              options={STATUS_OPTIONS}
              onChange={(status) =>
                setFilters((current) => ({
                  ...current,
                  status: status === 'aprobado' || status === 'pendiente' ? status : '',
                }))
              }
            />
            <FilterSelect
              label="Año"
              value={filters.year}
              options={years}
              onChange={(year) => setFilters((current) => ({ ...current, year }))}
            />
            <button type="button" className="btn btn--bronze" onClick={clearFilters} disabled={!hasActiveFilters}>
              <X size={16} aria-hidden="true" /> Limpiar
            </button>
            <div className="filter-search">
              <label htmlFor={searchId} className="visually-hidden">
                Buscar artículos por título, autor o descripción
              </label>
              <Search className="filter-search__icon" size={17} aria-hidden="true" />
              <input
                id={searchId}
                type="search"
                placeholder="Buscar artículos…"
                value={filters.query}
                onChange={(event) => setFilters((current) => ({ ...current, query: event.target.value }))}
              />
            </div>
          </form>
        </div>

        <p className="visually-hidden" aria-live="polite">
          {articles.length} {articles.length === 1 ? 'artículo encontrado' : 'artículos encontrados'}
        </p>
        {articles.length > 0 ? (
          <div className="articles-grid">
            {articles.map((article) => (
              <ArticleCard key={article.id} article={article} onOpen={setSelectedArticle} />
            ))}
          </div>
        ) : (
          <p className="article-empty">No se encontraron artículos con esos filtros.</p>
        )}
      </section>

      {selectedArticle && <ArticleDialog article={selectedArticle} onClose={() => setSelectedArticle(null)} />}
    </div>
  );
}
