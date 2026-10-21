async (page) => await page.evaluate(() => {
  const el=[...document.querySelectorAll('.page-content *')].find(n=>n.classList.contains('table-subtext'));
  return {html:el?.outerHTML, size:el&&getComputedStyle(el).fontSize, styles:[...document.styleSheets].map(s=>({href:s.href,rules:[...s.cssRules].filter(r=>r.cssText.includes('table-subtext')).map(r=>r.cssText)}))};
})
