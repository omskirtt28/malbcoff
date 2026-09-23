/* Reusable photo/live scanner for POS search, inventory search and accessory setup.
 * Use fills the selected field; the owning page retains its normal validation. */
(() => {
  'use strict';
  if (!document.querySelector('[data-device-scan]')) return;
  const modal = document.createElement('div');
  modal.className = 'modal camera-scan-modal shared-device-scanner'; modal.hidden = true;
  modal.innerHTML = `
    <div class="modal-backdrop" data-close></div>
    <div class="modal-dialog camera-scan-dialog" role="dialog" aria-modal="true" aria-labelledby="sharedScannerTitle">
      <div class="modal-header"><div><span class="eyebrow">CAMERA SCANNER</span><h2 id="sharedScannerTitle">Scan Item</h2><p>Review the printed value before using it.</p></div><button type="button" class="icon-button" data-close aria-label="Close scanner">×</button></div>
      <div class="modal-body">
        <label class="field"><span>Read</span><select data-mode><option value="auto">IMEI, Serial Number or Barcode</option><option value="imei">IMEI</option><option value="serial">Serial Number</option><option value="barcode">Barcode</option></select></label>
        <div class="camera-scan-stage"><video playsinline muted></video><img data-photo hidden alt="Captured label"><div data-selection hidden></div></div>
        <div class="camera-scan-message" role="status" aria-live="polite" data-message>Preparing camera…</div>
        <button class="btn btn-outline" type="button" data-select hidden>Select Text</button>
        <div class="shared-scanner-crop" data-crop hidden><small>Area being read — keep barcode bars outside a text selection.</small><img alt="Selected text"></div>
        <label class="field"><span>Check against the label</span><input data-value type="text" maxlength="120" autocomplete="off" spellcheck="false" placeholder="TYPE OR CORRECT THE VALUE"></label>
        <div class="camera-serial-candidates" data-candidates></div>
      </div>
      <div class="modal-actions camera-scan-actions"><button class="btn btn-secondary" type="button" data-close>Cancel</button><button class="btn btn-outline" type="button" data-gallery>Use Existing Photo</button><button class="btn btn-outline" type="button" data-camera>Take Photo</button><button class="btn btn-primary" type="button" data-use>Use Value</button></div>
    </div>`;
  document.body.appendChild(modal);
  const $ = selector => modal.querySelector(selector);
  const video = $('video'), photo = $('[data-photo]'), stage = $('.camera-scan-stage');
  const mode = $('[data-mode]'), valueInput = $('[data-value]'), message = $('[data-message]');
  const capture = document.createElement('input'), gallery = document.createElement('input');
  for (const input of [capture, gallery]) { input.type = 'file'; input.accept = 'image/*'; input.hidden = true; document.body.appendChild(input); }
  capture.setAttribute('capture', 'environment');
  let target = null, opener = null, submitSearch = false, file = null, photoUrl = '';
  let stream = null, session = 0, frame = 0, lastFrame = 0, busy = false;
  let selecting = false, start = null, pointer = null, ignoreClickUntil = 0;
  const normalize = raw => mode.value === 'serial' || mode.value === 'imei'
    ? String(raw || '').replace(/\s+/g, '').toUpperCase() : String(raw || '').trim();
  function valid(value) {
    if (mode.value === 'imei') return window.MalbcoffImeiReader?.valid(value);
    if (mode.value === 'serial') return window.MalbcoffImeiReader?.validSerial(value);
    return value.length > 0 && value.length <= 120 && !/[\x00-\x1f\x7f]/.test(value);
  }
  function status(text, error = false) {
    message.textContent = text; message.classList.toggle('is-error', error);
  }
  function resetSelection() {
    selecting = false; start = null; pointer = null;
    $('[data-selection]').hidden = true; $('[data-select]').textContent = 'Select Text'; photo.style.touchAction = 'manipulation';
  }
  function stop() {
    session++; busy = false; cancelAnimationFrame(frame); frame = 0;
    if (stream) stream.getTracks().forEach(track => track.stop());
    stream = null; video.srcObject = null; resetSelection();
  }
  function close() {
    stop(); modal.hidden = true;
    if (photoUrl) URL.revokeObjectURL(photoUrl);
    photoUrl = ''; file = null; photo.removeAttribute('src'); photo.hidden = true;
    $('[data-crop] img').removeAttribute('src'); $('[data-crop]').hidden = true;
    if (!document.querySelector('.modal:not([hidden])')) document.body.classList.remove('modal-open');
    opener?.focus(); target = null;
  }
  function showPhoto(nextFile) {
    stop(); file = nextFile;
    if (photoUrl) URL.revokeObjectURL(photoUrl);
    photoUrl = URL.createObjectURL(file); photo.src = photoUrl; photo.hidden = false; video.hidden = true;
    $('[data-select]').hidden = false;
  }
  function review(values, text) {
    const unique = [...new Set(values.map(normalize).filter(valid))];
    valueInput.value = unique.length === 1 ? unique[0] : '';
    $('[data-candidates]').replaceChildren();
    if (unique.length > 1) for (const value of unique.slice(0, 8)) {
      const button = document.createElement('button'); button.type = 'button'; button.className = 'btn btn-outline'; button.textContent = value;
      button.addEventListener('click', () => { valueInput.value = value; }); $('[data-candidates]').appendChild(button);
    }
    status(text || (unique.length ? 'Check the value against its printed label, then tap Use Value.' : 'Tap the printed value or select its text. You can also enter it below.'));
  }
  async function baseCanvas(image) {
    const bitmap = await createImageBitmap(image);
    try {
      const scale = Math.min(1, 4096 / Math.max(bitmap.width, bitmap.height));
      const canvas = document.createElement('canvas'); canvas.width = Math.max(1, Math.round(bitmap.width * scale)); canvas.height = Math.max(1, Math.round(bitmap.height * scale));
      canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height); return canvas;
    } finally { bitmap.close?.(); }
  }
  async function codes(source, current, live = false) {
    const options = { live, cancelled: () => current !== session };
    if (mode.value === 'imei') return { values: (await MalbcoffImeiReader.read(source, options)).unique };
    if (mode.value === 'serial') return MalbcoffImeiReader.readSerial(source, options);
    return MalbcoffImeiReader.readCodes(source, options);
  }
  async function readPhoto(nextFile, point = null, region = null) {
    if (!target || modal.hidden) return;
    if (nextFile) showPhoto(nextFile);
    if (!file) return;
    const current = ++session; busy = true; resetSelection();
    valueInput.value = ''; $('[data-candidates]').replaceChildren(); $('[data-crop]').hidden = true;
    status('Reading label…');
    try {
      const canvas = await baseCanvas(file);
      if (current !== session) return;
      if (!point && !region) {
        let result = { values: [] };
        try { result = await codes(canvas, current); } catch (_) { /* Printed-text fallback below. */ }
        if (current !== session) return;
        if (result.values.length) { review(result.values); return; }
      }
      const types = mode.value === 'auto' ? ['imei', 'serial', 'barcode'] : [mode.value];
      const found = new Set();
      for (const type of types) {
        const result = await MalbcoffSerialOcr.read(canvas, {
          type, point, region, cancelled: () => current !== session,
          progress: text => { if (current === session) status(text); },
          preview: image => { if (current === session) { $('[data-crop] img').src = image.toDataURL('image/png'); $('[data-crop]').hidden = false; } }
        });
        if (current !== session) return;
        for (const value of result.values) found.add(value);
        if (found.size) break;
      }
      review([...found]);
    } catch (error) {
      if (current === session) review([], error?.message || 'Reading could not finish. Retake the photo or enter the printed value.');
    } finally { if (current === session) busy = false; capture.value = ''; gallery.value = ''; }
  }
  async function detect(time) {
    if (!stream || modal.hidden) return;
    const current = session;
    if (!busy && video.readyState >= 2 && time - lastFrame >= 500) {
      busy = true; lastFrame = time;
      try {
        const result = await codes(video, current, true);
        if (current !== session) return;
        if (result.values.length) {
          const canvas = document.createElement('canvas'); canvas.width = video.videoWidth; canvas.height = video.videoHeight;
          canvas.getContext('2d').drawImage(video, 0, 0);
          const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
          if (current !== session) return;
          if (blob) { showPhoto(blob); review(result.values); return; }
        }
      } catch (_) { if (current === session) status('Tap Take Photo or use an existing photo to read this label.'); }
      finally { if (current === session) busy = false; }
    }
    if (current === session && stream) frame = requestAnimationFrame(detect);
  }
  async function open(button) {
    stop(); opener = button; target = document.querySelector(button.dataset.scanTarget);
    if (!target) return;
    submitSearch = button.hasAttribute('data-scan-submit');
    mode.value = button.dataset.scanMode || 'auto';
    $('#sharedScannerTitle').textContent = button.dataset.scanLabel || 'Scan Item';
    mode.disabled = button.dataset.scanMode === 'barcode';
    valueInput.value = ''; $('[data-candidates]').replaceChildren(); $('[data-crop]').hidden = true;
    file = null; if (photoUrl) URL.revokeObjectURL(photoUrl); photoUrl = ''; photo.removeAttribute('src'); photo.hidden = true; video.hidden = false;
    $('[data-select]').hidden = true; modal.hidden = false; document.body.classList.add('modal-open');
    status('Point at the identifier barcode. You can also take a photo or enter its printed value.');
    if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) { capture.value = ''; capture.click(); return; }
    const current = session;
    try {
      const opened = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } }, audio: false });
      if (current !== session || modal.hidden) { opened.getTracks().forEach(track => track.stop()); return; }
      stream = opened; video.srcObject = opened; await video.play();
      if (current === session) { lastFrame = 0; frame = requestAnimationFrame(detect); }
    } catch (_) { if (current === session) { stop(); status('Allow camera access, or use Take Photo / Use Existing Photo.', true); } }
  }
  document.addEventListener('click', event => { const button = event.target.closest('[data-device-scan]'); if (button) open(button); });
  modal.querySelectorAll('[data-close]').forEach(button => button.addEventListener('click', close));
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && !modal.hidden) close(); });
  window.addEventListener('pagehide', stop);
  $('[data-use]').addEventListener('click', () => {
    if (!target?.isConnected || modal.hidden) return;
    const value = normalize(valueInput.value);
    if (!valid(value)) { status(mode.value === 'imei' ? 'Enter a valid 15-digit IMEI.' : 'Enter the printed value before using it.', true); message.scrollIntoView({ block: 'nearest' }); return; }
    const field = target, submit = submitSearch;
    field.value = value; close(); field.dispatchEvent(new Event('input', { bubbles: true })); field.dispatchEvent(new Event('change', { bubbles: true })); field.focus();
    if (submit) field.form?.requestSubmit();
  });
  valueInput.addEventListener('input', () => {
    // A late OCR result must not replace a value the user is already correcting.
    if (busy || stream) stop();
    status('Check the printed value, then tap Use Value.');
  });
  function pick(input) { stop(); input.value = ''; input.click(); }
  $('[data-camera]').addEventListener('click', () => pick(capture)); $('[data-gallery]').addEventListener('click', () => pick(gallery));
  for (const input of [capture, gallery]) input.addEventListener('change', () => { if (input.files?.[0]) readPhoto(input.files[0]); });
  mode.addEventListener('change', () => { stop(); review([]); if (file) readPhoto(null); else status('Take a photo for the selected identifier type.'); });
  function point(event, clamp = false) {
    if (!photo.naturalWidth || photo.hidden) return null;
    const rect = photo.getBoundingClientRect(), scale = Math.min(rect.width / photo.naturalWidth, rect.height / photo.naturalHeight);
    const w = photo.naturalWidth * scale, h = photo.naturalHeight * scale;
    let x = (event.clientX - rect.left - (rect.width - w) / 2) / w, y = (event.clientY - rect.top - (rect.height - h) / 2) / h;
    if (!clamp && (x < 0 || y < 0 || x > 1 || y > 1)) return null;
    return { x: Math.max(0, Math.min(1, x)), y: Math.max(0, Math.min(1, y)) };
  }
  function draw(a, b) {
    const rect = photo.getBoundingClientRect(), bounds = stage.getBoundingClientRect();
    const scale = Math.min(rect.width / photo.naturalWidth, rect.height / photo.naturalHeight), w = photo.naturalWidth * scale, h = photo.naturalHeight * scale;
    const region = { x: Math.min(a.x, b.x), y: Math.min(a.y, b.y), w: Math.abs(a.x - b.x), h: Math.abs(a.y - b.y) }, box = $('[data-selection]');
    box.hidden = false; box.style.left = `${rect.left - bounds.left + (rect.width - w) / 2 + region.x * w}px`; box.style.top = `${rect.top - bounds.top + (rect.height - h) / 2 + region.y * h}px`;
    box.style.width = `${region.w * w}px`; box.style.height = `${region.h * h}px`; return region;
  }
  $('[data-select]').addEventListener('click', () => {
    if (!file) return;
    if (selecting) { resetSelection(); return; }
    session++; busy = false; selecting = true; photo.style.touchAction = 'none'; $('[data-select]').textContent = 'Cancel Selection';
    status('Drag a box around only the printed value. Release to read it.'); stage.scrollIntoView({ block: 'nearest' });
  });
  photo.addEventListener('pointerdown', event => { if (!selecting || !event.isPrimary) return; start = point(event); if (start) { event.preventDefault(); pointer = event.pointerId; photo.setPointerCapture(pointer); draw(start, start); } });
  photo.addEventListener('pointermove', event => { if (!selecting || !start || event.pointerId !== pointer) return; event.preventDefault(); const end = point(event, true); if (end) draw(start, end); });
  photo.addEventListener('pointerup', event => {
    if (!selecting || !start || event.pointerId !== pointer) return;
    event.preventDefault(); const end = point(event, true), region = end ? draw(start, end) : null; ignoreClickUntil = performance.now() + 600; resetSelection();
    if (region && region.w * photo.naturalWidth >= 12 && region.h * photo.naturalHeight >= 6) readPhoto(null, null, region);
    else status('Select Text and drag a box around the whole printed value.');
  });
  photo.addEventListener('pointercancel', resetSelection);
  photo.addEventListener('click', event => { if (busy || selecting || performance.now() < ignoreClickUntil || !file) return; const selected = point(event); if (selected) readPhoto(null, selected); });
})();
