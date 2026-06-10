/** URL base del API Laravel (Sanctum). */
export const API_URL =
  (typeof import.meta !== 'undefined' && import.meta.env?.VITE_API_URL) ||
  'http://localhost:8000/api';

export const AUTH_SESSION_KEY = 'auth_session';
export const TOKEN_KEY = 'token';
export const USER_KEY = 'user';
