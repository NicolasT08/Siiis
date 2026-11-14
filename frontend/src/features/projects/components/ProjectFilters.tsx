import { useId } from 'react';
import { ChevronDown, Search, X } from 'lucide-react';
import type { Filtros } from '../projectUtils';

type Opcion = { value: string; label: string };

type Props = {
  filtros: Filtros;
  opciones: { estado: Opcion[]; categoria: Opcion[]; anio: Opcion[] };
  disabled: boolean;
  onChange: (cambio: Partial<Filtros>) => void;
  onClear: () => void;
};

function Select({
  label,
  value,
  options,
  disabled,
  onChange,
}: {
  label: string;
  value: string;
  options: Opcion[];
  disabled: boolean;
  onChange: (value: string) => void;
}) {
  const id = useId();
  return (
    <div className="filter-select">
      <label htmlFor={id} className="visually-hidden">
        {label}
      </label>
      <select id={id} value={value} disabled={disabled} onChange={(e) => onChange(e.target.value)}>
        <option value="">{label}</option>
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
      <ChevronDown className="filter-select__chevron" size={16} aria-hidden="true" />
    </div>
  );
}

export default function ProjectFilters({ filtros, opciones, disabled, onChange, onClear }: Props) {
  const searchId = useId();
  const activos = Boolean(filtros.estado || filtros.categoria || filtros.anio || filtros.q);

  return (
    <form className="project-filters" role="search" onSubmit={(e) => e.preventDefault()}>
      <Select
        label="Estado"
        value={filtros.estado}
        options={opciones.estado}
        disabled={disabled}
        onChange={(estado) => onChange({ estado })}
      />
      <Select
        label="Categoría"
        value={filtros.categoria}
        options={opciones.categoria}
        disabled={disabled}
        onChange={(categoria) => onChange({ categoria })}
      />
      <Select
        label="Año"
        value={filtros.anio}
        options={opciones.anio}
        disabled={disabled}
        onChange={(anio) => onChange({ anio })}
      />

      <button type="button" className="btn btn--bronze" onClick={onClear} disabled={!activos}>
        <X size={16} aria-hidden="true" /> Limpiar
      </button>

      <div className="filter-search">
        <label htmlFor={searchId} className="visually-hidden">
          Buscar proyectos por título, autor o descripción
        </label>
        <Search className="filter-search__icon" size={17} aria-hidden="true" />
        <input
          id={searchId}
          type="search"
          placeholder="Buscar proyectos…"
          value={filtros.q}
          disabled={disabled}
          onChange={(e) => onChange({ q: e.target.value })}
        />
      </div>
    </form>
  );
}
