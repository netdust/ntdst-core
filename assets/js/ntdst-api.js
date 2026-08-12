/**
 * NTDST API wrapper
 *
 * Shared window.ntdstAPI for mu-plugin admin pages. Enqueue via
 * ntdst_enqueue_api_client() on the PHP side; it localizes the required
 * nonce as window.ntdstAPIConfig.
 *
 * The theme bundle (stridence/src/main.js) currently inlines its own
 * identical copy for the frontend. Skipped if window.ntdstAPI is already
 * defined so loading this on the frontend is a no-op.
 *
 * Use instead of raw fetch() for any /wp-json/ntdst/v1/action endpoint.
 *
 * Required globals:
 *   window.ntdstAPIConfig.restNonce  (wp_rest cookie nonce)
 */
(function (global) {
  'use strict';

  if (global.ntdstAPI) {
    return;
  }

  function restNonce() {
    return (global.ntdstAPIConfig && global.ntdstAPIConfig.restNonce)
      || (global.strideConfig && global.strideConfig.restNonce)
      || '';
  }

  global.ntdstAPI = {
    _nonceCache: {},

    async _ensureActionNonce(action) {
      if (this._nonceCache[action]) {
        return this._nonceCache[action];
      }
      const response = await fetch('/wp-json/ntdst/v1/get_nonce', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': restNonce(),
        },
        body: JSON.stringify({ action }),
      });
      const result = await response.json();
      if (!result.success) {
        throw new Error((result.data && result.data.message) || 'Kon geen beveiligingstoken ophalen');
      }
      this._nonceCache[action] = result.data.nonce;
      return result.data.nonce;
    },

    /**
     * Call an NTDST API action (preferred method).
     * @param {string} action
     * @param {object} params
     * @returns {Promise<object>} Action result data
     */
    async call(action, params = {}) {
      const nonce = await this._ensureActionNonce(action);
      const response = await fetch('/wp-json/ntdst/v1/action', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': restNonce(),
        },
        body: JSON.stringify({ action, nonce, ...params }),
      });
      const result = await response.json();

      if (!result.success && result.data && result.data.code === 'invalid_nonce') {
        delete this._nonceCache[action];
        return this.call(action, params);
      }
      if (!result.success) {
        throw new Error((result.data && result.data.message) || 'Actie mislukt');
      }
      return result.data;
    },

    /**
     * Upload files via NTDST API action (multipart/form-data).
     * @param {string} action
     * @param {FormData} formData
     * @returns {Promise<object>}
     */
    async upload(action, formData) {
      const nonce = await this._ensureActionNonce(action);
      formData.set('action', action);
      formData.set('nonce', nonce);

      const response = await fetch('/wp-json/ntdst/v1/action', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-WP-Nonce': restNonce() },
        body: formData,
      });
      const result = await response.json();

      if (!result.success && result.data && result.data.code === 'invalid_nonce') {
        delete this._nonceCache[action];
        return this.upload(action, formData);
      }
      if (!result.success) {
        throw new Error((result.data && result.data.message) || 'Upload mislukt');
      }
      return result.data;
    },

    /**
     * Download a file via NTDST API action.
     * @param {string} action
     * @param {object} params
     * @returns {Promise<Blob>}
     */
    async download(action, params = {}) {
      const nonce = await this._ensureActionNonce(action);
      const response = await fetch('/wp-json/ntdst/v1/action', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': restNonce(),
        },
        body: JSON.stringify({ action, nonce, ...params }),
      });
      if (!response.ok) {
        throw new Error('Download mislukt');
      }
      return response.blob();
    },
  };
})(window);
