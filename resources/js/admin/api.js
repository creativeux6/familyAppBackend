const TOKEN_KEY = 'familyapp_panel_token';
const USER_KEY = 'familyapp_panel_user';

export function getToken() {
  return localStorage.getItem(TOKEN_KEY);
}

export function setSession(token, user) {
  localStorage.setItem(TOKEN_KEY, token);
  localStorage.setItem(USER_KEY, JSON.stringify(user));
}

export function clearSession() {
  localStorage.removeItem(TOKEN_KEY);
  localStorage.removeItem(USER_KEY);
}

export function getCachedUser() {
  const raw = localStorage.getItem(USER_KEY);
  if (!raw) return null;
  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

export function isAdminRole(user) {
  const roles = user?.roles ?? [];
  return roles.includes('super_admin') || roles.includes('admin');
}

async function parseError(response) {
  try {
    const data = await response.json();
    if (data.message) return data.message;
    if (data.errors) {
      return Object.values(data.errors).flat().join(' ') || 'Request failed';
    }
    return 'Request failed';
  } catch {
    return `Request failed (${response.status})`;
  }
}

export async function api(path, { method = 'GET', body, token } = {}) {
  const headers = {
    Accept: 'application/json',
    'X-Client': 'web',
  };

  const authToken = token ?? getToken();
  if (authToken) {
    headers.Authorization = `Bearer ${authToken}`;
  }

  if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
  }

  const response = await fetch(`/api/v1${path}`, {
    method,
    headers,
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });

  if (response.status === 204) {
    return null;
  }

  if (!response.ok) {
    if (response.status === 401) {
      clearSession();
    }
    throw new Error(await parseError(response));
  }

  return response.json();
}
