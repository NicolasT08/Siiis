export default function LoginPage() {
  return (
    <section className="auth-page container">
      <div className="auth-card">
        <h1>Iniciar sesión</h1>
        <form>
          <label>
            Correo
            <input type="email" placeholder="correo@ejemplo.com" />
          </label>
          <label>
            Contraseña
            <input type="password" placeholder="********" />
          </label>
          <button type="submit" className="primary-btn">Entrar</button>
        </form>
        <p>
          ¿No tienes cuenta? <a href="/registro">Regístrate</a>
        </p>
      </div>
    </section>
  );
}
