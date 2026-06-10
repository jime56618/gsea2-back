import React from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import '../css/SaaS.css';

/**
 * Banner superior: días restantes de trial o aviso de bloqueo próximo.
 */
export default function TrialBanner() {
  const { subscription, locked, can } = useAuth();

  if (!subscription || locked) return null;

  const { status, days_left, trial_ends_at } = subscription;
  const isTrialing = status === 'trialing';

  if (!isTrialing) return null;

  const urgent = days_left <= 3;

  return (
    <div className={`gsea-trial-banner ${urgent ? 'gsea-trial-banner--urgent' : ''}`}>
      <span>
        Periodo de prueba: te quedan <strong>{days_left}</strong> día{days_left !== 1 ? 's' : ''}.
        {trial_ends_at && (
          <span className="gsea-trial-banner__date">
            {' '}
            (vence {new Date(trial_ends_at).toLocaleDateString('es-MX')})
          </span>
        )}
      </span>
      {can('billing.manage') && (
        <Link to="/configuracion/facturacion" className="gsea-trial-banner__cta">
          Activar plan
        </Link>
      )}
    </div>
  );
}
