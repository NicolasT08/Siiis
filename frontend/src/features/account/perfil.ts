/** Lee los campos de perfil de un formulario con la forma que espera PATCH /users/{id} (vacío → null). */
export function leerPerfil(datos: FormData) {
  const texto = (c: string) => String(datos.get(c) ?? '').trim();
  const opcional = (c: string) => texto(c) || null;
  const semestre = Number(texto('semestre'));
  return {
    nombre: texto('nombre'),
    apellido: texto('apellido'),
    programa_academico: opcional('programa_academico'),
    semestre: semestre >= 1 ? semestre : null,
    telefono: opcional('telefono'),
    biografia: opcional('biografia'),
  };
}
