/** Política de contraseñas del backend (DEC-B11): 8+ caracteres, una letra y un número, máximo 72 bytes. */
export function problemaPassword(password: string): string | null {
  if (password.length < 8) return 'La contraseña debe tener al menos 8 caracteres.';
  if (new TextEncoder().encode(password).length > 72) return 'La contraseña es demasiado larga (máximo 72 bytes).';
  if (!/\p{L}/u.test(password) || !/[0-9]/.test(password)) return 'La contraseña debe tener al menos una letra y un número.';
  return null;
}
