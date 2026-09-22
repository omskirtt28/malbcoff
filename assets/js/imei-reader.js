/* Local multi-barcode reader for phone box labels. No photos leave the browser. */
(() => {
  'use strict';
  const scriptUrl = document.currentScript?.src || location.href;
  let configured = false;

  function valid(value) {
    if (!/^\d{15}$/.test(value) || /^(\d)\1{14}$/.test(value)) return false;
    let sum = 0;
    for (let i = 0; i < 15; i++) {
      let n = Number(value[i]);
      if (i % 2) { n *= 2; if (n > 9) n -= 9; }
      sum += n;
    }
    return sum % 10 === 0;
  }

  function summarize(hits) {
    const unique = [...new Set(hits.map(h => h.imei))];
    const result = { pair: null, unique, hits, ambiguous: unique.length > 2 };
    if (unique.length !== 2) return result;
    // Match neighboring bars in ONE sticker. A median across repeated stickers
    // reverses the pair when IMEI 1 is only readable on the lower sticker.
    const pairs = [];
    for (const a of hits) for (const b of hits) {
      if (a.imei === b.imei || b.y <= a.y) continue;
      const width = Math.max(a.width, b.width);
      const gap = (b.y - a.y) / width;
      if (gap < 0.025 || gap > 0.5 || Math.abs(a.x - b.x) > width * 0.22) continue;
      if (Math.min(a.width, b.width) / width < 0.65) continue;
      pairs.push({ a, b, score: gap + Math.abs(a.x - b.x) / width });
    }
    pairs.sort((a, b) => a.score - b.score);
    const best = pairs[0];
    if (!best) return result;
    // Equally plausible, contradictory ordering needs another closer photo.
    if (pairs.some(p => p.a.imei !== best.a.imei && p.score <= best.score * 1.35)) return result;
    result.pair = { 1: best.a.imei, 2: best.b.imei };
    return result;
  }

  function canvasFrom(source, angle = 0, maxSide = 2400) {
    const sw = source.videoWidth || source.naturalWidth || source.width;
    const sh = source.videoHeight || source.naturalHeight || source.height;
    const scale = Math.min(1, maxSide / Math.max(sw, sh));
    const w = sw * scale, h = sh * scale;
    const rad = angle * Math.PI / 180, cos = Math.cos(rad), sin = Math.sin(rad);
    const canvas = document.createElement('canvas');
    canvas.width = Math.ceil(Math.abs(w * cos) + Math.abs(h * sin));
    canvas.height = Math.ceil(Math.abs(w * sin) + Math.abs(h * cos));
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.translate(canvas.width / 2, canvas.height / 2); ctx.rotate(rad);
    ctx.drawImage(source, -w / 2, -h / 2, w, h);
    return { canvas, cos, sin, w, h };
  }

  function configure() {
    if (!window.ZXingWASM) throw new Error('Barcode reader did not load. Refresh this page.');
    if (!configured) {
      window.ZXingWASM.prepareZXingModule({ overrides: {
        locateFile: name => new URL('../vendor/zxing-wasm/' + name, scriptUrl).href
      } });
      configured = true;
    }
  }

  // Serial numbers are alphanumeric. Do not reinterpret 15-digit IMEIs or
  // numeric EAN/UPC barcodes as serial numbers, and do not guess/remove digits.
  function validSerial(value) {
    return /^[A-Z0-9]{1,80}$/.test(value) && /[A-Z]/.test(value);
  }

  async function readSerial(source, { live = false, cancelled = () => false } = {}) {
    configure();
    const values = new Set();
    for (const angle of (live ? [0] : [0, -4, 4, -8, 8])) {
      if (cancelled()) return { values: [] };
      // Keep fine bars from a full phone photograph; let the decoder try its
      // own smaller scales instead of discarding detail before the first pass.
      const { canvas } = canvasFrom(source, angle, live ? 1600 : 4096);
      const pixels = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height);
      const codes = await window.ZXingWASM.readBarcodes(pixels, {
        formats: ['Code128', 'Code39', 'Code93', 'DataMatrix'], tryHarder: true,
        tryRotate: true, tryDownscale: !live, maxNumberOfSymbols: 64
      });
      if (cancelled()) return { values: [] };
      for (const code of codes) {
        if (code.error) continue;
        const value = String(code.text || '').trim().toUpperCase()
          .replace(/^(?:SERIAL(?:\s*(?:NUMBER|NO\.?))?|S\/N|SN)\s*[:#]\s*/, '');
        if (validSerial(value)) values.add(value);
      }
      if (values.size) break;
      await new Promise(resolve => setTimeout(resolve, 0));
    }
    return { values: [...values] };
  }

  async function read(source, { live = false, cancelled = () => false } = {}) {
    configure();
    const hits = [];
    // Slight rotations recover skewed Code 128 bars, including photographed screens.
    for (const angle of (live ? [0] : [0, -4, 4, -8, 8])) {
      if (cancelled()) return summarize([]);
      const frame = canvasFrom(source, angle, live ? 1600 : 2400);
      const { canvas, cos, sin, w, h } = frame;
      const pixels = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height);
      const codes = await window.ZXingWASM.readBarcodes(pixels, {
        formats: ['Code128', 'Code39', 'ITF'], tryHarder: true,
        tryRotate: true, tryDownscale: false, maxNumberOfSymbols: 64
      });
      if (cancelled()) return summarize([]);
      for (const code of codes) {
        const value = String(code.text || '').trim();
        if (!valid(value) || code.error) continue;
        const points = Object.values(code.position).map(p => {
          const x = p.x - canvas.width / 2, y = p.y - canvas.height / 2;
          return { x: x * cos + y * sin + w / 2, y: -x * sin + y * cos + h / 2 };
        });
        const x = points.reduce((s, p) => s + p.x, 0) / points.length;
        const y = points.reduce((s, p) => s + p.y, 0) / points.length;
        const width = Math.hypot(points[1].x - points[0].x, points[1].y - points[0].y);
        hits.push({ imei: value, x, y, width });
      }
      // Every pass decodes the whole image before checking uniqueness.
      const result = summarize(hits);
      if (result.ambiguous || result.pair) return result;
      await new Promise(resolve => setTimeout(resolve, 0));
    }
    return summarize(hits);
  }

  window.MalbcoffImeiReader = { read, valid, summarize, readSerial, validSerial };
})();
