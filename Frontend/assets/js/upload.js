(() => {
  'use strict';
  const defaults = { portada: 5 * 1024 ** 2, trailer: 100 * 1024 ** 2, pelicula: 2 * 1024 ** 3 };
  const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

  document.querySelectorAll('[data-upload-form]').forEach((form) => {
    const fileInput = form.querySelector('input[type="file"]');
    const progress = form.querySelector('[data-upload-progress]');
    const status = form.querySelector('[data-upload-status]');
    const cancelButton = form.querySelector('[data-upload-cancel]');
    const submit = form.querySelector('[type="submit"]');
    const token = () => form.querySelector('[name="_token"]')?.value || '';
    let active = false;
    let cancelled = false;
    let uploadId = null;
    let xhr = null;
    let controller = null;

    const say = (message) => { if (status) status.textContent = message; };
    const api = async (url, values = {}, signal) => {
      const body = new URLSearchParams({ _token: token(), ...values });
      const response = await fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-Token': token() }, signal });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) {
        const message = typeof data.error === 'string' ? data.error : data.message;
        const error = new Error(message || 'No se pudo procesar la carga. Actualiza la página y vuelve a intentarlo.');
        error.status = response.status;
        throw error;
      }
      return data;
    };

    const put = (url, blob, headers, previous, total) => new Promise((resolve, reject) => {
      if (cancelled) { reject(new DOMException('Carga cancelada', 'AbortError')); return; }
      xhr = new XMLHttpRequest();
      xhr.open('PUT', url);
      xhr.timeout = 10 * 60 * 1000;
      Object.entries(headers || {}).forEach(([key, value]) => xhr.setRequestHeader(key, value));
      xhr.upload.onprogress = (event) => {
        if (progress && event.lengthComputable) progress.value = Math.min(99, Math.round(((previous + event.loaded) / total) * 100));
      };
      xhr.onload = () => {
        if (xhr.status >= 200 && xhr.status < 300) resolve(xhr.getResponseHeader('ETag'));
        else reject(new Error('La transferencia se interrumpió. Comprueba tu conexión e inténtalo de nuevo.'));
      };
      xhr.onerror = () => reject(new Error('No se pudo conectar con el almacenamiento. Comprueba la conexión y la configuración de CORS.'));
      xhr.ontimeout = () => reject(new Error('La transferencia tardó demasiado. Vuelve a intentarlo.'));
      xhr.onabort = () => reject(new DOMException('Carga cancelada', 'AbortError'));
      xhr.send(blob);
    });

    const stop = async () => {
      if (!active) return;
      cancelled = true;
      controller?.abort();
      xhr?.abort();
      if (cancelButton) cancelButton.disabled = true;
      say('Cancelando la carga…');
      if (uploadId) {
        try { await api(form.dataset.cancelUrl, { upload_id: uploadId }); }
        catch { /* Expired abandoned uploads are also handled by server cleanup. */ }
      }
      say('Carga cancelada. Puedes seleccionar otro archivo.');
    };
    cancelButton?.addEventListener('click', stop);

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (active || !fileInput?.files?.length) return;
      const file = fileInput.files[0];
      const kind = form.dataset.kind;
      const maximum = Number(form.dataset.maxBytes || defaults[kind]);
      const inferred = { jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', webp: 'image/webp', mp4: 'video/mp4' }[file.name.split('.').pop()?.toLowerCase()];
      const mime = file.type || inferred;
      const allowed = kind === 'portada' ? ['image/jpeg', 'image/png', 'image/webp'] : ['video/mp4'];
      if (!allowed.includes(mime)) { say('Selecciona un archivo con el formato indicado.'); return; }
      if (file.size < 12 || !Number.isFinite(maximum) || file.size > maximum) { say('El archivo está vacío o supera el tamaño permitido.'); return; }
      cancelled = false;
      active = true;
      uploadId = null;
      controller = new AbortController();
      if (submit) submit.disabled = true;
      fileInput.disabled = true;
      if (progress) { progress.hidden = false; progress.value = 0; }
      if (cancelButton) { cancelButton.hidden = false; cancelButton.disabled = false; }
      const values = new FormData(form);
      try {
        say('Preparando la carga…');
        const upload = await api(form.dataset.initUrl, {
          pelicula_id: form.dataset.movieId,
          tipo: kind,
          mime,
          tamano: String(file.size),
          fuente_url: values.get('fuente_url') || '',
          referencia_derechos: values.get('referencia_derechos') || '',
        }, controller.signal);
        uploadId = upload.upload_id;
        const parts = [];
        if (upload.mode === 'multipart') {
          const partSize = Number(upload.part_size);
          const count = Math.ceil(file.size / partSize);
          for (let number = 1; number <= count; number++) {
            let completed = false;
            for (let attempt = 0; attempt < 3 && !completed; attempt++) {
              if (cancelled) throw new DOMException('Carga cancelada', 'AbortError');
              try {
                say(`Cargando parte ${number} de ${count}${attempt ? ' · reintentando' : ''}…`);
                const signed = await api(form.dataset.partUrl, { upload_id: uploadId, part_number: String(number) }, controller.signal);
                const etag = await put(signed.url, file.slice((number - 1) * partSize, Math.min(number * partSize, file.size)), signed.headers || { 'Content-Type': 'application/octet-stream' }, (number - 1) * partSize, file.size);
                if (!etag) throw new Error('El almacenamiento no devolvió la confirmación de la parte. Configura CORS para exponer ETag.');
                parts.push({ PartNumber: number, ETag: etag });
                completed = true;
              } catch (error) {
                if (cancelled || error.name === 'AbortError' || attempt === 2) throw error;
                await delay(1000 * (attempt + 1));
              }
            }
          }
        } else {
          say('Cargando archivo…');
          await put(upload.upload_url, file, upload.headers, 0, file.size);
        }
        if (cancelled) throw new DOMException('Carga cancelada', 'AbortError');
        if (cancelButton) cancelButton.disabled = true;
        say('Verificando el archivo recibido…');
        let confirmed = false;
        for (let attempt = 0; attempt < 3 && !confirmed; attempt++) {
          try {
            await api(form.dataset.completeUrl, { upload_id: uploadId, parts: JSON.stringify(parts) });
            confirmed = true;
          } catch (error) {
            if (attempt === 2 || (error.status && error.status !== 409 && error.status < 500)) throw error;
            await delay(1500 * (attempt + 1));
          }
        }
        if (progress) progress.value = 100;
        say('Archivo guardado. ');
        if (status) {
          const reload = document.createElement('a');
          reload.href = window.location.href;
          reload.textContent = 'Actualizar la ficha';
          status.append(reload, document.createTextNode(' para ver los cambios.'));
        }
        fileInput.value = '';
      } catch (error) {
        if (cancelled || error.name === 'AbortError') {
          say('Carga cancelada. Puedes volver a intentarlo.');
        } else {
          say(error.message || 'No se pudo completar la carga.');
          if (uploadId) {
            try { await api(form.dataset.cancelUrl, { upload_id: uploadId }); } catch { /* A verified/completed upload is never deleted by cancellation. */ }
          }
        }
      } finally {
        active = false;
        xhr = null;
        controller = null;
        fileInput.disabled = false;
        if (submit) submit.disabled = false;
        if (cancelButton) cancelButton.hidden = true;
      }
    });
  });
})();
