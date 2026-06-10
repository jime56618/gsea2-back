import React, { useEffect, useState } from 'react';
import { Link, useParams, useNavigate } from 'react-router-dom';
import { API_URL } from './utils/constants';
import './css/SaaS.css';

/**
 * Página pública: aceptar invitación a una promotoría.
 * Ruta sugerida: /invitacion/:token
 */
export default function AcceptInvitation() {
  const { token } = useParams();
  const navigate = useNavigate();
  const [preview, setPreview] = useState(null);
  const [name, setName] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [needsRegister, setNeedsRegister] = useState(false);
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [done, setDone] = useState(false);

  useEffect(() => {
    if (!token) return;
    fetch(`${API_URL}/invitations/token/${token}`, { headers: { Accept: 'application/json' } })
      .then((r) => r.json().then((d) => ({ ok: r.ok, d })))
      .then(({ ok, d }) => {
        if (!ok) throw new Error(d.message || 'Invitación inválida');
        setPreview(d);
      })
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false));
  }, [token]);

  const handleAccept = async (e) => {
    e.preventDefault();
    setSubmitting(true);
    setError('');
    try {
      const body = { token };
      if (needsRegister) {
        body.name = name;
        body.password = password;
        body.password_confirmation = passwordConfirmation;
      }
      const res = await fetch(`${API_URL}/invitations/accept`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(body),
      });
      const data = await res.json();
      if (!res.ok) {
        if (data.code === 'registration_required') {
          setNeedsRegister(true);
          setError('Crea una contraseña para completar el registro.');
          return;
        }
        throw new Error(data.message || 'No se pudo aceptar');
      }
      setDone(true);
    } catch (e) {
      setError(e.message);
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) {
    return (
      <div className="gsea-billing-page">
        <div className="gsea-billing-card">Cargando invitación…</div>
      </div>
    );
  }

  if (error && !preview) {
    return (
      <div className="gsea-billing-page">
        <div className="gsea-billing-card">
          <h1>Invitación no válida</h1>
          <p>{error}</p>
          <Link to="/register">Ir al login</Link>
        </div>
      </div>
    );
  }

  if (done) {
    return (
      <div className="gsea-billing-page">
        <div className="gsea-billing-card">
          <h1>¡Listo!</h1>
          <p>Ya puedes iniciar sesión con {preview?.email}</p>
          <Link to="/register" className="gsea-billing-btn" style={{ display: 'inline-block', marginTop: '1rem' }}>
            Iniciar sesión
          </Link>
        </div>
      </div>
    );
  }

  return (
    <div className="gsea-billing-page">
      <div className="gsea-billing-card" style={{ textAlign: 'left' }}>
        <h1 style={{ textAlign: 'center' }}>Unirte a {preview?.workspace?.nombre}</h1>
        <p style={{ textAlign: 'center' }}>
          Rol: <strong>{preview?.role?.nombre}</strong>
          <br />
          Correo: <strong>{preview?.email}</strong>
        </p>
        <form onSubmit={handleAccept}>
          {needsRegister && (
            <>
              <label>Nombre completo</label>
              <input className="gsea-input" value={name} onChange={(e) => setName(e.target.value)} required />
              <label>Contraseña</label>
              <input
                className="gsea-input"
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
              />
              <label>Confirmar contraseña</label>
              <input
                className="gsea-input"
                type="password"
                value={passwordConfirmation}
                onChange={(e) => setPasswordConfirmation(e.target.value)}
                required
              />
            </>
          )}
          {error && <p className="gsea-error">{error}</p>}
          <button type="submit" className="gsea-billing-btn" disabled={submitting}>
            {submitting ? 'Procesando…' : 'Aceptar invitación'}
          </button>
        </form>
      </div>
    </div>
  );
}
