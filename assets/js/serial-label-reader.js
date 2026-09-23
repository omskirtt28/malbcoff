/* Serial label reader. Work on individual text rows/words at character scale,
 * not a fixed percentage of the photograph. All recognition stays on device. */
(() => {
  'use strict';
  const assets = new URL('../vendor/serial-ocr/', document.currentScript.src).href;
  const marker = /S[E3]R[I1L][A4]L\s*(?:NUMBER|N[O0][.:]?)?|S\s*\/\s*N/;
  const excluded = new Set(['SERIALNUMBER', 'SERIALNO', 'IPHONEPROMAX', 'MADEINCHINA',
    'PRODUCTOFCHINA', 'SERIAL', 'NUMBER', 'CALIFORNIA', 'ASSEMBLED', 'TRADEMARKS']);
  const diagnostics = [];
  let workerPromise = null, queue = Promise.resolve();
  const clamp = (n, lo, hi) => Math.max(lo, Math.min(hi, n));
  const median = values => [...values].sort((a, b) => a - b)[Math.floor(values.length / 2)] || 1;
  function record(stage, detail) {
    if (!window.MalbcoffSerialOcr?.debug) return;
    diagnostics.push({ stage, ...detail });
    if (diagnostics.length > 30) diagnostics.shift();
  }

  function extract(text, selected = false) {
    const found = new Set();
    const lines = String(text || '').toUpperCase().split(/\r?\n/).map(s => s.trim()).filter(Boolean);
    const add = (value, labelled) => {
      if (/^[A-Z0-9]{8,20}$/.test(value) && /[A-Z]/.test(value)
          && (labelled || selected || /\d/.test(value)) && !excluded.has(value)) found.add(value);
    };
    for (let i = 0; i < lines.length; i++) {
      const match = marker.exec(lines[i]);
      if (!match && !selected) continue;
      let tail = match ? lines[i].slice(match.index + match[0].length) : lines[i];
      if (match && !/[A-Z0-9]/.test(tail)) tail = lines[i + 1] || '';
      tail = tail.replace(/^\s*(?:\(S\)\s*)?(?:N[O0]\.?|SN)\s*[:#.-]?\s*/, '')
        .split(/\b(?:IMEI|MEID|EID|UPC|MODEL|PRODUCT)\b/)[0];
      const tokens = tail.match(/[A-Z0-9]+/g) || [];
      for (const token of tokens) add(token, !!match);
      // Spaces inside an OCR word can split a serial. Never join a label or
      // surrounding prose to it, and never guess O/0, I/1, B/8 or a missing S.
      if (tokens.length > 1 && tokens.length <= 5 && tokens.join('').length <= 20
          && tokens.every(t => !excluded.has(t))) {
        add(tokens.join(''), !!match);
      }
    }
    return [...found];
  }

  function extractImei(text) {
    const values = new Set();
    // Keep complete numeric runs. Do not take a 15-digit substring from EID,
    // join separate rows, or substitute letters to manufacture a valid IMEI.
    for (const line of String(text || '').toUpperCase().split(/\r?\n/)) {
      const content = line.replace(/\bIMEI(?:\s*[12](?!\d))?\s*[:#]?/g, ' ');
      for (const match of content.matchAll(/\d(?:[\t -]*\d)*/g)) {
        const value = match[0].replace(/[\t -]/g, '');
        const before = content[match.index - 1] || '';
        const after = content[match.index + match[0].length] || '';
        if (/[A-Z]/.test(before + after)) continue;
        if (window.MalbcoffImeiReader?.valid(value)) values.add(value);
      }
    }
    return [...values];
  }

  function extractBarcode(text, selected) {
    const values = new Set();
    for (const line of String(text || '').split(/\r?\n/)) {
      const label = /\b(?:BARCODE|UPC|EAN)\s*[:#]?\s*/i.exec(line);
      if (!selected && !label) continue;
      const tail = label ? line.slice(label.index + label[0].length) : line;
      for (const token of tail.match(/[A-Za-z0-9][A-Za-z0-9._\/-]{3,119}/g) || []) values.add(token);
    }
    return [...values];
  }

  function canvas(width, height) {
    const out = document.createElement('canvas');
    out.width = Math.max(1, Math.ceil(width)); out.height = Math.max(1, Math.ceil(height));
    const ctx = out.getContext('2d', { willReadFrequently: true });
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, out.width, out.height);
    ctx.imageSmoothingEnabled = true; ctx.imageSmoothingQuality = 'high';
    return out;
  }

  function crop(source, rect, maxSide = 2400) {
    const x = clamp(rect.x, 0, source.width - 1), y = clamp(rect.y, 0, source.height - 1);
    const w = clamp(rect.w, 1, source.width - x), h = clamp(rect.h, 1, source.height - y);
    const scale = Math.min(1, maxSide / Math.max(w, h));
    const out = canvas(w * scale, h * scale);
    out.getContext('2d').drawImage(source, x, y, w, h, 0, 0, out.width, out.height);
    return out;
  }

  // Local background thresholding separates ink from screen texture/shadows.
  // Use the same mask for finding glyphs and a separate OCR fallback; always
  // retain a pass over the original pixels so thresholding cannot erase a digit.
  function inkMask(source) {
    const w = source.width, h = source.height;
    const pixels = source.getContext('2d').getImageData(0, 0, w, h).data;
    const gray = new Uint8Array(w * h), integral = new Float64Array((w + 1) * (h + 1));
    const stride = w + 1;
    for (let y = 0; y < h; y++) {
      let sum = 0;
      for (let x = 0; x < w; x++) {
        const p = y * w + x, i = p * 4;
        gray[p] = Math.round(.299 * pixels[i] + .587 * pixels[i + 1] + .114 * pixels[i + 2]);
        sum += gray[p]; integral[(y + 1) * stride + x + 1] = integral[y * stride + x + 1] + sum;
      }
    }
    const radius = Math.max(9, Math.round(Math.min(w, h) * .025));
    const ink = new Uint8Array(w * h);
    for (let y = 0; y < h; y++) {
      const y0 = Math.max(0, y - radius), y1 = Math.min(h, y + radius + 1);
      for (let x = 0; x < w; x++) {
        const x0 = Math.max(0, x - radius), x1 = Math.min(w, x + radius + 1), p = y * w + x;
        const mean = (integral[y1 * stride + x1] - integral[y0 * stride + x1]
          - integral[y1 * stride + x0] + integral[y0 * stride + x0]) / ((x1 - x0) * (y1 - y0));
        ink[p] = gray[p] < Math.min(215, mean - 13) ? 1 : 0;
      }
    }
    return ink;
  }

  function binaryCopy(source) {
    const out = canvas(source.width, source.height), ctx = out.getContext('2d');
    const pixels = ctx.getImageData(0, 0, out.width, out.height), ink = inkMask(source);
    for (let p = 0; p < ink.length; p++) {
      const v = ink[p] ? 0 : 255, i = p * 4;
      pixels.data[i] = pixels.data[i + 1] = pixels.data[i + 2] = v;
    }
    ctx.putImageData(pixels, 0, 0); return out;
  }

  function textRows(source) {
    const small = crop(source, { x: 0, y: 0, w: source.width, h: source.height }, 1800);
    const w = small.width, h = small.height, scale = source.width / w;
    const ink = inkMask(small), stack = new Uint32Array(ink.length), glyphs = [];
    for (let seed = 0; seed < ink.length; seed++) {
      if (!ink[seed]) continue;
      let count = 0, size = 1, x0 = w, y0 = h, x1 = 0, y1 = 0;
      stack[0] = seed; ink[seed] = 0;
      while (size) {
        const p = stack[--size], y = Math.floor(p / w), x = p - y * w;
        count++; x0 = Math.min(x0, x); x1 = Math.max(x1, x);
        y0 = Math.min(y0, y); y1 = Math.max(y1, y);
        for (let yy = Math.max(0, y - 1); yy <= Math.min(h - 1, y + 1); yy++) {
          for (let xx = Math.max(0, x - 1); xx <= Math.min(w - 1, x + 1); xx++) {
            const next = yy * w + xx;
            if (ink[next]) { ink[next] = 0; stack[size++] = next; }
          }
        }
      }
      const gw = x1 - x0 + 1, gh = y1 - y0 + 1;
      if (count >= 5 && gh >= 4 && gh < h * .12 && gw < w * .2 && gw / gh < 3) {
        glyphs.push({ x: x0, y: y0, w: gw, h: gh, density: count / (gw * gh) });
      }
    }
    glyphs.sort((a, b) => a.x - b.x);
    const groups = [];
    for (const g of glyphs) {
      let best = null, score = Infinity;
      for (const group of groups) {
        const last = group[group.length - 1], dx = g.x - last.x - last.w;
        const height = Math.min(last.h, g.h);
        if (dx < -height * .2 || dx > height * 2.8 || g.h / last.h < .5 || g.h / last.h > 2) continue;
        const dy = Math.abs((g.y + g.h) - (last.y + last.h));
        if (dy > height * .35 + Math.max(0, dx) * .15) continue;
        const s = dy / height + Math.max(0, dx) / height;
        if (s < score) { best = group; score = s; }
      }
      if (best) best.push(g); else groups.push([g]);
    }
    const rows = [], barcodeBands = [];
    for (const group of groups) {
      if (group.length < 4) continue;
      const height = median(group.map(g => g.h));
      const bars = group.filter(g => g.h / g.w > 3.5 && g.density > .65).length;
      // A barcode is predominantly solid vertical strokes; a word is not.
      if (bars / group.length > .55) {
        const first = group[0], last = group[group.length - 1];
        barcodeBands.push({ left: first.x * scale, right: (last.x + last.w) * scale,
          top: median(group.map(g => g.y)) * scale });
        continue;
      }
      const mx = group.reduce((sum, g) => sum + g.x + g.w / 2, 0) / group.length;
      const my = group.reduce((sum, g) => sum + g.y + g.h, 0) / group.length;
      let numerator = 0, denominator = 0;
      for (const g of group) {
        const dx = g.x + g.w / 2 - mx;
        numerator += dx * (g.y + g.h - my); denominator += dx * dx;
      }
      const slope = clamp(numerator / (denominator || 1), -.2, .2);
      const members = group.map(g => ({ x: g.x * scale, y: g.y * scale, w: g.w * scale, h: g.h * scale }));
      rows.push({ members, height: height * scale, slope, x: mx * scale, y: (my - height / 2) * scale });
    }
    for (const row of rows) {
      const left = row.members[0].x, last = row.members[row.members.length - 1];
      row.barcodeDistance = Math.min(100, ...barcodeBands.filter(b => b.right > left && b.left < last.x + last.w
        && b.top >= row.y).map(b => (b.top - row.y) / row.height));
    }
    return rows;
  }

  // Rotate a tight strip around its baseline. A fixed 2600px row made small
  // serials huge and dragged the adjacent barcode into the recognition image.
  function rowImage(source, row, members = row.members, targetHeight = 40) {
    const left = Math.min(...members.map(g => g.x)), right = Math.max(...members.map(g => g.x + g.w));
    const mid = (left + right) / 2, cy = row.y + (mid - row.x) * row.slope;
    const scale = Math.min(5, targetHeight / row.height, 2400 / (right - left));
    const padding = 12, bandHeight = row.height * 1.5;
    const out = canvas((right - left) * scale + padding * 2, bandHeight * scale + padding * 2);
    const ctx = out.getContext('2d');
    ctx.translate(out.width / 2, out.height / 2); ctx.scale(scale, scale);
    ctx.rotate(-Math.atan(row.slope)); ctx.translate(-mid, -cy); ctx.drawImage(source, 0, 0);
    // White gutters prevent clipped neighboring lines becoming stray characters.
    ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, out.width, padding); ctx.fillRect(0, out.height - padding, out.width, padding);
    ctx.fillRect(0, 0, padding, out.height); ctx.fillRect(out.width - padding, 0, padding, out.height);
    return out;
  }

  function words(row) {
    const result = []; let word = [];
    for (const g of row.members) {
      const prev = word[word.length - 1];
      if (prev && g.x - prev.x - prev.w > row.height * .4) { result.push(word); word = []; }
      word.push(g);
    }
    if (word.length) result.push(word);
    return result;
  }

  async function deadline(promise, ms, message) {
    let timer;
    try { return await Promise.race([promise, new Promise((_, reject) => { timer = setTimeout(() => reject(new Error(message)), ms); })]); }
    finally { clearTimeout(timer); }
  }

  async function worker() {
    if (!window.Tesseract?.createWorker) throw new Error('Printed serial reader did not load. Reload Receive Stock.');
    if (!workerPromise) {
      const pending = window.Tesseract.createWorker('eng', 1, {
        workerPath: assets + 'worker.min.js', corePath: assets,
        langPath: assets.replace(/\/$/, ''), workerBlobURL: false, cacheMethod: 'none', gzip: true,
        errorHandler: error => record('engine-error', { message: String(error) })
      }, { load_system_dawg: '0', load_freq_dawg: '0', load_number_dawg: '0', load_punc_dawg: '0' });
      workerPromise = deadline(pending, 45000, 'Serial reader could not start. Reload Receive Stock and try again.')
        .catch(error => { workerPromise = null; pending.then(w => w.terminate()).catch(() => {}); throw error; });
    }
    return workerPromise;
  }

  function read(source, { type = 'serial', point = null, region = null, cancelled = () => false,
    progress = () => {}, preview = () => {} } = {}) {
    const task = async () => {
      if (cancelled()) return { values: [] };
      const label = type === 'imei' ? 'IMEI' : type === 'barcode' ? 'Barcode' : 'Serial Number';
      const labelMarker = type === 'imei' ? /\bIMEI(?:\s*[12])?\b/ : type === 'barcode' ? /\b(?:BARCODE|UPC|EAN)\b/ : marker;
      const parse = (text, selected = false) => type === 'imei' ? extractImei(text)
        : type === 'barcode' ? extractBarcode(text, selected) : extract(text, selected);
      progress(`Preparing ${label} reader…`);
      const engine = await worker();
      const recognize = async (image, mode, word = false) => {
        if (cancelled()) return null;
        preview(image);
        try {
          const result = await deadline(engine.recognize(image, {
            tessedit_pageseg_mode: String(mode), tessedit_char_whitelist: word && type !== 'barcode' ? (type === 'imei' ? '0123456789' : 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789') : '',
            preserve_interword_spaces: '1', user_defined_dpi: '300'
          }, { text: true, blocks: true, hocr: false, tsv: false }), 20000, `${label} reading took too long. Select a smaller area around the printed value.`);
          record('ocr-result', { mode, text: result.data?.text || '', confidence: result.data?.confidence });
          return cancelled() ? null : result.data;
        } catch (error) {
          workerPromise = null; await engine.terminate().catch(() => {}); throw error;
        }
      };
      const lineRead = async (image, selected, word = false) => {
        const values = new Set();
        for (const pass of [{ image, mode: word ? 8 : 7 }, { image: binaryCopy(image), mode: 13 }]) {
          const data = await recognize(pass.image, pass.mode, word);
          if (!data) break;
          for (const value of parse(data.text, selected)) values.add(value);
        }
        return [...values];
      };
      let working = source;
      if (region) {
        working = crop(source, { x: region.x * source.width, y: region.y * source.height,
          w: region.w * source.width, h: region.h * source.height }, 3200);
      }
      if (cancelled()) return { values: [] };
      progress(point || region ? `Isolating the selected ${label} text…` : `Finding ${label} on the label…`);
      const rows = textRows(working);
      record('rows', { count: rows.length, selected: !!(point || region) });
      if (point || region) {
        const px = point ? point.x * source.width : working.width / 2;
        const py = point ? point.y * source.height : working.height / 2;
        const distance = row => {
          const left = row.members[0].x, last = row.members[row.members.length - 1];
          const dx = Math.max(left - px, px - last.x - last.w, 0);
          return Math.abs(py - row.y - (px - row.x) * row.slope) + dx * .3;
        };
        rows.sort((a, b) => distance(a) - distance(b));
        const found = new Set();
        for (const row of rows.slice(0, region ? 3 : 2)) {
          if (cancelled()) return { values: [] };
          if (point && distance(row) > Math.max(row.height * 2.5, source.height * .035)) continue;
          const parts = words(row).filter(part => part.length >= 6 && part.length <= 22);
          parts.sort((a, b) => Math.abs((a[0].x + a[a.length - 1].x + a[a.length - 1].w) / 2 - px)
            - Math.abs((b[0].x + b[b.length - 1].x + b[b.length - 1].w) / 2 - px));
          // Start with the value under the finger, then read its labelled row.
          for (const part of parts.slice(0, 2)) {
            for (const value of await lineRead(rowImage(working, row, part), true, true)) found.add(value);
          }
          for (const value of await lineRead(rowImage(working, row), true)) found.add(value);
          if (found.size) return { values: [...found] };
        }
        // An explicit selection still works when pixel segmentation finds no row.
        // This fallback uses precisely the user's rectangle, never a guessed wide band.
        if (region) {
          const scale = Math.min(4, 64 / working.height, 2000 / working.width);
          const image = canvas(working.width * scale + 24, working.height * scale + 24);
          image.getContext('2d').drawImage(working, 12, 12, image.width - 24, image.height - 24);
          for (const value of await lineRead(image, true, true)) found.add(value);
        }
        return { values: [...found] };
      }
      // Read text rows independently. Barcode bars no longer determine whether
      // Tesseract considers the surrounding label to contain text at all.
      const page = crop(source, { x: 0, y: 0, w: source.width, h: source.height }, 2400);
      const data = await recognize(page, 11);
      if (!data) return { values: [] };
      const direct = parse(data.text);
      if (direct.length) return { values: direct };
      const labelLines = (data.lines || []).filter(line => line.bbox && labelMarker.test(String(line.text || '').toUpperCase()));
      const pageScale = source.width / page.width;
      const proximity = row => labelLines.length ? Math.min(...labelLines.map(line =>
        Math.abs(row.y - (line.bbox.y0 + line.bbox.y1) / 2 * pageScale))) : 0;
      rows.sort((a, b) => proximity(a) - proximity(b) || a.barcodeDistance - b.barcodeDistance || b.height - a.height);
      for (let i = 0; i < Math.min(rows.length, 14); i++) {
        if (cancelled()) return { values: [] };
        progress(`Reading label text (${i + 1}/${Math.min(rows.length, 14)})…`);
        const row = rows[i], image = rowImage(source, row);
        const reading = await recognize(image, 7);
        if (!reading) return { values: [] };
        const values = parse(reading.text);
        if (values.length) return { values };
        if (labelMarker.test(String(reading.text || '').toUpperCase())) {
          const found = new Set(await lineRead(image, false));
          // The label may be legible even when the value was rejected as a word.
          // Read only trailing groups on this identified row as serial words.
          for (const part of words(row).filter(part => part.length >= 8 && part.length <= 20).slice(-2)) {
            for (const value of await lineRead(rowImage(source, row, part), true, true)) found.add(value);
          }
          if (found.size) return { values: [...found] };
        }
      }
      return { values: [] };
    };
    const pending = queue.then(task, task); queue = pending.catch(() => {}); return pending;
  }
  window.MalbcoffSerialOcr = { read, debug: false, diagnostics };
})();
