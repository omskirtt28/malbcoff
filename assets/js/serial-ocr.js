/* Local printed-serial fallback. Never substitute ambiguous letters/digits. */
(() => {
  'use strict';
  const assets = new URL('../vendor/serial-ocr/', document.currentScript.src).href;
  let workerPromise = null;
  let queue = Promise.resolve();
  function candidates(text, tapped) {
    const values = new Set();
    const lines = String(text || '').toUpperCase().split(/\r?\n/).map(s => s.trim()).filter(Boolean);
    const add = text => {
      const value = text.replace(/[ \t]/g, '').replace(/^[#:.]+/, '').trim();
      if (/^[A-Z0-9]{8,20}$/.test(value) && /[A-Z]/.test(value)
          && !['SERIALNUMBER','SERIALNO','IPHONEPROMAX','MADEINCHINA'].includes(value)) values.add(value);
    };
    lines.forEach((line, i) => {
      const label = line.match(/\b(?:SERIAL\s*(?:NUMBER|NO\.?)|S\s*\/\s*N)\s*[:#.-]?\s*(.*)$/);
      if (label) add(label[1] || lines[i + 1] || '');
    });
    if (!values.size && tapped) for (const line of lines) if (/^[A-Z0-9]{8,20}$/.test(line)) add(line);
    return [...values];
  }
  function crop(source, rect, width) {
    const canvas = document.createElement('canvas');
    canvas.width = width;canvas.height = Math.max(1, Math.round(rect.h / rect.w * width));
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    ctx.fillStyle = '#fff';ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.imageSmoothingEnabled = true;ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(source, rect.x, rect.y, rect.w, rect.h, 0, 0, canvas.width, canvas.height);
    return canvas;
  }
  async function worker() {
    if (!window.Tesseract?.createWorker) throw new Error('Printed serial reader is missing.');
    if (!workerPromise) workerPromise = Tesseract.createWorker('eng', 1, {
      workerPath: assets + 'worker.min.js', corePath: assets,
      langPath: assets.replace(/\/$/, ''), workerBlobURL: false, cacheMethod: 'none', gzip: true
    }).catch(error => {workerPromise = null;throw error;});
    return workerPromise;
  }
  function read(source, { point = null, cancelled = () => false, progress = () => {} } = {}) {
    const task = async () => {
      if (cancelled()) return { values: [] };
      progress('Preparing printed Serial Number reader…');
      const engine = await worker();
      if (cancelled()) return { values: [] };
      const w = source.width, h = source.height;
      const rects = point ? [{
        x: Math.max(0, Math.min(w * .5, point.x * w - w * .25)),
        y: Math.max(0, Math.min(h * .88, point.y * h - h * .06)), w: w * .5, h: h * .12
      }] : [{ x: 0, y: 0, w, h },
        { x: 0, y: h * .25, w: w * .7, h: h * .6 },
        { x: w * .3, y: h * .25, w: w * .7, h: h * .6 }];
      await engine.setParameters({
        tessedit_char_whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789():/#.- ',
        tessedit_pageseg_mode: '11', preserve_interword_spaces: '1', user_defined_dpi: '300'
      });
      for (let i = 0; i < rects.length; i++) {
        if (cancelled()) return { values: [] };
        progress(point ? 'Reading the selected Serial No. row…' : `Reading printed Serial No. (${i + 1}/${rects.length})…`);
        const result = await engine.recognize(crop(source, rects[i], point ? 1800 : (i ? 2000 : 2400)));
        if (cancelled()) return { values: [] };
        const values = candidates(result?.data?.text, !!point);
        if (values.length) return { values };
      }
      return { values: [] };
    };
    const pending = queue.then(task, task);
    queue = pending.catch(() => {});
    return pending;
  }
  window.MalbcoffSerialOcr = { read };
})();
