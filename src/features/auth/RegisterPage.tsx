export default function RegisterPage() {
  return (
    <section className="auth-page container">
      <div className="auth-card">
        <h1>Registro</h1>
        <form>
          <label>
            Nombre
            <input type="text" placeholder="Tu nombre" />
          </label>
          <label>
            Correo
            <input type="email" placeholder="correo@ejemplo.com" />
          </label>
          <label>
            Contraseña
            <input type="password" placeholder="********" />
          </label>
          <button type="submit" className="primary-btn">Crear cuenta</button>
        </form>
        <p>
          ¿Ya tienes cuenta? <a href="/login">Inicia sesión</a>
        </p>
      </div>
    </section>
  );
}
