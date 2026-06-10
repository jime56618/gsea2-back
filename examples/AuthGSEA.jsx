import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import './css/AuthGSEA.css';
import logo from '../assets/images/logo-gsea.png';
import { API_URL, TOKEN_KEY } from './utils/constants';
import { persistWorkspaceFromUser } from './utils/workspaceStorage';

const JSON_HEADERS = {
  'Content-Type': 'application/json',
  Accept: 'application/json',
};

function formatApiError(data) {
  if (!data) return 'Error en la solicitud';
  if (typeof data.message === 'string' && data.message) return data.message;
  if (data.errors && typeof data.errors === 'object') {
    const flat = Object.values(data.errors).flat().filter(Boolean);
    if (flat.length) return String(flat[0]);
  }
  return 'Error en la solicitud';
}

export default function AuthGSEA() {
  const [isLogin, setIsLogin] = useState(true);
  const [showPassword, setShowPassword] = useState(false);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [regName, setRegName] = useState('');
  const [regEmail, setRegEmail] = useState('');
  const [regPassword, setRegPassword] = useState('');
  const [regPasswordConfirmation, setRegPasswordConfirmation] = useState('');
  const [regWorkspace, setRegWorkspace] = useState('');
  const [loading, setLoading] = useState(false);
  const [errorMsg, setErrorMsg] = useState('');
  const navigate = useNavigate();

  const persistLogin = (data) => {
    localStorage.setItem(TOKEN_KEY, data.token);
    persistWorkspaceFromUser(data);
  };

  const handleLogin = async (e) => {
    e.preventDefault();
    setLoading(true);
    setErrorMsg('');

    try {
      const res = await fetch(`${API_URL}/login`, {
        method: 'POST',
        headers: JSON_HEADERS,
        body: JSON.stringify({
          email,
          password,
          device_name: 'web',
        }),
      });

      const data = await res.json();

      if (!res.ok) {
        setErrorMsg(formatApiError(data));
        return;
      }

      persistLogin(data);
      navigate('/dashboard');
    } catch (error) {
      console.error(error);
      setErrorMsg('Error de conexión con el servidor');
    } finally {
      setLoading(false);
    }
  };

  const handleRegister = async (e) => {
    e.preventDefault();
    setLoading(true);
    setErrorMsg('');

    try {
      const res = await fetch(`${API_URL}/register`, {
        method: 'POST',
        headers: JSON_HEADERS,
        body: JSON.stringify({
          name: regName,
          email: regEmail,
          password: regPassword,
          password_confirmation: regPasswordConfirmation,
          workspace: regWorkspace,
          device_name: 'web',
        }),
      });

      const data = await res.json();

      if (!res.ok) {
        setErrorMsg(formatApiError(data));
        return;
      }

      persistLogin(data);
      navigate('/dashboard');
    } catch (error) {
      console.error(error);
      setErrorMsg('Error de conexión con el servidor');
    } finally {
      setLoading(false);
    }
  };

  const EyeIcon = () => (
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="gsea-auth-eye-icon">
      <path strokeLinecap="round" strokeLinejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
      <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
    </svg>
  );

  const EyeSlashIcon = () => (
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" className="gsea-auth-eye-icon">
      <path strokeLinecap="round" strokeLinejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
    </svg>
  );

  return (
    <div className="gsea-auth-page-wrapper">
      <div className={`gsea-auth-main-card ${!isLogin ? 'gsea-auth-mode-register' : ''}`}>
        <div className="gsea-auth-forms-container">
          {isLogin && (
            <div className="gsea-auth-form-wrapper gsea-auth-login-view">
              <h2 className="gsea-auth-title">Bienvenido</h2>
              <p className="gsea-auth-subtitle">Acceso al sistema GSEA</p>

              <form className="gsea-auth-form-element" onSubmit={handleLogin}>
                <div className="gsea-auth-field">
                  <label>Dirección de Correo</label>
                  <input
                    type="email"
                    name="email"
                    autoComplete="email"
                    placeholder="agente@gsea.com"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    required
                  />
                </div>

                <div className="gsea-auth-field">
                  <label>Contraseña</label>
                  <div className="gsea-auth-input-group">
                    <input
                      type={showPassword ? 'text' : 'password'}
                      name="password"
                      autoComplete="current-password"
                      placeholder="••••••••"
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                      required
                    />
                    <button
                      type="button"
                      className="gsea-auth-eye-button"
                      onClick={() => setShowPassword(!showPassword)}
                    >
                      {showPassword ? <EyeSlashIcon /> : <EyeIcon />}
                    </button>
                  </div>
                </div>

                {errorMsg && <p className="gsea-auth-error">{errorMsg}</p>}

                <button type="submit" className="gsea-auth-btn-primary" disabled={loading}>
                  {loading ? 'Cargando...' : 'Iniciar Sesión'}
                </button>
              </form>

              <p className="gsea-auth-footer-text">
                ¿No tienes cuenta?{' '}
                <span
                  className="gsea-auth-link-action"
                  onClick={() => {
                    setErrorMsg('');
                    setIsLogin(false);
                  }}
                >
                  Regístrate aquí
                </span>
              </p>
            </div>
          )}

          <div className="gsea-auth-form-wrapper gsea-auth-register-view">
            <h2 className="gsea-auth-title">Registro</h2>
            <p className="gsea-auth-subtitle">Crea tu promotoría (trial 14 días)</p>

            <form className="gsea-auth-form-element" onSubmit={handleRegister}>
              <div className="gsea-auth-register-scrollable">
                <div className="gsea-auth-field">
                  <label>Nombre completo</label>
                  <input
                    type="text"
                    placeholder="Nombre Apellido"
                    value={regName}
                    onChange={(e) => setRegName(e.target.value)}
                    required
                    autoComplete="name"
                  />
                </div>
                <div className="gsea-auth-field">
                  <label>Email</label>
                  <input
                    type="email"
                    placeholder="correo@ejemplo.com"
                    value={regEmail}
                    onChange={(e) => setRegEmail(e.target.value)}
                    required
                    autoComplete="email"
                  />
                </div>
                <div className="gsea-auth-grid-compact">
                  <div className="gsea-auth-field">
                    <label>Contraseña</label>
                    <div className="gsea-auth-input-group">
                      <input
                        type={showPassword ? 'text' : 'password'}
                        placeholder="••••"
                        value={regPassword}
                        onChange={(e) => setRegPassword(e.target.value)}
                        required
                        autoComplete="new-password"
                      />
                      <button type="button" className="gsea-auth-eye-button" onClick={() => setShowPassword(!showPassword)}>
                        {showPassword ? <EyeSlashIcon /> : <EyeIcon />}
                      </button>
                    </div>
                  </div>
                  <div className="gsea-auth-field">
                    <label>Confirmar</label>
                    <div className="gsea-auth-input-group">
                      <input
                        type={showPassword ? 'text' : 'password'}
                        placeholder="••••"
                        value={regPasswordConfirmation}
                        onChange={(e) => setRegPasswordConfirmation(e.target.value)}
                        required
                        autoComplete="new-password"
                      />
                      <button type="button" className="gsea-auth-eye-button" onClick={() => setShowPassword(!showPassword)}>
                        {showPassword ? <EyeSlashIcon /> : <EyeIcon />}
                      </button>
                    </div>
                  </div>
                </div>
                <div className="gsea-auth-field">
                  <label>Nombre de la promotoría</label>
                  <input
                    type="text"
                    className="gsea-auth-select"
                    placeholder="Ej. Mi promotoría"
                    value={regWorkspace}
                    onChange={(e) => setRegWorkspace(e.target.value)}
                    required
                  />
                </div>
              </div>

              {errorMsg && <p className="gsea-auth-error">{errorMsg}</p>}

              <button type="submit" className="gsea-auth-btn-primary" disabled={loading}>
                {loading ? 'Cargando...' : 'Crear cuenta'}
              </button>
            </form>

            <p className="gsea-auth-footer-text">
              ¿Ya tienes cuenta?
              <span
                className="gsea-auth-link-action"
                onClick={() => {
                  setErrorMsg('');
                  setIsLogin(true);
                }}
              >
                {' '}
                Inicia sesión
              </span>
            </p>
          </div>
        </div>

        <div className="gsea-auth-overlay-panel">
          <div className="gsea-auth-overlay-content">
            <div className="gsea-auth-logo-box">
              <img src={logo} alt="GSEA Logo" className="gsea-auth-logo-img" />
            </div>
            <h1 className="gsea-auth-brand">GSEA CRM</h1>
            <p className="gsea-auth-description">Potenciando la gestión de seguros con datos reales.</p>
          </div>
        </div>
      </div>
    </div>
  );
}
