(function () {
  const tokenMeta = document.querySelector('meta[name="csrf-token"]');
  const csrfToken = tokenMeta ? tokenMeta.getAttribute('content') : '';

  if (!csrfToken) {
    return;
  }

  const unsafeMethods = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);

  function toAbsoluteUrl(url) {
    try {
      return new URL(url, window.location.origin);
    } catch (e) {
      return null;
    }
  }

  function isSameOrigin(url) {
    const absolute = toAbsoluteUrl(url);
    return absolute !== null && absolute.origin === window.location.origin;
  }

  function ensureFormCsrf(form) {
    if (!(form instanceof HTMLFormElement)) {
      return;
    }

    const method = (form.getAttribute('method') || 'GET').toUpperCase();
    if (method !== 'POST') {
      return;
    }

    const action = form.getAttribute('action') || window.location.pathname;
    if (!isSameOrigin(action)) {
      return;
    }

    if (form.querySelector('input[name="csrf_token"]')) {
      return;
    }

    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'csrf_token';
    input.value = csrfToken;
    form.appendChild(input);
  }

  if (typeof window.fetch === 'function') {
    const nativeFetch = window.fetch.bind(window);

    window.fetch = function (input, init) {
      const effectiveInit = init ? { ...init } : {};
      const sourceMethod = effectiveInit.method || (input && input.method) || 'GET';
      const method = String(sourceMethod).toUpperCase();
      const url = typeof input === 'string' ? input : (input && input.url ? input.url : window.location.href);

      if (unsafeMethods.has(method) && isSameOrigin(url)) {
        const headers = new Headers(effectiveInit.headers || (input instanceof Request ? input.headers : undefined));

        if (!headers.has('X-CSRF-Token')) {
          headers.set('X-CSRF-Token', csrfToken);
        }

        if (!headers.has('X-Requested-With')) {
          headers.set('X-Requested-With', 'XMLHttpRequest');
        }

        effectiveInit.headers = headers;
      }

      return nativeFetch(input, effectiveInit);
    };
  }

  document.addEventListener('submit', function (event) {
    const form = event.target;
    ensureFormCsrf(form);
  });

  const nativeSubmit = HTMLFormElement.prototype.submit;
  HTMLFormElement.prototype.submit = function () {
    ensureFormCsrf(this);
    return nativeSubmit.call(this);
  };
})();
